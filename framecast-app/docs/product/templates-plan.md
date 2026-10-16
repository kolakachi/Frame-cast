# Templates plan

Owner decisions, 2026-10-16. Mockups: `create-ui/templates-mockup/v2/` (index, template, staff). Todo: TEMPLATES in `create-todo.md`.

## What a template is

A finished Create version that anyone can start from. "Make mine" makes the same video for the user's product: same layout, moves, timing, style and length, with their product, words, colours and photos.

## Who sees it

- Templates (nav item, page, every template) are staff-only until one switch makes them public (env flag, as `UGC_TYPES_LIVE`). No per-template visibility.
- Staff add a template from a finished version with "Add to templates", which opens in a side drawer (`SideDrawer.vue`, like "Change this video").
- Filters and the drawer use our own dropdown (`UiSelect.vue`, with a borderless variant for the filter row), never a native select.

## How many before going public

12, at least 2 per category tab, with some 1:1 and 16:9: offer ads 3, UGC ads 3, app showcases 2, explainers 2, announcements 2. Made from staff builds only, never customer videos. Each is a paid build (about $1–4); ask before making them.

## The parts list

Written when staff add a template, from the version's code and the record of what it bought. Staff check it in the drawer.

| Part | Recorded | Where it comes from in the code |
|---|---|---|
| Text | each line: role, default, most characters that fit | `data-composition-variables` (string) |
| Colours | each colour's role: field, accent, ink… | `data-composition-variables` (color), used as CSS vars |
| Fonts | headline and body families | `@font-face` in the bundle |
| Images | each slot: element id, shape, what it shows, source (user photo, AI image, stock) | `<img>` sources + `create_plan_media` |
| Voice | script lines and timing | the narration track + plan |
| Music and sound | the track and effects | `create_plan_media` (music) |
| AI clips | each generated moment and what it shows | `create_plan_media` |

Structure, motion and timing are locked: they are the template.

Builds already declare text and colours as HyperFrames composition variables, and every new build should keep doing so (the builder writes them today; `preflight.mjs` fills gaps). A template without variables can still be used, through the builder.

## "Make mine"

1. Copy the template's version into a new conversation for the user.
2. Fill each part, inferring first:
   - product link → name, photos, selling points (the product read UGC already uses);
   - brand kit → colours, fonts, logo;
   - uploads → their photos;
   - ask in chat only for what is missing (offer, call to action).
3. Style rules stay in charge: brand colours go into the template's colour roles only if text stays readable; copy longer than a slot is shortened and shown before anything is made.
4. Make it in the cheapest way that works:
   - text and colours only → a free edit (`FreeEditService`): one render, no model call, no credits. HyperFrames renders new values with `--variables`, and `--batch` renders many rows at once.
   - photos, voice, any part that is not a variable → a change run (the builder swaps them by patch).
   - new generation (voice, music, AI images, AI clips) → bought per item, as today.

## Price

- Fixed: the swap. Low, and the same each time for a template; quoted per template, not estimated from the style.
- Per item: anything generated new for this product, each listed with its price ("Voice: 3 cr, 2 AI clips: 240 cr"). The user can drop an item before paying, for example their own photo instead of an AI image.
- CostEstimate gets a `template` kind, learned from template runs, so neo-brutalism's style weight (2.0×) does not inflate template quotes.

## How references relate

Create already asks how closely to follow a reference video (`ReferenceMatch`): exactly (moment for moment, brand and content swapped), similar (format, look and pacing, own story), inspired (just the idea). A template is "exactly" with the parts list known up front, so nothing has to be studied or guessed.

## Test (2026-10-16): Ridge 2 → Volt Rush, no image or video generation

Change run on the Ridge 2 conversation (aeb4755c, ws1), with two Volt Rush photos attached (assets 5450, 5585), text and colours given, the music kept, only the voice made new.

Result (run 7b22a17d, version 2 of aeb4755c; video `~/Downloads/template-test-volt-rush.mp4`):

- Looks right: same beats, moves, cuts, fonts and music; the Volt Rush photos sit in the bordered cards; new words, lime field, black offer burst; voice re-made (3 cr).
- Cost: 566 credits ($2.23) against 971 ($3.84) for the original build, so 42% cheaper. The quote estimated 227, so the estimate was 2.5× low.
- Where it went: six large early builder calls (about 470 cr) re-reading and re-writing the whole file; then waste the template didn't need:
  - a preview failed because the new voice was shorter than its slot (13.6 s vs 14.7 s);
  - the finish was refused twice by the "search the art library for icons or 3D" rule, which a swap never needs;
  - the final check asked for three moves the plan named but the template never had, which cost a repair round.

Conclusion: having the builder do the swap is cheaper but not cheap enough. Most of a template swap needs no model at all:

| Part | No-model way |
|---|---|
| Text, colours | variables (free edit, `--variables`) |
| Photos | swap the image file behind each slot (same element, `object-fit` keeps the frame) |
| Voice | re-voice the new script line by line at the template's line starts (TTS, about 3 cr) |
| Music, fonts, moves | kept as they are |

Then render and run the usual checks. The builder is called only when a check fails (a line too long for its slot, a photo that reads badly), as a small repair. That would put a no-generation template at about the voice cost plus one render, and a fixed price we can quote.

Fix in any case (they hurt template runs and ordinary changes alike):
- the art-library rule should not fire on a change that only swaps content;
- final checks should judge a template run against the template's own moves, not plan wording it never had;
- a re-voiced line that is shorter than its slot should be placed, not fail the preview.

Note: version 1 of aeb4755c (revision 08fd1388) is the template; version 2 is this test.
