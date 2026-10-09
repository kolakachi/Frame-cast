# WyvStudio collage kit (in wyv-motion.js)

Load wyv-motion.js after GSAP, as for every kit move.

## Collage (a zine or sticker ad)
Seeded, so every render is the same. Cut people and products out first (the cutout tool) and print every photo.
- `const cut = WM.tear(tl, '#tear', at, {colors: ['#e8261a', '#f5c518'], strips: 2, from: 'left'})`: torn-paper strips sweep across as the cut. `#tear` is an empty full-frame layer above the scenes; switch the scene underneath at `cut` (the frame is covered then). from: left, right, top or bottom; 1 to 3 strips.
- `WM.sticker(tl, '#label', at, {rotate: -6, border: 6})`: a cut-out PNG or a text label gets a white die-cut border and a shadow, and slaps on with a tilt.
- `const end = WM.cards(tl, '#deck', at, {every: 2, tilt: 5})`: a rapid montage; `#deck` holds stacked `<div class="wm-card"><img …></div>`, each shown for `every` frames (24 fps). Returns when it ends.
- `WM.grade('#photo', 'halftone', {size: 8})`: a print look on a picture, set once: `silver`, `ink`, `duotone` ({dark, light}) or `halftone`.
- `WM.sunburst(tl, '#rays', at, {colors: ['#f39a1e', '#1f7a8c', '#f6f1e7'], rays: 30, duration: 2})`: rays turning behind a scene (a full-frame layer under the content).
- `WM.confetti(tl, '#fx', at, {count: 28, from: {x: 540, y: 520}})`: paper pieces burst and fall (a full-frame layer above the content).
- `WM.circleText(tl, '#badge', at, {text: 'MADE TO MOVE · ', size: 40, spin: 90, duration: 3})`: text around a circle, turning; `#badge` is an empty square box.
