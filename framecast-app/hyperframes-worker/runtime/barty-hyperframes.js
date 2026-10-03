/* WyvStudio adapter: upstream Barty scene seeking, driven only by Hyperframes/GSAP. */
(() => {
  let mounted = false;
  window.WyvBroll = {
    scene(cfg, compositionId = 'main') {
      if (mounted) throw Error('Only one Barty scene per document is supported');
      if (!window.M || !window.gsap) throw Error('Load GSAP and barty-motion.js first');
      if (![cfg.W, cfg.H, cfg.T].every(n => Number.isFinite(n) && n > 0)) throw Error('Invalid Barty dimensions/duration');
      const root = [...document.querySelectorAll('[data-composition-id]')].find(n => n.dataset.compositionId === compositionId);
      if (!root || Number(root.dataset.width) !== cfg.W || Number(root.dataset.height) !== cfg.H || Number(root.dataset.duration) !== cfg.T) throw Error('Barty scene must match its Hyperframes root');
      for (const id of ['wrap', 'stage', 'world', 'shape', 'cursor']) if (!root.contains(document.getElementById(id))) throw Error('Missing Barty node: ' + id);
      window.__timelines ||= {};
      if (window.__timelines[compositionId]) throw Error('Composition already has a timeline');
      const seek = M.scene(cfg);
      // A property setter runs even when GSAP suppresses callback events during seeking.
      // onUpdate would silently fail in that renderer mode.
      let time = 0;
      const clock = {};
      Object.defineProperty(clock, 'time', { get: () => time, set: value => {
        time = Math.max(0, Math.min(cfg.T, Number(value)));
        seek(time);
      }});
      const timeline = gsap.timeline({ paused: true });
      timeline.to(clock, { time: cfg.T, duration: cfg.T, ease: 'none' }, 0);
      window.__timelines[compositionId] = timeline;
      mounted = true;
      return timeline;
    }
  };
})();
