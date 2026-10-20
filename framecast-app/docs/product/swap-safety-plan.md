# SWAP-SAFE: a template copy with the customer's own words never falls into a priced build

Plan for the owner, 2026-10-19. Nothing here is built until approved.

## Why

HOME-CTA and outreach send new people into the free first ad: a copy of a template with their words, colours and
photo, made as a cheap swap (3 credits, no builder). Making ten outreach copies today showed three ways that swap breaks
on real customer words. For a paying user the price jumps from 3 credits to 175–1,000. For a free user it is worse: the
free plan only allows a swap, so they get no ad at all.

| What happened (2026-10-19) | Where | How often today |
|---|---|---|
| A new voice line takes longer to say than the sample's slot: "line 4 is 2.93 s against a 2.09 s slot". The build fails, and sending it again rebuilds the whole video at full price | `hyperframes-worker/agent/voice-swap.mjs:27` (speed-up capped at 1.1×, `RULES.maxTempo`); a failed swap sends the next plan to the builder (`api/app/Services/Create/VoiceSwap.php:60`) | Kailua (1.40× needed), Beelite (1.19×, 1.23×) |
| A word is longer than its field allows (1.25× the sample): "beelitecandles.com" in a field drawn for "fieldwell.co". The planner either puts it in the wrong field or plans a full build | `api/app/Services/Create/PartSwap.php:25` (`maxChars`) | Beelite (twice) |
| A reply to a copy whose swap failed is priced as a full build, even for a one-word change | the same rule in `VoiceSwap.php:60` | Beelite, Kailua |

The cut-off text that made 1.25× necessary is fixed (the motion kit's fit, deployed 2026-10-19), so the field limit
can now be relaxed safely.

## What changes

1. **Fields take up to 2× the sample's length.** `PartSwap::maxChars` goes from 1.25× to 2×. The fit sets text smaller
   to its box (down to 30%), and the template audit already tests one long word per field. A web address or brand name
   then fits, with no field swapped and no full build.
2. **A voice line that is too long gets a shorter spoken version, not a rebuild.**
   - First the speed-up cap rises from 1.1× to 1.15×, still natural, which fixes the near misses.
   - If a line still does not fit, the swap asks for a shorter *spoken* line once (one cheap model call plus one re-voice,
     about 3 credits, ours) while the screen keeps the full words: the screen says "beelitecandles.com", the voice says
     "Shop Beelite". It is spoken only if it fits.
   - Only if that also fails does the swap stop, and it never turns into a full build on its own (step 3).
3. **The free first ad never becomes a priced build.**
   - When a swap cannot be made, the free user is told plainly which line is too long and what length fits ("Make the
     web address shorter, up to 18 characters"), and their next try is still a free swap.
   - A paying user is offered the same choice first: "shorten this line (3 credits)" or "rebuild the video (about X
     credits)". The full build stays available, but never as a silent default.
   - The rule in `VoiceSwap.php:60` that sends every later plan to the builder after one failed swap now applies only
     when the user picks the rebuild.
4. **Plain message for the daily limit.** "Local pilot daily limit reached" becomes "You've made 50 videos today.
   More tomorrow." (`ConversationService.php:542`; the limit itself, 50 builds a day, is unchanged.)

## How it is checked

- Unit tests: `maxChars` at 2×; the voice fit at 1.15×; the shortened-line path in `voice-swap.mjs`; a failed swap no
  longer forces the builder unless the user chose the rebuild; the free plan never receives a priced quote from a swap.
- Replay of today's three failures locally (Kailua's address line, Beelite's web address, Beelite's "100% pure"): each
  must finish as a swap of a few credits.
- The 16 free templates are audited again (long word per field, at the new 2× length) before the limit goes up on prod.
- One paid canary on prod with a long brand name, then the five outreach copies of batch 2 as the live check.

## Cost and size

- Build: about a day. API (PartSwap, VoiceSwap, the free gate, one message), worker (voice-swap.mjs), web (the
  shorten-or-rebuild choice).
- Running cost: at most one extra model call and one re-voice per failed line, about 3 credits, paid by us on free ads.
- Risk: a shortened spoken line differs from the screen text. It is limited to lines that would otherwise fail, and the
  screen text never changes.

## Not in this plan

- Templates with fixed letter tiles (the sale countdown's flip board) stay off the free list.
- Planner slips (two fields swapped once, a confused question once) are watched, not fixed here: step 1 removes the
  main reason the planner swapped fields.

## Approved 2026-10-19 with three additions, and what was built

Owner: "yes approve SWAP-SAFE with 6, 7 and 8 added".

6. **Making the same version again is a free re-render.** The planner sets `swap: {"rerender": true}` with no parts
   touched; it renders the version as it is for 0 credits (it was priced as a full build, 1,005 credits). The render picks
   up fixes made since, such as the motion kit's text fit.
7. **A product that nearly disappears once cut out is flagged.** The cut-out measures how much of its own outline the
   product fills; under 15% (a cord bracelet 6%, chain necklaces 10–14%; jars, idols and hoops 30–87%) the cut-out is
   marked `cutout_thin`, and the planner still makes the swap but says in left_out that a worn or in-use photo in a photo
   template ("Three reasons to buy") would show it better.
8. **Prices take any currency.** A price or number field takes at least 10 characters ("Rs 2,700" where "$45" stood).

Built (2026-10-19):

- Field room (`PartSwap::maxChars`): one word twice the field's own (it cannot wrap, and the fit shrinks it); words that
  wrap half as long again (an extra line can run past a mask, which the fit does not shrink: tested by the audit's long
  pass); prices at least 10. The template audit's long-word pass uses the same room.
- Shorter spoken lines (`SpokenFit`): at the quote, before the voice is bought, each new line's spoken length (syllables,
  numbers, symbols, web addresses and street abbreviations as they are said) is compared with the line it replaces; one
  more than 10% longer is rewritten shorter by the small model (Haiku 4.5, cached), and only used if it fits. A line the
  worker measured too long on an earlier try is shortened by that measurement. The speed-up limit is 1.15x.
- After a failed swap: the message says "send it again and that line will be said shorter (the words on screen stay),
  or ask to rebuild (priced first)". Sending again stays a swap; only a request to rebuild uses the builder. Simplified
  from the plan: the choice is in the message, not a new button.
- The daily limit says "You've made 50 videos today. More tomorrow (the count resets at midnight UTC)."

## Canary on prod (2026-10-20)

Beelite's original words on "Three reasons to buy" (the template where they failed on 2026-10-19), in-house:
`beelitecandles.com` in the button and link fields, "100% pure beeswax" as a reason, the voice saying them.

- Planned as a swap (21 credits planning, at cost), quoted at **3 credits**, made: the long words kept on screen, the
  voice lines shortened before the voice was bought ("Pure beeswax", "Shop Beelite"); words, required items passed.
- Found and fixed:
  1. The web address ran 22 px past each end of the button pill: the text fit measured the card at rest, before
     `WM.morph` narrowed it into a pill. The morph now marks its narrowest box and the fit uses it (418dd882);
     re-rendered, the address sits inside the pill.
  2. "Render this version again" was quoted as a 355-credit build: the plan listed the video's narration and a
     voice-keeping swap allowed nothing to be listed. It now stays free (759cc94e); re-render quoted and charged 0.
  3. The free re-render's note read "Swapped in your , …"; it now says it made the same version again (d8f3808f).
- Still open: the reading check flags "Hand-dipped pairs" on screen 1.1 s against 1.9 s needed; a swap keeps the
  template's timing, so a longer reason has less time per word. Planning still asks for 60 credits available, an
  old-rate minimum (at cost it usually costs 18–40).
