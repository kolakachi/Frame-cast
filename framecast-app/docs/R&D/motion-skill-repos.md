# Motion skill repos (reviewed 2026-10-09)

Seventeen links the owner shared. Three were already ours: `iart-ai/motion-design-skills` (unchanged since our pin),
`Barty-Bart/motion-graphics` (our spring engine; its one new skill, object separation with SAM 2, is too heavy for the
build worker) and `lottie-web` (installed; the HyperFrames adapter seeks it exactly). Rule we kept: every guide line costs
the builder context, so a resource is taken only when it replaces something weaker or fixes a gap seen in real videos.

## Taken (built locally 2026-10-09)
- **Render traps** into `hyperframes-worker/agent/craft.md` (from `AbubakrChan/product-launch-motion` traps and
  `Sunwood-ai-labs/hyperframes-motion-reel-skill`, both MIT): blend-mode overlays render white; a property only in a
  `fromTo`'s from side vanishes on seek; two tweens on one transform judder; a filter tween from none jumps; overlapping
  label swaps double-print; `offset*` not `getBoundingClientRect` under a camera; push-in keep-out arithmetic; masked
  text counts as overlap; animated Lottie transforms render blank; `main.js` may run before the DOM.
- **Single-frame glitch check** on the finished video (`howseen-ai/claude-motion-design` `pops()` flash test, MIT):
  blocks delivery and goes to a repair round. No false alarm on 19 past renders. Their "pop" test was left out: a hard
  cut also triggers it.
- **Motion rules** in the craft guide: one energy arc per video (klik), easing by material (LottieFiles), sound matches
  the move's weight (klik), everything still on the last frame before a cut (product-launch-motion).

## Worth taking later
- **Beat map** (`whaleyxbt/claude-motion` `sims/beats.py`, MIT; `cth9191/animate` `tools/beats.mjs`, MIT): tempo,
  downbeats, kicks with strength, energy per bar, the drop. Better than our basic beat grid for the user's own music.
- **Director** (`whaleyxbt/claude-motion` `sims/kit/director.js`): cuts on bar lines, punch-ins on kicks, shake and
  flash on the drop, captions on the beat; a pure function of time, so it ports to GSAP.
- **Motion blur from sub-frames** (howseen, tmix with a 180° shutter, never across a cut). Our renderer has a
  motionBlur option: test it before adding anything.
- **Premium effect recipes** (`charlie947/motion-graphics-skills` `effects.md`, MIT, text only): tab pill whose front
  edge leads, bars collapsing to a line, magnetic dock falloff, glass focus with chromatic copies, seeded particle logo,
  ghost of the start state. Condense into a kit when one is needed.
- **Illustrated looks in code** (`cth9191/animate` `styles/*/kit.js`, MIT): riso halftone overprint, cut paper,
  crosshatch, sketchbook, pixel, isometric, manim-style. Could become style packs; port as `render(t)` canvas code.
- **Cue checks** (product-launch-motion `level-sfx.mjs`, `verify-cue.sh`): level a sound, trim its silent head, check
  the cue's envelope in the delivered file.
- **Remotion 3D rules** (`remotion-dev/skills` `3d.md`, no licence file: rewrite in our words): no `useFrame()`,
  `ThreeCanvas` needs width and height, `<Sequence layout="none">` inside it, render with `--gl=angle`. And
  `@remotion/effects` (lightLeak, halftone, chromaticAberration, zoomBlur, lut) works on our Remotion version.
- **Lottie**: a small hand-picked set (LottieFiles' free files allow commercial use but forbid bulk-compiling into a
  competing library), plus a start offset per scene (every player is seeked to composition time today).
- **Peer system to compare**: `Orkas-AI/Orkas-VideoStudio` (MIT, HyperFrames): editable plan/EDL, narration fit,
  design review and stage-consistency skills, a "promise check" delivery guard.
- **Text behind the subject** (Barty object separation): needs a lighter matting model than SAM 2 on the worker; our
  new video cutout (Robust Video Matting) may already be enough for stills and short clips.

## Skipped
- `haidrrrry/claude-remotion-skill`: generic advice we have.
- `199-biotechnologies/motion-dev-animations-skill`, `motiondivision/motion`: React/web interaction; Motion's time
  setting only schedules a render, so a seeked frame is not guaranteed.
- `nateherkai/hyperframes-student-kit` style library: licence is not permissive and landscape only. Two ideas kept
  instead: pad the timeline so the last frame never flashes black; a beat counts as on time within −0.2 s to +1.8 s of
  its word.
- `t3knobox/klik-anim-skill-creation`: no licence. Ideas only (energy arc, device ledger, sound matches motion,
  `measure-motion.py` energy curve, `lint-motion.mjs`).
- `frankxai/awesome-motion-design-agent-skills`: a thin list; `greensock/gsap-skills` duplicates what we have.
