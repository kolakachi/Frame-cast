# The HyperFrames registry (vendored blocks and components)

Hundreds of finished motion pieces ship with the sandbox: device and browser stages with a slot for real UI, caption styles, CTA lockups, logo stings, count-ups, charts, chat and notification mock-ups, cursor paths, before/after wipes, halftone and grain textures, light leaks, shader transitions, a Lottie mascot. Search them before hand-building any named visual; wire one and set its variables instead of writing it from scratch. Hand-build only what the catalogue lacks.

## Find
`catalog {query}` ranks items by name, title, tags and description (add `tag` such as transition, captions, mock-ui, product-demo, typography, background, cta, character, overlay; `type` block or component). `catalog {name}` with an exact name returns the item in full: its variables, size, duration, how it mounts, and its usage header (concept, envelope, variables).

## Wire a sub-composition (blocks, and components whose mount is "sub-composition")
One div in the host composition; the runtime loads the file, seeks its timeline in sync, and scales it into the box you give it:
```html
<div id="hero-cta" data-composition-id="cta-lockup" data-composition-src="compositions/components/cta-lockup.html"
     data-start="11" data-duration="4" data-track-index="3" data-width="1920" data-height="1080"
     data-variable-values='{"action_line":"Make your first video","button_label":"Try WyvStudio","accent":"blue"}'
     style="position:absolute;left:1000px;top:560px;width:800px;height:400px"></div>
```
- `data-composition-src` is the item's `entry` from the catalogue, exactly. `data-composition-id` must equal the item's name. `data-width`/`data-height` are the item's own dimensions; the `style` box is where it sits on your stage (the runtime scales the item into it).
- `data-start`/`data-duration` place it on your timeline; duration at most the item's own duration (a shorter mount cuts its tail; many items hold their end state, see the usage header's envelope).
- `data-variable-values` is a JSON object of the item's variable ids; strings, numbers, colours, enum values as listed. Text longer than a variable's maxLength is cut.
- Give every mount an `id`. Several mounts of the same item are fine with different ids and values.
- Items with a "slot" (device stages, frames) take your own content inside the mount div, as the usage header shows.

## Use a snippet (components whose mount is "snippet")
Read the item with `catalog {name}`: the usage header says which HTML to place inside your stage, which CSS to add, and which timeline calls to make. Copy only those parts into index.html, style.css and main.js. Snippets inherit your stage's size and duration.

## Rules
- The sandbox serves `gsap.min.js`, the GSAP plugins under `gsap/`, `lottie_light.min.js` and the shipped fonts at the project root; wired items already point there. Never add script tags to URLs.
- A wired item's files are staged for you at check time; do not write them yourself. Reference only names the catalogue returns.
- One signature item per video is plenty; the rest is layout, type and your own timeline. Match the item's colours to the brand through its variables (accent, colours) and keep the brand's fonts on your own text.
