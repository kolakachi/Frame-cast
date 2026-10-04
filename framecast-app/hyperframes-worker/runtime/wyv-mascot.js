/* WyvStudio prepared 2D mascot adapter. No asset generation, timers or network.
 * Requires GSAP and authored SVG layers. Evaluated by the Hyperframes timeline.
 */
(function (global) {
  'use strict';
  const number = (v, min, max, label) => {
    if (typeof v !== 'number' || !Number.isFinite(v) || v < min || v > max) throw Error('Mascot: invalid ' + label);
    return v;
  };
  const known = (value, keys, label) => {
    if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).some(k => !keys.includes(k))) throw Error('Mascot: invalid ' + label);
  };
  function validate(spec) {
    known(spec, ['duration', 'blinks', 'head', 'gaze', 'mouths'], 'score');
    number(spec.duration, .1, 300, 'duration');
    const out = {duration: spec.duration};
    for (const channel of ['blinks', 'head', 'gaze', 'mouths']) {
      const cues = spec[channel] ?? [];
      if (!Array.isArray(cues) || cues.length > 1000) throw Error('Mascot: invalid ' + channel);
      let end = -1;
      out[channel] = cues.map(c => {
        const keys = channel === 'blinks' ? ['at', 'duration'] : channel === 'mouths' ? ['at', 'shape'] : channel === 'head' ? ['at', 'duration', 'x', 'y', 'tilt'] : ['at', 'duration', 'x', 'y'];
        known(c, keys, channel + ' cue');
        number(c.at, 0, spec.duration, 'cue time');
        const duration = channel === 'mouths' ? 0 : number(c.duration, .04, channel === 'blinks' ? 1 : spec.duration, 'cue duration');
        if (c.at < end || (channel === 'mouths' && c.at === end) || c.at + duration > spec.duration + 1e-8) throw Error('Mascot: overlapping, unordered or out-of-range ' + channel);
        end = c.at + duration;
        if (channel === 'mouths') {
          if (!['rest', 'smile', 'open', 'round'].includes(c.shape)) throw Error('Mascot: unsupported mouth shape');
        } else if (channel !== 'blinks') {
          number(c.x, -30, 30, 'x'); number(c.y, -30, 30, 'y');
          if (channel === 'head') number(c.tilt, -20, 20, 'tilt');
        }
        return {...c};
      });
    }
    return out;
  }
  function attach(parent, root, score, at = 0) {
    if (!global.gsap || !parent || typeof parent.add !== 'function') throw Error('Mascot: GSAP parent timeline required');
    if (!root || root.namespaceURI !== 'http://www.w3.org/2000/svg' || typeof root.querySelectorAll !== 'function') throw Error('Mascot: prepared SVG root required; a flat image is not a rig');
    const spec = validate(score); number(at, 0, 3600, 'start');
    const one = selector => {
      const nodes = root.querySelectorAll(selector);
      if (nodes.length !== 1) throw Error('Mascot: expected one layer ' + selector);
      return nodes[0];
    };
    const head = one('[data-rig-part="head"]');
    const eyes = ['left-eye', 'right-eye'].map(p => one('[data-rig-part="' + p + '"]'));
    const pupils = ['left-pupil', 'right-pupil'].map(p => one('[data-rig-part="' + p + '"]'));
    const mouths = ['rest', 'smile', 'open', 'round'].map(p => one('[data-rig-mouth="' + p + '"]'));
    if (![...eyes, ...pupils, ...mouths].every(n => head.contains(n))) throw Error('Mascot: facial layers must belong to head');
    if (!eyes.every((eye, i) => eye.contains(pupils[i]))) throw Error('Mascot: pupils must belong to corresponding eyes');
    // Build paused; never use callbacks (Hyperframes can seek with events suppressed).
    const tl = global.gsap.timeline({paused: true});
    tl.to({}, {duration: spec.duration}, 0);
    tl.set(head, {x: 0, y: 0, rotation: 0, transformOrigin: '50% 85%'}, 0);
    tl.set(eyes, {scaleY: 1, transformOrigin: '50% 50%'}, 0);
    tl.set(pupils, {x: 0, y: 0}, 0);
    // One write per mouth per cue avoids overlapping zero-duration sets on rewind.
    const mouthNames = ['rest', 'smile', 'open', 'round'];
    const initial = spec.mouths.find(c => c.at === 0)?.shape ?? 'rest';
    tl.set(mouths, {opacity: i => mouthNames[i] === initial ? 1 : 0}, 0);
    for (const c of spec.blinks) {
      tl.to(eyes, {scaleY: .06, duration: c.duration * .45, ease: 'power1.in'}, c.at);
      tl.to(eyes, {scaleY: 1, duration: c.duration * .55, ease: 'power1.out'}, c.at + c.duration * .45);
    }
    for (const c of spec.head) tl.to(head, {x: c.x, y: c.y, rotation: c.tilt, duration: c.duration, ease: 'sine.inOut'}, c.at);
    for (const c of spec.gaze) tl.to(pupils, {x: c.x, y: c.y, duration: c.duration, ease: 'sine.inOut'}, c.at);
    for (const c of spec.mouths) {
      if (c.at > 0) tl.set(mouths, {opacity: i => mouthNames[i] === c.shape ? 1 : 0}, c.at);
    }
    parent.add(tl, at); tl.paused(false);
    return {timeline: tl, score: spec, capabilities: ['blink', 'gaze', 'head-tilt', 'mouth-shapes'], limitations: ['prepared layered SVG only', 'no automatic lip-sync', 'no full-body actions or 3D turns']};
  }

  /* A raster face: the resting head plus eye and mouth patches cut from an expression sheet
   * (face.json: {base, width, height, patches: [{name, file, x, y, w, h}]}). The layers are
   * built here from the manifest, so they always line up. Everything is a timed set: any seek is exact.
   *   WyvMascot.face(tl, '#maya', {kit, src: 'maya-face/', words: [{text, start, end}], at: 0,
   *     duration: 15, blinks: 'auto' | [times], expressions: [{at, duration, eyes: 'wink', mouth: 'smile'}]})
   * words: the narration's word times (narrationTiming) placed on the timeline; the mouth follows each
   * syllable's vowel (open, oh, ee) and closes between words. */
  const VOWEL = c => /[a]/.test(c) ? 'open' : /[ouw]/.test(c) ? 'oh' : /[eiy]/.test(c) ? 'ee' : null;
  function syllables(text) {
    // A word without vowels (psst, hmm, shh) is a near-closed whisper.
    const groups = String(text).toLowerCase().replace(/[^a-z]/g, '').match(/[aeiouy]+/g) || ['e'];
    return groups.map(g => VOWEL(g[0]) || 'open');
  }
  function mouthCues(words, at) {
    const cues = []; let last = null;
    const put = (t, shape) => { if (shape !== last) { cues.push({t: +t.toFixed(3), shape}); last = shape; } };
    (words || []).forEach((w, i) => {
      const a = at + Number(w.start), b = at + Number(w.end); if (!(b > a)) return;
      const parts = syllables(w.text || w.word || ''), step = (b - a) / parts.length;
      parts.forEach((shape, k) => {
        put(a + k * step, shape);
        // Long syllables flap: the mouth half-closes before the next one.
        if (step > 0.16 && k < parts.length - 1) put(a + k * step + step * 0.7, 'rest');
      });
      const next = words[i + 1], gap = next ? at + Number(next.start) - b : Infinity;
      if (gap >= 0.09) put(b, 'rest');
    });
    return cues;
  }
  function face(tl, root, opts) {
    if (!global.gsap || !tl || typeof tl.set !== 'function') throw Error('Mascot face: GSAP timeline required');
    root = typeof root === 'string' ? document.querySelector(root) : root;
    const kit = opts && opts.kit;
    if (!root || !kit || !kit.base || !(kit.width > 0) || !(kit.height > 0) || !Array.isArray(kit.patches)) throw Error('Mascot face: element and face.json kit required');
    const src = opts.src || '', at = Number(opts.at) || 0, duration = Number(opts.duration) || 15;
    if (getComputedStyle(root).position === 'static') root.style.position = 'relative';
    root.style.aspectRatio = kit.width + ' / ' + kit.height;
    const img = (file, box, name) => {
      const el = document.createElement('img'); el.src = src + file; el.alt = ''; el.draggable = false;
      el.style.cssText = 'position:absolute;display:block;left:' + (box.x / kit.width * 100) + '%;top:' + (box.y / kit.height * 100) + '%;width:' + (box.w / kit.width * 100) + '%;height:' + (box.h / kit.height * 100) + '%;' + (name ? 'opacity:0;' : '');
      if (name) el.setAttribute('data-face', name);
      root.appendChild(el); return el;
    };
    img(kit.base, {x: 0, y: 0, w: kit.width, h: kit.height});
    const layers = {};
    for (const p of kit.patches) layers[p.name] = img(p.file, p, p.name);
    const has = n => !!layers[n];
    // Each channel shows at most one patch; null is the resting face.
    const show = (part, name, t) => {
      const names = Object.keys(layers).filter(n => n.startsWith(part + '-'));
      if (names.length) tl.set(names.map(n => layers[n]), {opacity: i => names[i] === part + '-' + name ? 1 : 0}, t);
    };
    // Expressions win over talking and blinking while they last.
    const expressions = (opts.expressions || []).map(e => ({a: at + e.at, b: at + e.at + (e.duration || 0.6), eyes: e.eyes, mouth: e.mouth}));
    const inExpr = (t, part) => expressions.some(e => e[part] && t >= e.a && t < e.b);
    for (const c of mouthCues(opts.words, at)) if (!inExpr(c.t, 'mouth')) show('mouth', c.shape === 'rest' || !has('mouth-' + c.shape) ? null : c.shape, c.t);
    let blinks = opts.blinks;
    if (blinks === undefined || blinks === 'auto') { blinks = []; for (let t = at + 1.1, k = 0; t < at + duration - 0.3; k++, t += 2.9 + ((k * 7919) % 11) / 10) blinks.push(t - at); }
    if (has('eyes-closed')) for (const b of blinks) { const t = at + b; if (inExpr(t, 'eyes') || inExpr(t + 0.13, 'eyes')) continue; show('eyes', 'closed', t); show('eyes', null, t + 0.13); }
    for (const e of expressions) {
      if (e.eyes && has('eyes-' + e.eyes)) { show('eyes', e.eyes, e.a); show('eyes', null, e.b); }
      if (e.mouth && has('mouth-' + e.mouth)) { show('mouth', e.mouth, e.a); show('mouth', null, e.b); }
    }
    return tl;
  }
  global.WyvMascot = Object.freeze({version: '1.1.0', validate, attach, face, mouthCues});
})(globalThis);
