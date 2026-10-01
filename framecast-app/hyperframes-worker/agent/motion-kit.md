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

## Good habits
- One idea per interaction: move, then press, then show the result; leave about 0.3 s between them.
- Use `WM.ease.heavy` for headlines and `WM.ease.snappy` for UI, so type feels weighty and UI feels quick.
- Use the same transition family throughout a video; mix at most two.
