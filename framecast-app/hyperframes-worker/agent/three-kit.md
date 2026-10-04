# 3D in the composition (wyv-3d.js)

The 3D mascot (plan.mascot3d) and 3D objects (plan.props3d, or a reference moment whose method is render_3d) are drawn
on `<canvas>` elements inside the composition, from the same timeline as everything else. This is the default for 3D:
it keeps each character and object in its exact slot (the layout check measures the canvas), needs no matching
backgrounds, and every frame is a function of time. Use Remotion clips only for 3D that fills no slot.

A complete worked example, a 15-second copy of a reference with a talking 3D mascot, three tossed cards with spinning
objects and every reference move: `kit/three-example.html`. Read it before building a 3D video.

## Setup
```html
<script src="gsap.min.js"></script><script src="wyv-motion.js"></script>
<script src="three-wyv.js"></script><script src="wyv-3d.js"></script>
```
Both files are added when the composition is checked or rendered; do not write them. Each 3D element is a canvas with
its pixel size as attributes (not CSS), positioned in its slot:
```html
<canvas id="bear" width="1060" height="1080" style="position:absolute;left:860px;top:0" data-ref="1590:m1:0"></canvas>
```
After registering every mascot and prop, start the clock once: `W3D.clock(tl, duration)`. Register the timeline in an
inline script at the end of index.html: `window.__timelines = window.__timelines || {}; window.__timelines.main = tl;`
(never assign into `window.__timelines` before creating it). Build mascots and props after their canvases exist in the
page (scripts at the end of body); W3D names the missing canvas or script if not.

## The mascot
```js
W3D.mascot('#bear', {spec: plan.mascot3d.spec, words, active: [0, 2.4], expressions, place: t => {
  const k = W3D.spring(t, 0, 11, .7), i = W3D.idle(t);              // slides in from the right, then lives
  return {cx: 505 + 760 * (1 - k), cy: 500, r: 250, yaw: -.5 * (1 - k) + i.yaw, tilt: i.tilt, pitch: i.pitch};
}});
```
- place(t): the head centre (cx, cy) and head radius r in canvas pixels, plus yaw, pitch, tilt (the head on the neck,
  radians, within ±0.6) and bodyYaw. The bust hangs below the head (about 3 radii); let it run off the canvas bottom.
- words: the narration's words `{text, start, end}` in composition seconds (narrationTiming, offset by the narration
  clip's data-start). The mouth follows each syllable and closes between words; blinks are automatic.
- expressions: `[{at, duration, face: 'smile'|'laugh'|'wink'|'surprised', gaze: [x, y]}]` in composition seconds
  (gaze alone looks toward something: a card, a button).
- active: `[from, to]` seconds the canvas is on screen; outside it the canvas is blank and costs nothing. Give each
  appearance of the character its own canvas in its own scene (a hook, a tile, an end card), all with the same spec.
- Motion is closed-form: `W3D.spring(t, t0, stiffness, damping)` (0 to 1 from t0), `W3D.idle(t, k)` (bob, tilt,
  sway). Never read state carried between frames.

## Objects (props)
```js
const camera = ({THREE, shapes, mesh}) => {
  const g = new THREE.Group();
  g.add(mesh(shapes.roundedBox(2.7, 1.65, 1.1, .2), '#d4d4d4'));                       // body
  const lens = mesh(new THREE.CylinderGeometry(.64, .68, .52, 40), '#b0b0b0'); lens.rotation.x = Math.PI / 2; lens.position.set(.22, -.05, .78); g.add(lens);
  const glass = mesh(new THREE.CylinderGeometry(.46, .46, .08, 40), '#0d0d0d', .5); glass.rotation.x = Math.PI / 2; glass.position.set(.22, -.05, 1.06); g.add(glass);
  return g;
};
W3D.prop('#cam', {build: camera, active: [2.7, 4.8], spin: {at: 2.72, turns: 1, settle: .9, drift: .12, rest: .55}});
```
- There is no catalogue: write each object's build from its description, about 3 units across, centred.
- shapes: roundedBox(w, h, d, r), panel(w, h, d, r), lathe([[radius, y], ...]), extrude([[x, y], ...], depth, bevel);
  any THREE geometry works too. mesh(geometry, colour, flat): flat 1 is unshaded (screens, dials, labels).
- spin: the product spin, a fast whip as its card lands easing into a slow drift; start it at the card's toss time.
  Without spin the object sways gently. dist (default 8.2) sets its size: larger is smaller.
- For the dither finish use light greys (#a0a0a0 to #e5e5e5) for surfaces; darker colours print solid black.

## Rules the checks enforce
- Animate the canvas (or its slot) with transforms (x, y, scale, autoAlpha), never left/top.
- Never tween with function values that read layout (getBoundingClientRect) mid-timeline: measure once when the
  timeline is built and pass numbers.
- Relative values (`'-=30'`) only when no other tween writes that property at the same time; prefer absolute.
- Large kinetic type that overlaps by design (a script word under a headline, a stamp over tiles): mark the element
  with `data-layout-allow-overlap` (and `data-layout-allow-occlusion` when it covers others).
- Labels must pass contrast: mono greys no lighter than #6b6b6b on white.
