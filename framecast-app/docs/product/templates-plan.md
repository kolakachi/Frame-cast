# Templates plan

Owner decisions, 2026-10-16. Mockups: `create-ui/templates-mockup/v2/` (index, template, staff). Todo: TEMPLATES in `create-todo.md`.

## What a template is

A finished Create version that anyone can start from. It is a reference like any other, with one difference: we already know its parts, so nothing has to be studied or guessed. The agent is not boxed in: the user picks how closely to follow the template, and the agent follows that choice (owner, 2026-10-16: "template should work how it is supposed to work, the agent should follow the reference options").

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

What may change depends on the reference option the user picks (below), not on the template.

Builds already declare text and colours as HyperFrames composition variables, and every new build should keep doing so (the builder writes them today; `preflight.mjs` fills gaps). A template without variables can still be used, through the builder.

## "Make mine"

1. Start a new conversation for the user with the template as its reference and the reference option they picked (default: exactly, since they chose a finished video).
2. The Creative Director plans as for any reference, with the template's parts list, plan and code in hand, and fills each part, inferring first:
   - product link → name, photos, selling points (the product read UGC already uses);
   - brand kit → colours, fonts, logo;
   - uploads → their photos;
   - ask in chat only for what is missing (offer, call to action).
3. Style rules stay in charge: brand colours go into the template's colour roles only if text stays readable; copy longer than a slot is shortened and shown before anything is made.
4. The production follows from the option, not the other way round:
   - exactly, and the plan only swaps parts → no AI builder: variables for text and colours, photo files swapped behind their slots, voice re-made at the template's line starts, then one render and the usual checks; the builder is called only to repair a failed check;
   - exactly, but something can't be a plain swap (a longer story, a new beat, a shape change) → the builder edits from the template's code;
   - similar → the builder makes a new video keeping the template's format, look and pacing;
   - inspired → an ordinary build that takes the template's idea.
   - new generation (voice, music, AI images, AI clips) is bought per item in every case.

## Price

- Exactly with only swaps: low and the same each time for a template, so it is quoted per template, not estimated from the style. Similar and inspired are quoted like any build.
- Per item: anything generated new for this product, each listed with its price ("Voice: 3 cr, 2 AI clips: 240 cr"). The user can drop an item before paying, for example their own photo instead of an AI image.
- CostEstimate gets a `template` kind, learned from template runs, so neo-brutalism's style weight (2.0×) does not inflate template quotes.

## How references relate

Create already asks how closely to follow a reference video (`ReferenceMatch`): exactly (moment for moment, brand and content swapped), similar (format, look and pacing, own story), inspired (just the idea). Templates use the same three options, shown on the template page next to "Make mine", and changeable in Details as today.

## Test (2026-10-16): Ridge 2 → Volt Rush, no image or video generation

Change run on the Ridge 2 conversation (aeb4755c, ws1), with two Volt Rush photos attached (assets 5450, 5585), text and colours given, the music kept, only the voice made new.

Result (run 7b22a17d, version 2 of aeb4755c; video `~/Downloads/template-test-volt-rush.mp4`):

- Looks right: same beats, moves, cuts, fonts and music; the Volt Rush photos sit in the bordered cards; new words, lime field, black offer burst; voice re-made (3 cr).
- Cost: 566 credits ($2.23) against 971 ($3.84) for the original build, so 42% cheaper. The quote estimated 227, so the estimate was 2.5× low.
- Where it went: six large early builder calls (about 470 cr) re-reading and re-writing the whole file; then waste the template didn't need:
  - a preview failed because the new voice was shorter than its slot (13.6 s vs 14.7 s);
  - the finish was refused twice by the "search the art library for icons or 3D" rule, which a swap never needs;
  - a repair round for a real fault the swap made: "ZERO SUGAR" was on screen 1.3 s and needs 1.5 s to read. (Earlier I wrote the final check demanded three moves the template never had; that was wrong: those were notes on the builder's previews and blocked nothing.)

Conclusion: having the builder do the swap is cheaper but not cheap enough. Most of a template swap needs no model at all:

| Part | No-model way |
|---|---|
| Text, colours | variables (free edit, `--variables`) |
| Photos | swap the image file behind each slot (same element, `object-fit` keeps the frame) |
| Voice | re-voice the new script line by line at the template's line starts (TTS, about 3 cr) |
| Music, fonts, moves | kept as they are |

Then render and run the usual checks (this is the exactly-with-swaps case above). The builder is called only when a check fails (a line too long for its slot, a photo that reads badly), as a small repair. That would put a no-generation template at about the voice cost plus one render, and a fixed price we can quote.

Fixed (2026-10-16), for template runs and ordinary changes alike:
- the art-library rule fires on a change only when it asks to add art (it matched "the stickers' fills");
- a sound that ends a little early (up to 10% of its slot or 1.5 s) no longer fails the preview; video stays exact;
- template runs are their own kind in the estimate, learned from each other once there are three, without the style's weight.

Note: version 1 of aeb4755c (revision 08fd1388) is the template; version 2 is this test.

## Status (2026-10-16)

Built (not deployed): the library (32 examples seeded, vault order kept), staff "Add to templates" in a side drawer, the Templates page and template page, "Make mine" following the reference options (exactly from a version copies it into the user's workspace as version 1; anything else attaches the template's video as the reference), the three fixes above. Tested in the browser locally: page, filters, our dropdown, phone width, add a version, Make mine → Create plans it and asks for what is missing.

Built 2026-10-16 (step 4): a change that only swaps parts is made without the builder (PartSwap). The Creative Director decides it, from the version's text and colour fields (with max_chars), its pictures (current_pictures) and the attached images; the quote bakes the new values and photos into the version's code; nothing to buy means a free re-render that runs like any small job, and a new voice uses the VOICE-ONLY swap (only the voice is charged). Anything else (layout, timing, music, new elements, text that needs more room, words that are fixed in the template) is built as usual. After the render, text the new words push off the frame (edge findings the version did not already have) is named in the version note.

Local test (Brightline on the WyvStudio motion film template): the planner chose a swap on its own (5 texts and colours, the logo), 0 credits, one render, no builder. The first run showed a long hook running off the frame and a fixed template word ("Running") staying; the planner now gets each field's limit and is told fixed words stay, and wrote "money, sorted?" to fit, saying so in its assumptions.

## Build order

1. Data and staff action: `create_templates` (version, title, category, description, ask hint, style, shape, length, parts list, preview), "Add to templates" in a side drawer on a finished version, staff-only.
2. Templates page and template page, behind the staff-only switch, with the reference option next to "Make mine".
3. "Make mine": a new conversation with the template as reference and the chosen option; the planner gets the parts list, plan and code.
4. The no-builder production for exactly-with-swaps, with the builder as repair.
5. The three fixes above, and a `template` kind in CostEstimate.
