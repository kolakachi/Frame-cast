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

## Good habits
- One idea per interaction: move, then press, then show the result; leave about 0.3 s between them.
- Use `WM.ease.heavy` for headlines and `WM.ease.snappy` for UI, so type feels weighty and UI feels quick.
- Use the same transition family throughout a video; mix at most two.
