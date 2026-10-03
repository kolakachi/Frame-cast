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
   Tailwind, Three.js and external fonts are not installed in this adapter.
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
