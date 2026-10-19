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
