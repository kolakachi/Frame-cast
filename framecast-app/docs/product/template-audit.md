# Template render audit (2026-10-17)

Why: the owner's free ad on led2 kept "wyvstudio.com" on its end card. A code scan can't prove what shows on screen, so
each of the 16 code-backed templates was copied from prod to the local stack, every text field was set to a marker
(ZQ1, ZQ2…), and the copy was rendered (free; no AI). Frames of the original and the marker render were compared side by
side. Any original word still on screen in the marker render is written into the template's code, and a free ad would
keep it.

Contact sheets (local only): `$CLAUDE_JOB_DIR/tmp/audit-sheets/<id>.png`, original on top, markers below.

## Result

| Template | Leftover text after the swap | Verdict |
|---|---|---|
| tbdvabrrxr How it works, drawn on a whiteboard (Parcelpath) | none | **Free** |
| lbaf5605f "Describe it." kinetic promo | none | **Free** |
| thntryaaux Product mechanics in blueprint style (Breeze) | end wordmark "BREEZE" | Hand fix: 1 spot |
| tdkhksrewe App tour on a phone (Moodline) | "A clearer head." (its field is never read) | Hand fix: wire line_1 |
| le55486c6 Ember & Oak candle offer | candle label "Ember & Oak", "warm", "and quiet", "20% off this week" | Hand fix: 4 spots |
| t2okcggzxx Calm product offer (Halden) | header "Halden · Ceramics", "Launch price", "$39", "$55", "halden.co" | Hand fix: 5 spots |
| l30703d4a Northside Roasters subscription | header, end card "Northside Roasters.", "25% off" badge | Hand fix: 3 spots |
| l8623176a App stat promo (Ledgerly) | "Ledgerly" logo, "app.ledgerly", stat labels and numbers | Hand fix: several |
| tu2jonrrdi Product drop (Volt Rush) | "VOLT RUSH" stickers and tickers, "4-PACK $9.99", "ZERO SUGAR" tags | Hand fix: several |
| l292 Bold type with a shape reveal | "SHOOT", end recap "BRANDED VIDEO WITHOUT A SHOOT" | Hand fix: 2 spots |
| le0c668f6 For small shop owners, 3D icons | the WyvStudio "W" mark at the end | Needs a logo slot |
| led2 Three steps to a product ad | "Describe / Approve / Get the video" UI and the WyvStudio end card | Not a fit: it's about WyvStudio |
| l1317b615 Kinetic type, word by word | everything except the end URL | Rebuild or drop |
| tfkowi1kr3 10-second kinetic type promo (Lumen) | everything | Rebuild or drop |
| tqh3hmfdw4 Launch in bold type (Northwind) | everything except "In stores" | Rebuild or drop |
| l21287e12 Flat-colour explainer with a chart | almost everything; WyvStudio UI | Rebuild or drop |

## Found on the way (fixed)

- **Brand fonts on free edits:** the free-edit render never fetched the template's Google brand fonts (Space Mono,
  Archivo Black), so a free ad or text edit on those templates failed its render check. The voice-swap path already
  fetched them; the free-edit path now does too.
- **Shape and length:** a "15-second vertical" brief set 9:16 / 15 s on a square 20 s template; the swap renders the
  template's own picture, so the render failed its size check. The free ad now keeps the template's shape and length
  (brief, Details and the quote), and any swap refuses a shape or length change (that needs the builder).
- **Exact copies attached a reference:** since e3dc0ba6, an exact copy of a template that wasn't free-eligible also got
  the template's video attached as a reference. An exact copy never needs one; fixed.

## The gate and the tool

A template is free only when its render audit passed (`create_templates.free_audit_json.passed`) and every text field is
read by its code (`FreeFirst::templateFits`). The two hand-audited templates were recorded by the migration.

`ops/template_audit.sh <template id | revision uuid>` (local, free, no AI):
- exports the version and runs `hyperframes-worker/agent/template-audit.mjs` in the render sandbox, which loads the
  page with the render's runtime files and seeks through it twice:
  - **short markers** (ZQ1, ZQ2…) in every text field: any other word in the frame, on top and readable, is
    **written into the code**, and a field whose marker never shows is **never on screen**;
  - **long values** (1.5× each sample's length): text that stays out of the frame or its clipping box for half a
    second, while its short marker was inside, **runs out of its box**;
  - a page script error fails the audit;
- with a template id, records the result with `create:template-audit`, which checks the result is of the template's own
  code (a hash that ignores asset ids, so a local audit can be recorded on prod after the move);
- `node agent/template-audit.mjs <id> shot <seconds> [short|long]` in the sandbox saves a picture of a reported moment.

Not seen: text drawn on a canvas or baked into a picture (pictures are slots anyway).

### First tool results (2026-10-17)

The tool agrees with the hand audit on leftovers. It also finds that both free templates break with longer copy:
the whiteboard's step labels and highlight run out of their boxes, and the "Describe it." promo's end link runs off
the frame at 1.5× its length. Both stay free (recorded by hand) until fixed.

## Building templates from now on

Staff tick **Build as a template** in Details (`settings.template_build`): the planner lists every on-screen word as
copy and buys nothing generated, and the builder gets the template rules (`TEMPLATE_RULES` in composition-agent.mjs:
every word a field, colours as fields, pictures as slots, text that fits short and long values, Google fonts only, a
voiceover line per scene). Then run the audit; only a pass makes it free.

## The first template builds (2026-10-17, local)

Eight kinds, each with "Build as a template" on: new drop (Volt Rush), sale countdown (Hush One), three reasons
(Fieldwell), problem then fix (Arc watch), review card (Halden), app on a phone (Habitly), local shop (Corner Grocer),
food (Saffron Street; the dish photo generated, one nano-banana call). Sample brands are made up.

- First pass: 3 of 8 passed (three reasons, app, local shop). The others wrote words into the code (tickers, circle text,
  word-by-word quotes split from fixed text) or declared fields they never read.
- From build 5 on, the builder's own check runs the audit (live-tool.mjs) and fails on words in the code or fields never
  shown, so it fixes them before finishing. One fix round, with the findings as the message, made all 8 pass.
- Spend: about 3,100 credits charged in all (8 builds, 6 fixes, planning), $11–14 of real model and media cost.
- Audit tuning from these builds: letter-spaced text is words; per-letter wrappers are climbed; cut-out photos don't
  cover text; overflow needs three samples in a row (entrances pass through a box edge faster).

## The second batch (2026-10-18)

Owner: new templates instead of repairing the near-clean ones (those stay paid-only). Eight more kinds: skincare steps,
book or course launch, before and after, gift guide, new colour with sizes, only a few left, pop-up or event, how it
works. With the audit inside the builder's check, 7 of 8 passed on the first build; the event's long type ran off the
frame and passed after one fix. Six sample photos generated (nano-banana). About 2,000 credits. The whiteboard and
"Describe it." templates left the free list (both broke on longer text): 16 free templates on prod.

## Fixed text under a click-through layer (2026-10-19)

The audit asks the browser what is under each point of a text, and the browser skips layers with `pointer-events:none`.
The skincare template draws its top label ("Dewlane · Glow Serum") in such a layer, so the label read as covered by the
scene underneath and the audit passed with an old brand on every copy (found making OUTREACH-5's Hinlura ad). The
audit now makes every element answer that question (it injects `*{pointer-events:auto !important}`; nothing is
clicked), and caught it at once: `Written into the code: Dewlane · Glow Serum`. The label was bound to the Wordmark
and Product name fields on prod, and the 16 free templates were re-audited on prod's own code with the fix.

## One long word per field (2026-10-19, TPL-AUDIT-WORDS)

The long pass wrote "ZQ3 fresh daily goods", short words that wrap; a real brand name or web address does not
("WicksNWax" and "MAPLE" were cut off in OUTREACH-5's ads, "montyseleven.co.uk" did not fit). A third pass now puts one
unbreakable word in every text field, as long as the planner may write it (1.25x the default, at most 12 letters where
the default has spaces), and fails a field whose word runs out of its box (`too_wide`). Three blind spots found on the
way: overflow was excused field by field, so a hidden copy of a scene kept for a transition excused the real headline
(now element by element); letters split for kinetic type were judged covered once pushed past the edge, so never
measured (now measured as their word, on the part a viewer can see); and the font's line height read as text cut by a
tight reveal mask (within 30% of the font size up and down is allowed). In template builds it is a note to the builder
("let it shrink to fit"), not yet a block.

Local run on the 16 free templates (prod's code, not recorded on prod): 11 cut a 12-letter name somewhere, 5 pass
(skincare how-to, food dish, pop-up event, before/after, customer review). Checked by eye on four: real each time.

## Field text fits its box (2026-10-19, owner: option A)

One step in the shared motion kit (`WM.fitFields`, runtime/wyv-motion.js) instead of rebuilding templates: once a page
has written its string fields, any field text that runs out of its clipping box, its card or button (an element with its
own background or border) or the frame at the side is set smaller, aiming inside the padding and 4% in from the frame,
never below 30%. Only text that is a field's words (a "SALE ✦ SALE" ribbon is not), not type drawn bigger than half the
frame, not a giant wipe, not anything marked `data-wyv-nofit`. Measured at rest: every transform lifted, scenes not yet
shown displayed for the measurement, each field's font asked for first (a browser loads a face only when visible text
uses it), and each pass starts from the drawn size. Side to side only: up and down, a page stacks scenes it shows one
at a time, so load-time positions are not screen positions.

Checked: all 16 free templates' own sample designs and six of our recent Create videos are untouched; it shrank only real
overflows ("WicksNWax" and "MARINA" in today's outreach ads). The audit then: 15 of 16 pass; the sale countdown fails on
its End date flip board (fixed letter tiles cannot shrink). The long-word check now blocks template builds. Also: the card
rule is the long-word check's only (the long pass keeps clip and frame, as since 2026-10-17), number and price fields are
tested with numbers, and the audit measures words without the trailing space a wrapped line hangs past its edge.
