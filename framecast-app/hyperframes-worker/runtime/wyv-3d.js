/* WyvStudio 3D for compositions: the parametric mascot and props drawn on <canvas> elements in the page, from the
 * same timeline as everything else. Load after gsap.min.js and three-wyv.js (the three.js bundle with the mascot
 * and prop builders). Every frame is a function of time, so checks, previews and renders are exact and seekable.
 *
 *   W3D.mascot('#bear', {spec: plan.mascot3d.spec, words, active: [0, 2.4], place: t => ({cx, cy, r}), expressions});
 *   W3D.prop('#cam', {build: ({THREE, shapes, mesh}) => group, active: [2.7, 4.8], spin: {at: 2.72}});
 *   W3D.clock(tl, 15);   // once, after every mascot and prop
 */
(function () {
  const stages = [];
  let shared = null;
  // One WebGL renderer for every canvas: each stage is drawn there and copied in, so a dozen 3D elements never
  // run out of WebGL contexts.
  const renderer = () => {
    if (!shared) {
      shared = new W3.THREE.WebGLRenderer({canvas: document.createElement('canvas'), alpha: true, antialias: false, preserveDrawingBuffer: true});
      shared.setPixelRatio(1); shared.setClearColor(0x000000, 0);
    }
    return shared;
  };
  const $ = el => typeof el === 'string' ? document.querySelector(el) : el;
  function stage(canvas, {fov = 30, active = null, draw}) {
    const sel = canvas; canvas = $(canvas);
    // Plain errors the builder can act on in one step.
    if (!window.W3) throw Error('W3D: three-wyv.js is not loaded; load it before wyv-3d.js');
    if (!canvas || canvas.tagName !== 'CANVAS') throw Error('W3D: no <canvas> matches ' + (typeof sel === 'string' ? sel : 'the element given') + '; add it with width and height attributes');
    const T = W3.THREE, scene = new T.Scene(), cam = new T.PerspectiveCamera(fov, canvas.width / canvas.height, 0.1, 200), ctx = canvas.getContext('2d');
    let drawn = false;
    const s = {canvas, scene, cam, render(t) {
      // Outside its time window the canvas stays blank and costs nothing.
      if (active && (t < active[0] || t > active[1])) { if (drawn) { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; } return; }
      const r = renderer(); r.setSize(canvas.width, canvas.height, false);
      draw(t, scene, cam); r.render(scene, cam);
      ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.drawImage(r.domElement, 0, 0); drawn = true;
    }};
    stages.push(s); return s;
  }
  // A damped spring from 0 to 1 that starts at t0 (closed form: a pure function of time).
  const spring = (t, t0, w = 13, z = 0.5) => { if (t <= t0) return 0; const u = t - t0, wd = w * Math.sqrt(1 - z * z); return 1 - Math.exp(-z * w * u) * (Math.cos(wd * u) + z * w / wd * Math.sin(wd * u)); };
  // Small living motion for a character at rest: a bob, a tilt and a sway, scaled by k.
  const idle = (t, k = 1) => ({pitch: Math.sin(t * Math.PI * 1.3) * 0.025 * k, tilt: Math.sin(t * Math.PI * 0.9) * 0.04 * k, yaw: Math.sin(t * Math.PI * 0.55) * 0.07 * k});
  // The mascot on a canvas. place(t) gives the head centre (cx, cy) and head radius r in canvas pixels, plus yaw,
  // pitch, tilt (head on the neck, radians within ±0.6) and bodyYaw. words: the narration's words with start and
  // end in composition seconds (the mouth follows each syllable). expressions: [{at, duration, face:
  // 'smile'|'laugh'|'wink'|'surprised', gaze: [x, y]}] in composition seconds. blinks: times, or automatic.
  function mascot(canvas, {spec, words = [], active = null, place, expressions = [], blinks = null, cell = 2}) {
    const sel = canvas; canvas = $(canvas);
    if (!canvas) throw Error('W3D: no <canvas> matches ' + sel + '; add it with width and height attributes');
    if (typeof place !== 'function') throw Error('W3D.mascot ' + sel + ': place must be a function of t returning {cx, cy, r}');
    const m = W3.buildMascot(spec, {cell}), cues = W3.mouthCues(words, 0), bl = blinks || W3.autoBlinks(60, (active ? active[0] : 0) + 0.4);
    return stage(canvas, {active, draw: (t, scene, cam) => {
      if (!m.root.parent) scene.add(m.root);
      const p = place(t), dist = (canvas.height / 2) / p.r / Math.tan(15 * Math.PI / 180);
      cam.position.set(0, 0, dist); cam.lookAt(0, 0, 0);
      W3.applyRig(m, {x: (p.cx - canvas.width / 2) / p.r, y: -(p.cy - canvas.height / 2) / p.r - 0.3, yaw: p.yaw || 0, pitch: p.pitch || 0, tilt: p.tilt || 0, bodyYaw: p.bodyYaw || 0},
        W3.faceAt(t, {cues, blinks: bl, expressions}));
    }});
  }
  // A 3D object modelled in code. build({THREE, shapes, mat, mesh}) returns an Object3D about 3 units across,
  // called once; mesh(geometry, colour, flat) uses the finish (flat 1 = unshaded: screens, labels). spin: spinAt
  // options ({at, turns, settle, drift, rest}) for the product spin, or omit for a gentle sway. pitch tips it
  // toward the camera; dist is the camera distance (larger = smaller object).
  function prop(canvas, {build, active = null, spin = null, finish = 'dither', cell = 2, pitch = 0.42, scale = 1, dist = 8.2, yaw = 0.5}) {
    let holder = null, spinner = null;
    return stage(canvas, {active, draw: (t, scene, cam) => {
      if (!holder) {
        const T = W3.THREE, mat = (c, f = 0) => W3.finishMaterial(c, finish, {cell, flat: f});
        holder = new T.Group(); spinner = new T.Group(); holder.add(spinner);
        spinner.add(build({THREE: T, shapes: W3.shapes, mat, mesh: (g, c, f = 0) => new T.Mesh(g, mat(c, f))})); scene.add(holder);
      }
      cam.position.set(0, 0, dist); cam.lookAt(0, 0, 0); holder.rotation.set(pitch, 0, 0); holder.scale.setScalar(scale);
      spinner.rotation.y = spin ? W3.spinAt(t, spin) : yaw + Math.sin(t * 0.6) * 0.15;
    }});
  }
  // The clock: one tween on a setter drives every stage (and any per-frame callbacks, such as a timecode). It runs
  // on every seek, including the renderer's, which suppresses timeline events.
  function clock(tl, duration, after = []) {
    const c = {_t: -1, get t() { return this._t; }, set t(v) { this._t = v; for (const s of stages) s.render(v); for (const f of after) f(v); }};
    tl.fromTo(c, {t: 0}, {t: duration, duration, ease: 'none', immediateRender: true}, 0);
    return c;
  }
  window.W3D = {stage, mascot, prop, clock, spring, idle, stages};
})();
