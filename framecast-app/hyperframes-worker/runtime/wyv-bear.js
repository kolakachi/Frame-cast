/* WyvBear: the WyvStudio bear drawn as layered SVG, a drop-in for W3D.mascot. Load after wyv-3d.js; every
 * W3D.mascot(canvas, {words, active, place, expressions, blinks}) call then draws this bear in that canvas's box.
 * Each frame is a pure function of time (driven by W3D.clock), so any seek draws exactly the same picture:
 * the mouth opens per syllable of each word (shaped by its vowel), blinks are seeded, expressions ('smile',
 * 'laugh', 'wink', 'surprised', gaze [x, y]) ease in and out, and the ears lag behind the head's movement. */
(function () {
  'use strict';
  if (!window.W3D) throw new Error('wyv-bear.js needs wyv-3d.js loaded first');
  var NS = 'http://www.w3.org/2000/svg', made = 0;

  // Shared paints and filters, once per page.
  function defs() {
    if (document.getElementById('wb-defs')) return;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('id', 'wb-defs'); svg.setAttribute('width', '0'); svg.setAttribute('height', '0'); svg.style.position = 'absolute';
    svg.innerHTML = '<defs>'
      + '<radialGradient id="wb-fur" cx="42%" cy="30%" r="75%"><stop offset="0" stop-color="#f4ad63"/><stop offset=".55" stop-color="#e2893c"/><stop offset="1" stop-color="#b9611f"/></radialGradient>'
      + '<radialGradient id="wb-furEar" cx="45%" cy="35%" r="70%"><stop offset="0" stop-color="#f0a259"/><stop offset="1" stop-color="#c96a26"/></radialGradient>'
      + '<radialGradient id="wb-earIn" cx="50%" cy="55%" r="60%"><stop offset="0" stop-color="#e0864a"/><stop offset="1" stop-color="#b8602a"/></radialGradient>'
      + '<radialGradient id="wb-iris" cx="45%" cy="40%" r="60%"><stop offset="0" stop-color="#1c120c"/><stop offset=".55" stop-color="#3a2516"/><stop offset=".85" stop-color="#7a5434"/><stop offset="1" stop-color="#a8814f"/></radialGradient>'
      + '<radialGradient id="wb-cheek" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#ff6f9a" stop-opacity=".62"/><stop offset="1" stop-color="#ff6f9a" stop-opacity="0"/></radialGradient>'
      + '<radialGradient id="wb-nose" cx="40%" cy="30%" r="80%"><stop offset="0" stop-color="#6a3a72"/><stop offset="1" stop-color="#2c1233"/></radialGradient>'
      + '<linearGradient id="wb-mouth" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4a1d1d"/><stop offset="1" stop-color="#a8495a"/></linearGradient>'
      + '<linearGradient id="wb-under" x1="0" y1="0" x2="0" y2="1"><stop offset=".55" stop-color="#7a3a12" stop-opacity="0"/><stop offset="1" stop-color="#7a3a12" stop-opacity=".35"/></linearGradient>'
      + '<filter id="wb-fluff" x="-15%" y="-15%" width="130%" height="130%"><feTurbulence type="fractalNoise" baseFrequency="0.42" numOctaves="3" seed="7" result="n"/>'
      + '<feDisplacementMap in="SourceGraphic" in2="n" scale="15" xChannelSelector="R" yChannelSelector="G" result="d"/><feGaussianBlur in="d" stdDeviation="1.1"/></filter>'
      + '<filter id="wb-strands" x="0" y="0" width="100%" height="100%"><feTurbulence type="fractalNoise" baseFrequency="0.55 0.12" numOctaves="2" seed="3"/>'
      + '<feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 .85  0 0 0 0 .6  0 0 0 .9 -.25"/></filter>'
      + '<clipPath id="wb-headClip"><ellipse cx="385" cy="660" rx="350" ry="372"/></clipPath>'
      + '<clipPath id="wb-eyeL"><circle cx="265" cy="578" r="58"/></clipPath><clipPath id="wb-eyeR"><circle cx="507" cy="578" r="58"/></clipPath>'
      + '</defs>';
    document.body.appendChild(svg);
  }

  // Seeded fur tufts round an ellipse (drawn once).
  function tufts(cx, cy, rx, ry, n, seed, colors) {
    var s = seed, rnd = function () { s = (s * 16807) % 2147483647; return s / 2147483647; }, out = '';
    for (var i = 0; i < n; i++) {
      var a = Math.PI * 2 * (i + rnd() * 0.8) / n, len = 14 + rnd() * 20, w = 7 + rnd() * 7, bend = (rnd() - 0.5) * 0.5;
      var x = cx + Math.cos(a) * (rx - 6), y = cy + Math.sin(a) * (ry - 6), ox = Math.cos(a + bend), oy = Math.sin(a + bend);
      var tx = x + ox * len, ty = y + oy * len, px = -Math.sin(a) * w / 2, py = Math.cos(a) * w / 2;
      out += '<path d="M' + (x + px) + ' ' + (y + py) + ' Q' + ((x + tx) / 2 + px * 0.6) + ' ' + ((y + ty) / 2 + py * 0.6) + ' ' + tx + ' ' + ty
        + ' Q' + ((x + tx) / 2 - px * 0.6) + ' ' + ((y + ty) / 2 - py * 0.6) + ' ' + (x - px) + ' ' + (y - py) + 'Z" fill="' + colors[Math.floor(rnd() * colors.length)] + '"/>';
    }
    return out;
  }

  function eye(side, x) {
    var ix = x - 3;
    return '<g class="wb-eye-' + side + '"><circle cx="' + x + '" cy="578" r="58" fill="#f6efe6"/>'
      + '<g class="wb-look"><circle cx="' + ix + '" cy="578" r="52" fill="url(#wb-iris)"/><circle cx="' + ix + '" cy="576" r="29" fill="#0d0805"/>'
      + '<ellipse cx="' + (ix - 19) + '" cy="556" rx="15" ry="11" fill="#fff" opacity=".95"/>'
      + '<path d="M' + (ix + 24) + ' 552 l4 10 10 4 -10 4 -4 10 -4-10 -10-4 10-4z" fill="#fff"/><circle cx="' + (ix + 14) + '" cy="604" r="5" fill="#fff" opacity=".6"/></g>'
      + '<g clip-path="url(#wb-eye' + (side === 'l' ? 'L' : 'R') + ')"><ellipse class="wb-lid" cx="' + x + '" cy="578" rx="64" ry="64" fill="#e08a3f"/>'
      + '<path class="wb-lash" d="M' + (x - 46) + ' 590 Q' + x + ' 612 ' + (x + 46) + ' 590" stroke="#6b3412" stroke-width="5" stroke-linecap="round" fill="none"/>'
      + '<ellipse class="wb-lower" cx="' + x + '" cy="680" rx="70" ry="40" fill="#e3903f"/></g></g>';
  }

  function markup(seed) {
    return '<g class="wb-root"><g class="wb-earL">' + tufts(152, 390, 108, 108, 70, seed + 2, ['#e8954a', '#d07530', '#f0a259'])
      + '<circle cx="152" cy="390" r="108" fill="url(#wb-furEar)" filter="url(#wb-fluff)"/><circle cx="168" cy="405" r="62" fill="url(#wb-earIn)"/></g>'
      + '<g class="wb-earR">' + tufts(618, 390, 108, 108, 70, seed + 3, ['#e8954a', '#d07530', '#f0a259'])
      + '<circle cx="618" cy="390" r="108" fill="url(#wb-furEar)" filter="url(#wb-fluff)"/><circle cx="602" cy="405" r="62" fill="url(#wb-earIn)"/></g>'
      + '<g class="wb-head">' + tufts(385, 660, 350, 372, 260, seed + 1, ['#e48d40', '#d77c34', '#ef9f55', '#c96c27'])
      + '<ellipse cx="385" cy="660" rx="350" ry="372" fill="url(#wb-fur)" filter="url(#wb-fluff)"/>'
      + '<rect x="35" y="288" width="700" height="745" filter="url(#wb-strands)" clip-path="url(#wb-headClip)" opacity=".55" style="mix-blend-mode:soft-light"/>'
      + '<ellipse cx="385" cy="660" rx="350" ry="372" fill="url(#wb-under)" clip-path="url(#wb-headClip)"/>'
      + '<ellipse cx="385" cy="712" rx="128" ry="86" fill="#f3ad6a" opacity=".45" filter="url(#wb-fluff)"/></g>'
      + '<g class="wb-face"><ellipse class="wb-cheek" cx="190" cy="692" rx="92" ry="64" fill="url(#wb-cheek)"/><ellipse class="wb-cheek" cx="580" cy="692" rx="92" ry="64" fill="url(#wb-cheek)"/>'
      + eye('l', 265) + eye('r', 507)
      + '<path d="M385 690 C352 668 330 650 336 630 C342 610 370 610 385 628 C400 610 428 610 434 630 C440 650 418 668 385 690Z" fill="url(#wb-nose)"/>'
      + '<ellipse cx="368" cy="628" rx="13" ry="7" fill="#fff" opacity=".22"/>'
      + '<path d="M385 690 L385 728" stroke="#7a3f1f" stroke-width="4" stroke-linecap="round" fill="none"/>'
      + '<path class="wb-mouth" fill="url(#wb-mouth)"/><path class="wb-smile" stroke="#7a3f1f" stroke-width="5" stroke-linecap="round" fill="none"/></g></g>';
  }

  // Mouth and smile shapes share one command structure, so any blend of them is a valid path.
  var MOUTH = {
    closed: [367, 735, 385, 738, 403, 735, 401, 738, 385, 739, 369, 738, 367, 735],
    A: [352, 732, 385, 726, 418, 732, 414, 792, 385, 796, 356, 792, 352, 732],
    O: [366, 732, 385, 724, 404, 732, 410, 772, 385, 780, 360, 772, 366, 732],
    E: [342, 733, 385, 729, 428, 733, 421, 760, 385, 763, 349, 760, 342, 733]
  };
  var SMILE = [[318, 716, 350, 746, 385, 728, 420, 746, 452, 716], [300, 708, 345, 770, 385, 738, 425, 770, 470, 708]];
  function mix(a, b, k) { return a.map(function (v, i) { return v + (b[i] - v) * k; }); }
  function mouthPath(n) { return 'M' + n[0] + ' ' + n[1] + ' Q' + n[2] + ' ' + n[3] + ' ' + n[4] + ' ' + n[5] + ' Q' + n[6] + ' ' + n[7] + ' ' + n[8] + ' ' + n[9] + ' Q' + n[10] + ' ' + n[11] + ' ' + n[12] + ' ' + n[13] + 'Z'; }
  function smilePath(n) { return 'M' + n[0] + ' ' + n[1] + ' Q' + n[2] + ' ' + n[3] + ' ' + n[4] + ' ' + n[5] + ' Q' + n[6] + ' ' + n[7] + ' ' + n[8] + ' ' + n[9]; }

  // How open the mouth is, and its shape, at t: each word opens once per syllable, shaped by that syllable's vowel.
  function speech(words, t) {
    for (var i = 0; i < words.length; i++) {
      var w = words[i], text = String(w.text || w[0] || ''), a = Number(w.start != null ? w.start : w[1]), b = Number(w.end != null ? w.end : w[2]);
      if (!(t >= a && t < b) || b <= a) continue;
      var vowels = text.toLowerCase().match(/[aeiouy]+/g) || ['a'], n = vowels.length, p = (t - a) / (b - a) * n, k = Math.min(n - 1, Math.floor(p));
      var v = vowels[k], shape = /[ou]/.test(v) ? 'O' : /[eiy]/.test(v) ? 'E' : 'A';
      return {open: Math.sin(Math.PI * (p - k)), shape: shape};
    }
    return {open: 0, shape: 'A'};
  }
  // An expression's weight at t: eases in over .12 s, holds, eases out over .18 s.
  function weight(x, t) {
    var a = x.at, b = x.at + (x.duration || 0.5);
    if (t < a || t > b + 0.18) return 0;
    if (t < a + 0.12) return (t - a) / 0.12;
    if (t > b) return 1 - (t - b) / 0.18;
    return 1;
  }
  function autoBlinks(seed, from) {
    var s = seed, rnd = function () { s = (s * 16807) % 2147483647; return s / 2147483647; }, out = [], t = from + 0.6;
    while (t < 60) { out.push(t); t += 2.4 + rnd() * 2.2; }
    return out;
  }
  function lidAt(times, t) {
    for (var i = 0; i < times.length; i++) { var d = t - times[i]; if (d >= 0 && d < 0.07) return d / 0.07; if (d >= 0.07 && d < 0.17) return 1 - (d - 0.07) / 0.1; }
    return 0;
  }

  W3D.mascot = function (sel, opts) {
    defs();
    var canvas = typeof sel === 'string' ? document.querySelector(sel) : sel;
    if (!canvas) throw new Error('WyvBear: nothing matches ' + sel);
    if (typeof opts.place !== 'function') throw new Error('WyvBear ' + sel + ': place must be a function of t returning {cx, cy, r}');
    var w = Number(canvas.getAttribute('width')) || canvas.offsetWidth, h = Number(canvas.getAttribute('height')) || canvas.offsetHeight;
    // The bear takes the canvas's place, id and slot, so the composition's own code still finds it.
    var box = document.createElementNS(NS, 'svg');
    for (var i = 0; i < canvas.attributes.length; i++) { var at = canvas.attributes[i]; if (at.name !== 'width' && at.name !== 'height') box.setAttribute(at.name, at.value); }
    box.setAttribute('viewBox', '0 0 ' + w + ' ' + h); box.setAttribute('width', w); box.setAttribute('height', h);
    box.style.position = 'absolute'; box.style.overflow = 'visible';
    box.innerHTML = markup(37 + (made++) * 11);
    canvas.parentNode.replaceChild(box, canvas);
    var q = function (c) { return box.querySelector(c); }, qa = function (c) { return box.querySelectorAll(c); };
    var root = q('.wb-root'), face = q('.wb-face'), earL = q('.wb-earL'), earR = q('.wb-earR'), head = q('.wb-head');
    var mouth = q('.wb-mouth'), smile = q('.wb-smile'), lids = qa('.wb-lid'), lashes = qa('.wb-lash'), lowers = qa('.wb-lower'), looks = qa('.wb-look'), cheeks = qa('.wb-cheek');
    var words = opts.words || [], expr = opts.expressions || [], active = opts.active || null;
    var blinks = opts.blinks || autoBlinks(1595 + made * 7, active ? active[0] : 0);
    W3D.stages.push({render: function (t) {
      var on = !active || (t >= active[0] && t <= active[1]);
      box.style.visibility = on ? 'visible' : 'hidden';
      if (!on) return;
      // opts.size: the bear's head against the slot's head radius (it has no body, so it fills the slot a little larger).
      var p = opts.place(t), before = opts.place(Math.max(0, t - 0.08)), k = (p.r || 175) / 350 * (opts.size || 1.3);
      var tilt = (p.tilt || 0) * 57.3 * 0.6, yaw = p.yaw || 0, pitch = p.pitch || 0;
      root.setAttribute('transform', 'translate(' + (p.cx - 385 * k) + ' ' + (p.cy - 660 * k) + ') scale(' + k + ') rotate(' + tilt + ' 385 660)');
      // A flat drawing turns by sliding its face; the ears lag the head's movement and settle.
      face.setAttribute('transform', 'translate(' + (yaw * 38) + ' ' + (pitch * 40) + ')');
      var drop = (p.cy - before.cy) / (k || 1), sway = Math.sin(t * 2.1) * 2;
      earL.setAttribute('transform', 'translate(' + (-yaw * 10) + ' 0) rotate(' + Math.max(-18, Math.min(18, -drop * 0.25 + sway)) + ' 200 470)');
      earR.setAttribute('transform', 'translate(' + (-yaw * 10) + ' 0) rotate(' + Math.max(-18, Math.min(18, drop * 0.25 - sway)) + ' 570 470)');
      head.setAttribute('transform', 'translate(0 ' + (-Math.sin(t * Math.PI * 1.1) * 4) + ')');
      // Expressions at t.
      var happy = 0, laugh = 0, wink = 0, surprise = 0, gx = 0, gy = 0;
      expr.forEach(function (x) {
        var e = weight(x, t); if (!e) return;
        if (x.face === 'smile') happy = Math.max(happy, e);
        if (x.face === 'laugh') { laugh = Math.max(laugh, e); happy = Math.max(happy, e); }
        if (x.face === 'wink') wink = Math.max(wink, e);
        if (x.face === 'surprised') surprise = Math.max(surprise, e);
        if (x.gaze) { gx += x.gaze[0] * e; gy += x.gaze[1] * e; }
      });
      var sp = speech(words, t), open = sp.open * 0.9, shape = sp.shape;
      if (laugh) { open = Math.max(open, laugh * (0.55 + 0.35 * Math.abs(Math.sin(t * Math.PI * 7)))); shape = 'A'; }
      if (surprise) { open = Math.max(open, surprise); shape = 'O'; }
      mouth.setAttribute('d', mouthPath(mix(MOUTH.closed, MOUTH[shape], open)));
      smile.setAttribute('d', smilePath(mix(SMILE[0], SMILE[1], happy)));
      looks.forEach(function (l) { l.setAttribute('transform', 'translate(' + (gx * 16) + ' ' + (-gy * 12) + ')'); });
      var blink = lidAt(blinks, t);
      lids.forEach(function (l, i) {
        var c = Math.max(blink, i === 1 ? wink : 0), x = i === 0 ? 265 : 507;
        l.setAttribute('transform', 'translate(0 520) scale(1 ' + c + ') translate(0 -520)');
        lashes[i].style.opacity = c > 0.85 ? 1 : 0;
        lowers[i].setAttribute('transform', 'translate(0 ' + (-42 * happy) + ')');
      });
      cheeks.forEach(function (c, i) {
        var cx = i === 0 ? 190 : 580;
        c.setAttribute('transform', 'translate(' + cx + ' 692) scale(' + (1 + 0.16 * happy) + ') translate(' + (-cx) + ' ' + (-692 - 14 * happy) + ')');
      });
      if (laugh) root.setAttribute('transform', root.getAttribute('transform') + ' translate(0 ' + (-Math.abs(Math.sin(t * Math.PI * 7)) * 10 * laugh) + ')');
    }});
    return box;
  };
})();
