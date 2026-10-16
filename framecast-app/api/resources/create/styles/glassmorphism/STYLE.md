# Glassmorphism

Real interface, made precious: frosted glass panels floating at different depths over a soft colour field, so the
product feels light, calm and expensive.

## Look
- A colour field behind everything: WM.mesh with 3-4 of the brand's colours (deep base, two mid tones, one bright).
- Glass cards: background rgba(255,255,255,.14-.2), backdrop-filter blur(24-30px) saturate(140%), a 1.5-2 px white border at 30-40%, radius 36-48 px, a soft shadow (0 30px 60px rgba(0,0,0,.25)).
- Real UI inside the cards: built in code with the product's own words and numbers (approved facts only). White text, Inter, two weights.
- Depth: 2-3 layers of cards at different scales; the far ones slightly smaller and softer.

## Motion
- Cards glide in from depth: scale .94 to 1, y 40 to 0, opacity, 0.6-0.8 s with an ease-out; never a hard pop.
- The camera drifts the whole time (a slow pan or push on the world, 2-4% over the beat); the mesh drifts behind.
- One card in focus at a time: the active card comes forward, the others dim and soften (WM.rackFocus).
- Numbers count (WM.count), toggles and buttons press (WM.press), notifications slide down.

## Structure
1. Hook: the main card with the one thing the app does, in frame 1.
2. 2-3 feature cards, each in focus in turn.
3. The payoff: a notification or result card lands.
4. Close: the app name on glass with the call to action.

## Never
- Glass over a flat white or grey field (it disappears): the colour field is what makes the glass read.
- Text over busy glass without enough contrast: raise the card's opacity rather than shrink the text.
- More than three cards on screen at once.
