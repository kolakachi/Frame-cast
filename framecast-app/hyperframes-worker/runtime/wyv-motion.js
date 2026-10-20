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

  // Type that moves must stay type (measured by snapcn, MIT: github.com/shadcn-labs/snapcn). Hinting re-snaps each
  // glyph's stems to whole pixels as its size changes, so scaled letters boil (3.4% shape drift; 0.2% without it), and
  // at a device scale of 1 glyphs also snap vertically. geometricPrecision turns both off.
  if (document.createElement) {
    var textStyle = document.createElement('style');
    textStyle.textContent = 'html{text-rendering:geometricPrecision}';
    var into = document.head || document.documentElement;
    if (into) into.appendChild(textStyle);
  }
  // GSAP's default promotes a moving element to a compositor layer (translate3d) and resamples it as a bitmap: type
  // turns into a rescaled texture and shimmers in a frame-by-frame render. Draw every frame as real type instead.
  if (window.gsap.config) window.gsap.config({ force3D: false });
  // Seeded randomness (mulberry32): the same seed gives the same torn edge, tilt or confetti on every render.
  var gradeCount = 0;
  function seeded(seed) { var a = (seed | 0) || 1; return function () { a = (a + 0x6D2B79F5) | 0; var t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }

  var WM = {
    ease: ease,

    /* baseline: pivots each element's scale on its measured text baseline. A scale that moves the baseline makes the
       type climb the pixel grid in whole-pixel jumps (stuck, then jump); pivoting on it measured 0.014 px judder
       against 0.284 px from the centre. Call it before tweening scale on a headline; remeasures once fonts load. */
    baseline: function (els) {
      (els && els.nodeType ? [els] : all(els)).forEach(function (el) {
        var mark = document.createElement('span');
        mark.style.cssText = 'display:inline-block;width:0;height:0';
        el.appendChild(mark);
        var measure = function () {
          var keep = el.style.transform; el.style.transform = 'none';
          el.style.transformOrigin = '50% ' + (mark.getBoundingClientRect().top - el.getBoundingClientRect().top) + 'px';
          el.style.transform = keep;
        };
        measure();
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
      });
    },

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
      // The narrowest box it becomes, for the text fit (WM.fitFields measures at rest, before the morph: a web address
      // that fit the 440 px card overflowed the 400 px pill it became, SWAP-SAFE canary 2026-10-20).
      var widths = states.filter(function (s) { return typeof s.width === 'number'; });
      if (el && widths.length) {
        var narrow = widths.reduce(function (a, b) { return b.width < a.width ? b : a; });
        el.setAttribute('data-wyv-fit-w', narrow.width);
        if (typeof narrow.height === 'number') el.setAttribute('data-wyv-fit-h', narrow.height);
        if (typeof narrow.borderRadius === 'number') el.setAttribute('data-wyv-fit-r', narrow.borderRadius);
      }
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
      // Bigger than the frame on purpose: never fitted (WM.fitFields).
      if (el.setAttribute) el.setAttribute('data-wyv-nofit', '');
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

    /* camera: a Screen-Studio-style zoom to an element and back. content is the layer that moves (a full-frame
       wrapper of the scene); target the element to frame. opts: scale (default: fit the target to ~60% of the
       frame, at most 2.4), duration (default .6 in and out), hold (seconds zoomed, default 1.2), focus {x, y} (0..1
       inside the target, default the centre). Transforms only, measured once when the timeline is built. */
    camera: function (tl, content, target, at, opts) {
      content = $(content); target = $(target); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight, r = rect(target, stage);
      var s = opts.scale || Math.min(2.4, Math.max(1.15, Math.min(0.6 * W / r.width, 0.6 * H / r.height)));
      var f = opts.focus || { x: 0.5, y: 0.5 }, cx = r.left + r.width * f.x, cy = r.top + r.height * f.y;
      var x = Math.min(0, Math.max(W - W * s, W / 2 - s * cx)), y = Math.min(0, Math.max(H - H * s, H / 2 - s * cy));
      var d = opts.duration == null ? 0.6 : opts.duration, hold = opts.hold == null ? 1.2 : opts.hold;
      tl.set(content, { transformOrigin: '0 0' }, 0)
        .to(content, { x: x, y: y, scale: s, duration: d, ease: ease.heavy }, at)
        .to(content, { x: 0, y: 0, scale: 1, duration: d, ease: ease.heavy }, at + d + hold);
      return tl;
    },

    /* Documents (pages and slides the user chose to show as they look). Never redraw a page: show the real image.

       book: pages that turn like paper. el is the book: a positioned box; its children .wm-page (each a div holding
       the page's <img>) are the pages in order. One page at a time (default): the box is one page and each turn
       carries it over the spine (its left edge). spread: true: the box is an open book two pages wide, page 1 on the
       left and 2 on the right; each turn carries the right page over, its back being the next left page, and shows
       the page after it on the right. A turning page bends (the corner lifts first, the edge leads, it flattens as it
       lands with the edge trailing), the light moves across it and it casts a shadow on the page beneath.
       turns: the times of the turns, in order (fewer than the pages is fine). opts: duration (1.1), bend (degrees of
       curl, 45), strips (18), sound (page). The pages are rebuilt into strips once, when the timeline is
       built; every frame is a pure function of the time, so seeking is exact. */
    book: function (tl, el, turns, opts) {
      el = $(el); opts = opts || {};
      var pages = Array.prototype.slice.call(el.querySelectorAll(':scope > .wm-page'));
      var d = opts.duration || 1.1, N = Math.max(4, opts.strips || 18), B = opts.bend == null ? 45 : opts.bend;
      var spread = !!opts.spread, half = spread ? 50 : 100;
      var srcOf = function (page) { var img = page && page.querySelector('img'); return img ? img.getAttribute('src') : ''; };
      el.style.perspective = (opts.perspective || 3600) + 'px';
      el.style.perspectiveOrigin = (spread ? '50%' : '0%') + ' 40%';
      el.style.transformStyle = 'preserve-3d';
      var box = function (left, z) {
        var b = document.createElement('div');
        b.style.cssText = 'position:absolute;top:0;bottom:0;width:' + half + '%;left:' + (left ? 0 : 100 - half) + '%;transform-style:preserve-3d;z-index:' + z;
        el.appendChild(b); return b;
      };
      var flat = function (page, left, z) { var b = box(left, z); page.style.position = 'absolute'; page.style.inset = '0'; page.style.overflow = 'hidden'; b.appendChild(page); return b; };
      // One turning leaf: a chain of strips hinged to each other, each with a slice of the front and of the back.
      var leafOf = function (front, back, z) {
        var holder = box(spread ? false : true, z), chain = [], parent = holder;
        var fs = srcOf(front), bs = srcOf(back);
        for (var i = 0; i < N; i++) {
          var st = document.createElement('div');
          st.style.cssText = 'position:absolute;top:0;height:100%;width:' + (i === 0 ? 100 / N + '%' : '100%') + ';left:' + (i === 0 ? '0' : '100%') + ';transform-style:preserve-3d;transform-origin:0% 50%';
          var face = function (src, col, isBack) {
            var f = document.createElement('div');
            f.style.cssText = 'position:absolute;inset:0;backface-visibility:hidden;-webkit-backface-visibility:hidden;background:#fff' + (src ? ' url("' + src + '")' : '') +
              ';background-size:' + (N * 100) + '% 100%;background-position:' + (col / (N - 1) * 100) + '% 0' +
              // A hair wider than its strip, so neighbouring strips overlap and no seam shows.
              ';transform:' + (isBack ? 'rotateY(180deg) ' : '') + 'scaleX(1.03)';
            var shade = document.createElement('div');
            shade.style.cssText = 'position:absolute;inset:0;pointer-events:none;background:#000;opacity:0';
            f.appendChild(shade); st.appendChild(f); return shade;
          };
          var sf = face(fs, i, false), sb = back ? face(bs, N - 1 - i, true) : null;
          parent.appendChild(st); chain.push({ el: st, front: sf, back: sb }); parent = st;
        }
        front.style.display = 'none'; if (back) back.style.display = 'none';
        return { holder: holder, chain: chain };
      };
      var leaves = [], i;
      if (spread) {
        if (pages[0]) flat(pages[0], true, 1);
        for (i = 1; i < pages.length; i += 2) {
          if (i + 2 < pages.length || pages[i + 1]) leaves.push(leafOf(pages[i], pages[i + 1], 500 - i));
          else flat(pages[i], false, 500 - i);
          if (pages[i + 2] && i + 2 === pages.length - 1) { flat(pages[i + 2], false, 1); break; }
        }
      } else {
        for (i = 0; i < pages.length - 1; i++) leaves.push(leafOf(pages[i], null, 500 - i));
        if (pages.length) flat(pages[pages.length - 1], true, 1);
      }
      // The shadow a turning page throws on the page beneath it, from the spine outwards.
      var cast = document.createElement('div');
      cast.style.cssText = 'position:absolute;top:0;bottom:0;width:' + half + '%;left:' + (spread ? 50 : 0) + '%;pointer-events:none;opacity:0;z-index:450;' +
        'background:linear-gradient(90deg,rgba(0,0,0,.42),rgba(0,0,0,.12) 35%,rgba(0,0,0,0) 70%)';
      el.appendChild(cast);
      var ease = function (t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
      // The page at progress p (0..1): the hinge turns 0 to 180 with a small settle past it; the curl peaks mid-turn
      // and leads with the outer edge; the corner lifts a little first. Returns each strip's own angle and shading.
      var pose = function (p, withBack) {
        var lift = Math.sin(Math.min(1, p / 0.12) * Math.PI / 2), q = Math.max(0, (p - 0.08) / 0.92);
        var turn = p >= 1 ? 180 : 180 * ease(q);
        // The free edge leads as the page rises and trails as it falls, so it lands last and never passes the spine.
        var curl = p <= 0 || p >= 1 ? 0 : Math.sin(2 * Math.PI * q) * B + (1 - Math.min(1, q / 0.2)) * 18 * lift;
        var rot = [], front = [], back = [], prev = 0;
        for (var k = 0; k < N; k++) {
          var cum = p <= 0 ? 0 : Math.min(179.5, Math.max(0, turn + curl * Math.pow(k / (N - 1), 1.6))), own = cum - prev, a = cum * Math.PI / 180;
          if (p >= 1) { cum = 180; own = k === 0 ? 180 : 0; }
          prev = cum; rot.push(-own);
          // Light from the front: a face turning away from the viewer darkens.
          front.push(p <= 0 || p >= 1 ? 0 : (1 - Math.max(0, Math.cos(a))) * 0.45);
          back.push(!withBack || p <= 0 || p >= 1 ? 0 : (1 + Math.cos(a)) * 0.35);
        }
        return { rot: rot, front: front, back: back, cast: Math.sin(Math.min(1, Math.max(0, p)) * Math.PI) * 0.85 };
      };
      // Precomputed keyframes (no callbacks), so any frame seeks exactly.
      var S = 36;
      (turns || []).slice(0, leaves.length).forEach(function (at, j) {
        var lf = leaves[j], poses = [];
        for (var s2 = 0; s2 <= S; s2++) poses.push(pose(s2 / S, true));
        tl.set(lf.holder, { zIndex: 600 + j }, at);
        lf.chain.forEach(function (c, k) {
          tl.to(c.el, { keyframes: poses.slice(1).map(function (ps) { return { rotationY: ps.rot[k], duration: d / S, ease: 'none' }; }) }, at);
          tl.to(c.front, { keyframes: poses.slice(1).map(function (ps) { return { opacity: ps.front[k], duration: d / S, ease: 'none' }; }) }, at);
          if (c.back) tl.to(c.back, { keyframes: poses.slice(1).map(function (ps) { return { opacity: ps.back[k], duration: d / S, ease: 'none' }; }) }, at);
        });
        tl.to(cast, { keyframes: poses.slice(1).map(function (ps) { return { opacity: ps.cast, duration: d / S, ease: 'none' }; }) }, at);
      });
      leaves.forEach(function (lf) { lf.chain.forEach(function (c) { gsap.set(c.el, { rotationY: 0, transformOrigin: '0% 50%' }); }); });
      return tl;
    },

    /* Collage (a zine or sticker ad: torn paper, cut-outs, rapid cards). Everything is seeded, so it renders the same.

       tear: torn-paper strips sweep across the frame as the cut. el is an empty full-frame layer above the scenes;
       switch the scene underneath at the returned time (the frame is covered then). opts: colors (one per strip,
       default ['#e8261a']), strips (1-3), from ('left', 'right', 'top', 'bottom'), duration (.7), seed. */
    tear: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var cols = opts.colors || [opts.color || '#e8261a'], n = Math.max(1, Math.min(3, opts.strips || cols.length)), d = opts.duration || 0.7;
      var from = opts.from || 'left', horiz = from === 'left' || from === 'right', sign = from === 'left' || from === 'top' ? -1 : 1, rnd = seeded(opts.seed || 7);
      var edge = function (lead) {
        // A torn edge: many small jags along the strip's leading and trailing sides.
        var pts = [], k, steps = 26;
        for (k = 0; k <= steps; k++) pts.push([k / steps * 100, (lead ? 0 : 100) + (rnd() * 3.2 + 0.4) * (lead ? 1 : -1)]);
        return pts;
      };
      el.style.pointerEvents = 'none'; el.style.overflow = 'hidden';
      var cover = at + d * 0.5;
      for (var i = 0; i < n; i++) {
        var strip = document.createElement('div'), fiber = document.createElement('div');
        var a = edge(true), b = edge(false).reverse();
        // Moving sideways, the torn edges are the strip's left and right ends; moving up or down, its top and bottom.
        var poly = a.concat(b).map(function (p) { return horiz ? p[1] + '% ' + p[0] + '%' : p[0] + '% ' + p[1] + '%'; });
        var size = 100 / n + 8, offset = i * (100 / n) - 4;
        strip.style.cssText = 'position:absolute;' + (horiz ? 'top:' + offset + '%;height:' + size + '%;left:-10%;width:130%' : 'left:' + offset + '%;width:' + size + '%;top:-10%;height:130%') +
          ';background:' + cols[i % cols.length] + ';clip-path:polygon(' + poly.join(',') + ');box-shadow:inset 0 0 40px rgba(0,0,0,.12)';
        // The white paper fibre showing along the torn edge.
        fiber.style.cssText = strip.style.cssText.replace(/background:[^;]+/, 'background:#f6f1e7');
        el.appendChild(fiber); el.appendChild(strip);
        var stagger = i * 0.06, prop = horiz ? 'xPercent' : 'yPercent', far = 140 * sign;
        [fiber, strip].forEach(function (x) {
          var start = {}; start[prop] = far;
          // The fibre is a touch longer, so a white torn edge shows past the colour.
          if (x === fiber) start[horiz ? 'scaleX' : 'scaleY'] = 1.025;
          tl.set(x, start, 0)
            .to(x, (function () { var v = {}; v[prop] = 0; v.duration = d * 0.45; v.ease = 'power3.out'; return v; })(), at + stagger)
            .to(x, (function () { var v = {}; v[prop] = -far; v.duration = d * 0.45; v.ease = 'power3.in'; return v; })(), cover + 0.08 + stagger);
        });
      }
      return cover;
    },

    /* sticker: a cut-out (a transparent PNG, or a block of text) gets a white die-cut border and a shadow, and slaps
       on with a tilt and a small bounce. opts: rotate (final tilt, -6), border (px, 6), from (start scale, .2). The
       border is a stack of drop-shadows, set once (never tweened). */
    sticker: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var b = opts.border == null ? 6 : opts.border, r = opts.rotate == null ? -6 : opts.rotate;
      el.style.filter = ['drop-shadow(' + b + 'px 0 0 #fff)', 'drop-shadow(-' + b + 'px 0 0 #fff)', 'drop-shadow(0 ' + b + 'px 0 #fff)', 'drop-shadow(0 -' + b + 'px 0 #fff)', 'drop-shadow(0 10px 14px rgba(0,0,0,.28))'].join(' ');
      tl.fromTo(el, { autoAlpha: 0, scale: opts.from || 0.2, rotation: r + 18 }, { autoAlpha: 1, scale: 1, rotation: r, duration: 0.45, ease: ease.playful, immediateRender: false }, at);
      tl.set(el, { autoAlpha: 0, scale: opts.from || 0.2, rotation: r + 18 }, 0);
      return tl;
    },

    /* marquee: a strip of text that scrolls at a constant speed and loops with no seam (the neo-brutalism proof showed
       "LAUNCH WEUNCH WEEK" where a hand-built strip ran out and overlapped, 2026-10-16). The strip gets two identical
       halves, each repeating the text enough to fill it, and moves by exactly half its own width per loop: measured in
       percent, so a web font that loads late can't open a gap. el: the strip (overflow hidden; its text is the unit).
       opts: text (else the element's text), gap (separator, ' ✦ '), loop (seconds per pass, 4), dir ('left' or 'right'),
       duration (seconds it runs: its slot), repeat (copies per half; from the strip's width and the text's length). */
    marquee: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var unit = String(opts.text || el.textContent || '').replace(/\s*✦\s*$/, '').trim(), gap = opts.gap == null ? ' ✦ ' : opts.gap;
      var size = parseFloat(getComputedStyle(el).fontSize) || 40, box = el.offsetWidth || 1080;
      var per = opts.repeat || Math.max(2, Math.ceil(box / Math.max(1, (unit.length + gap.length) * size * 0.45)) + 1);
      var half = new Array(per + 1).join(unit + gap);
      el.textContent = '';
      var track = document.createElement('div');
      track.className = 'wm-marquee-track';
      track.style.cssText = 'display:inline-block;white-space:pre;will-change:transform';
      track.textContent = half + half;
      el.appendChild(track);
      var loop = opts.loop || 4, dur = opts.duration || loop, left = opts.dir !== 'right';
      var wrap = function (v) { v = parseFloat(v) % 50; return v > 0 ? v - 50 : v; };
      tl.fromTo(track, { xPercent: left ? 0 : -50 }, { xPercent: (left ? -50 : 50) * dur / loop + (left ? 0 : -50), duration: dur, ease: 'none',
        modifiers: { xPercent: wrap }, immediateRender: true }, at);
      return at + dur;
    },

    /* cards: a rapid montage, each card shown for a few frames, hard cuts, a little tilt and a slam. el holds the
       cards (.wm-card, stacked). opts: every (frames per card, 2), fps (24), tilt (degrees, 0 for none), seed. */
    cards: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var list = Array.prototype.slice.call(el.querySelectorAll(':scope > .wm-card')), step = (opts.every || 2) / (opts.fps || 24), rnd = seeded(opts.seed || 11);
      list.forEach(function (c, k) {
        var t = at + k * step, tilt = opts.tilt ? (rnd() * 2 - 1) * opts.tilt : 0;
        tl.set(c, { autoAlpha: 0 }, 0).set(c, { autoAlpha: 1, rotation: tilt, scale: 1.04 }, t).to(c, { scale: 1, duration: step, ease: 'power2.out' }, t);
        if (k < list.length - 1) tl.set(c, { autoAlpha: 0 }, t + step);
      });
      return at + list.length * step;
    },

    /* grade: a printed look on a picture, set once (not animated): silver (bright greyscale), ink (hard black and
       white), duotone ({dark, light} colours), halftone (dots; size px). Uses an SVG filter, no blend modes. */
    grade: function (el, kind, opts) {
      el = $(el); opts = opts || {};
      var id = 'wm-grade-' + (++gradeCount) + '-' + kind, ns = 'http://www.w3.org/2000/svg';
      var hex = function (h) { h = (h || '#000').replace('#', ''); return [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16) / 255; }); };
      var grey = '<feColorMatrix type="matrix" values=".3 .59 .11 0 0  .3 .59 .11 0 0  .3 .59 .11 0 0  0 0 0 1 0"/>';
      var body;
      if (kind === 'silver') body = grey + '<feComponentTransfer><feFuncR type="linear" slope="1.25" intercept="-.05"/><feFuncG type="linear" slope="1.25" intercept="-.05"/><feFuncB type="linear" slope="1.25" intercept="-.05"/></feComponentTransfer>';
      else if (kind === 'ink') body = grey + '<feComponentTransfer><feFuncR type="discrete" tableValues="0 0 1 1"/><feFuncG type="discrete" tableValues="0 0 1 1"/><feFuncB type="discrete" tableValues="0 0 1 1"/></feComponentTransfer>';
      else if (kind === 'duotone') {
        var a = hex(opts.dark || '#1d2b53'), b = hex(opts.light || '#ff6b35');
        body = grey + '<feComponentTransfer><feFuncR type="table" tableValues="' + a[0] + ' ' + b[0] + '"/><feFuncG type="table" tableValues="' + a[1] + ' ' + b[1] + '"/><feFuncB type="table" tableValues="' + a[2] + ' ' + b[2] + '"/></feComponentTransfer>';
      } else if (kind === 'halftone') {
        // Darkness plus a tiled dot, thresholded: big dots in the shadows, small in the highlights.
        var sz = opts.size || 8, dot = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="' + sz + '" height="' + sz + '"><defs><radialGradient id="g"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></radialGradient></defs><rect width="' + sz + '" height="' + sz + '" fill="url(#g)"/></svg>');
        body = grey + '<feImage href="' + dot + '" x="0" y="0" width="' + sz + '" height="' + sz + '" result="d"/><feTile in="d" result="t"/><feComposite in="SourceGraphic" in2="t" operator="arithmetic" k1="0" k2="1" k3="1" k4="-.5"/>' +
          '<feComponentTransfer><feFuncR type="discrete" tableValues="0 1"/><feFuncG type="discrete" tableValues="0 1"/><feFuncB type="discrete" tableValues="0 1"/><feFuncA type="linear" slope="0" intercept="1"/></feComponentTransfer>' +
          // Only where the picture is: the dot tile would otherwise print over the whole box, even when hidden.
          '<feColorMatrix type="matrix" values=".33 .33 .33 0 0  .33 .33 .33 0 0  .33 .33 .33 0 0  0 0 0 1 0"/><feComposite in2="SourceAlpha" operator="in"/>';
      } else return el;
      var svg = document.createElementNS(ns, 'svg');
      svg.setAttribute('width', '0'); svg.setAttribute('height', '0'); svg.style.position = 'absolute';
      svg.innerHTML = '<filter id="' + id + '" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%">' + body + '</filter>';
      document.body.appendChild(svg);
      el.style.filter = 'url(#' + id + ')';
      return el;
    },

    /* sunburst: rays behind a scene, turning slowly. el is a full-frame layer. opts: colors (2 or more), rays (24),
       spin (degrees over the duration, 20), duration (the scene's length). */
    sunburst: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var cols = opts.colors || ['#f39a1e', '#1f7a8c'], n = opts.rays || 24, stops = [], k, w = 360 / n;
      for (k = 0; k < n; k++) stops.push(cols[k % cols.length] + ' ' + (k * w) + 'deg ' + ((k + 1) * w) + 'deg');
      var disc = document.createElement('div');
      disc.style.cssText = 'position:absolute;left:50%;top:50%;width:220%;aspect-ratio:1;background:conic-gradient(' + stops.join(',') + ')';
      el.style.overflow = 'hidden'; el.appendChild(disc);
      tl.set(disc, { xPercent: -50, yPercent: -50, rotation: 0 }, 0)
        .to(disc, { rotation: opts.spin == null ? 20 : opts.spin, duration: opts.duration || 3, ease: 'none' }, at);
      return tl;
    },

    /* confetti: seeded paper pieces burst from a point and fall. el is a full-frame layer. opts: count (24), colors,
       from ({x, y} in stage px; default the centre), spread (px, 420), duration (1.6), seed. */
    confetti: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight, rnd = seeded(opts.seed || 5), d = opts.duration || 1.6;
      var cols = opts.colors || ['#e8261a', '#f5c518', '#1f7a8c', '#ff7eb6', '#ffffff'], o = opts.from || { x: W / 2, y: H / 2 }, sp = opts.spread || 420;
      for (var k = 0; k < (opts.count || 24); k++) {
        var p = document.createElement('div'), w = 10 + rnd() * 14, h = 6 + rnd() * 10;
        p.style.cssText = 'position:absolute;left:' + (o.x - w / 2) + 'px;top:' + (o.y - h / 2) + 'px;width:' + w + 'px;height:' + h + 'px;background:' + cols[k % cols.length] + ';border-radius:2px;box-shadow:0 2px 3px rgba(0,0,0,.15)';
        el.appendChild(p);
        var ang = rnd() * Math.PI * 2, dist = sp * (0.4 + rnd() * 0.6), up = -(180 + rnd() * 260);
        tl.set(p, { autoAlpha: 0 }, 0).set(p, { autoAlpha: 1 }, at)
          .fromTo(p, { x: 0, rotation: 0 }, { x: Math.cos(ang) * dist, rotation: (rnd() * 2 - 1) * 720, duration: d, ease: 'power2.out', immediateRender: false }, at)
          .fromTo(p, { y: 0 }, { keyframes: [{ y: up * 0.9 + Math.sin(ang) * dist * 0.3, duration: d * 0.35, ease: 'power2.out' }, { y: H * 0.6, duration: d * 0.65, ease: 'power2.in' }], immediateRender: false }, at)
          .to(p, { autoAlpha: 0, duration: 0.2 }, at + d - 0.2);
      }
      return tl;
    },

    /* ---- Social-native (RD-15): what a feed looks like from inside it. ---- */

    /* heartBurst: seeded hearts float up from the like button and fade, as when a video is liked. el is a full-frame
       layer above the content. opts: count (10), from ({x, y} stage px; default the right rail), colors, rise (px, 520),
       size (px, 76), duration (1.6), seed. */
    heartBurst: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight, rnd = seeded(opts.seed || 9), d = opts.duration || 1.6;
      var cols = opts.colors || ['#ff2d55', '#ff4d6d', '#ff7a93'], o = opts.from || { x: W * 0.86, y: H * 0.62 }, rise = opts.rise || 520, sz = opts.size || 76;
      for (var k = 0; k < (opts.count || 10); k++) {
        var h = document.createElement('div'), s = sz * (0.7 + rnd() * 0.6), t0 = at + k * 0.07;
        h.style.cssText = 'position:absolute;left:' + (o.x - s / 2) + 'px;top:' + (o.y - s / 2) + 'px;width:' + s + 'px;height:' + s + 'px';
        h.innerHTML = '<svg viewBox="0 0 24 24" width="100%" height="100%"><path fill="' + cols[k % cols.length] + '" d="M12 21s-7.5-4.6-9.6-9.2C.9 8.3 3 4.5 6.7 4.5c2.1 0 3.6 1.2 4.3 2.4.7-1.2 2.2-2.4 4.3-2.4 3.7 0 5.8 3.8 4.3 7.3C19.5 16.4 12 21 12 21z"/></svg>';
        el.appendChild(h);
        var drift = (rnd() * 2 - 1) * 120;
        tl.set(h, { autoAlpha: 0 }, 0).set(h, { autoAlpha: 1 }, t0)
          .fromTo(h, { y: 0, scale: 0.4 }, { y: -rise * (0.6 + rnd() * 0.4), scale: 1, duration: d, ease: 'power2.out', immediateRender: false }, t0)
          .fromTo(h, { x: 0, rotation: 0 }, { keyframes: [{ x: drift * 0.5, rotation: drift * 0.08, duration: d * 0.5, ease: 'sine.inOut' }, { x: drift, rotation: -drift * 0.05, duration: d * 0.5, ease: 'sine.inOut' }], immediateRender: false }, t0)
          .to(h, { autoAlpha: 0, duration: d * 0.35, ease: 'power1.in' }, t0 + d * 0.65);
      }
      return tl;
    },

    /* replySticker: the "Reply to @name's comment" sticker that opens a reply video: a white card with the avatar,
       the label and the comment, popping on with a tilt and settling. el is the card (built in HTML, see the social
       kit). opts: rotate (final degrees, -3), duration (.5). */
    replySticker: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var r = opts.rotate == null ? -3 : opts.rotate;
      tl.set(el, { autoAlpha: 0 }, 0).set(el, { autoAlpha: 1 }, at)
        .fromTo(el, { scale: 0.55, rotation: r - 9, y: 30 }, { scale: 1, rotation: r, y: 0, duration: opts.duration || 0.5, ease: ease.playful, immediateRender: false }, at);
      return tl;
    },

    /* stitch: the clip being answered plays full frame, then on the cut it shrinks into a rounded corner thumbnail
       while the reply fills the frame behind it (picture in picture). quote and reply are full-frame layers; reply sits
       under quote. opts: corner ('top-left'), scale (.3), duration (.55), hold (s the thumbnail stays; 0 = to the end),
       radius (px, 28). */
    stitch: function (tl, quote, reply, at, opts) {
      quote = $(quote); reply = $(reply); opts = opts || {};
      var sc = opts.scale || 0.3, corner = opts.corner || 'top-left', d = opts.duration || 0.55, inset = 0.04;
      var ox = /right/.test(corner) ? '100%' : '0%', oy = /bottom/.test(corner) ? '100%' : '0%';
      tl.set(reply, { autoAlpha: 1 }, at)
        .fromTo(quote, { scale: 1, xPercent: 0, yPercent: 0, borderRadius: 0 }, { scale: sc, xPercent: (/right/.test(corner) ? -1 : 1) * inset * 100, yPercent: (/bottom/.test(corner) ? -1 : 1) * inset * 100 * 0.56,
          borderRadius: (opts.radius || 28) / sc, transformOrigin: ox + ' ' + oy, duration: d, ease: ease['default'], immediateRender: false }, at);
      if (opts.hold) tl.to(quote, { autoAlpha: 0, scale: sc * 0.8, duration: 0.3, ease: 'power2.in' }, at + d + opts.hold);
      return tl;
    },

    /* ---- Product hero (RD-16): a product photo (a cutout PNG) made to feel shot in a studio. ---- */

    /* floor: set once. A cutout stands on a glossy floor: a soft contact shadow under it and a faded mirror image below.
       el is the product <img> (a transparent cutout) inside a positioned box. opts: reflection (opacity, .28),
       fade (share of the height the reflection shows, .45), shadow (opacity, .35), gap (px, 0). */
    floor: function (el, opts) {
      el = $(el); opts = opts || {};
      var box = el.parentNode, h = el.offsetHeight, w = el.offsetWidth, top = el.offsetTop + h + (opts.gap || 0), fade = opts.fade || 0.45;
      var mirror = el.cloneNode(true); mirror.removeAttribute('id');
      mirror.style.cssText = 'position:absolute;left:' + el.offsetLeft + 'px;top:' + top + 'px;width:' + w + 'px;height:' + h + 'px;transform:scaleY(-1);opacity:' + (opts.reflection == null ? 0.28 : opts.reflection)
        + ';-webkit-mask-image:linear-gradient(to top,rgba(0,0,0,1) 0%,rgba(0,0,0,0) ' + Math.round(fade * 100) + '%);mask-image:linear-gradient(to top,rgba(0,0,0,1) 0%,rgba(0,0,0,0) ' + Math.round(fade * 100) + '%);pointer-events:none';
      var shadow = document.createElement('div');
      shadow.style.cssText = 'position:absolute;left:' + (el.offsetLeft + w * 0.12) + 'px;top:' + (top - h * 0.03) + 'px;width:' + (w * 0.76) + 'px;height:' + (h * 0.06) + 'px;border-radius:50%;background:radial-gradient(ellipse at center,rgba(0,0,0,' + (opts.shadow == null ? 0.35 : opts.shadow) + ') 0%,rgba(0,0,0,0) 70%);pointer-events:none';
      box.insertBefore(shadow, el); box.insertBefore(mirror, el);
      return { mirror: mirror, shadow: shadow };
    },

    /* productTurn (low, 2-3 s): a flat product photo turns a few degrees in 3D under the light, as on a turntable,
       with a sheen sliding across its surface (the sheen is masked to the cutout, so only the product shines).
       el is the product <img>. opts: angle (degrees each side, 14), duration (2.4), sheen (true), lift (px, 12). */
    productTurn: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var a = opts.angle || 14, d = opts.duration || 2.4;
      if (el.parentNode && el.parentNode.style) el.parentNode.style.perspective = el.parentNode.style.perspective || '1400px';
      tl.fromTo(el, { rotationY: -a, y: 0 }, { rotationY: a, y: -(opts.lift == null ? 12 : opts.lift), duration: d, ease: 'sine.inOut', immediateRender: false }, at);
      if (opts.sheen !== false && el.getAttribute) {
        var src = el.getAttribute('src'), s = document.createElement('div');
        s.style.cssText = 'position:absolute;left:' + el.offsetLeft + 'px;top:' + el.offsetTop + 'px;width:' + el.offsetWidth + 'px;height:' + el.offsetHeight + 'px;pointer-events:none;mix-blend-mode:screen;'
          + 'background:linear-gradient(105deg,rgba(255,255,255,0) 42%,rgba(255,255,255,.4) 50%,rgba(255,255,255,0) 58%);background-size:300% 100%;'
          + (src ? '-webkit-mask-image:url(' + src + ');mask-image:url(' + src + ');-webkit-mask-size:100% 100%;mask-size:100% 100%;' : '');
        el.parentNode.appendChild(s);
        tl.fromTo(s, { backgroundPosition: '100% 0', rotationY: -a }, { backgroundPosition: '0% 0', rotationY: a, y: -(opts.lift == null ? 12 : opts.lift), duration: d, ease: 'sine.inOut', immediateRender: false }, at);
      }
      return tl;
    },

    /* spotlight (mid, 1.2 s): the stage is dark and a pool of light travels in and lands on the product, then opens a
       little. el is a full-frame layer above the scene. opts: to ({x, y} stage px: the product's centre), from ({x, y};
       default off the top left), radius (px, 360), open (final radius, radius * 1.25), dark (.82), duration (1.2),
       lift (seconds after landing when the dark fades away; omit to stay dark). */
    spotlight: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight, to = opts.to || { x: W / 2, y: H / 2 }, from = opts.from || { x: -W * 0.2, y: H * 0.1 };
      var r = opts.radius || 360, dark = opts.dark == null ? 0.82 : opts.dark, d = opts.duration || 1.2;
      // CSS variables tween on every seek (a callback would not run when a render seeks frame by frame).
      el.style.background = 'radial-gradient(circle var(--wm-sr) at var(--wm-sx) var(--wm-sy),rgba(0,0,0,0) 0%,rgba(0,0,0,0) 55%,rgba(0,0,0,' + dark + ') 100%)';
      tl.set(el, { autoAlpha: 0 }, 0).set(el, { autoAlpha: 1 }, at)
        .fromTo(el, { '--wm-sx': from.x + 'px', '--wm-sy': from.y + 'px' }, { '--wm-sx': to.x + 'px', '--wm-sy': to.y + 'px', duration: d * 0.75, ease: ease['default'], immediateRender: true }, at)
        .fromTo(el, { '--wm-sr': r + 'px' }, { '--wm-sr': (opts.open || r * 1.25) + 'px', duration: d * 0.4, ease: 'sine.out', immediateRender: true }, at + d * 0.7);
      // The lights come up after the reveal (opts.lift: seconds after it lands), so later beats are not left in the dark.
      if (opts.lift != null) tl.to(el, { autoAlpha: 0, duration: 0.6, ease: 'sine.inOut' }, at + d + opts.lift);
      return tl;
    },

    /* explode (high, ~1 s): the parts or ingredients burst out of the product and settle at their places around it,
       each with its label. parts: elements already laid out where they end; they start at the product's centre.
       opts: from (the product element, or {x, y} stage px), stagger (.06), duration (.8), spin (degrees, 25). */
    explode: function (tl, parts, at, opts) {
      opts = opts || {}; var stage = stageOf(opts), list = all(parts);
      var o = opts.from && opts.from.x != null ? opts.from : centre(opts.from || list[0].parentNode, stage);
      list.forEach(function (el, i) {
        var c = centre(el, stage), spin = (i % 2 ? 1 : -1) * (opts.spin == null ? 25 : opts.spin);
        tl.fromTo(el, { x: o.x - c.x, y: o.y - c.y, scale: 0.3, rotation: spin, autoAlpha: 0 },
          { x: 0, y: 0, scale: 1, rotation: 0, autoAlpha: 1, duration: opts.duration || 0.8, ease: ease['default'], immediateRender: true }, at + i * (opts.stagger == null ? 0.06 : opts.stagger));
      });
      return tl;
    },

    /* ---- Looks (kit/looks.md): pieces a named style needs. ---- */

    /* splitFlap (mid, ~1 s + .04 s a tile): a departures board: each tile flips through a few seeded characters and
       lands on its own, with a flap falling over the top half each time. el is an empty box; opts.text (lines with \n),
       cols (the widest line), step (.07 s a flip), flips (6), stagger (.04 s a tile), seed, colors {tile, text, accent}.
       Tile size follows el's font-size (a tile is .78em by 1.15em). Seek-safe: every character is its own layer. */
    splitFlap: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var lines = String(opts.text == null ? (el.textContent || '') : opts.text).toUpperCase().split('\n');
      var cols = opts.cols || Math.max.apply(null, lines.map(function (l) { return l.length; }));
      var rnd = seeded(opts.seed || 13), step = opts.step || 0.07, flips = opts.flips == null ? 6 : opts.flips, stagger = opts.stagger == null ? 0.04 : opts.stagger;
      var col = opts.colors || {}, tileBg = col.tile || '#1c1c1f', ink = col.text || '#f4f1ea';
      var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
      if (!el._wmFlap) {
        el.textContent = '';
        el._wmFlap = [];
        lines.forEach(function (line) {
          var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:.06em;margin-bottom:.06em';
          for (var c = 0; c < cols; c++) {
            var ch = line.charAt(c) || ' ', tile = document.createElement('div');
            tile.style.cssText = 'position:relative;width:.78em;height:1.15em;background:' + tileBg + ';border-radius:.08em;overflow:hidden;color:' + ink + ';text-align:center;line-height:1.15em;box-shadow:inset 0 -.04em 0 rgba(0,0,0,.35)';
            var seq = ch === ' ' ? [' '] : [];
            if (ch !== ' ') { for (var k = 0; k < flips; k++) seq.push(chars.charAt(Math.floor(rnd() * chars.length))); seq.push(ch); }
            var layers = seq.map(function (s, k) { var l = document.createElement('span'); l.textContent = s; l.style.cssText = 'position:absolute;left:0;right:0;top:0;bottom:0;visibility:' + (k === 0 && ch === ' ' ? 'visible' : 'hidden'); tile.appendChild(l); return l; });
            var flap = document.createElement('div'); flap.style.cssText = 'position:absolute;left:0;right:0;top:0;height:50%;background:' + tileBg + ';transform-origin:50% 100%;visibility:hidden';
            var seam = document.createElement('div'); seam.style.cssText = 'position:absolute;left:0;right:0;top:50%;height:.03em;background:rgba(0,0,0,.55)';
            tile.appendChild(flap); tile.appendChild(seam); row.appendChild(tile);
            el._wmFlap.push({ layers: layers, flap: flap, blank: ch === ' ', accent: col.accent && /[0-9]/.test(ch) });
          }
          el.appendChild(row);
        });
      }
      el._wmFlapTimes = [];
      el._wmFlap.forEach(function (t, i) {
        if (t.blank) return;
        var t0 = at + i * stagger;
        t.layers.forEach(function (l, k) {
          var tk = t0 + k * step;
          tl.set(l, { autoAlpha: 1 }, tk);
          if (k > 0) tl.set(t.layers[k - 1], { autoAlpha: 0 }, tk);
          if (k > 0) tl.fromTo(t.flap, { autoAlpha: 1, scaleY: 1, filter: 'brightness(1.4)' }, { scaleY: 0, filter: 'brightness(.7)', duration: step * 0.9, ease: 'power1.in', immediateRender: false }, tk - step)
            .set(t.flap, { autoAlpha: 0 }, tk);
        });
        tl.set(t.layers[0], { autoAlpha: 0 }, 0).set(t.layers[0], { autoAlpha: 1 }, t0);
        el._wmFlapTimes.push(t0 + (t.layers.length - 1) * step);
      });
      return tl;
    },

    /* mesh (low, the whole hold): soft colour fields drift and blend behind the content, like a mesh gradient.
       el is an empty full-frame layer under the content. opts: colors (3-4, the first is the base), duration (8),
       seed, scale (field size as a share of the frame's longer side, .9). Radial fields, so dark ones do not band. */
    mesh: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth || 1080, H = stage.offsetHeight || 1920, L = Math.max(W, H);
      var cols = opts.colors || ['#1b1f6b', '#7b2ff7', '#f107a3', '#26c6da'], rnd = seeded(opts.seed || 21), d = opts.duration || 8, sz = L * (opts.scale || 0.9);
      if (!el._wmMesh) {
        el.style.background = cols[0]; el.style.overflow = 'hidden';
        el._wmMesh = cols.slice(1).concat(cols.length < 4 ? [cols[1]] : []).map(function (c) {
          var b = document.createElement('div');
          b.style.cssText = 'position:absolute;left:0;top:0;width:' + sz + 'px;height:' + sz + 'px;border-radius:50%;background:radial-gradient(circle at 50% 50%,' + c + ' 0%,' + c + 'cc 25%,' + c + '00 68%)';
          el.appendChild(b); return b;
        });
      }
      el._wmMesh.forEach(function (b, i) {
        var x0 = rnd() * W - sz / 2, y0 = rnd() * H - sz / 2, x1 = rnd() * W - sz / 2, y1 = rnd() * H - sz / 2;
        tl.fromTo(b, { x: x0, y: y0, scale: 0.85 + rnd() * 0.3 }, { x: x1, y: y1, scale: 0.85 + rnd() * 0.3, duration: d, ease: 'sine.inOut', immediateRender: true }, at);
      });
      return tl;
    },

    /* circleText: a line of text set around a circle (a stamp, a badge, "MADE TO MOVE · "), turning. el is an empty
       square box; opts: text, size (font px, 34), spin (degrees over duration, 120), duration (4), font. */
    circleText: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var txt = Array.from(opts.text || 'MADE IN CODE · '), R = el.offsetWidth / 2, fs = opts.size || 34;
      el.style.position = el.style.position || 'absolute';
      txt.forEach(function (ch, k) {
        var sp = document.createElement('span');
        sp.textContent = ch;
        sp.style.cssText = 'position:absolute;left:50%;top:0;height:' + R + 'px;transform-origin:0 100%;font-size:' + fs + 'px;line-height:1;font-weight:800;letter-spacing:0;' + (opts.font ? 'font-family:' + opts.font + ';' : '') +
          'transform:translateX(-' + (fs * 0.3) + 'px) rotate(' + (k / txt.length * 360) + 'deg)';
        el.appendChild(sp);
      });
      tl.fromTo(el, { rotation: 0 }, { rotation: opts.spin == null ? 120 : opts.spin, duration: opts.duration || 4, ease: 'none', immediateRender: false }, at);
      return tl;
    },

    /* slides: a deck's slides, each filling the frame as it looks. el holds the slides (.wm-slide, each a div with its
       <img>), stacked; at: the time each slide after the first comes in. opts: transition push (default: the next
       slide pushes the last one left), fade or zoom (the last one recedes as the next lands), duration (.6), sound. */
    slides: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var list = Array.prototype.slice.call(el.querySelectorAll(':scope > .wm-slide')), d = opts.duration || 0.6, kind = opts.transition || 'push';
      el.style.overflow = 'hidden';
      list.forEach(function (sl, k) { sl.style.position = 'absolute'; sl.style.inset = '0'; tl.set(sl, { autoAlpha: k === 0 ? 1 : 0, zIndex: k }, 0); });
      (at || []).slice(0, list.length - 1).forEach(function (t, k) {
        var a = list[k], b = list[k + 1];
        if (kind === 'fade') tl.set(b, { autoAlpha: 0 }, t).to(b, { autoAlpha: 1, duration: d, ease: 'sine.inOut' }, t).set(a, { autoAlpha: 0 }, t + d);
        else if (kind === 'zoom') tl.fromTo(b, { autoAlpha: 0, scale: 1.06 }, { autoAlpha: 1, scale: 1, duration: d, ease: ease.default }, t).to(a, { scale: 0.94, autoAlpha: 0, duration: d, ease: 'power2.in' }, t);
        else tl.fromTo(b, { autoAlpha: 1, xPercent: 100 }, { xPercent: 0, duration: d, ease: ease.heavy }, t).to(a, { xPercent: -30, duration: d, ease: ease.heavy }, t).set(a, { autoAlpha: 0, xPercent: 0 }, t + d);
      });
      return tl;
    },

    /* pageFocus: move the camera to a part of a page or slide and back, so the viewer reads what is being said.
       content is the layer that moves (a full-frame wrapper holding the book or slides); page the page or slide
       element; box {x, y, w, h} the part, as fractions of the page (look at the page to find it). opts: scale (fit
       the part to ~80% of the frame, 1.2 to 3), duration (.7), hold (1.5; Infinity stays in until the next move),
       back (true). */
    pageFocus: function (tl, content, page, box, at, opts) {
      content = $(content); page = $(page); opts = opts || {}; box = box || { x: 0, y: 0, w: 1, h: 1 };
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight, r = rect(page, stage);
      var bw = r.width * box.w, bh = r.height * box.h, cx = r.left + r.width * (box.x + box.w / 2), cy = r.top + r.height * (box.y + box.h / 2);
      var s = opts.scale || Math.min(3, Math.max(1.2, Math.min(0.8 * W / bw, 0.8 * H / bh)));
      var x = W / 2 - s * cx, y = H / 2 - s * cy, d = opts.duration == null ? 0.7 : opts.duration, hold = opts.hold == null ? 1.5 : opts.hold;
      tl.set(content, { transformOrigin: '0 0' }, 0).to(content, { x: x, y: y, scale: s, duration: d, ease: ease.heavy }, at);
      if (opts.back !== false && isFinite(hold)) tl.to(content, { x: 0, y: 0, scale: 1, duration: d, ease: ease.heavy }, at + d + hold);
      return tl;
    },

    /* flood: a shape grows from a point to fill the frame in one colour (about .3 s, past the corners), holds a beat,
       then shrinks away into the next scene. el is a full-frame div above the scenes; from is {x, y} in stage px or an
       element whose centre it starts from; to (optional) where it shrinks into. Switch the scene underneath during the
       hold. opts: color, grow (.3), hold (.25), shrink (.35). */
    flood: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var stage = stageOf(opts), W = stage.offsetWidth, H = stage.offsetHeight;
      var pt = function (p, dflt) { if (!p) return dflt; if (p.x != null) return p; return centre($(p), stage); };
      var a = pt(opts.from, { x: W / 2, y: H / 2 }), b = pt(opts.to, a), R = Math.hypot(W, H);
      var g = opts.grow || 0.3, h = opts.hold == null ? 0.25 : opts.hold, k = opts.shrink || 0.35;
      tl.set(el, { backgroundColor: opts.color || '#111', clipPath: 'circle(0px at ' + a.x + 'px ' + a.y + 'px)', autoAlpha: 1 }, at)
        .to(el, { clipPath: 'circle(' + R + 'px at ' + a.x + 'px ' + a.y + 'px)', duration: g, ease: 'power3.in' }, at)
        .set(el, { clipPath: 'circle(' + R + 'px at ' + b.x + 'px ' + b.y + 'px)' }, at + g + h)
        .to(el, { clipPath: 'circle(0px at ' + b.x + 'px ' + b.y + 'px)', duration: k, ease: 'power3.out' }, at + g + h)
        .set(el, { autoAlpha: 0 }, at + g + h + k);
      return tl;
    },

    /* rise: text rises out of a mask line (its parent clips it). opts: duration (.55), stagger (.06) for several
       lines, from (yPercent, default 110). */
    rise: function (tl, els, at, opts) {
      opts = opts || {};
      all(els).forEach(function (el, i) {
        if (el.parentNode && el.parentNode.style) el.parentNode.style.overflow = 'hidden';
        tl.fromTo(el, { yPercent: opts.from == null ? 110 : opts.from }, { yPercent: 0, duration: opts.duration || 0.55, ease: ease.snappy, immediateRender: true }, at + i * (opts.stagger == null ? 0.06 : opts.stagger));
      });
      return tl;
    },

    /* edges: a pill, tab highlight or bar moves from one span to another with its two edges on separate springs:
       the leading edge snaps ahead, the trailing edge follows and catches up, so it stretches and settles like a
       liquid tab. el is positioned at x = 0 with its own width; from and to are {x, w} in px of its parent.
       opts: lead (.32 s), trail (.48 s). Transforms only (x and scaleX from the left). */
    edges: function (tl, el, at, from, to, opts) {
      el = $(el); opts = opts || {};
      var base = el.offsetWidth || from.w, right = to.x + to.w > from.x + from.w;
      var p = { l: from.x, r: from.x + from.w };
      var apply = function () { gsap.set(el, { x: p.l, scaleX: Math.max(0.01, (p.r - p.l) / base), transformOrigin: '0 50%' }); };
      tl.set(el, { x: from.x, scaleX: from.w / base, transformOrigin: '0 50%' }, at);
      var lead = { duration: opts.lead || 0.32, ease: ease.snappy, onUpdate: apply }, trail = { duration: opts.trail || 0.48, ease: ease['default'], onUpdate: apply };
      if (right) { tl.to(p, Object.assign({ r: to.x + to.w }, lead), at).to(p, Object.assign({ l: to.x }, trail), at); }
      else { tl.to(p, Object.assign({ l: to.x }, lead), at).to(p, Object.assign({ r: to.x + to.w }, trail), at); }
      return tl;
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

    /* ---- Camera, rhythm and readouts (from-scratch vocabulary). Energy: low, mid or high; each says when to use it. ---- */

    /* pushIn (low, 2-4 s): a slow push into a held scene so a still beat keeps tension. el: the scene's full-frame
       wrapper. opts: scale (1.08), duration (2.5), focus {x, y} 0..1 (.5, .45). At most twice a video. */
    pushIn: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var f = opts.focus || { x: 0.5, y: 0.45 };
      tl.set(el, { transformOrigin: (f.x * 100) + '% ' + (f.y * 100) + '%' }, at)
        .fromTo(el, { scale: 1 }, { scale: opts.scale || 1.08, duration: opts.duration || 2.5, ease: 'sine.inOut', immediateRender: false }, at);
      return tl;
    },
    /* pullBack (mid, about .9 s): open tight on a detail, then pull out to reveal the whole. opts: from (1.8),
       focus {x, y} 0..1 of the detail, duration (.9). */
    pullBack: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var f = opts.focus || { x: 0.5, y: 0.5 };
      tl.set(el, { transformOrigin: (f.x * 100) + '% ' + (f.y * 100) + '%' }, at)
        .fromTo(el, { scale: opts.from || 1.8 }, { scale: 1, duration: opts.duration || 0.9, ease: ease.heavy, immediateRender: true }, at);
      return tl;
    },
    /* dutch (mid, about .7 s): the scene enters tilted and slightly large, then rolls level. opts: angle (-7). */
    dutch: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      tl.fromTo(el, { rotation: opts.angle == null ? -7 : opts.angle, scale: 1.12, transformOrigin: '50% 50%' }, { rotation: 0, scale: 1, duration: opts.duration || 0.7, ease: ease['default'], immediateRender: true }, at);
      return tl;
    },
    /* coldOpen (high, 1-1.5 s): three or more very short shots cut hard, then a beat of black before the title.
       els: the shots (full-frame, hidden before), in order. opts: each (.3 s), black (.4 s; the stage shows through,
       so give the stage a dark background). */
    coldOpen: function (tl, els, at, opts) {
      opts = opts || {}; var each = opts.each || 0.3, list = all(els);
      list.forEach(function (el, i) { tl.set(el, { autoAlpha: 1 }, at + i * each).set(el, { autoAlpha: 0 }, at + (i + 1) * each); });
      return tl;
    },
    /* textMask (mid, 1.5-3 s): the word is a window onto a picture or colour moving behind it. el: the word, with
       opts.image a CSS background (url(...) or a gradient). opts: from ('0% 50%'), to ('100% 50%'), size ('220% auto'),
       duration (2). Keep the word large and heavy so the picture reads through it. */
    textMask: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      var style = { backgroundSize: opts.size || '220% auto', WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent', WebkitTextFillColor: 'transparent' };
      if (opts.image) style.backgroundImage = opts.image;
      tl.set(el, style, 0)
        .fromTo(el, { backgroundPosition: opts.from || '0% 50%' }, { backgroundPosition: opts.to || '100% 50%', duration: opts.duration || 2, ease: 'sine.inOut', immediateRender: true }, at);
      return tl;
    },
    /* rampFreeze (high, about .6 s): an element shoots in, brakes hard and freezes with a flash: the hit of a
       launch. opts: from {x, y} offset in px ({x: -900, y: 0}), flash (true). */
    rampFreeze: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var from = opts.from || { x: -900, y: 0 };
      tl.set(el, { x: from.x, y: from.y, autoAlpha: 1 }, at)
        .to(el, { keyframes: [{ x: from.x * 0.25, y: from.y * 0.25, duration: 0.12, ease: 'none' }, { x: 0, y: 0, duration: 0.45, ease: 'expo.out' }] }, at);
      if (opts.flash !== false) tl.fromTo(el, { filter: 'brightness(2.2)' }, { filter: 'brightness(1)', duration: 0.25, ease: 'power2.out', immediateRender: false }, at + 0.14);
      return tl;
    },
    /* hiddenCut (high, about .7 s): an object (a bar, a product, a shape) sweeps across the frame and the scene changes
       behind it while it covers the frame. blocker: positioned above the scenes, at least as tall as the frame and
       wider than it at mid-sweep. opts: dir ('right'), duration (.7). */
    hiddenCut: function (tl, blocker, a, b, at, opts) {
      blocker = $(blocker); opts = opts || {}; var d = opts.duration || 0.7, s = opts.dir === 'left' ? -1 : 1;
      tl.set(blocker, { autoAlpha: 1, xPercent: -130 * s }, at)
        .to(blocker, { xPercent: 130 * s, duration: d, ease: 'power2.inOut' }, at)
        .set($(a), { autoAlpha: 0 }, at + d / 2).set($(b), { autoAlpha: 1 }, at + d / 2)
        .set(blocker, { autoAlpha: 0 }, at + d);
      return tl;
    },
    /* odometer (mid, about 1 s): the digits of an approved number roll up into place, one column each, the last
       digit landing last. el: the number's element (its text is replaced). opts: duration (.9), stagger (.06). */
    odometer: function (tl, el, value, at, opts) {
      el = $(el); opts = opts || {}; var chars = String(value).split(''), cols = [];
      el.textContent = '';
      chars.forEach(function (c) {
        var col = document.createElement('span'); col.style.display = 'inline-block'; col.style.overflow = 'hidden'; col.style.height = '1em'; col.style.lineHeight = '1em'; col.style.verticalAlign = 'top';
        if (!/[0-9]/.test(c)) { col.textContent = c; el.appendChild(col); return; }
        var strip = document.createElement('span'); strip.style.display = 'block';
        for (var k = 0; k <= 9; k++) { var d = document.createElement('span'); d.style.display = 'block'; d.style.height = '1em'; d.textContent = String(k); strip.appendChild(d); }
        col.appendChild(strip); el.appendChild(col); cols.push({ strip: strip, digit: Number(c) });
      });
      cols.forEach(function (c, i) {
        tl.fromTo(c.strip, { yPercent: 0 }, { yPercent: -10 * c.digit, duration: opts.duration || 0.9, ease: 'power3.out', immediateRender: true }, at + i * (opts.stagger == null ? 0.06 : opts.stagger));
      });
      return tl;
    },
    /* gauge (mid, about 1.2 s): a ring or bar sweeps to an approved value. el: an SVG circle or path with a stroke
       (a ring), or any block (a bar, filled from the left). value 0..100. opts: duration (1.2). */
    gauge: function (tl, el, value, at, opts) {
      el = $(el); opts = opts || {}; var p = Math.max(0, Math.min(100, value)) / 100, d = opts.duration || 1.2;
      if (el.getTotalLength) {
        var len = el.getTotalLength();
        tl.set(el, { strokeDasharray: len }, 0).fromTo(el, { strokeDashoffset: len }, { strokeDashoffset: len * (1 - p), duration: d, ease: 'power3.out', immediateRender: true }, at);
      } else tl.fromTo(el, { scaleX: 0, transformOrigin: '0 50%' }, { scaleX: p, duration: d, ease: 'power3.out', immediateRender: true }, at);
      return tl;
    },
    /* streak (high, about .35 s): speed lines tear across the frame over a cut. el: a full-frame div above the
       scenes (it is filled with lines). opts: color ('rgba(255,255,255,.85)'), lines (14), dir ('right'). */
    streak: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var n = opts.lines || 14, s = opts.dir === 'left' ? -1 : 1, lines = [];
      el.style.overflow = 'hidden';
      for (var i = 0; i < n; i++) {
        var l = document.createElement('div'), y = (i * 37 + 11) % 100, h = 2 + (i * 7) % 9, w = 30 + (i * 23) % 50;
        l.style.cssText = 'position:absolute;left:0;top:' + y + '%;height:' + h + 'px;width:' + w + '%;border-radius:' + h + 'px;background:' + (opts.color || 'rgba(255,255,255,0.85)');
        el.appendChild(l); lines.push(l);
      }
      tl.set(el, { autoAlpha: 1 }, at - 0.18);
      lines.forEach(function (l, i) { tl.fromTo(l, { xPercent: -260 * s }, { xPercent: 340 * s, duration: 0.32, ease: 'power2.in', immediateRender: true }, at - 0.18 + (i % 5) * 0.015); });
      tl.set(el, { autoAlpha: 0 }, at + 0.22);
      return tl;
    },
    /* smash (high, a single frame): a hard cut on the hit, with a one-frame white flash. For a smash to silence,
       also end the music clip at `at` and start a second clip of the same file after the silence (data-start,
       data-media-start), so the cut lands in sudden quiet. opts: flash (true), color ('#fff'). el: a full-frame flash
       div above the scenes (optional). */
    smash: function (tl, a, b, at, opts) {
      opts = opts || {};
      tl.set($(a), { autoAlpha: 0 }, at).set($(b), { autoAlpha: 1 }, at);
      if (opts.flash !== false && opts.el) tl.set($(opts.el), { autoAlpha: 1, backgroundColor: opts.color || '#fff' }, at).set($(opts.el), { autoAlpha: 0 }, at + 1 / 24);
      return tl;
    },
    /* split (mid, about .7 s): two panels slide in from opposite sides into halves of the frame and a divider draws
       between them: before and after, them and us, two options. a, b: the panels, each sized to its half. opts:
       dir ('vertical': left and right; 'horizontal': top and bottom), divider (an element drawn along the split). */
    split: function (tl, a, b, at, opts) {
      opts = opts || {}; var v = opts.dir !== 'horizontal', k = v ? 'xPercent' : 'yPercent', d = opts.duration || 0.6;
      var fa = {}, fb = {}, ta = { duration: d, ease: ease['default'], immediateRender: true }, tb = { duration: d, ease: ease['default'], immediateRender: true };
      fa[k] = -105; fb[k] = 105; ta[k] = 0; tb[k] = 0; fa.autoAlpha = 1; fb.autoAlpha = 1;
      tl.fromTo($(a), fa, ta, at).fromTo($(b), fb, tb, at + 0.08);
      if (opts.divider) { var s0 = {}, s1 = { duration: 0.35, ease: ease.snappy, immediateRender: true }; s0[v ? 'scaleY' : 'scaleX'] = 0; s1[v ? 'scaleY' : 'scaleX'] = 1; tl.fromTo($(opts.divider), s0, s1, at + d * 0.6); }
      return tl;
    },
    /* stack (mid, about .4 s an item): list items arrive one at a time; each new one pushes in at the front and the
       earlier ones step up and dim, so the list builds as it is read. els: the items in order, absolutely placed at
       the same front slot. times: when each arrives (on the voice), or opts.each (.8 s). opts: gap (px, the item
       height plus spacing), dim (.45). */
    stack: function (tl, els, times, opts) {
      opts = opts || {}; var list = all(els), gap = opts.gap || 140, dim = opts.dim == null ? 0.45 : opts.dim;
      var at = function (i) { return Array.isArray(times) ? times[i] : (times || 0) + i * (opts.each || 0.8); };
      list.forEach(function (el, i) {
        tl.fromTo(el, { y: 80, autoAlpha: 0 }, { y: 0, autoAlpha: 1, duration: 0.4, ease: ease.snappy, immediateRender: true }, at(i));
        for (var j = 0; j < i; j++) tl.to(list[j], { y: -gap * (i - j), opacity: dim, duration: 0.4, ease: ease.snappy }, at(i));
      });
      return tl;
    },
    /* parallax (low, 2-5 s): a still picture split into layers (background, product cutout, foreground) drifts with
       depth: nearer layers move further, so a photo feels shot on a moving camera. layers: back to front. opts:
       duration (3), distance (px for the front layer, 60), dir ('left'|'right'|'up'|'down'), scale (1.04). */
    parallax: function (tl, layers, at, opts) {
      opts = opts || {}; var list = all(layers), n = list.length, dist = opts.distance || 60, d = opts.duration || 3;
      var axis = opts.dir === 'up' || opts.dir === 'down' ? 'y' : 'x', sign = opts.dir === 'right' || opts.dir === 'down' ? 1 : -1;
      list.forEach(function (el, i) {
        var f = n > 1 ? (i + 1) / n : 1, from = {}, to = { duration: d, ease: 'sine.inOut', immediateRender: true };
        from[axis] = -sign * dist * f / 2; to[axis] = sign * dist * f / 2; from.scale = opts.scale || 1.04; to.scale = opts.scale || 1.04;
        tl.fromTo(el, from, to, at);
      });
      return tl;
    },

    /* ---- Focus and letters (RD-10): type that arrives like a lens finding it. ---- */

    /* blurIn (low, .7 s): text sharpens in from a soft blur, line by line (els in reading order). Premium and calm;
       no scale, so the letters never climb the pixel grid. opts: duration (.7), stagger (.12), blur (px, 14), rise (em, .15). */
    blurIn: function (tl, els, at, opts) {
      opts = opts || {}; var b = opts.blur == null ? 14 : opts.blur;
      all(typeof els === 'string' || els.length != null ? els : [els]).forEach(function (el, i) {
        tl.fromTo(el, { autoAlpha: 0, y: (opts.rise == null ? 0.15 : opts.rise) + 'em', filter: 'blur(' + b + 'px)' },
          { autoAlpha: 1, y: 0, filter: 'blur(0px)', duration: opts.duration || 0.7, ease: ease.heavy, immediateRender: true }, at + i * (opts.stagger == null ? 0.12 : opts.stagger));
      });
      return tl;
    },

    /* letters (mid, ~.6 s + .03 s a letter): a headline's letters rise one by one out of a mask line. el holds plain
       text; it is split into letters once (words kept whole so lines only break between words). Wrap the line in a
       container with data-layout-allow-overlap. opts: stagger (.03), duration (.5), from (yPercent, 110). */
    letters: function (tl, el, at, opts) {
      el = $(el); opts = opts || {};
      if (!el._wmLetters) {
        var text = el.textContent || '', list = [];
        el.textContent = '';
        text.split(/(\s+)/).forEach(function (part) {
          if (!part) return;
          if (/^\s+$/.test(part)) { el.appendChild(document.createTextNode ? document.createTextNode(' ') : document.createElement('span')); return; }
          var word = document.createElement('span'); word.style.display = 'inline-block'; word.style.whiteSpace = 'nowrap';
          part.split('').forEach(function (ch) {
            var mask = document.createElement('span'), letter = document.createElement('span');
            mask.style.display = 'inline-block'; mask.style.overflow = 'hidden'; mask.style.verticalAlign = 'top';
            letter.style.display = 'inline-block'; letter.textContent = ch;
            mask.appendChild(letter); word.appendChild(mask); list.push(letter);
          });
          el.appendChild(word);
        });
        el._wmLetters = list;
      }
      el._wmLetters.forEach(function (letter, i) {
        tl.fromTo(letter, { yPercent: opts.from == null ? 110 : opts.from }, { yPercent: 0, duration: opts.duration || 0.5, ease: ease.snappy, immediateRender: true },
          at + i * (opts.stagger == null ? 0.03 : opts.stagger));
      });
      return tl;
    },

    /* rackFocus (low, .6 s): the focus pulls from one thing to another, like a lens: `from` (an element or a list)
       goes soft and dims, `to` sharpens. For UI: the panel that matters comes into focus while the rest falls back.
       opts: duration (.6), blur (px, 8), dim (.55). Camera-like, so it eases in and out. */
    rackFocus: function (tl, from, to, at, opts) {
      opts = opts || {}; var b = opts.blur == null ? 8 : opts.blur, d = opts.duration || 0.6;
      if (from) all(typeof from === 'string' || from.length != null ? from : [from]).forEach(function (el) {
        tl.fromTo(el, { filter: 'blur(0px)', opacity: 1 }, { filter: 'blur(' + b + 'px)', opacity: opts.dim == null ? 0.55 : opts.dim, duration: d, ease: 'sine.inOut', immediateRender: false }, at);
      });
      tl.fromTo($(to), { filter: 'blur(' + b + 'px)' }, { filter: 'blur(0px)', opacity: 1, duration: d, ease: 'sine.inOut', immediateRender: false }, at);
      return tl;
    },

    /* glow (mid, .5 s): a highlight flashes with light, then settles: a key word, a price, a button.
       opts: color (the accent, '#fff'), size (px, 22), duration (.5), sound ('chime' by default, false for none). */
    glow: function (tl, el, at, opts) {
      el = $(el); opts = opts || {}; var c = opts.color || '#fff', d = opts.duration || 0.5, s = opts.size || 22;
      tl.fromTo(el, { filter: 'brightness(1) drop-shadow(0 0 0px ' + c + ')' }, { filter: 'brightness(1.35) drop-shadow(0 0 ' + s + 'px ' + c + ')', duration: d * 0.3, ease: 'power2.out', immediateRender: false }, at)
        .to(el, { filter: 'brightness(1) drop-shadow(0 0 0px ' + c + ')', duration: d * 0.7, ease: 'sine.inOut' }, at + d * 0.3);
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
  // Sound cues for the host's sound pass: each move records where it hits (in its timeline's time), and before the
  // render the host lays the matching effect from the built-in library under it, lined up on the sound's peak.
  // opts.sound: false for silence, or another library sound's name. WM.sound(tl, name, at) adds a cue by hand.
  var o = function (a, i) { return (a[i] && typeof a[i] === 'object') ? a[i] : {}; };
  var CUES = {
    pop: function (a) { return [['pop', a[2] + 0.04]]; },
    glow: function (a) { return [['chime', a[2] + 0.05]]; },
    heartBurst: function (a) { return [['pop', a[2] + 0.02]]; },
    // One soft tick as each tile lands (the sound pass thins ticks that crowd each other).
    splitFlap: function (a) { var e = $(a[1]); return ((e && e._wmFlapTimes) || []).map(function (t) { return ['tick', t]; }); },
    spotlight: function (a) { return [['whoosh', a[2] + 0.1]]; },
    explode: function (a) { return [['whoosh-big', a[2] + 0.05]]; },
    replySticker: function (a) { return [['pop', a[2] + 0.06]]; },
    stitch: function (a) { return [['whoosh-fast', a[3] + 0.05]]; },
    stamp: function (a) { return [['thud', a[2] + 0.1]]; },
    press: function (a) { return [['click', a[2] + 0.09]]; },
    toss: function (a) { return [['swish', a[2] + 0.1]]; },
    device: function (a) { return [['slide', a[2] + (o(a, 3).duration || 0.5) / 2]]; },
    flood: function (a) { return [['whoosh-big', a[2] + (o(a, 3).grow || 0.3)]]; },
    iris: function (a) { return [['swish', a[2] + (o(a, 3).duration || 0.5) * 0.7]]; },
    wipe: function (a) { return [['whoosh', a[2] + 0.27]]; },
    push: function (a) { return [['whoosh', a[3] + 0.3]]; },
    whip: function (a) { return [['whoosh-fast', a[3]]]; },
    giantWipe: function (a) { return [['whoosh-big', a[3] + 0.28]]; },
    camera: function (a) { var d = o(a, 4).duration; return [['slide', a[3] + (d == null ? 0.6 : d) * 0.35]]; },
    tear: function (a) { var d = o(a, 3).duration || 0.7; return [['swish', a[2] + d * 0.25]]; },
    sticker: function (a) { return [['pop', a[2] + 0.06]]; },
    cards: function (a) { var n = 0; try { n = $(a[1]).querySelectorAll(':scope > .wm-card').length; } catch (e) {} var st = (o(a, 3).every || 2) / (o(a, 3).fps || 24), out = []; for (var i = 0; i < n; i += Math.max(1, Math.round(0.25 / st))) out.push(['tick', a[2] + i * st]); return out; },
    confetti: function (a) { return [['pop', a[2] + 0.02]]; },
    book: function (a) { var d = o(a, 3).duration || 1.1; return (a[2] || []).map(function (t) { return ['page', t + d * 0.3]; }); },
    slides: function (a) { var d = o(a, 3).duration || 0.6, k = o(a, 3).transition || 'push'; return (a[2] || []).map(function (t) { return [k === 'fade' ? 'slide' : 'whoosh', t + d * 0.4]; }); },
    pageFocus: function (a) { var d = o(a, 5).duration; return [['slide', a[4] + (d == null ? 0.7 : d) * 0.35]]; },
    fly: function (a) { return [['swish', a[3] + (o(a, 4).duration || 0.6) * 0.5]]; },
    layout: function (a) { var d = o(a, 4).duration; return d === 0 ? [] : [['slide', a[2] + (d == null ? 0.55 : d) / 2]]; },
    edges: function (a) { return [['tick', a[2] + 0.05]]; },
    through: function (a) { return [['whoosh-big', o(a, 1).at]]; },
    type: function (a) { return [['keys', a[3], String(a[2] || '').length / (a[4] || 18)]]; },
    count: function (a) { return [['blip', a[4] + (a[5] || 0)]]; },
    cursor: function (a) { return (a[2] || []).filter(function (p) { return p.click; }).map(function (p) { return ['click', p.at]; }); },
    morph: function (a) { return (a[2] || []).slice(1).map(function (s) { return ['slide', s.at + 0.2]; }); },
    pullBack: function (a) { return [['slide', a[2] + 0.15]]; },
    dutch: function (a) { return [['swish', a[2] + 0.1]]; },
    coldOpen: function (a) { var n = all(a[1]).length, e = o(a, 3).each || 0.3, out = []; for (var i = 1; i <= n; i++) out.push(['tick', a[2] + i * e]); return out; },
    rampFreeze: function (a) { return [['thud', a[2] + 0.14]]; },
    hiddenCut: function (a) { return [['whoosh', a[4] + (o(a, 5).duration || 0.7) / 2]]; },
    odometer: function (a) { return [['blip', a[3] + (o(a, 4).duration || 0.9)]]; },
    gauge: function (a) { return [['blip', a[3] + (o(a, 4).duration || 1.2)]]; },
    streak: function (a) { return [['whoosh-fast', a[2]]]; },
    smash: function (a) { return [['thud', a[3]]]; },
    split: function (a) { return [['slide', a[3] + 0.3]]; },
    stack: function (a) { var n = all(a[1]).length, t = a[2], e = o(a, 3).each || 0.8, out = []; for (var i = 0; i < n; i++) out.push(['tick', (Array.isArray(t) ? t[i] : (t || 0) + i * e) + 0.05]); return out; },
  };
  var OPTS = { tear: 3, sticker: 3, cards: 3, confetti: 3, book: 3, slides: 3, pageFocus: 5, device: 3, flood: 3, iris: 3, pop: 3, stamp: 3, press: 3, toss: 3, giantWipe: 4, camera: 4, fly: 4, layout: 4, edges: 5, through: 1,
    glow: 3, splitFlap: 3, spotlight: 3, explode: 3, heartBurst: 3, replySticker: 3, stitch: 4, pullBack: 3, dutch: 3, coldOpen: 3, rampFreeze: 3, hiddenCut: 5, odometer: 4, gauge: 4, streak: 3, smash: 4, split: 4, stack: 3 };
  var cues = [];
  Object.keys(CUES).forEach(function (name) {
    var fn = WM[name];
    WM[name] = function () {
      var out = fn.apply(WM, arguments), a = arguments, pick = OPTS[name] == null ? undefined : o(a, OPTS[name]).sound;
      if (pick !== false) CUES[name](a).forEach(function (h) {
        if (isFinite(h[1])) cues.push({ tl: a[0], at: h[1], sound: typeof pick === 'string' ? pick : h[0], len: h[2] });
      });
      return out;
    };
  });
  // Which moves actually ran while the timeline was built (not merely appear in the code): the final check's evidence.
  var used = {};
  Object.keys(WM).forEach(function (name) {
    if (typeof WM[name] !== 'function' || name === 'pinToClip') return;
    var fn = WM[name];
    WM[name] = function () { used[name] = (used[name] || 0) + 1; return fn.apply(WM, arguments); };
  });
  WM.movesUsed = function () { return Object.keys(used).map(function (n) { return { move: n, calls: used[n] }; }); };
  WM.sound = function (tl, name, at) { cues.push({ tl: tl, at: at, sound: name }); return tl; };
  /* Every cue in composition time: [{sound, t, len?}]. Read after the timelines are built and nested. */
  WM.cueTimes = function () {
    return cues.map(function (c) {
      var t = c.at, n = c.tl;
      while (n && n.parent && n.parent !== gsap.globalTimeline) { t = n.startTime() + t / (n.timeScale() || 1); n = n.parent; }
      var cue = { sound: c.sound, t: Math.round(t * 1000) / 1000 };
      if (c.len) cue.len = Math.round(c.len * 1000) / 1000;
      return cue;
    });
  };

  /* Field text fits its box (HOME-CTA's free ads, owner 2026-10-19). A template is drawn for its sample words; a copy
   * puts in the customer's own, and one long brand name or web address ("WicksNWax", "montyseleven.co.uk") cannot wrap,
   * so a headline set big for "VOLT RUSH" ran off its card. Once the page has written its fields, any text that came
   * from a string field and runs out of its clipping box, card or button (or the frame) at the side is set smaller until it fits, never below 30% of
   * its size (a 12-letter name in a headline drawn for two short words needs about 40%). Only field text: a ribbon that repeats a word on purpose ("SALE ✦ SALE") is not a field's words and is left
   * alone, and so is type drawn bigger than half the frame or marked data-wyv-nofit (a giant wipe). Measured at rest: the entrance transforms GSAP has applied are lifted for the measurement and put back.
   * Runs when the page has loaded and again when its fonts are ready; text that already fits is never touched. */
  function fieldValues() {
    var vals = [];
    try {
      var raw = document.documentElement.getAttribute('data-composition-variables');
      (JSON.parse(raw || '[]') || []).forEach(function (d) { if (d && d.type === 'string' && d['default'] != null) vals.push(String(d['default'])); });
    } catch (e) {}
    try {
      var live = window.__hyperframes && window.__hyperframes.getVariables && window.__hyperframes.getVariables();
      if (live) Object.keys(live).forEach(function (k) { if (typeof live[k] === 'string') vals.push(live[k]); });
    } catch (e) {}
    var norm = function (t) { return String(t).replace(/\s+/g, ' ').trim().toLowerCase(); };
    return vals.map(norm).filter(function (v) { return v.length >= 2; });
  }
  // The index of the field whose words t is (all of them, or whole words of them), or -1.
  function isFieldText(t, vals) {
    if (t.length < 2) return -1;
    for (var i = 0; i < vals.length; i++) if (vals[i] === t || (' ' + vals[i] + ' ').indexOf(' ' + t + ' ') >= 0) return i;
    return -1;
  }
  // The box text must stay in: the nearest element (itself included) that clips, or a visible panel around it (its own
  // background, picture or border, smaller than the frame): a card's or button's words belong inside it.
  // inner: the room to aim for once a text does not fit: inside its box's padding, or 4% in from the frame's sides (an
  // entrance that grows the text a little must not push it off the frame).
  function clipBox(el, inner) {
    var W = window.innerWidth, H = window.innerHeight, c = null;
    for (var e = el; e && e !== document.body && !c; e = e.parentElement) {
      var st = getComputedStyle(e), r = e.getBoundingClientRect(), m = st.backgroundColor.match(/rgba?\(([^)]+)\)/);
      var alpha = m ? (m[1].split(',')[3] === undefined ? 1 : parseFloat(m[1].split(',')[3])) : 0;
      var clips = /hidden|clip/.test(st.overflow + st.overflowX + st.overflowY);
      var panel = alpha >= 0.5 || st.backgroundImage !== 'none' || parseFloat(st.borderLeftWidth) >= 1 || parseFloat(st.borderTopWidth) >= 1;
      if (clips || (panel && r.width < W * 0.95 && r.width >= 2) || e.hasAttribute('data-wyv-fit-w')) {
        var pl = parseFloat(st.borderLeftWidth) + (inner ? parseFloat(st.paddingLeft) : 0), pr = parseFloat(st.borderRightWidth) + (inner ? parseFloat(st.paddingRight) : 0);
        c = { left: r.left + pl, top: r.top, right: r.right - pr, bottom: r.bottom };
        // A box morphed narrower later (WM.morph): fit to its narrowest size, centred where it is; a pill's rounded ends
        // are not room for words.
        var fw = parseFloat(e.getAttribute('data-wyv-fit-w'));
        if (fw > 0 && fw < r.width) {
          var mid = (r.left + r.right) / 2, fh = parseFloat(e.getAttribute('data-wyv-fit-h')) || r.height, fr = parseFloat(e.getAttribute('data-wyv-fit-r')) || 0;
          var round = inner && fr >= 0.3 * fh ? Math.min(fr, fh / 2) * 0.6 : 0;
          c.left = Math.max(c.left, mid - fw / 2 + pl + round); c.right = Math.min(c.right, mid + fw / 2 - pr - round);
        }
      }
    }
    if (!c) c = { left: 0, top: 0, right: W, bottom: H };
    var m = inner ? W * 0.04 : 0;
    return { left: Math.max(m, c.left), top: Math.max(0, c.top), right: Math.min(W - m, c.right), bottom: Math.min(H, c.bottom) };
  }
  // The box the words take, spaces left out: a wrapped line's trailing space hangs past the edge without showing.
  function wordsBox(el) {
    var b = null, walk = document.createTreeWalker(el, 4), node, m, rg = document.createRange(), re = /\S+/g;
    while ((node = walk.nextNode())) {
      re.lastIndex = 0;
      while ((m = re.exec(node.nodeValue))) {
        rg.setStart(node, m.index); rg.setEnd(node, m.index + m[0].length);
        var r = rg.getBoundingClientRect();
        if (r.width < 1 && r.height < 1) continue;
        b = b ? { left: Math.min(b.left, r.left), top: Math.min(b.top, r.top), right: Math.max(b.right, r.right), bottom: Math.max(b.bottom, r.bottom) } : { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
      }
    }
    if (b) b.width = b.right - b.left;
    return b || { left: 0, top: 0, right: 0, bottom: 0, width: 0 };
  }
  WM.wordsBox = wordsBox;
  function fits(el, inner) {
    var b = wordsBox(el), c = clipBox(el, inner);
    if (b.width < 2 || c.right - c.left < 2) return true;
    return b.left >= c.left - 2 && b.right <= c.right + 2;
  }
  // The elements holding a string field's text (the innermost holding all of it), outer ones before the ones inside.
  function fieldElements() {
    if (!document.body || !document.createRange) return [];
    var vals = fieldValues();
    if (!vals.length) return [];
    var norm = function (t) { return String(t || '').replace(/\s+/g, ' ').trim().toLowerCase(); };
    var all = document.body.querySelectorAll('*'), found = [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (/^(SCRIPT|STYLE|SVG|svg|CANVAS|VIDEO|IMG)$/.test(el.tagName)) continue;
      // Type cropped by the frame on purpose (giant wipes, or marked data-wyv-nofit by the page) is left as drawn.
      if (el.closest && el.closest('[data-wyv-nofit]')) continue;
      if (parseFloat(getComputedStyle(el).fontSize) >= Math.min(window.innerWidth, window.innerHeight) * 0.5) continue;
      var t = norm(el.textContent), field = isFieldText(t, vals);
      if (field < 0) continue;
      el.__wyvField = field;
      var inner = false;
      for (var k = 0; k < el.children.length; k++) if (norm(el.children[k].textContent) === t) { inner = true; break; }
      if (!inner) found.push(el);
    }
    // Outer first (document order): a phrase set smaller carries its words with it, and a word inside is fitted only if
    // it still runs out of its own box (each word of a headline in its own reveal mask: "GREEN JASPER" fitted the
    // frame as a whole while "JASPER" was cut by its mask, MOOSH, 2026-10-19).
    return found;
  }
  WM.fitFields = function () {
    var done = [], found = fieldElements();
    found.forEach(function (el) {
      // At rest: lift the inline transforms from the page down to its letters for the measurement (split letters start
      // below their mask for an entrance).
      // Every transform, written by GSAP or by the page's own CSS (a swinging price tag), so the box is the drawn one.
      var lifted = [], lift = function (e) {
        if (!e.style) return;
        lifted.push([e, e.style.transform, e.style.rotate, e.style.scale, e.style.translate]);
        e.style.transform = 'none'; e.style.rotate = 'none'; e.style.scale = 'none'; e.style.translate = 'none';
      };
      for (var e = el; e && e !== document.body; e = e.parentElement) lift(e);
      // A scene not shown yet (display:none until its time) has no size: shown, unseen, while it is measured.
      var shown = [];
      for (var h = el; h && h !== document.body; h = h.parentElement) if (getComputedStyle(h).display === 'none') { shown.push([h, h.style.display]); h.style.display = 'block'; }
      var inside = el.querySelectorAll('*');
      for (var j = 0; j < inside.length; j++) lift(inside[j]);
      try {
        var hidden = el.getBoundingClientRect();
        if (hidden.width < 1 && hidden.height < 1) return;
        // Each pass starts from the drawn size: a pass before the fonts loaded measured a wider stand-in face.
        el.__wyvScale = 0;
        if (el.dataset.wyvFitFrom) { el.style.fontSize = el.dataset.wyvFitPrior || ''; delete el.dataset.wyvFitFrom; delete el.dataset.wyvFitPrior; }
        if (fits(el)) return;
        var size = parseFloat(getComputedStyle(el).fontSize);
        el.dataset.wyvFitPrior = el.style.fontSize || '';
        if (!(size > 0)) return;
        el.dataset.wyvFitFrom = String(size);
        var lo = 0.3, hi = 1;
        for (var n = 0; n < 8; n++) { var mid = (lo + hi) / 2; el.style.fontSize = (size * mid) + 'px'; if (fits(el, true)) lo = mid; else hi = mid; }
        el.style.fontSize = (size * lo) + 'px';
        el.__wyvScale = lo;
        done.push({ text: el.textContent.slice(0, 40), scale: Math.round(lo * 100) / 100 });
      } finally {
        shown.forEach(function (x) { x[0].style.display = x[1]; });
        lifted.forEach(function (x) { x[0].style.transform = x[1]; x[0].style.rotate = x[2]; x[0].style.scale = x[3]; x[0].style.translate = x[4]; });
      }
    });
    // The words of one field drawn at one size stay at one size: a headline split over two lines shrinks together
    // ("GREEN" would keep 94% while "JASPER" needed 78%).
    found.forEach(function (el) {
      var size = parseFloat(el.dataset.wyvFitFrom || getComputedStyle(el).fontSize), least = 1;
      found.forEach(function (o) { if (o.__wyvField === el.__wyvField && o.__wyvScale && parseFloat(o.dataset.wyvFitFrom) === size) least = Math.min(least, o.__wyvScale); });
      if (least < (el.__wyvScale || 1)) {
        if (!el.dataset.wyvFitFrom) { el.dataset.wyvFitFrom = String(size); el.dataset.wyvFitPrior = el.style.fontSize || ''; }
        el.style.fontSize = (size * least) + 'px'; el.__wyvScale = least;
      }
    });
    return done;
  };
  if (document.addEventListener && document.readyState !== undefined) {
    var fitNow = function () { try { WM.fitFields(); } catch (e) {} };
    // A browser loads a face when visible text first uses it: a field in a later scene would be measured in a stand-in
    // face. Each field's face is asked for at once (the page's fonts-ready then waits for it), then the fields are fitted.
    var start = function () {
      fitNow();
      // The renderer writes a copy's own words after the page has loaded (the template's sample words first): fitted
      // again whenever text changes, once per burst of changes. Only text and elements are watched, so the sizes the
      // fit sets do not set it off again (MOOSH's "GREEN JASPER" rendered cut while the page first said "VOLT RUSH").
      try {
        if (window.MutationObserver) {
          var queued = false;
          new MutationObserver(function () {
            if (queued) return; queued = true;
            Promise.resolve().then(function () { queued = false; fitNow(); });
          }).observe(document.body || document.documentElement, { childList: true, characterData: true, subtree: true });
        }
      } catch (e) {}
      try {
        var loads = [];
        fieldElements().forEach(function (el) {
          var st = getComputedStyle(el);
          loads.push(document.fonts.load(st.fontStyle + ' ' + st.fontWeight + ' ' + st.fontSize + ' ' + st.fontFamily, el.textContent || 'x').catch(function () {}));
        });
        if (loads.length) Promise.all(loads).then(fitNow).then(fitNow);
      } catch (e) {}
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else setTimeout(start, 0);
    // Twice once fonts are ready: the second pass runs after a page's own fonts-ready callbacks have written their text.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNow).then(fitNow);
  }
  window.WM = WM;
})();
