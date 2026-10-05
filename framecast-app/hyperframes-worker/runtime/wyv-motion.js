/* WyvStudio motion kit. Load it with a script tag after gsap.min.js.
 * Everything here adds ordinary GSAP tweens to a timeline you pass in, so it
 * seeks and renders exactly like the rest of the composition. Deterministic:
 * no timers, no randomness, no state outside the timeline. */
(function () {
  'use strict';
  if (!window.gsap) throw new Error('wyv-motion.js needs gsap.min.js loaded first');

  // Closed-form damped spring, as an ease over the tween's duration.
  // zeta < 1 overshoots; the curve is pinned to exactly 1 at the end.
  function springEase(zeta, cycles) {
    var w = 2 * Math.PI * cycles, wd = w * Math.sqrt(Math.max(1e-6, 1 - zeta * zeta));
    var raw = function (p) { return 1 - Math.exp(-zeta * w * p) * (Math.cos(wd * p) + (zeta * w / wd) * Math.sin(wd * p)); };
    var end = raw(1);
    return function (p) { return p >= 1 ? 1 : p <= 0 ? 0 : raw(p) + (1 - end) * p; };
  }
  var ease = {
    snappy: springEase(0.62, 1.6),   // buttons, toggles, leading edges: tiny overshoot
    default: springEase(0.78, 1.25), // cards, containers, camera
    heavy: springEase(1, 0.9),       // big type, logo lockups: no overshoot
    playful: springEase(0.42, 1.8),  // mascots, stickers: visible bounce
  };
  ease.default = ease['default'];

  function $(el) { return typeof el === 'string' ? document.querySelector(el) : el; }

  var WM = {
    ease: ease,

    /* Cursor: moves along points, pressing where click is true.
       points: [{x, y, at, click}] in px of the stage; at in seconds. */
    cursor: function (tl, el, points) {
      el = $(el);
      points.forEach(function (pt, i) {
        if (i === 0) { tl.set(el, { x: pt.x, y: pt.y, autoAlpha: 1 }, pt.at); return; }
        var prev = points[i - 1], dur = Math.max(0.25, Math.min(0.9, pt.at - prev.at - 0.05));
        // A slight arc, like a hand, not a straight machine line.
        var mx = (prev.x + pt.x) / 2 + (pt.y - prev.y) * 0.12, my = (prev.y + pt.y) / 2 - Math.abs(pt.x - prev.x) * 0.08;
        tl.to(el, { keyframes: [{ x: mx, y: my, duration: dur * 0.5, ease: 'sine.in' }, { x: pt.x, y: pt.y, duration: dur * 0.5, ease: 'power3.out' }] }, pt.at - dur);
        if (pt.click) tl.to(el, { scale: 0.82, duration: 0.08, ease: 'power2.in', yoyo: true, repeat: 1, transformOrigin: '20% 20%' }, pt.at);
      });
      return tl;
    },

    /* Button press: dips, flashes its accent, springs back. */
    press: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      tl.to(el, { scale: 0.94, duration: 0.09, ease: 'power2.in' }, at)
        .to(el, { scale: 1, duration: 0.45, ease: ease.snappy }, at + 0.09);
      if (opts.glow) tl.fromTo(el, { boxShadow: '0 0 0 0 ' + opts.glow }, { boxShadow: '0 0 0 18px rgba(0,0,0,0)', duration: 0.6, ease: 'power2.out' }, at + 0.05);
      return tl;
    },

    /* Typing: reveals text at cps characters a second. Each character is a timed
       set rather than an update callback, so any seek (including one that suppresses
       events, as renderers do) shows exactly the right text. */
    type: function (tl, el, text, at, cps) {
      el = $(el); cps = cps || 18;
      el.textContent = ''; // empty until the typing starts, wherever the playhead is seeked
      for (var i = 0; i < text.length; i++) tl.set(el, { textContent: text.slice(0, i + 1) }, at + i / cps);
      return tl;
    },

    /* Toggle: knob slides, track changes colour. */
    toggle: function (tl, track, knob, at, opts) {
      opts = opts || {};
      tl.to($(knob), { x: opts.distance || 28, duration: 0.4, ease: ease.snappy }, at);
      if (opts.on) tl.to($(track), { backgroundColor: opts.on, duration: 0.25, ease: 'power2.out' }, at);
      return tl;
    },

    /* Counter: counts to a value (use only approved numbers). One timed set per
       frame (30 a second) on an ease-out curve, so seeks show the exact value. */
    count: function (tl, el, from, to, at, duration, format) {
      el = $(el); format = format || function (v) { return Math.round(v).toLocaleString('en-US'); };
      duration = duration || 1.2; el.textContent = format(from);
      var steps = Math.max(1, Math.round(duration * 30)), last = null;
      for (var i = 1; i <= steps; i++) {
        var p = i / steps, v = from + (to - from) * (1 - Math.pow(1 - p, 3)), text = format(i === steps ? to : v);
        if (text !== last) tl.set(el, { textContent: text }, at + duration * p);
        last = text;
      }
      return tl;
    },

    /* Morph: one shape moves through states instead of new cards fading in.
       states: [{at, width, height, borderRadius, backgroundColor, x, y}] */
    morph: function (tl, el, states) {
      el = $(el);
      states.forEach(function (s, i) {
        var props = Object.assign({}, s); delete props.at;
        if (i === 0) tl.set(el, props, s.at);
        else tl.to(el, Object.assign(props, { duration: 0.7, ease: ease['default'] }), s.at);
      });
      return tl;
    },

    /* Transitions between two full-frame scenes (outgoing a, incoming b). */
    wipe: function (tl, b, at, dir) {
      var from = { left: 'inset(0 100% 0 0)', right: 'inset(0 0 0 100%)', up: 'inset(100% 0 0 0)', down: 'inset(0 0 100% 0)' }[dir || 'left'];
      tl.set($(b), { autoAlpha: 1 }, at).fromTo($(b), { clipPath: from }, { clipPath: 'inset(0 0 0 0)', duration: 0.55, ease: 'expo.inOut' }, at);
      return tl;
    },
    push: function (tl, a, b, at, dir) {
      var axis = dir === 'up' || dir === 'down' ? 'yPercent' : 'xPercent', s = dir === 'right' || dir === 'down' ? 1 : -1;
      var o = {}, i = {}; o[axis] = 100 * s; i[axis] = -100 * s;
      tl.to($(a), Object.assign(o, { duration: 0.6, ease: 'expo.inOut' }), at)
        .set($(b), { autoAlpha: 1 }, at)
        .fromTo($(b), i, (function () { var e = { duration: 0.6, ease: 'expo.inOut' }; e[axis] = 0; return e; })(), at);
      return tl;
    },
    whip: function (tl, a, b, at) {
      // Fastest at the cut: ease in on the exit, ease out on the entry, blur in between.
      tl.to($(a), { xPercent: -60, filter: 'blur(24px)', autoAlpha: 0, duration: 0.28, ease: 'expo.in' }, at - 0.28)
        .fromTo($(b), { xPercent: 60, filter: 'blur(24px)', autoAlpha: 0 }, { xPercent: 0, filter: 'blur(0px)', autoAlpha: 1, duration: 0.42, ease: 'expo.out' }, at);
      return tl;
    },
    /* Giant-type wipe: one huge word sweeps across the frame as the cut, so the
       next scene is revealed behind it. el is a full-frame div holding the word in
       display type at 2 to 3 times the frame height, overflow hidden on the stage. */
    giantWipe: function (tl, el, word, at, opts) {
      el = $(el); opts = opts || {};
      el.textContent = word;
      tl.set(el, { autoAlpha: 1, xPercent: 110, filter: 'blur(0px)' }, at - 0.01)
        .to(el, { keyframes: [{ xPercent: 0, filter: 'blur(14px)', duration: 0.28, ease: 'expo.in' }, { xPercent: -120, filter: 'blur(0px)', duration: 0.5, ease: 'expo.out' }] }, at)
        .set(el, { autoAlpha: 0 }, at + 0.8);
      return tl;
    },

    /* Stamp: a badge slams in rotated, overshoots and settles, like an ink stamp. */
    stamp: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      tl.fromTo(el, { autoAlpha: 0, scale: 2.2, rotation: (opts.rotation || -8) - 6 },
        { autoAlpha: 1, scale: 1, rotation: opts.rotation || -8, duration: 0.45, ease: ease.playful, transformOrigin: '50% 50%' }, at);
      return tl;
    },

    /* Field flip: the full-frame background cuts to a new colour on a hit. el is the stage background. */
    field: function (tl, el, color, at) {
      tl.set($(el), { backgroundColor: color }, at);
      return tl;
    },

    /* Light leak: a warm glow that sweeps across the frame over a cut. el is a
       full-frame div above the scenes with mix-blend-mode: screen. */
    leak: function (tl, el, at, color) {
      el = $(el); color = color || 'rgba(255,140,60,0.85)';
      tl.set(el, { background: 'radial-gradient(60% 50% at 50% 50%, ' + color + ', rgba(0,0,0,0) 70%)', autoAlpha: 0, xPercent: -70 }, 0)
        .to(el, { keyframes: [{ autoAlpha: 1, xPercent: -10, duration: 0.35, ease: 'power2.out' }, { autoAlpha: 0, xPercent: 60, duration: 0.5, ease: 'power2.in' }] }, at - 0.35);
      return tl;
    },

    /* ---- Reference moves. Each one is named after the move a reference study tags. ---- */

    /* words: a headline builds word by word on the voice. els: the word spans
       (a selector or a list), times: when each word is said (narrationTiming). */
    words: function (tl, els, times, opts) {
      opts = opts || {};
      all(els).forEach(function (w, i) {
        if (times[i] == null) return;
        tl.fromTo(w, { autoAlpha: 0, y: opts.rise == null ? '0.32em' : opts.rise, filter: 'blur(6px)' },
          { autoAlpha: 1, y: 0, filter: 'blur(0px)', duration: 0.32, ease: ease.snappy }, times[i] - (opts.lead == null ? 0.05 : opts.lead));
      });
      return tl;
    },

    /* writeOn: an emphasis word (script or italic) is written on left to right, then
       underlined. Its last letter lands on `at` + duration; start it early enough that
       the word is complete as it is said. opts.underline: the underline element. */
    writeOn: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var d = opts.duration || 0.55;
      // Visibility is its own set: a value only in fromTo's start state is not restored after a backward seek.
      tl.fromTo(el, { clipPath: 'inset(-30% 100% -30% -8%)' }, { clipPath: 'inset(-30% -8% -30% -8%)', duration: d, ease: 'power1.inOut' }, at)
        .set(el, { autoAlpha: 1 }, at);
      if (opts.underline) tl.fromTo($(opts.underline), { scaleX: 0, transformOrigin: '0% 50%' }, { scaleX: 1, duration: 0.35, ease: 'power3.out' }, at + d * 0.75)
        .set($(opts.underline), { autoAlpha: 1 }, at + d * 0.75);
      return tl;
    },

    /* iris: the next scene opens as a circle growing from an element (the dot of a
       question mark, a button), with echo rings running ahead of its edge. b is the
       incoming full-frame scene; opts.from the element it grows from. Positions are
       measured when the move starts, so elements moved earlier in the timeline are found. */
    iris: function (tl, b, at, opts) {
      b = $(b); opts = opts || {};
      var stage = stageOf(opts), d = opts.duration || 0.5, g = null;
      var geo = function () {
        if (g) return g;
        var W = stage.offsetWidth, H = stage.offsetHeight, c = opts.from ? centre(opts.from, stage) : { x: W / 2, y: H / 2 };
        g = { c: c, R: Math.ceil(Math.max(Math.hypot(c.x, c.y), Math.hypot(W - c.x, c.y), Math.hypot(c.x, H - c.y), Math.hypot(W - c.x, H - c.y))) + 4 };
        return g;
      };
      var circle = function (r) { return function () { var q = geo(); return 'circle(' + (r === 'R' ? q.R : 0) + 'px at ' + q.c.x + 'px ' + q.c.y + 'px)'; }; };
      tl.set(b, { autoAlpha: 1 }, at)
        .fromTo(b, { clipPath: circle(0) }, { clipPath: circle('R'), duration: d, ease: 'power2.in', immediateRender: false }, at)
        .set(b, { clipPath: 'none' }, at + d);
      var rings = opts.rings == null ? 2 : opts.rings;
      for (var i = 0; i < rings; i++) {
        var ring = document.createElement('div');
        ring.setAttribute('data-wm', 'iris-ring');
        ring.style.cssText = 'position:absolute;left:0;top:0;width:100px;height:100px;border-radius:50%;pointer-events:none;z-index:' + (opts.z || 50) +
          ';border:' + (opts.ringWidth || 3) + 'px solid ' + (opts.ringColor || 'rgba(255,255,255,.7)') + ';visibility:hidden';
        stage.appendChild(ring);
        var t = at + i * 0.06;
        // Echoes are faint and quick: they hint at the edge, they are not the move.
        tl.set(ring, { left: function () { return geo().c.x - 50; }, top: function () { return geo().c.y - 50; } }, t)
          .fromTo(ring, { autoAlpha: 0.5 - i * 0.14, scale: 0.05 }, { scale: (function (k) { return function () { return geo().R / 50 * (0.55 + k * 0.12); }; })(i), autoAlpha: 0, duration: d * 0.75, ease: 'power2.out', immediateRender: false }, t)
          .set(ring, { autoAlpha: 0 }, t + d * 0.75);
      }
      return tl;
    },

    /* toss: a card is thrown in spinning and settles upright with a little overshoot.
       opts.from: 'right' (default), 'left', 'top' or 'bottom'; opts.rotation: the angle it lands at. */
    toss: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var dx = { right: 1, left: -1, top: 0, bottom: 0 }[opts.from || 'right'], dy = { right: -0.25, left: -0.25, top: -1, bottom: 1 }[opts.from || 'right'];
      var dist = opts.distance || 900, land = opts.rotation || 0;
      tl.fromTo(el, { autoAlpha: 0, x: dx * dist, y: dy * dist, rotation: land + 200 * (dx || 1), rotationY: 70, scale: 0.7, transformPerspective: 1200, filter: 'blur(10px)' },
        { autoAlpha: 1, x: 0, y: 0, rotation: land, rotationY: 0, scale: 1, filter: 'blur(0px)', duration: opts.duration || 0.7, ease: ease['default'] }, at);
      return tl;
    },

    /* pop: something appears from a point with a bounce (speech bubbles, tiles,
       chips). opts.origin: the transform origin, e.g. '0% 100%' for a bubble tail. */
    pop: function (tl, el, at, opts) {
      opts = opts || {};
      tl.fromTo($(el), { autoAlpha: 0, scale: opts.from == null ? 0.4 : opts.from, transformOrigin: opts.origin || '50% 50%' },
        { autoAlpha: 1, scale: 1, duration: opts.duration || 0.5, ease: ease.playful }, at);
      return tl;
    },

    /* device: a full-bleed panel shrinks into a device screen, carrying what is on
       it. The panel is position:absolute; opts.to is the screen box in stage pixels
       {left, top, width, height, radius}; opts.content (a full-frame layer inside the
       panel) shrinks with it so nothing vanishes mid-move; opts.chrome (bezel, notch,
       status bar) fades in as it lands. */
    device: function (tl, panel, at, opts) {
      panel = $(panel); opts = opts || {};
      var to = opts.to, d = opts.duration || 0.5;
      tl.to(panel, { left: to.left, top: to.top, width: to.width, height: to.height, borderRadius: to.radius == null ? 48 : to.radius, duration: d, ease: 'expo.inOut' }, at);
      if (opts.content) {
        // The panel's starting size is its layout when the timeline is built (full-bleed).
        var c = $(opts.content), k = to.width / panel.offsetWidth;
        tl.to(c, { scale: k, y: (to.height - c.offsetHeight * k) / 2, transformOrigin: '0px 0px', duration: d, ease: 'expo.inOut' }, at);
      }
      if (opts.chrome) tl.fromTo($(opts.chrome), { autoAlpha: 0, scale: 1.04 }, { autoAlpha: 1, scale: 1, duration: 0.3, ease: 'power2.out' }, at + d * 0.7);
      return tl;
    },

    /* through: push into an element until it fills the frame, then come out of
       another element in the next scene: a match cut on shape (a black button
       becoming another black button). a/from: outgoing scene and the element pushed
       into; b/to: incoming scene and the element it opens from. Both scenes are
       full-frame at the stage's top left. Fastest at `at`. */
    through: function (tl, opts) {
      var a = $(opts.a), b = $(opts.b), stage = stageOf(opts);
      var into = function (el) {
        var memo = null;
        return function (k) {
          if (!memo) {
            var W = stage.offsetWidth, H = stage.offsetHeight, r = rect(el, stage), s = Math.max(W / r.width, H / r.height) * 1.12;
            memo = { x: W / 2 - s * (r.left + r.width / 2), y: H / 2 - s * (r.top + r.height / 2), scale: s };
          }
          return memo[k];
        };
      };
      // hold: how long the incoming element stays full-frame before the pull back, so the swap reads.
      var at = opts.at, inD = opts.inDuration || 0.32, outD = opts.outDuration || 0.5, hold = opts.hold == null ? 0.16 : opts.hold, blur = blurFilter(stage, opts.blur == null ? 28 : opts.blur);
      var full = into($(opts.from)), back = into($(opts.to));
      tl.set(a, { transformOrigin: '0px 0px', filter: 'url(#' + blur.id + ')' }, at - inD)
        .to(a, { x: function () { return full('x'); }, y: function () { return full('y'); }, scale: function () { return full('scale'); }, duration: inD, ease: 'expo.in' }, at - inD)
        .fromTo(blur.node, { attr: { stdDeviation: '0 0' } }, { attr: { stdDeviation: blur.max + ' 0' }, duration: inD, ease: 'expo.in', immediateRender: false }, at - inD)
        .set(a, { autoAlpha: 0 }, at)
        // The incoming element is full-frame from the cut, holds, then pulls back.
        .set(b, { autoAlpha: 1, x: function () { return back('x'); }, y: function () { return back('y'); }, scale: function () { return back('scale'); }, transformOrigin: '0px 0px', filter: 'url(#' + blur.id + ')' }, at)
        .to(b, { x: 0, y: 0, scale: 1, duration: outD, ease: 'expo.inOut' }, at + hold)
        .fromTo(blur.node, { attr: { stdDeviation: blur.max + ' 0' } }, { attr: { stdDeviation: '0 0' }, duration: 0.12, ease: 'power2.out', immediateRender: false }, at)
        .fromTo(blur.node, { attr: { stdDeviation: '0 0' } }, { attr: { stdDeviation: blur.max * 0.5 + ' 0' }, duration: outD * 0.4, ease: 'power2.in', immediateRender: false }, at + hold)
        .to(blur.node, { attr: { stdDeviation: '0 0' }, duration: outD * 0.6, ease: 'power2.out' }, at + hold + outD * 0.4)
        .set([a, b], { filter: 'none' }, at + hold + outD);
      return tl;
    },

    /* layout: reshape a full-frame clip (a UGC take, a generated shot) into a region of the frame and back, while it
       keeps playing: 'full', 'top', 'bottom', 'left', 'right', 'pip' (a corner card), or {x, y, w, h} in stage px.
       The clip is scaled to cover the region and cropped to it (transforms and clip-path only, never left/top), so
       a take can go full screen -> split -> full without a cut. Give the clip full-frame size and object-fit: cover.
       opts: duration (0 = a hard switch; default .55), focus {x, y} (0..1, the point kept in view, default the
       upper-middle where a face is), radius (px, for 'pip'), pip ('br' | 'bl' | 'tr' | 'tl'), stage. */
    layout: function (tl, el, at, region, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight;
      var r = region, pad = Math.round(Math.min(W, H) * 0.04);
      if (typeof r === 'string') {
        var pw = Math.round(W * 0.34), ph = Math.round(pw * H / W), corner = opts.pip || 'br';
        r = { full: { x: 0, y: 0, w: W, h: H }, top: { x: 0, y: 0, w: W, h: H / 2 }, bottom: { x: 0, y: H / 2, w: W, h: H / 2 },
          left: { x: 0, y: 0, w: W / 2, h: H }, right: { x: W / 2, y: 0, w: W / 2, h: H },
          pip: { x: corner.indexOf('l') >= 0 ? pad : W - pw - pad, y: corner.indexOf('t') === 0 ? pad : H - ph - pad, w: pw, h: ph } }[r];
        if (!r) throw new Error('WM.layout: region must be full, top, bottom, left, right, pip or {x, y, w, h}');
      }
      var f = opts.focus || { x: 0.5, y: 0.4 }, s = Math.max(r.w / W, r.h / H);
      // Keep the focus point as central in the region as covering allows.
      var tx = r.x + r.w / 2 - s * W * f.x, ty = r.y + r.h / 2 - s * H * f.y;
      tx = Math.min(r.x, Math.max(r.x + r.w - s * W, tx)); ty = Math.min(r.y, Math.max(r.y + r.h - s * H, ty));
      var lx = (r.x - tx) / s, ly = (r.y - ty) / s, lr = (r.x + r.w - tx) / s, lb = (r.y + r.h - ty) / s;
      var rad = (opts.radius == null ? (region === 'pip' ? 28 : 0) : opts.radius) / s;
      var clip = 'inset(' + ly.toFixed(2) + 'px ' + (W - lr).toFixed(2) + 'px ' + (H - lb).toFixed(2) + 'px ' + lx.toFixed(2) + 'px round ' + rad.toFixed(2) + 'px)';
      var to = { x: tx, y: ty, scale: s, clipPath: clip, transformOrigin: '0 0' };
      var d = opts.duration == null ? 0.55 : opts.duration;
      if (d <= 0) tl.set(el, to, at); else tl.to(el, Object.assign(to, { duration: d, ease: ease['default'] }), at);
      return tl;
    },

    /* pinToClip: place an element (the real app screen, built in HTML) onto a device screen inside a generated clip,
       in perspective, so the UI sits in the drawn or filmed world. quad: the screen's corners in the clip's own pixels
       [top-left, top-right, bottom-right, bottom-left] from the media op "screen"; natural: {width, height} of the clip.
       The clip is assumed to fill its element with object-fit: cover and not to move (the op says "stable"). The pinned
       element keeps its own size; it is mapped once, at build time. */
    pinToClip: function (el, clip, quad, natural, opts) {
      el = $(el); clip = $(clip); opts = opts || {};
      var stage = stageOf(opts), box = rect(clip, stage), w = el.offsetWidth, h = el.offsetHeight;
      var s = Math.max(box.width / natural.width, box.height / natural.height);
      var ox = box.left + (box.width - natural.width * s) / 2, oy = box.top + (box.height - natural.height * s) / 2;
      var q = quad.map(function (p) { return [p[0] * s + ox, p[1] * s + oy]; });
      el.style.position = 'absolute'; el.style.left = '0px'; el.style.top = '0px'; el.style.transformOrigin = '0 0';
      el.style.transform = WM.quadMatrix(w, h, q);
      return el;
    },

    /* quadMatrix: the CSS matrix3d that maps a w x h box onto the four corners q (tl, tr, br, bl). */
    quadMatrix: function (w, h, q) {
      var x0 = q[0][0], y0 = q[0][1], x1 = q[1][0], y1 = q[1][1], x2 = q[2][0], y2 = q[2][1], x3 = q[3][0], y3 = q[3][1];
      var dx1 = x1 - x2, dx2 = x3 - x2, dx3 = x0 - x1 + x2 - x3, dy1 = y1 - y2, dy2 = y3 - y2, dy3 = y0 - y1 + y2 - y3;
      var det = dx1 * dy2 - dx2 * dy1, a13 = (dx3 * dy2 - dx2 * dy3) / det, a23 = (dx1 * dy3 - dx3 * dy1) / det;
      var a11 = x1 - x0 + a13 * x1, a21 = x3 - x0 + a23 * x3, a12 = y1 - y0 + a13 * y1, a22 = y3 - y0 + a23 * y3;
      var m = [a11 / w, a12 / w, 0, a13 / w, a21 / h, a22 / h, 0, a23 / h, 0, 0, 1, 0, x0, y0, 0, 1];
      return 'matrix3d(' + m.map(function (v) { return +v.toFixed(8); }).join(',') + ')';
    },

    /* fly: a chip leaves its place and arcs into a target (a "+$19" chip into the
       checkout total), shrinking as it lands. Pair it with WM.count on the target's
       number at at + duration. opts.lift: how high the arc rises in pixels. */
    fly: function (tl, chip, target, at, opts) {
      chip = $(chip); target = $(target); opts = opts || {};
      var stage = stageOf(opts), d = opts.duration || 0.6, lift = opts.lift == null ? 110 : opts.lift, memo = null;
      var v = function (k) {
        if (!memo) { var a = centre(chip, stage), z = centre(target, stage); memo = { dx: z.x - a.x, dy: z.y - a.y }; }
        return { mx: memo.dx * 0.5, my: Math.min(0, memo.dy) * 0.5 - lift, dx: memo.dx, dy: memo.dy }[k];
      };
      tl.to(chip, { x: function () { return v('mx'); }, y: function () { return v('my'); }, scale: 1.08, duration: d * 0.5, ease: 'sine.out' }, at)
        .to(chip, { x: function () { return v('dx'); }, y: function () { return v('dy'); }, scale: 0.5, duration: d * 0.5, ease: 'sine.in' }, at + d * 0.5)
        .to(chip, { autoAlpha: 0, duration: 0.12 }, at + d - 0.08);
      if (opts.bump !== false) tl.fromTo(target, { scale: 1 }, { scale: 1.06, duration: 0.12, ease: 'power2.out', yoyo: true, repeat: 1, immediateRender: false }, at + d - 0.04);
      return tl;
    },
  };

  // Elements and geometry. Positions come from layout (offsets), not from the
  // rendered box, so they are the same wherever the playhead is when the timeline is built.
  function all(els) { return typeof els === 'string' ? Array.prototype.slice.call(document.querySelectorAll(els)) : Array.prototype.slice.call(els); }
  function stageOf(opts) { return $(opts && opts.stage) || document.querySelector('[data-composition-id]') || document.body; }
  function rect(el, stage) {
    el = $(el); var left = 0, top = 0, n = el;
    while (n && n !== stage) { left += n.offsetLeft; top += n.offsetTop; n = n.offsetParent; if (n && n !== stage && !stage.contains(n)) break; }
    return { left: left, top: top, width: el.offsetWidth, height: el.offsetHeight };
  }
  function centre(el, stage) { var r = rect(el, stage); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }
  // A horizontal motion blur: an SVG filter blurring along x only, tweened by attribute.
  var blurs = 0;
  function blurFilter(stage, max) {
    var id = 'wm-hblur-' + (++blurs), ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg'); svg.setAttribute('width', '0'); svg.setAttribute('height', '0'); svg.style.position = 'absolute';
    var f = document.createElementNS(ns, 'filter'); f.setAttribute('id', id); f.setAttribute('x', '-20%'); f.setAttribute('width', '140%');
    var g = document.createElementNS(ns, 'feGaussianBlur'); g.setAttribute('stdDeviation', '0 0');
    f.appendChild(g); svg.appendChild(f); stage.appendChild(svg);
    return { id: id, node: g, max: max };
  }
  window.WM = WM;
})();
