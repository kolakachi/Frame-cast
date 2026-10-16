/* WyvStudio illustrations (RD-24): small flat animated pictures drawn in code, ours outright.
   Load after gsap.min.js. Each piece draws an SVG into an empty square box and animates on the timeline:
     WI.bag(tl, '#box', at, {palette})
   The palette follows the brand: ink (outlines), accent (the main colour), support (a light tint), paper (white
   parts), pop (a second colour for small highlights). Unset roles come from the page's --color_text, --color_accent
   and --color_background variables, then from the defaults below. Everything is seeded by index: no randomness, so
   every frame renders the same however it is reached. */
(function () {
  var gsap = window.gsap;
  function $(el) { return typeof el === 'string' ? document.querySelector(el) : el; }
  function cssVar(name) {
    try { var v = getComputedStyle(document.documentElement).getPropertyValue(name); return v && v.trim() ? v.trim() : null; } catch (e) { return null; }
  }
  function palette(opts) {
    var p = (opts && opts.palette) || {};
    return {
      ink: p.ink || cssVar('--color_text') || '#1E1E22',
      accent: p.accent || cssVar('--color_accent') || '#FF6B35',
      support: p.support || '#FFE3D6',
      paper: p.paper || '#FFFFFF',
      pop: p.pop || '#2E86DE'
    };
  }
  var uid = 0;
  // Draws the SVG into the box (once) and returns a lookup for its parts by data-p name.
  function draw(el, markup) {
    el = $(el);
    if (!el._wi) {
      el.innerHTML = '<svg viewBox="0 0 400 400" width="100%" height="100%" style="display:block;overflow:hidden">' + markup + '</svg>';
      el._wi = true;
    }
    return function (name) { return el.querySelectorAll('[data-p="' + name + '"]'); };
  }
  var S = 'stroke-linejoin="round" stroke-linecap="round"';

  var WI = {
    /* bag (mid, ~1.6 s): a shopping bag drops in, lands with a squash, and two items pop up out of it with motion
       lines. For a purchase, an order, a sale, "shop now". */
    bag: function (tl, el, at, opts) {
      opts = opts || {}; var c = palette(opts), id = 'wi' + (++uid);
      var p = draw(el,
        '<g data-p="lines" stroke="' + c.ink + '" stroke-width="8" ' + S + ' fill="none">' +
          '<path d="M78 150 L48 130"/><path d="M322 150 L352 130"/><path d="M70 205 L36 205"/><path d="M330 205 L364 205"/></g>' +
        '<g data-p="items">' +
          '<g transform="translate(160 190)"><g data-p="item"><rect x="-26" y="-70" width="52" height="96" rx="12" fill="' + c.pop + '" stroke="' + c.ink + '" stroke-width="8"/><rect x="-14" y="-92" width="28" height="26" rx="6" fill="' + c.paper + '" stroke="' + c.ink + '" stroke-width="8"/></g></g>' +
          '<g transform="translate(240 200)"><g data-p="item"><rect x="-36" y="-46" width="72" height="72" rx="10" fill="' + c.support + '" stroke="' + c.ink + '" stroke-width="8"/><path d="M-36 -14 H36 M0 -46 V26" stroke="' + c.ink + '" stroke-width="8" fill="none"/></g></g>' +
        '</g>' +
        '<g data-p="bag">' +
          '<path d="M150 175 C150 110 250 110 250 175" fill="none" stroke="' + c.ink + '" stroke-width="10" ' + S + '/>' +
          '<path d="M96 170 H304 L322 352 H78 Z" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="10" ' + S + '/>' +
          '<path d="M96 170 H304 L306 196 H94 Z" fill="' + c.ink + '" opacity=".18"/>' +
          '<circle cx="200" cy="262" r="26" fill="' + c.paper + '" stroke="' + c.ink + '" stroke-width="8"/>' +
          '<path d="M188 262 l9 9 l17 -19" fill="none" stroke="' + c.ink + '" stroke-width="8" ' + S + '/>' +
        '</g>' +
        '<ellipse data-p="shadow" cx="200" cy="362" rx="120" ry="12" fill="' + c.ink + '" opacity=".15"/>');
      var bag = p('bag'), items = p('item'), lines = p('lines'), shadow = p('shadow');
      gsap.set(shadow, { svgOrigin: '200 362' }); gsap.set(bag, { svgOrigin: '200 352' }); gsap.set(lines, { svgOrigin: '200 200' });
      tl.fromTo(bag, { y: -320, scaleX: 1, scaleY: 1 }, { y: 0, duration: 0.45, ease: 'power3.in', immediateRender: true }, at)
        .fromTo(shadow, { scaleX: 0.3, opacity: 0 }, { scaleX: 1, opacity: 0.15, duration: 0.45, ease: 'power3.in', immediateRender: true }, at)
        .to(bag, { scaleX: 1.12, scaleY: 0.86, duration: 0.09, ease: 'power2.out' }, at + 0.45)
        .to(bag, { scaleX: 1, scaleY: 1, duration: 0.5, ease: 'elastic.out(1, 0.45)' }, at + 0.54);
      Array.prototype.forEach.call(items, function (it, i) {
        tl.fromTo(it, { y: 110, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5, ease: 'back.out(2.2)', immediateRender: true }, at + 0.62 + i * 0.12);
      });
      tl.fromTo(lines, { opacity: 0, scale: 0.6 }, { opacity: 1, scale: 1, duration: 0.18, immediateRender: true }, at + 0.5)
        .to(lines, { opacity: 0, duration: 0.3 }, at + 0.85);
      return tl;
    },

    /* phone (mid, ~1.8 s): a phone buzzes, a notification slides down onto its screen and a badge pops with a count.
       For a message, an order coming in, an alert, "you've got a sale". opts.count: the badge number (1). */
    phone: function (tl, el, at, opts) {
      opts = opts || {}; var c = palette(opts);
      var p = draw(el,
        '<g data-p="waves" fill="none" stroke="' + c.accent + '" stroke-width="9" ' + S + '>' +
          '<path d="M300 110 a40 40 0 0 1 0 60"/><path d="M322 90 a70 70 0 0 1 0 100"/>' +
          '<path d="M100 230 a40 40 0 0 0 0 60"/><path d="M78 210 a70 70 0 0 0 0 100"/></g>' +
        '<g data-p="phone">' +
          '<rect x="125" y="50" width="150" height="300" rx="28" fill="' + c.ink + '"/>' +
          '<rect x="137" y="72" width="126" height="256" rx="16" fill="' + c.support + '"/>' +
          '<rect x="178" y="58" width="44" height="8" rx="4" fill="' + c.paper + '" opacity=".5"/>' +
          '<g data-p="note"><rect x="145" y="92" width="110" height="54" rx="12" fill="' + c.paper + '" stroke="' + c.ink + '" stroke-width="6"/>' +
            '<rect x="155" y="104" width="22" height="22" rx="6" fill="' + c.accent + '"/>' +
            '<rect x="184" y="106" width="60" height="7" rx="3.5" fill="' + c.ink + '"/><rect x="184" y="120" width="42" height="7" rx="3.5" fill="' + c.ink + '" opacity=".4"/></g>' +
          '<rect x="150" y="170" width="100" height="10" rx="5" fill="' + c.ink + '" opacity=".15"/><rect x="150" y="192" width="80" height="10" rx="5" fill="' + c.ink + '" opacity=".15"/><rect x="150" y="214" width="92" height="10" rx="5" fill="' + c.ink + '" opacity=".15"/>' +
        '</g>' +
        '<g data-p="badge"><circle cx="270" cy="62" r="30" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="7"/>' +
          '<text x="270" y="74" text-anchor="middle" font-family="Inter, sans-serif" font-weight="800" font-size="34" fill="' + c.paper + '">' + (opts.count == null ? 1 : opts.count) + '</text></g>');
      var phone = p('phone'), note = p('note'), badge = p('badge'), waves = p('waves');
      gsap.set(phone, { svgOrigin: '200 200' }); gsap.set(waves, { svgOrigin: '200 200' }); gsap.set(badge, { svgOrigin: '270 62' });
      // The buzz: a fixed rotation sequence, so every render shakes the same.
      var buzz = [-6, 6, -5, 5, -3, 3, 0];
      tl.fromTo(phone, { y: 40, opacity: 0 }, { y: 0, opacity: 1, duration: 0.35, ease: 'power3.out', immediateRender: true }, at);
      buzz.forEach(function (r, i) { tl.to(phone, { rotation: r, duration: 0.05, ease: 'none', svgOrigin: '200 200' }, at + 0.4 + i * 0.05); });
      tl.fromTo(waves, { opacity: 0, scale: 0.7 }, { opacity: 1, scale: 1, duration: 0.25, immediateRender: true }, at + 0.4)
        .to(waves, { opacity: 0, scale: 1.15, duration: 0.3 }, at + 0.7)
        .fromTo(note, { y: -70, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: 'back.out(1.8)', immediateRender: true }, at + 0.55)
        .fromTo(badge, { scale: 0 }, { scale: 1, duration: 0.4, ease: 'back.out(3)', immediateRender: true }, at + 0.85);
      return tl;
    },

    /* rocket (high, ~2 s): a rocket shakes on the pad, lifts off on a flickering flame through puffs of smoke and
       climbs past streaking stars. For a launch, growth, "take off", a big result. */
    rocket: function (tl, el, at, opts) {
      opts = opts || {}; var c = palette(opts);
      var puffs = '', stars = '', k;
      var px = [150, 250, 120, 280, 200], pr = [34, 30, 26, 24, 40];
      for (k = 0; k < px.length; k++) puffs += '<circle data-p="puff" cx="' + px[k] + '" cy="345" r="' + pr[k] + '" fill="' + c.paper + '" stroke="' + c.support + '" stroke-width="10"/>';
      var sx = [70, 330, 110, 300, 60, 340];
      for (k = 0; k < sx.length; k++) stars += '<path data-p="star" d="M' + sx[k] + ' ' + (60 + k * 40) + ' v36" stroke="' + c.pop + '" stroke-width="7" ' + S + '/>';
      var p = draw(el,
        '<g>' + stars + '</g>' +
        '<g data-p="rocket">' +
          '<g data-p="flame"><path d="M178 300 Q200 380 222 300 Z" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="7" ' + S + '/><path d="M190 300 Q200 345 210 300 Z" fill="' + c.paper + '"/></g>' +
          '<path d="M160 250 L120 300 L162 292 Z" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="8" ' + S + '/>' +
          '<path d="M240 250 L280 300 L238 292 Z" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="8" ' + S + '/>' +
          '<path d="M200 60 C250 110 255 220 240 300 H160 C145 220 150 110 200 60 Z" fill="' + c.paper + '" stroke="' + c.ink + '" stroke-width="9" ' + S + '/>' +
          '<path d="M200 60 C222 82 234 105 240 128 H160 C166 105 178 82 200 60 Z" fill="' + c.accent + '" stroke="' + c.ink + '" stroke-width="8" ' + S + '/>' +
          '<circle cx="200" cy="185" r="26" fill="' + c.support + '" stroke="' + c.ink + '" stroke-width="8"/>' +
        '</g>' +
        '<g>' + puffs + '</g>');
      var rocket = p('rocket'), flame = p('flame'), puff = p('puff'), star = p('star');
      // Pivots are set before any tween: an origin given only in a tween's end state shifts its start.
      gsap.set(flame, { svgOrigin: '200 300' });
      Array.prototype.forEach.call(puff, function (pf, i) { gsap.set(pf, { svgOrigin: px[i] + ' 345' }); });
      var shake = [-3, 3, -3, 3, -2, 2, 0];
      tl.fromTo(rocket, { y: 30, x: 0 }, { y: 30, duration: 0.01, immediateRender: true }, at);
      shake.forEach(function (x, i) { tl.to(rocket, { x: x, duration: 0.05, ease: 'none' }, at + i * 0.05); });
      // The flame flickers on a fixed beat while the rocket climbs.
      for (k = 0; k < 14; k++) tl.to(flame, { scaleY: k % 2 ? 0.8 : 1.25, duration: 0.07, ease: 'sine.inOut' }, at + 0.1 + k * 0.07);
      tl.to(rocket, { y: -480, duration: 1.2, ease: 'power3.in' }, at + 0.4);
      Array.prototype.forEach.call(puff, function (pf, i) {
        tl.fromTo(pf, { scale: 0.2, opacity: 0 }, { scale: 1.3, opacity: 1, duration: 0.5, ease: 'power2.out', immediateRender: true }, at + 0.35 + i * 0.06)
          .to(pf, { opacity: 0, y: 20, duration: 0.6, ease: 'power1.in' }, at + 0.95 + i * 0.06);
      });
      Array.prototype.forEach.call(star, function (s, i) {
        tl.fromTo(s, { y: -60, opacity: 0 }, { y: 260, opacity: 1, duration: 0.5, ease: 'none', immediateRender: true }, at + 0.7 + i * 0.12)
          .to(s, { opacity: 0, duration: 0.1 }, at + 1.1 + i * 0.12);
      });
      return tl;
    }
  };
  // ---- Shared helpers for the pieces below ----
  // A shape's fill, outline and width; outlines are round-jointed.
  function o(f, s, w) { return 'fill="' + f + '" stroke="' + s + '" stroke-width="' + w + '" ' + S; }
  function each(list, fn) { Array.prototype.forEach.call(list, fn); }
  // A stroke that draws itself on: pathLength 1, dash offset 1 to 0.
  function drawOn(tl, list, at, d, gap) {
    each(list, function (el, i) { tl.fromTo(el, { strokeDasharray: 1, strokeDashoffset: 1 }, { strokeDashoffset: 0, duration: d, ease: 'power1.inOut', immediateRender: true }, at + i * (gap || 0)); });
  }
  function pop(tl, list, at, gap, d) {
    each(list, function (el, i) { tl.fromTo(el, { scale: 0, opacity: 0 }, { scale: 1, opacity: 1, duration: d || 0.4, ease: 'back.out(2.4)', immediateRender: true }, at + i * (gap || 0)); });
  }
  // Pivots are set before any tween (an origin only in a tween's end state shifts its start).
  function pivot(list, xy) { each(list, function (el, i) { gsap.set(el, { svgOrigin: typeof xy === 'function' ? xy(i) : xy }); }); }
  function star(cx, cy, r) {
    var pts = [], k; for (k = 0; k < 10; k++) { var a = -Math.PI / 2 + k * Math.PI / 5, rr = k % 2 ? r * 0.45 : r; pts.push((cx + Math.cos(a) * rr).toFixed(1) + ' ' + (cy + Math.sin(a) * rr).toFixed(1)); }
    return 'M' + pts.join(' L') + ' Z';
  }
  function twinkle(cx, cy, r) { return 'M' + cx + ' ' + (cy - r) + ' Q' + cx + ' ' + cy + ' ' + (cx + r) + ' ' + cy + ' Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy + r) + ' Q' + cx + ' ' + cy + ' ' + (cx - r) + ' ' + cy + ' Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy - r) + ' Z'; }

  var MORE = {
    /* cart (mid, ~1.5 s): a cart rolls in on turning wheels and two items drop into it. Shopping, "add to cart". */
    cart: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="lines" stroke="' + c.ink + '" stroke-width="8" ' + S + '><path d="M40 210 H90"/><path d="M30 250 H80"/></g>' +
        '<g data-p="cart">' +
          '<g transform="translate(205 120)"><g data-p="item"><rect x="-22" y="-60" width="44" height="80" rx="10" ' + o(c.pop, c.ink, 8) + '/></g></g>' +
          '<g transform="translate(265 135)"><g data-p="item"><rect x="-30" y="-40" width="60" height="60" rx="8" ' + o(c.support, c.ink, 8) + '/></g></g>' +
          '<path d="M70 110 H112 L140 270 H300 L330 140 H122" ' + o(c.accent, c.ink, 10) + '/>' +
          '<path d="M135 205 H315" stroke="' + c.ink + '" stroke-width="8" fill="none"/>' +
          '<g data-p="wheel"><circle cx="160" cy="315" r="24" ' + o(c.paper, c.ink, 9) + '/><path d="M160 297 V333" stroke="' + c.ink + '" stroke-width="7"/></g>' +
          '<g data-p="wheel"><circle cx="280" cy="315" r="24" ' + o(c.paper, c.ink, 9) + '/><path d="M280 297 V333" stroke="' + c.ink + '" stroke-width="7"/></g>' +
        '</g>');
      var cart = p('cart'), wheels = p('wheel'), items = p('item'), lines = p('lines');
      pivot(wheels, function (i) { return (i ? 280 : 160) + ' 315'; });
      tl.fromTo(cart, { x: -440 }, { x: 0, duration: 0.65, ease: 'power3.out', immediateRender: true }, at);
      each(wheels, function (w) { tl.fromTo(w, { rotation: -720 }, { rotation: 0, duration: 0.65, ease: 'power3.out', immediateRender: true }, at); });
      tl.fromTo(lines, { opacity: 0, x: -60 }, { opacity: 1, x: 0, duration: 0.3, immediateRender: true }, at + 0.25).to(lines, { opacity: 0, duration: 0.25 }, at + 0.6);
      each(items, function (it, i) { tl.fromTo(it, { y: -260, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: 'bounce.out', immediateRender: true }, at + 0.7 + i * 0.15); });
      return tl;
    },

    /* parcel (mid, ~1.6 s): a parcel slides up to a front door and a tick pops above it. Delivery, "arrives tomorrow". */
    parcel: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<rect x="232" y="70" width="120" height="270" rx="8" ' + o(c.support, c.ink, 10) + '/>' +
        '<circle cx="330" cy="215" r="8" fill="' + c.ink + '"/>' +
        '<rect x="210" y="340" width="165" height="16" rx="8" fill="' + c.ink + '" opacity=".2"/>' +
        '<g data-p="box"><rect x="80" y="240" width="130" height="100" rx="8" ' + o(c.accent, c.ink, 9) + '/>' +
          '<path d="M80 270 H210 M145 240 V270" stroke="' + c.ink + '" stroke-width="8" fill="none"/>' +
          '<rect x="160" y="295" width="34" height="22" rx="4" fill="' + c.paper + '"/></g>' +
        '<g data-p="tick"><circle cx="145" cy="175" r="34" ' + o(c.pop, c.ink, 8) + '/><path d="M130 176 l11 11 l20 -23" fill="none" stroke="' + c.paper + '" stroke-width="9" ' + S + '/></g>');
      var box = p('box'), tick = p('tick');
      pivot(tick, '145 175'); pivot(box, '145 340');
      tl.fromTo(box, { x: -320, rotation: -8 }, { x: 0, rotation: 0, duration: 0.6, ease: 'power3.out', immediateRender: true }, at)
        .to(box, { scaleY: 0.92, scaleX: 1.05, duration: 0.08 }, at + 0.6).to(box, { scaleY: 1, scaleX: 1, duration: 0.35, ease: 'elastic.out(1,0.5)' }, at + 0.68);
      pop(tl, tick, at + 0.85, 0, 0.45);
      return tl;
    },

    /* gift (high, ~1.4 s): a gift box's lid pops off and sparkles burst out. A bonus, a free gift, a reward. */
    gift: function (tl, el, at, opts) {
      var c = palette(opts), sp = '', k, sx = [110, 290, 140, 260], sy = [120, 110, 70, 60];
      for (k = 0; k < 4; k++) sp += '<path data-p="spark" d="' + twinkle(sx[k], sy[k], 20) + '" ' + o(c.pop, c.ink, 5) + '/>';
      var p = draw(el, sp +
        '<rect x="110" y="200" width="180" height="150" rx="10" ' + o(c.accent, c.ink, 10) + '/>' +
        '<rect x="185" y="200" width="30" height="150" fill="' + c.support + '" stroke="' + c.ink + '" stroke-width="8"/>' +
        '<g data-p="lid"><rect x="95" y="160" width="210" height="48" rx="10" ' + o(c.accent, c.ink, 10) + '/>' +
          '<rect x="185" y="160" width="30" height="48" fill="' + c.support + '" stroke="' + c.ink + '" stroke-width="8"/>' +
          '<path d="M200 160 C170 120 130 130 150 158 Z M200 160 C230 120 270 130 250 158 Z" ' + o(c.support, c.ink, 8) + '/></g>');
      var lid = p('lid'), spark = p('spark');
      pivot(lid, '200 208'); pivot(spark, function (i) { return sx[i] + ' ' + sy[i]; });
      tl.fromTo(lid, { y: 0, rotation: 0 }, { y: -6, duration: 0.08, yoyo: true, repeat: 3, ease: 'none', immediateRender: true }, at)
        .to(lid, { y: -75, rotation: -14, duration: 0.45, ease: 'back.out(1.6)' }, at + 0.35);
      pop(tl, spark, at + 0.45, 0.06, 0.4);
      each(spark, function (s, i) { tl.to(s, { rotation: 45, scale: 0.75, duration: 0.5, ease: 'sine.inOut' }, at + 0.95 + i * 0.06); });
      return tl;
    },

    /* tag (mid, ~1.5 s): a price tag swings on its string and settles; a percent sign pops on it. A sale, a deal, pricing. */
    tag: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<circle cx="200" cy="60" r="12" fill="' + c.ink + '"/>' +
        '<g data-p="tag"><path d="M200 60 L200 120" stroke="' + c.ink + '" stroke-width="7"/>' +
          '<path d="M200 115 L270 165 V320 H130 V165 Z" ' + o(c.accent, c.ink, 10) + '/>' +
          '<circle cx="200" cy="160" r="12" ' + o(c.paper, c.ink, 7) + '/>' +
          '<g data-p="pct"><text x="200" y="280" text-anchor="middle" font-family="Inter, sans-serif" font-weight="900" font-size="96" fill="' + c.paper + '" stroke="' + c.ink + '" stroke-width="5" paint-order="stroke">%</text></g></g>');
      var tag = p('tag'), pct = p('pct');
      pivot(tag, '200 60'); pivot(pct, '200 250');
      var swing = [28, -20, 13, -8, 4, 0];
      tl.fromTo(tag, { rotation: 40 }, { rotation: swing[0], duration: 0.01, immediateRender: true }, at);
      swing.forEach(function (r, i) { tl.to(tag, { rotation: r, duration: 0.2, ease: 'sine.inOut' }, at + i * 0.2); });
      pop(tl, pct, at + 0.5, 0, 0.45);
      return tl;
    },

    /* chart (mid, ~1.6 s): bars grow in order and a rising arrow draws over them. Growth, sales up, results. */
    chart: function (tl, el, at, opts) {
      var c = palette(opts), bars = '', k, h = [70, 120, 170, 230], x = [95, 160, 225, 290];
      for (k = 0; k < 4; k++) bars += '<rect data-p="bar" x="' + (x[k] - 24) + '" y="' + (330 - h[k]) + '" width="48" height="' + h[k] + '" rx="6" ' + o(k === 3 ? c.accent : c.support, c.ink, 8) + '/>';
      var p = draw(el, '<path d="M50 60 V330 H360" fill="none" stroke="' + c.ink + '" stroke-width="10" ' + S + '/>' + bars +
        '<path data-p="line" pathLength="1" d="M80 240 L150 200 L215 215 L300 110" fill="none" stroke="' + c.pop + '" stroke-width="12" ' + S + '/>' +
        '<path data-p="head" d="M300 110 l-34 2 m34 -2 l-6 34" fill="none" stroke="' + c.pop + '" stroke-width="12" ' + S + '/>');
      var bar = p('bar'), line = p('line'), head = p('head');
      pivot(bar, function (i) { return x[i] + ' 330'; }); pivot(head, '300 110');
      each(bar, function (b, i) { tl.fromTo(b, { scaleY: 0 }, { scaleY: 1, duration: 0.45, ease: 'back.out(1.6)', immediateRender: true }, at + i * 0.12); });
      drawOn(tl, line, at + 0.55, 0.6);
      pop(tl, head, at + 1.1, 0, 0.3);
      return tl;
    },

    /* chat (mid, ~1.8 s): a message bubble pops in, a typing bubble bounces its dots, and a reply lands. A conversation,
       customer support, "just message us". */
    chat: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="b1"><path d="M60 80 H250 a20 20 0 0 1 20 20 V170 a20 20 0 0 1 -20 20 H110 L70 220 L80 190 H60 a20 20 0 0 1 -20 -20 V100 a20 20 0 0 1 20 -20 Z" ' + o(c.accent, c.ink, 9) + '/>' +
          '<rect x="70" y="115" width="150" height="12" rx="6" fill="' + c.paper + '"/><rect x="70" y="145" width="100" height="12" rx="6" fill="' + c.paper + '" opacity=".7"/></g>' +
        '<g data-p="typing"><rect x="190" y="250" width="130" height="64" rx="32" ' + o(c.support, c.ink, 8) + '/>' +
          '<circle data-p="dot" cx="225" cy="282" r="9" fill="' + c.ink + '"/><circle data-p="dot" cx="255" cy="282" r="9" fill="' + c.ink + '"/><circle data-p="dot" cx="285" cy="282" r="9" fill="' + c.ink + '"/></g>' +
        '<g data-p="b2"><path d="M150 240 H340 a20 20 0 0 1 20 20 V320 a20 20 0 0 1 -20 20 H330 L340 365 L300 340 H150 a20 20 0 0 1 -20 -20 V260 a20 20 0 0 1 20 -20 Z" ' + o(c.paper, c.ink, 9) + '/>' +
          '<rect x="160" y="272" width="160" height="12" rx="6" fill="' + c.ink + '" opacity=".7"/><rect x="160" y="300" width="110" height="12" rx="6" fill="' + c.ink + '" opacity=".35"/></g>');
      var b1 = p('b1'), typing = p('typing'), dot = p('dot'), b2 = p('b2');
      pivot(b1, '70 220'); pivot(typing, '255 314'); pivot(b2, '340 365');
      pop(tl, b1, at, 0, 0.45); pop(tl, typing, at + 0.45, 0, 0.3);
      each(dot, function (d, i) { for (var k = 0; k < 3; k++) tl.fromTo(d, { y: 0 }, { y: -12, duration: 0.12, yoyo: true, repeat: 1, ease: 'sine.inOut', immediateRender: false }, at + 0.7 + k * 0.3 + i * 0.08); });
      tl.to(typing, { scale: 0, opacity: 0, duration: 0.2 }, at + 1.6);
      pop(tl, b2, at + 1.65, 0, 0.45);
      return tl;
    },

    /* mail (mid, ~1.5 s): an envelope's flap opens and a letter rises out with a heart. An email, a newsletter, a note. */
    mail: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="open"><path d="M80 180 L200 95 L320 180 Z" ' + o(c.accent, c.ink, 10) + '/></g>' +
        '<g transform="translate(0 0)"><g data-p="letter"><rect x="120" y="150" width="160" height="150" rx="8" ' + o(c.paper, c.ink, 8) + '/>' +
          '<path d="M200 205 c-14 -20 -42 -6 -30 14 l30 26 l30 -26 c12 -20 -16 -34 -30 -14 Z" ' + o(c.accent, c.ink, 6) + '/></g></g>' +
        '<path d="M80 180 H320 V330 H80 Z" ' + o(c.support, c.ink, 10) + '/>' +
        '<path d="M80 330 L200 250 L320 330" fill="none" stroke="' + c.ink + '" stroke-width="9" ' + S + '/>' +
        '<g data-p="flap"><path d="M80 180 L200 265 L320 180 Z" ' + o(c.accent, c.ink, 10) + '/></g>');
      var flap = p('flap'), open = p('open'), letter = p('letter');
      pivot(flap, '200 180'); pivot(open, '200 180');
      tl.fromTo(flap, { scaleY: 1 }, { scaleY: 0, duration: 0.22, ease: 'power2.in', immediateRender: true }, at + 0.15)
        .fromTo(open, { scaleY: 0 }, { scaleY: 1, duration: 0.25, ease: 'power2.out', immediateRender: true }, at + 0.37)
        .fromTo(letter, { y: 60 }, { y: -120, duration: 0.6, ease: 'back.out(1.6)', immediateRender: true }, at + 0.6);
      return tl;
    },

    /* megaphone (high, ~1.4 s): a megaphone kicks up and sound waves pulse out twice. An announcement, "big news". */
    megaphone: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="arc" fill="none" stroke="' + c.accent + '" stroke-width="10" ' + S + '><path d="M300 150 q30 50 0 100"/></g>' +
        '<g data-p="arc" fill="none" stroke="' + c.accent + '" stroke-width="10" ' + S + '><path d="M330 120 q50 80 0 160"/></g>' +
        '<g data-p="mega"><path d="M90 170 L250 100 V300 L90 230 Z" ' + o(c.accent, c.ink, 10) + '/>' +
          '<rect x="60" y="165" width="40" height="70" rx="10" ' + o(c.support, c.ink, 9) + '/>' +
          '<path d="M120 230 L135 300 H170 L160 245" ' + o(c.support, c.ink, 9) + '/>' +
          '<rect x="245" y="95" width="22" height="210" rx="11" ' + o(c.paper, c.ink, 9) + '/></g>');
      var mega = p('mega'), arc = p('arc');
      pivot(mega, '160 200'); pivot(arc, '280 200');
      tl.fromTo(mega, { rotation: 12, scale: 0.8, opacity: 0 }, { rotation: -6, scale: 1, opacity: 1, duration: 0.35, ease: 'back.out(2)', immediateRender: true }, at)
        .to(mega, { rotation: 0, duration: 0.3, ease: 'sine.out' }, at + 0.35);
      [0, 1].forEach(function (r) { each(arc, function (a, i) { tl.fromTo(a, { opacity: 0, scale: 0.7 }, { opacity: 1, scale: 1.08, duration: 0.25, immediateRender: r === 0 }, at + 0.4 + r * 0.45 + i * 0.1).to(a, { opacity: 0, duration: 0.2 }, at + 0.65 + r * 0.45 + i * 0.1); }); });
      return tl;
    },

    /* rating (mid, ~1.4 s): five stars fill one after another with a pop. Reviews, "rated 5 stars" (only when approved). */
    rating: function (tl, el, at, opts) {
      var c = palette(opts), outl = '', fill = '', k, n = opts && opts.stars != null ? opts.stars : 5;
      for (k = 0; k < 5; k++) { var cx = 60 + k * 70; outl += '<path d="' + star(cx, 200, 32) + '" ' + o(c.paper, c.ink, 7) + '/>'; if (k < n) fill += '<path data-p="star" d="' + star(cx, 200, 32) + '" ' + o(c.accent, c.ink, 7) + '/>'; }
      var p = draw(el, outl + fill), st = p('star');
      pivot(st, function (i) { return (60 + i * 70) + ' 200'; });
      pop(tl, st, at, 0.16, 0.4);
      return tl;
    },

    /* thumbs (mid, ~1.1 s): a thumbs-up pops in with a twist and burst lines. Approval, "customers love it". */
    thumbs: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="burst" stroke="' + c.pop + '" stroke-width="10" ' + S + '><path d="M200 50 V80"/><path d="M90 110 L112 130"/><path d="M310 110 L288 130"/><path d="M60 220 H90"/><path d="M340 220 H310"/></g>' +
        '<g data-p="thumb"><rect x="90" y="190" width="56" height="130" rx="10" ' + o(c.support, c.ink, 9) + '/>' +
          '<path d="M146 200 L190 120 C200 100 235 105 228 140 L220 185 H290 C315 185 320 215 300 225 C318 235 312 260 292 262 C305 275 296 298 276 300 C285 318 268 330 250 330 H160 Q146 330 146 316 Z" ' + o(c.accent, c.ink, 9) + '/></g>');
      var thumb = p('thumb'), burst = p('burst');
      pivot(thumb, '200 260'); pivot(burst, '200 200');
      tl.fromTo(thumb, { scale: 0, rotation: -35 }, { scale: 1, rotation: 0, duration: 0.5, ease: 'back.out(2.6)', immediateRender: true }, at)
        .fromTo(burst, { scale: 0.6, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.2, immediateRender: true }, at + 0.35)
        .to(burst, { scale: 1.15, opacity: 0, duration: 0.3 }, at + 0.6);
      return tl;
    },

    /* bulb (mid, ~1.3 s): a light bulb switches on: it glows and rays shoot out. An idea, a tip, "the smart way". */
    bulb: function (tl, el, at, opts) {
      var c = palette(opts), rays = '', k;
      for (k = 0; k < 8; k++) { var a = k * Math.PI / 4 - Math.PI / 2; if (Math.abs(Math.sin(a) - 1) < 0.01) continue;
        rays += '<path data-p="ray" pathLength="1" d="M' + (200 + Math.cos(a) * 125).toFixed(1) + ' ' + (170 + Math.sin(a) * 125).toFixed(1) + ' L' + (200 + Math.cos(a) * 160).toFixed(1) + ' ' + (170 + Math.sin(a) * 160).toFixed(1) + '" stroke="' + c.accent + '" stroke-width="12" ' + S + '/>'; }
      var p = draw(el,
        '<circle data-p="glow" cx="200" cy="170" r="110" fill="' + c.accent + '" opacity=".25"/>' + rays +
        '<g data-p="bulb"><path d="M200 75 a95 95 0 0 1 55 172 V275 H145 V247 A95 95 0 0 1 200 75 Z" ' + o(c.paper, c.ink, 10) + '/>' +
          '<path data-p="fil" d="M175 245 V200 l25 -25 l25 25 V245" fill="none" stroke="' + c.ink + '" stroke-width="7" ' + S + '/>' +
          '<rect x="150" y="275" width="100" height="22" rx="6" ' + o(c.support, c.ink, 8) + '/><rect x="160" y="297" width="80" height="22" rx="6" ' + o(c.support, c.ink, 8) + '/></g>');
      var glow = p('glow'), ray = p('ray'), bulbBody = p('bulb').length ? p('bulb')[0].querySelector('path') : null;
      pivot(glow, '200 170');
      tl.fromTo(glow, { scale: 0, opacity: 0 }, { scale: 1, opacity: 0.25, duration: 0.4, ease: 'back.out(2)', immediateRender: true }, at + 0.2);
      if (bulbBody) tl.fromTo(bulbBody, { fill: c.paper }, { fill: c.support, duration: 0.15, immediateRender: true }, at + 0.2);
      drawOn(tl, ray, at + 0.3, 0.3, 0.04);
      tl.to(glow, { scale: 1.08, duration: 0.3, yoyo: true, repeat: 1, ease: 'sine.inOut' }, at + 0.7);
      return tl;
    },

    /* trophy (high, ~1.5 s): a trophy rises, a shine sweeps across it and confetti dots pop. A win, "number one", awards. */
    trophy: function (tl, el, at, opts) {
      var c = palette(opts), id = 'wic' + (++uid), dots = '', k, dx = [90, 320, 120, 290, 200], dy = [110, 100, 50, 45, 30];
      for (k = 0; k < 5; k++) dots += '<circle data-p="dot" cx="' + dx[k] + '" cy="' + dy[k] + '" r="10" fill="' + (k % 2 ? c.pop : c.accent) + '"/>';
      var cup = 'M130 90 H270 V150 A70 70 0 0 1 130 150 Z';
      var p = draw(el, dots + '<defs><clipPath id="' + id + '"><path d="' + cup + '"/></clipPath></defs>' +
        '<g data-p="trophy"><path d="M130 110 H90 a40 40 0 0 0 45 60 M270 110 H310 a40 40 0 0 1 -45 60" fill="none" stroke="' + c.ink + '" stroke-width="10" ' + S + '/>' +
          '<path d="' + cup + '" ' + o(c.accent, c.ink, 10) + '/>' +
          '<g clip-path="url(#' + id + ')"><rect data-p="shine" x="80" y="60" width="34" height="200" fill="' + c.paper + '" opacity=".6" transform="rotate(20 97 160)"/></g>' +
          '<path d="M185 218 H215 V265 H185 Z" ' + o(c.accent, c.ink, 9) + '/>' +
          '<rect x="140" y="262" width="120" height="30" rx="6" ' + o(c.support, c.ink, 9) + '/><rect x="120" y="290" width="160" height="34" rx="8" ' + o(c.ink, c.ink, 4) + '/></g>');
      var trophy = p('trophy'), shine = p('shine'), dot = p('dot');
      pivot(dot, function (i) { return dx[i] + ' ' + dy[i]; });
      tl.fromTo(trophy, { y: 120, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5, ease: 'back.out(1.8)', immediateRender: true }, at)
        .fromTo(shine, { x: -40 }, { x: 260, duration: 0.6, ease: 'power2.inOut', immediateRender: true }, at + 0.5);
      pop(tl, dot, at + 0.6, 0.07, 0.35);
      return tl;
    },

    /* plant (low, ~1.8 s): a sprout grows out of its pot and opens two leaves. Growth, care, sustainability. */
    plant: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<path data-p="stem" pathLength="1" d="M200 270 C200 220 195 180 205 120" fill="none" stroke="' + c.ink + '" stroke-width="10" ' + S + '/>' +
        '<path data-p="leaf" d="M200 200 C150 200 120 170 115 130 C160 130 195 150 200 200 Z" ' + o(c.accent, c.ink, 8) + '/>' +
        '<path data-p="leaf" d="M204 160 C250 160 285 130 290 90 C245 88 208 110 204 160 Z" ' + o(c.accent, c.ink, 8) + '/>' +
        '<path d="M120 260 H280 L260 350 H140 Z" ' + o(c.support, c.ink, 10) + '/><rect x="108" y="250" width="184" height="30" rx="8" ' + o(c.paper, c.ink, 9) + '/>');
      var stem = p('stem'), leaf = p('leaf');
      pivot(leaf, function (i) { return i ? '204 160' : '200 200'; });
      drawOn(tl, stem, at, 0.7);
      each(leaf, function (l, i) { tl.fromTo(l, { scale: 0, rotation: i ? -25 : 25 }, { scale: 1, rotation: 0, duration: 0.5, ease: 'back.out(2)', immediateRender: true }, at + 0.5 + i * 0.25); });
      return tl;
    },

    /* checklist (mid, ~1.5 s): three ticks draw into their boxes one after another. Steps done, features, "all set". */
    checklist: function (tl, el, at, opts) {
      var c = palette(opts), rows = '', k;
      for (k = 0; k < 3; k++) { var y = 115 + k * 75;
        rows += '<rect x="105" y="' + (y - 22) + '" width="44" height="44" rx="8" ' + o(c.paper, c.ink, 8) + '/>' +
          '<rect x="170" y="' + (y - 7) + '" width="' + (130 - k * 20) + '" height="14" rx="7" fill="' + c.ink + '" opacity=".25"/>' +
          '<path data-p="tick" pathLength="1" d="M114 ' + y + ' l10 11 l22 -26" fill="none" stroke="' + c.accent + '" stroke-width="10" ' + S + '/>'; }
      var p = draw(el, '<rect x="75" y="50" width="250" height="290" rx="16" ' + o(c.support, c.ink, 10) + '/>' +
        '<rect x="155" y="35" width="90" height="34" rx="10" ' + o(c.paper, c.ink, 8) + '/>' + rows);
      drawOn(tl, p('tick'), at + 0.15, 0.3, 0.35);
      return tl;
    },

    /* calendar (mid, ~1.4 s): a page tears off the calendar and a date on the next page is circled. A date, a deadline,
       "book a slot". */
    calendar: function (tl, el, at, opts) {
      var c = palette(opts), grid = '', k;
      for (k = 0; k < 12; k++) grid += '<rect x="' + (115 + (k % 4) * 45) + '" y="' + (175 + Math.floor(k / 4) * 45) + '' + '" width="28" height="22" rx="5" fill="' + c.ink + '" opacity=".22"/>';
      var p = draw(el,
        '<rect x="90" y="90" width="220" height="250" rx="16" ' + o(c.paper, c.ink, 10) + '/>' + grid +
        '<circle data-p="ring" pathLength="1" cx="219" cy="231" r="26" fill="none" stroke="' + c.accent + '" stroke-width="9" ' + S + ' transform="rotate(-90 219 231)"/>' +
        '<g data-p="page"><rect x="90" y="90" width="220" height="250" rx="16" ' + o(c.paper, c.ink, 10) + '/><rect x="140" y="170" width="120" height="110" rx="10" fill="' + c.support + '"/></g>' +
        '<path d="M90 106 a16 16 0 0 1 16 -16 H294 a16 16 0 0 1 16 16 V150 H90 Z" ' + o(c.accent, c.ink, 10) + '/>' +
        '<rect x="140" y="70" width="18" height="44" rx="9" fill="' + c.ink + '"/><rect x="242" y="70" width="18" height="44" rx="9" fill="' + c.ink + '"/>');
      var page = p('page'), ring = p('ring');
      pivot(page, '200 150');
      tl.fromTo(page, { y: 0, rotation: 0, opacity: 1 }, { y: 260, rotation: 22, opacity: 0, duration: 0.55, ease: 'power2.in', immediateRender: true }, at + 0.15);
      drawOn(tl, ring, at + 0.75, 0.45);
      return tl;
    },

    /* coffee (low, ~2 s): a cup on its saucer with steam curling up. A break, mornings, cafes, "while you have a coffee". */
    coffee: function (tl, el, at, opts) {
      var c = palette(opts), steam = '', k;
      for (k = 0; k < 3; k++) steam += '<path data-p="steam" pathLength="1" d="M' + (160 + k * 40) + ' 175 c-18 -25 18 -40 0 -65 c-14 -20 10 -30 0 -45" fill="none" stroke="' + c.ink + '" stroke-width="9" opacity=".55" ' + S + '/>';
      var p = draw(el, steam +
        '<ellipse cx="200" cy="320" rx="135" ry="22" ' + o(c.support, c.ink, 9) + '/>' +
        '<path d="M290 220 a35 35 0 0 1 0 70 h-15" fill="none" stroke="' + c.ink + '" stroke-width="12" ' + S + '/>' +
        '<path d="M100 190 H300 V250 A70 70 0 0 1 230 320 H170 A70 70 0 0 1 100 250 Z" ' + o(c.accent, c.ink, 10) + '/>' +
        '<rect x="100" y="190" width="200" height="16" fill="' + c.ink + '" opacity=".2"/>');
      var s = p('steam');
      each(s, function (el2, i) { [0, 1].forEach(function (r) {
        tl.fromTo(el2, { strokeDasharray: 1, strokeDashoffset: 1, y: 0, opacity: 0.55 }, { strokeDashoffset: 0, y: -18, duration: 0.6, ease: 'sine.out', immediateRender: r === 0 }, at + r * 0.9 + i * 0.15)
          .to(el2, { opacity: 0, y: -40, duration: 0.3, ease: 'sine.in' }, at + 0.6 + r * 0.9 + i * 0.15);
      }); });
      return tl;
    },

    /* clock (mid, ~1.4 s): a clock's hands spin fast, then settle. Speed, "in minutes", saving time. */
    clock: function (tl, el, at, opts) {
      var c = palette(opts), ticks = '', k;
      for (k = 0; k < 12; k++) { var a = k * Math.PI / 6; ticks += '<path d="M' + (200 + Math.sin(a) * 110).toFixed(1) + ' ' + (200 - Math.cos(a) * 110).toFixed(1) + ' L' + (200 + Math.sin(a) * (k % 3 ? 100 : 92)).toFixed(1) + ' ' + (200 - Math.cos(a) * (k % 3 ? 100 : 92)).toFixed(1) + '" stroke="' + c.ink + '" stroke-width="' + (k % 3 ? 5 : 8) + '" ' + S + '/>'; }
      var p = draw(el, '<g data-p="clock"><circle cx="200" cy="200" r="135" ' + o(c.paper, c.ink, 12) + '/><circle cx="200" cy="200" r="120" fill="' + c.support + '" opacity=".5"/>' + ticks +
        '<path data-p="hour" d="M200 200 V135" stroke="' + c.ink + '" stroke-width="12" ' + S + '/>' +
        '<path data-p="minute" d="M200 200 V100" stroke="' + c.accent + '" stroke-width="9" ' + S + '/>' +
        '<circle cx="200" cy="200" r="12" fill="' + c.ink + '"/></g>' +
        '<rect x="180" y="40" width="40" height="26" rx="6" ' + o(c.accent, c.ink, 8) + '/>');
      var clock = p('clock'), hour = p('hour'), minute = p('minute');
      pivot(hour, '200 200'); pivot(minute, '200 200'); pivot(clock, '200 200');
      tl.fromTo(minute, { rotation: 0 }, { rotation: 1080, duration: 1.1, ease: 'power2.inOut', immediateRender: true }, at)
        .fromTo(hour, { rotation: 0 }, { rotation: 90, duration: 1.1, ease: 'power2.inOut', immediateRender: true }, at)
        .to(clock, { scale: 1.06, duration: 0.12, yoyo: true, repeat: 1, ease: 'sine.inOut' }, at + 1.1);
      return tl;
    },

    /* pin (mid, ~1.3 s): a map pin drops onto a little map, bounces, and a ripple spreads under it. A location, "near you",
       store finder. */
    pin: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<path d="M60 250 L150 210 L250 250 L340 210 V330 L250 370 L150 330 L60 370 Z" ' + o(c.support, c.ink, 9) + '/>' +
        '<path d="M150 210 V330 M250 250 V370" stroke="' + c.ink + '" stroke-width="7" opacity=".4"/>' +
        '<ellipse data-p="ripple" cx="200" cy="300" rx="60" ry="18" fill="none" stroke="' + c.accent + '" stroke-width="8"/>' +
        '<g data-p="pin"><path d="M200 300 C160 240 140 210 140 175 a60 60 0 0 1 120 0 C260 210 240 240 200 300 Z" ' + o(c.accent, c.ink, 10) + '/>' +
          '<circle cx="200" cy="175" r="22" ' + o(c.paper, c.ink, 8) + '/></g>');
      var pin = p('pin'), ripple = p('ripple');
      pivot(pin, '200 300'); pivot(ripple, '200 300');
      tl.fromTo(pin, { y: -300 }, { y: 0, duration: 0.55, ease: 'bounce.out', immediateRender: true }, at)
        .fromTo(ripple, { scale: 0.2, opacity: 0 }, { scale: 1.3, opacity: 1, duration: 0.4, ease: 'power2.out', immediateRender: true }, at + 0.4)
        .to(ripple, { opacity: 0, scale: 1.7, duration: 0.4 }, at + 0.8);
      return tl;
    },

    /* lock (mid, ~1.2 s): a padlock jiggles and its shackle springs open. Unlock, access, "get started", security. */
    lock: function (tl, el, at, opts) {
      var c = palette(opts), p = draw(el,
        '<g data-p="shackle"><path d="M145 200 V140 a55 55 0 0 1 110 0 V200" fill="none" stroke="' + c.ink + '" stroke-width="22" ' + S + '/>' +
          '<path d="M145 200 V140 a55 55 0 0 1 110 0 V200" fill="none" stroke="' + c.support + '" stroke-width="10" ' + S + '/></g>' +
        '<g data-p="body"><rect x="110" y="190" width="180" height="150" rx="20" ' + o(c.accent, c.ink, 10) + '/>' +
          '<circle cx="200" cy="250" r="18" fill="' + c.ink + '"/><path d="M200 255 V295" stroke="' + c.ink + '" stroke-width="14" ' + S + '/></g>');
      var shackle = p('shackle'), body = p('body');
      pivot(body, '200 340'); pivot(shackle, '255 160');
      [-5, 5, -4, 4, 0].forEach(function (r, i) { tl.to(body, { rotation: r, duration: 0.06, ease: 'none' }, at + i * 0.06); });
      tl.fromTo(shackle, { y: 0, rotation: 0 }, { y: -40, duration: 0.2, ease: 'power2.out', immediateRender: true }, at + 0.35)
        .to(shackle, { rotation: 12, x: -6, duration: 0.4, ease: 'back.out(2)' }, at + 0.55);
      return tl;
    },

    /* search (mid, ~1.6 s): a magnifier sweeps across a page and stops on a line, which lights up. Search, "find it fast". */
    search: function (tl, el, at, opts) {
      var c = palette(opts), lines = '', k;
      for (k = 0; k < 5; k++) lines += '<rect x="110" y="' + (95 + k * 45) + '" width="' + [180, 150, 170, 120, 160][k] + '" height="14" rx="7" fill="' + c.ink + '" opacity=".22"/>';
      var p = draw(el, '<rect x="80" y="60" width="240" height="290" rx="14" ' + o(c.paper, c.ink, 10) + '/>' +
        '<rect data-p="hit" x="102" y="174" width="186" height="32" rx="8" fill="' + c.accent + '" opacity=".5"/>' + lines +
        '<g data-p="glass"><circle cx="0" cy="0" r="48" ' + o(c.support, c.ink, 11) + ' fill-opacity=".55"/><path d="M34 34 L80 80" stroke="' + c.ink + '" stroke-width="18" ' + S + '/></g>');
      var glass = p('glass'), hit = p('hit');
      pivot(hit, '102 190');
      gsap.set(glass, { x: 140, y: 120 });
      tl.fromTo(glass, { x: 140, y: 120 }, { keyframes: [{ x: 260, y: 140, duration: 0.4 }, { x: 150, y: 240, duration: 0.4 }, { x: 205, y: 190, duration: 0.35 }], ease: 'sine.inOut', immediateRender: true }, at)
        .fromTo(hit, { scaleX: 0 }, { scaleX: 1, duration: 0.35, ease: 'power2.out', immediateRender: true }, at + 1.15);
      return tl;
    },

    /* sparkle (low, ~1.4 s): three sparkles twinkle in and turn. Magic, "like new", AI, shine. */
    sparkle: function (tl, el, at, opts) {
      var c = palette(opts), sp = '', k, x = [200, 120, 285], y = [190, 110, 290], r = [110, 50, 60];
      for (k = 0; k < 3; k++) sp += '<path data-p="spark" d="' + twinkle(x[k], y[k], r[k]) + '" ' + o(k ? c.pop : c.accent, c.ink, 9) + '/>';
      var p = draw(el, sp), spark = p('spark');
      pivot(spark, function (i) { return x[i] + ' ' + y[i]; });
      each(spark, function (s, i) {
        tl.fromTo(s, { scale: 0, rotation: -45 }, { scale: 1, rotation: 0, duration: 0.45, ease: 'back.out(2.4)', immediateRender: true }, at + i * 0.18)
          .to(s, { scale: 0.85, duration: 0.3, yoyo: true, repeat: 1, ease: 'sine.inOut' }, at + 0.5 + i * 0.18);
      });
      return tl;
    },

    /* money (mid, ~1.5 s): coins drop one by one into a growing stack. Savings, earnings, "keep more of your money". */
    money: function (tl, el, at, opts) {
      var c = palette(opts), coins = '', k;
      for (k = 0; k < 5; k++) { var y = 320 - k * 34;
        coins += '<g data-p="coin"><ellipse cx="200" cy="' + (y + 10) + '" rx="85" ry="26" ' + o(c.accent, c.ink, 9) + '/>' +
          '<ellipse cx="200" cy="' + y + '" rx="85" ry="26" ' + o(c.support, c.ink, 9) + '/><ellipse cx="200" cy="' + y + '" rx="52" ry="14" fill="none" stroke="' + c.ink + '" stroke-width="6" opacity=".4"/></g>'; }
      var p = draw(el, '<ellipse cx="200" cy="350" rx="110" ry="16" fill="' + c.ink + '" opacity=".15"/>' + coins), coin = p('coin');
      each(coin, function (cn, i) { tl.fromTo(cn, { y: -360, opacity: 0 }, { y: 0, opacity: 1, duration: 0.4, ease: 'bounce.out', immediateRender: true }, at + i * 0.2); });
      return tl;
    }
  };
  for (var name in MORE) WI[name] = MORE[name];

  // The names the builder can use, in the order a brief is likely to need them.
  WI.names = Object.keys(WI).filter(function (k) { return typeof WI[k] === 'function'; });
  window.WI = WI;
})();
