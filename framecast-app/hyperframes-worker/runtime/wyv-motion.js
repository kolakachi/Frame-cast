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

    /* Typing: reveals text at cps characters a second with a caret.
       The element's final text is set by the timeline, so seeking anywhere is exact. */
    type: function (tl, el, text, at, cps) {
      el = $(el); cps = cps || 18;
      var state = { n: 0 };
      el.textContent = ''; // empty until the typing starts, wherever the playhead is seeked
      tl.to(state, { n: text.length, duration: text.length / cps, ease: 'none',
        onUpdate: function () { el.textContent = text.slice(0, Math.round(state.n)); } }, at);
      return tl;
    },

    /* Toggle: knob slides, track changes colour. */
    toggle: function (tl, track, knob, at, opts) {
      opts = opts || {};
      tl.to($(knob), { x: opts.distance || 28, duration: 0.4, ease: ease.snappy }, at);
      if (opts.on) tl.to($(track), { backgroundColor: opts.on, duration: 0.25, ease: 'power2.out' }, at);
      return tl;
    },

    /* Counter: counts to a value (use only approved numbers). */
    count: function (tl, el, from, to, at, duration, format) {
      el = $(el); var state = { v: from }; format = format || function (v) { return Math.round(v).toLocaleString('en-US'); };
      el.textContent = format(from);
      tl.to(state, { v: to, duration: duration || 1.2, ease: 'power3.out', onUpdate: function () { el.textContent = format(state.v); } }, at);
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
      tl.fromTo($(b), { clipPath: from, autoAlpha: 1 }, { clipPath: 'inset(0 0 0 0)', duration: 0.55, ease: 'expo.inOut' }, at);
      return tl;
    },
    push: function (tl, a, b, at, dir) {
      var axis = dir === 'up' || dir === 'down' ? 'yPercent' : 'xPercent', s = dir === 'right' || dir === 'down' ? 1 : -1;
      var o = {}, i = {}; o[axis] = 100 * s; i[axis] = -100 * s;
      tl.to($(a), Object.assign(o, { duration: 0.6, ease: 'expo.inOut' }), at)
        .fromTo($(b), Object.assign(i, { autoAlpha: 1 }), (function () { var e = { duration: 0.6, ease: 'expo.inOut' }; e[axis] = 0; return e; })(), at);
      return tl;
    },
    whip: function (tl, a, b, at) {
      // Fastest at the cut: ease in on the exit, ease out on the entry, blur in between.
      tl.to($(a), { xPercent: -60, filter: 'blur(24px)', autoAlpha: 0, duration: 0.28, ease: 'expo.in' }, at - 0.28)
        .fromTo($(b), { xPercent: 60, filter: 'blur(24px)', autoAlpha: 0 }, { xPercent: 0, filter: 'blur(0px)', autoAlpha: 1, duration: 0.42, ease: 'expo.out' }, at);
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
  };
  window.WM = WM;
})();
