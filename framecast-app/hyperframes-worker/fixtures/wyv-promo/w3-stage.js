// 3D on the HyperFrames timeline. Every stage is a 2D canvas in the page; one shared WebGL renderer draws each
// stage's scene and copies it in, so a dozen 3D elements never exhaust the browser's WebGL contexts. Stages are
// drawn from the timeline's time whenever it is seeked (a tween on a setter, which runs even when timeline
// events are suppressed), so every frame is a pure function of time: exact and seekable.
(function () {
  let shared = null;
  const renderer = () => {
    if (!shared) {
      shared = new W3.THREE.WebGLRenderer({canvas: document.createElement('canvas'), alpha: true, antialias: false, preserveDrawingBuffer: true});
      shared.setPixelRatio(1); shared.setClearColor(0x000000, 0);
    }
    return shared;
  };
  // active: [from, to] seconds when the stage is on screen; outside it the canvas is left blank.
  window.W3Stage = function (canvas, {fov = 30, active = null, draw}) {
    const T = W3.THREE, scene = new T.Scene(), cam = new T.PerspectiveCamera(fov, canvas.width / canvas.height, 0.1, 200), ctx = canvas.getContext('2d');
    let drawn = false;
    return {canvas, scene, cam, render(t) {
      if (active && (t < active[0] || t > active[1])) { if (drawn) { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; } return; }
      const r = renderer(); r.setSize(canvas.width, canvas.height, false);
      draw(t, scene, cam); r.render(scene, cam);
      ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.drawImage(r.domElement, 0, 0); drawn = true;
    }};
  };
  // One clock for the whole film: each stage, then any per-frame callbacks (a timecode), at time t.
  window.W3Clock = function (tl, stages, duration, after = []) {
    const clock = {_t: -1, get t() { return this._t; }, set t(v) { this._t = v; for (const s of stages) s.render(v); for (const f of after) f(v); }};
    tl.fromTo(clock, {t: 0}, {t: duration, duration, ease: 'none', immediateRender: true}, 0);
    return clock;
  };
})();
