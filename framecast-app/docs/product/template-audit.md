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

## The gate

`config('create.free_templates')` lists the audited templates; `FreeFirst::templateFits` also needs every text field to
be read by the code. A template joins the list only after a marker render shows every word changing.
