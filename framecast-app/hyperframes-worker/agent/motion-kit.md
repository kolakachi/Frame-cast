# WyvStudio motion kit (wyv-motion.js)

Load it after GSAP: `<script src="gsap.min.js"></script><script src="wyv-motion.js"></script>`, then build your timeline as usual and pass it to the helpers. Each helper adds ordinary tweens to that timeline, so it seeks and renders exactly. `at` is seconds on the timeline. Elements can be selectors or nodes.

## Spring eases
Use these instead of default curves: `ease: WM.ease.snappy` (buttons, toggles, small UI: tiny overshoot), `WM.ease.default` (cards, containers, camera), `WM.ease.heavy` (big type, logo lockups: no overshoot), `WM.ease.playful` (mascots, stickers: visible bounce).

## Interactions
- `WM.cursor(tl, '#cursor', [{x, y, at}, {x, y, at, click: true}, ...])`: a cursor that arcs between points like a hand and presses on clicks. The cursor is an absolutely positioned element at left:0; top:0 (start it hidden); x and y are stage pixels of its tip.
- `WM.press(tl, '#btn', at, {glow: 'rgba(255,107,53,.8)'})`: the button dips, glows and springs back. Pair it with a cursor click at the same time.
- `WM.type(tl, '#typed', 'Your idea', at, 16)`: types text at 16 characters a second. The element is emptied until the typing starts. Put the full text in the HTML too, for the source.
- `WM.toggle(tl, '#track', '#knob', at, {distance: 58, on: '#FF6B35'})`: the knob springs across and the track takes the colour.
- `WM.count(tl, '#num', 0, 1200, at, 1.4, v => Math.round(v) + ' teams')`: counts up and settles. Only for numbers the user approved.
- `WM.morph(tl, '#shape', [{at, width, height, borderRadius, backgroundColor}, ...])`: one shape moves through states (pill, bar, card, panel) with a spring, instead of new cards fading in. Put content inside it and fade the content per state.

## Camera, flood, rise and edges
- `WM.camera(tl, '#content', '#target', at, {scale, hold, focus})`: zoom the content layer (a full-frame wrapper) into one element and back, like a screen recording; good for product, SaaS and app promos. Put the cursor inside the content layer so it scales with it.
- `WM.flood(tl, '#flood', at, {from: '#button', to: {x, y}, color})`: a colour grows from a point past the corners (about 0.3 s), holds, then shrinks into the next scene. Switch the scene underneath during the hold. `#flood` is a full-frame div above the scenes.
- `WM.rise(tl, '.line', at, {stagger})`: text rises out of a mask line; its parent clips it.
- `WM.edges(tl, '#pill', at, {x, w}, {x, w})`: a pill or tab highlight moves with its leading edge ahead and its trailing edge catching up (stretch, then settle).

## Transitions between scenes
Scenes are full-frame layers; the incoming one starts hidden (visibility:hidden).
- `WM.whip(tl, '#a', '#b', at)`: the outgoing scene whips off with motion blur, the next whips in; fastest exactly at `at`.
- `WM.push(tl, '#a', '#b', at, 'left'|'right'|'up'|'down')`: the next scene pushes the last one out.
- `WM.wipe(tl, '#b', at, 'left'|'right'|'up'|'down')`: the next scene is revealed by a hard-edged wipe.
- `WM.leak(tl, '#leak', at, 'rgba(255,140,60,.85)')`: a warm light leak sweeps over the cut. `#leak` is a full-frame div above the scenes with `mix-blend-mode: screen; visibility: hidden`.
Hide the outgoing scene once the transition is done (`tl.set('#a', {autoAlpha: 0}, at + 0.6)`), so it never sits under the next one.

## Signature moves
- `WM.giantWipe(tl, '#wipe', 'FLOW', at)`: one huge word sweeps across the frame as the cut; put the next scene underneath before `at`. `#wipe` is a full-frame div with display type at 2 to 3 times the frame height, white or the accent colour, `white-space: nowrap`.
- `WM.stamp(tl, '#stamp', at, {rotation: -8})`: a badge (a bordered word such as "Handled.") slams in, overshoots and settles. Use once, on the payoff.
- `WM.field(tl, '#stage', '#FF6B35', at)`: the background cuts to a new colour on a hit. Flip fields on key beats; keep text contrast when you do.

## Camera, rhythm and readouts (energy · length)
Match a beat's moves to its energy (the plan's playbook gives each beat low, mid or high). Vary the moves; keep the motion voice's timing.
- `WM.pushIn(tl, '#scene', at, {scale: 1.08, duration: 2.5, focus: {x: .5, y: .45}})` (low · 2-4 s): a slow push that keeps a held beat tense. At most twice a video.
- `WM.pullBack(tl, '#scene', at, {from: 1.8, focus: {x, y}})` (mid · .9 s): open tight on a detail, pull out to the whole.
- `WM.dutch(tl, '#scene', at, {angle: -7})` (mid · .7 s): the scene enters tilted and rolls level.
- `WM.coldOpen(tl, ['#s1', '#s2', '#s3'], at, {each: .3})` (high · ~1.3 s): hard cuts through very short shots, then a beat of black (dark stage) before the title.
- `WM.textMask(tl, '#word', at, {image: 'url(product.jpg)', from: '0% 50%', to: '100% 50%', duration: 2})` (mid · 1.5-3 s): a big heavy word is a window onto a picture or gradient moving behind it.
- `WM.rampFreeze(tl, '#hero', at, {from: {x: -900, y: 0}})` (high · .6 s): shoots in, brakes hard, freezes with a flash. The launch hit.
- `WM.hiddenCut(tl, '#blocker', '#a', '#b', at, {dir: 'right', duration: .7})` (high · .7 s): an object sweeps across and the scene changes behind it. The blocker covers the full frame height mid-sweep.
- `WM.odometer(tl, '#num', '12,480', at, {duration: .9})` (mid · ~1 s): digits roll into place. Approved numbers only.
- `WM.gauge(tl, '#ring', 87, at, {duration: 1.2})` (mid · 1.2 s): an SVG ring (stroke) or a bar sweeps to an approved value.
- `WM.streak(tl, '#streaks', at, {color: 'rgba(255,255,255,.85)'})` (high · .35 s): speed lines tear across a cut. `#streaks` is a full-frame div above the scenes.

- `WM.smash(tl, '#a', '#b', at, {el: '#flash'})` (high · one frame): a hard cut on the hit with a one-frame flash. Smash to silence: also end the music clip at `at` and start a second clip of the same file after the gap (`data-start`, `data-media-start`).
- `WM.split(tl, '#left', '#right', at, {dir: 'vertical', divider: '#line'})` (mid · .7 s): two panels slide into halves and a divider draws: before/after, two options. Each panel is sized to its half.
- `WM.stack(tl, '.item', [t1, t2, t3], {gap: 140})` (mid · .4 s an item): a list builds on the voice; each new item pushes in at the front, earlier ones step up and dim. Items share one front slot.
- `WM.parallax(tl, ['#bg', '#product', '#fg'], at, {duration: 3, distance: 60})` (low · 2-5 s): a still photo in layers (back to front) drifts with depth, like a moving camera. Use a product cutout as the middle layer.

## Reference moves
When the plan names a move (plan.reference_systems[].move, reference_decisions[].move), build that element with the recipe below; the check sends back a named move the composition never calls. kit/reference-moves.html is a worked 15-second example using all of them on one timeline. Elements start hidden (visibility:hidden); each move makes them visible itself.
- `words` → `WM.words(tl, '#h .w', [1.1, 1.3, 1.45])`: a headline builds word by word; one time per word span, from narrationTiming.
- `write_on` → `WM.writeOn(tl, '#emph', at, {duration: .45, underline: '#emph .u'})`: an emphasis word (script or italic) is written on left to right, then underlined. Start it so the last letter lands as the word is said.
- `iris` → `WM.iris(tl, '#next', at, {from: '#dot', ringColor: 'rgba(255,255,255,.8)'})`: the next full-frame scene opens as a circle growing from an element (the dot of a question mark, a stamp, a word), with faint echo rings. Hide the old scene once it is covered.
- `toss` → `WM.toss(tl, '#card', at, {rotation: -4, from: 'right'})`: a card is thrown in spinning and lands upright. Stagger cards about 0.4 s.
- `pop` → `WM.pop(tl, '#bubble', at, {origin: '100% 100%'})`: a bubble, tile, chip or sticker appears from a point with a bounce; set origin to its tail or anchor.
- `device` → `WM.device(tl, '#panel', at, {to: {left, top, width, height, radius}, content: '#panelContent', chrome: '#bezel'})`: a full-bleed panel (position:absolute, at full size when the timeline is built) shrinks into a phone or laptop screen, carrying its content layer; the bezel fades in as it lands. Then swap the screen's content.
- `through` → `WM.through(tl, {a: '#sceneA', from: '#button', b: '#sceneB', to: '#header', at})`: push into an element until it fills the frame, hold, then open out of another element in the next scene: a match cut on shape (a dark button becomes a dark header). Both scenes are full-frame layers at the stage's top left; pick a `to` element with the same colour and shape as `from`.
- `fly` → `WM.fly(tl, '#chip', '#total', at, {duration: .5})`, then `WM.count(tl, '#total', 12, 15, at + .5, .3)`: a chip arcs into a target that updates on arrival.
- `stamp`, `type`, `count`, `cursor`, `press`, `morph`, `whip`, `wipe`, `push`, `giant_wipe` (`WM.giantWipe`) and `field` are the recipes above.
- A stamp or bubble that is meant to sit over other text: add `data-layout-allow-overlap data-layout-allow-occlusion` to it.
- Move ids that are not here (`custom`) are built by hand from the plan's spec.

## Documents: pages and slides as they look
For assets of kind document_page: the user's own pages or slides, chosen to be shown as they look. Place the image itself (never a rebuilt or restyled version), keep the document's order, and hold each long enough to be recognised (about 3 s or more).
- `WM.book(tl, '#book', [4.2, 8.6], {spread: false, duration: 0.9})`: pages that turn. `#book` is a positioned box (one page's size; two pages wide with `spread: true`, page 1 on the left), holding one `<div class="wm-page"><img src="page-1.jpg" style="width:100%;height:100%;object-fit:cover"></div>` per page in order. Each time in the list turns one page over the spine, with the light falling across it and a page sound. A portrait page in a 9:16 frame: one page at a time; an open book in 16:9: `spread: true`. Put the book on a surface (a desk, a soft shadow, a little tilt) rather than floating on a flat colour.
- `WM.slides(tl, '#deck', [5, 10.5], {transition: 'push'})`: a deck's slides, each filling the frame (`<div class="wm-slide"><img …></div>` per slide in `#deck`). Each time brings in the next slide: `push` (default), `fade` or `zoom`. In 9:16, show the slide whole in the upper part of the frame (it is 16:9) and use the rest for the presenter or a caption, or push in on its key part.
- `WM.pageFocus(tl, '#content', '#page-3', {x: .08, y: .52, w: .84, h: .3}, at, {hold: 2})`: the camera moves to a part of a page or slide (fractions of that page: x, y, w, h) and back, so the viewer can read what the voice is saying: a chart, a figure, a heading. `#content` is the full-frame layer holding the book or slides. Inspect the page to find the box. Time it to the words (data-spoken).
- A presenter in the corner (a talking take or the 3D mascot) sits above the book or slides, never covering the part in focus.

## Sound
Moves bring their own sound: before the render each move's effect from the built-in library is laid under it, its peak on the hit, quieter under the voice, no closer than 0.25 s to another (the stronger hit wins). pop → pop, stamp → thud, press and cursor clicks → click, whip → whoosh-fast, wipe and push → whoosh, giantWipe, flood and through → whoosh-big, toss, iris and fly → swish, device, camera, layout, morph and pageFocus → slide, edges → tick, type → keys, count → blip, book → page (one per turn), slides → whoosh (slide for a fade); words, writeOn, rise and field are silent.
- `{sound: false}` in a move's options silences it; `{sound: 'thud'}` swaps its sound (library: whoosh, whoosh-fast, whoosh-big, swish, slide, pop, click, tick, thud, blip, chime, keys, page).
- `WM.sound(tl, 'chime', at)` adds a sound where there is no move (the logo lands, the offer appears).
- `data-sounds="off"` on the root composition turns all of it off: when the user asks for no effects, or the reference has none.
- Do not buy sound effects or place your own for these; they would double up.

## Good habits
- One idea per interaction: move, then press, then show the result; leave about 0.3 s between them.
- Use `WM.ease.heavy` for headlines and `WM.ease.snappy` for UI, so type feels weighty and UI feels quick.
- Use the same transition family throughout a video; mix at most two.
