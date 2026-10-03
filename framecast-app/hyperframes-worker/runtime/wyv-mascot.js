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
  global.WyvMascot = Object.freeze({version: '1.0.0', validate, attach});
})(globalThis);
