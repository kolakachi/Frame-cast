# Remotion clip adapter — local integration

Use for native React product demos, kinetic typography and iart workflows needing
Remotion. Read the relevant skills/iart-* workflow too. This is an additional
renderer, not a replacement for Hyperframes or a fixed visual template.

1. Write a flat source file such as `remotion-demo.js` using the write tool. Export
   a default React component. JSX is supported in .js. Do NOT registerRoot, declare
   Composition, add configs/install commands, or execute the source with node.
2. Allowed imports: react, react/jsx-runtime, remotion, and flat sibling .js. Use inline React styles; CSS file imports are not supported.
   Available Remotion APIs include useCurrentFrame, useVideoConfig, interpolate,
   spring, Sequence, AbsoluteFill, Img, Audio, staticFile. Other @remotion packages,
   Tailwind and external fonts are not installed in this adapter; three and @remotion/three are, for 3D characters (below).
3. Use staticFile('product.png') for an existing asset listed by assets. Preserve
   source images/audio. Honour colourTreatment.hex roles and locks; there is no
   imposed palette. Timing comes from frames at 24 fps, not timers/GSAP/wall clock.
4. Call run with cmd "remotion" and args ["still","project/remotion-demo.js","6","72"]
   for frame 72, or ["render","project/remotion-demo.js","6"] for a six-second MP4.
   Duration must fit approved settings (0.5–30 seconds); aspect/dimensions come from
   those settings. The host owns the output name and returns it in outputs.
5. Place the returned media in index.html using the normal timed video/image
   elements. If it carries narration use data-has-audio="true", and do not also play
   a duplicate narration track. Existing preview/critic/final render tools apply.
   For a transparent-looking overlay use a matching background; alpha is unsupported.
6. Edits: patch the saved React .js source, render again, then replace the clip src
   in index.html with the NEW output path. Never overwrite the original rendered
   clip or silently reuse it after source edits. Native source remains in the
   revision bundle; there is no conversion of React source into Hyperframes HTML.

Stills and videos cost local rendering time; no provider generation occurs in this
tool. The existing run limit/deadline still applies. A successful clip render is
not creative approval. This first adapter does not infer transcript timings or
inspect internal React text for claims, contrast or speech alignment. Keep checks
in the final composition and inspect rendered frames before finishing.


## 3D characters (wyv-mascot3d.js)
When plan.mascot3d is present (or a reference's mascot is a 3D render), render the character with the parametric mascot: it is rigged by construction (head turn, tilt and nod on the neck, blink, wink, gaze, mouth shapes on the narration's words, expressions) and needs no image generation.
```js
import React from 'react';
import {AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate} from 'remotion';
import {ThreeCanvas} from '@remotion/three';
import {Mascot3D} from './wyv-mascot3d.js';
export default function Clip(){
  const frame=useCurrentFrame(), {fps, width, height}=useVideoConfig();
  const enter=spring({frame, fps, config:{damping:15, stiffness:120}});
  const pose={x:interpolate(enter,[0,1],[7,2.3]), yaw:interpolate(enter,[0,1],[-0.55,0]), bodyYaw:-0.1};
  return <AbsoluteFill style={{background:'#ffffff'}}><ThreeCanvas width={width} height={height} camera={{fov:30, position:[0,-0.4,9]}} gl={{antialias:false, preserveDrawingBuffer:true}}>
    <Mascot3D spec={SPEC} words={WORDS} expressions={[{at:2.7, duration:0.6, face:'wink'}]} pose={pose} />
  </ThreeCanvas></AbsoluteFill>;
}
```
- SPEC is plan.mascot3d.spec as given. WORDS are the narration's word times (narrationTiming) relative to the clip's start: [{text, start, end}]; the mouth follows each syllable and closes between words; blinks are automatic.
- pose (an object, or a function (t, frame) => object): x, y (scene units; the bust is about 4.7 tall, the head radius 1), yaw, pitch, tilt (head on the neck, radians, keep within ±0.6), bodyYaw. Animate it with spring/interpolate from the frame.
- expressions: [{at, duration, face: 'smile'|'surprised'|'laugh'|'wink', gaze: [x, y]}], seconds from the clip's start.
- Finishes come from the spec (clay, dither, toon): dither is 1-bit ordered dither on a 2 px grid, keep the background white and the character greyscale for that look.
- Render the clip and place it in index.html like any Remotion clip; on a white page use the same white background so it sits seamlessly. For an exact copy put data-ref on the video element that fills the mascot's slot.
