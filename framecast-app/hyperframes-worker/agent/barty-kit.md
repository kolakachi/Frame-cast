# Barty motion-broll inside Hyperframes

Optional executable adapter, not a replacement renderer. Load local gsap.min.js,
barty-motion.js and barty-hyperframes.js in that order. Read the pinned engine API
at skills/barty/motion-broll/reference/engine-api.md for shape and layer options.
Do not run upstream build.py/render.js or install packages.

Create one Hyperframes root with data-composition-id, data-width, data-height,
data-duration. Inside it nest #wrap > #stage > #world > #shape; put #cursor inside
#stage. Position wrap/stage relatively, world/shape/cursor absolutely. Author CSS
for your content; upstream base.css/fonts are NOT loaded. Use existing local fonts.

Call WyvBroll.scene({W,H,T,bg,SH,start,SEQ,layers,...}, 'main') in an inline script
at the end of index.html; explicitly initialize window.__timelines there too.
The adapter registers a paused GSAP timeline. Do not separately register one, call
M.scene directly, or create RAF/preview loops. One scene per document; for several
beats use SH/SEQ/layers. This initial adapter is full-frame MP4, not alpha overlays.

Use approved colour_treatment roles in cfg.bg, SH state bg values and authored CSS;
there is no injected default palette. Use six-digit hex colours for engine tracks.
Keep supplied product imagery unchanged. Read source transcripts using transcript;
use their actual timestamps for SEQ and layer tin/tout (seconds on the same clock),
and retain normal Hyperframes audio clips. The adapter does not synthesize speech
or automatically align captions. Keep callbacks pure functions of time so backward
seeking produces identical frames. Use normal check/snapshot/strip/render tools.
