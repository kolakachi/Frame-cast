# WyvStudio product hero kit (wyv-motion.js)

A physical product (a bottle, a box, a shoe, a device) made to feel shot in a studio from one photo. Cut the product
out first (the cutout media item or tool) so it is a transparent PNG, and load wyv-motion.js after GSAP.

## Moves
- `WM.floor('#product', {reflection: .28, shadow: .35})`: set once. The cutout stands on a glossy floor: a contact shadow and a faded mirror image under it. `#product` is the `<img>` inside a positioned box with room below it.
- `WM.productTurn(tl, '#product', at, {angle: 14, duration: 2.4})`: the photo turns a few degrees in 3D, as on a turntable, with a sheen sliding across the product only. Keep the angle under about 18: it is a flat photo.
- `WM.spotlight(tl, '#light', at, {to: {x: 540, y: 870}, lift: 1.5})`: the stage goes dark and a pool of light travels in and lands on the product, then opens a little; `lift` fades the dark away that many seconds later. `#light` is an empty full-frame layer above the scene; `to` is the product's centre.
- `WM.explode(tl, '.ingredient', at, {from: '#product'})`: the ingredients or parts burst out of the product and settle where you laid them out, each with its label. Lay each one out at its final place (around the product, inside the safe area); they start at the product's centre.
- Before and after: `WM.split` (two panels with a divider) or the registry's `before-after-wipe` / `comparison-split`. A key light crossing the scene: the registry's `light-sweep-pass`.
- A product with no photo, modelled in 3D: W3D.prop (kit/three.md) instead of these.

## A hero beat, in order
spotlight lands (1.2 s) → productTurn while the name or claim writes on → explode the ingredients with labels → lift the dark → the price or offer.

## Rules
- Only the user's own product photo or an approved generated one; never redraw a real product's label or logo.
- One hero treatment per video: a turn, a spotlight or an explode carries the beat, not all at once on every shot.
- Ingredient and part names come from the approved facts or the product page; never invent them.
