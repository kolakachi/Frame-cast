# LENGTH-TARGET: the chosen length is a target, not a limit

Plan for the owner, 2026-10-19. Not built yet.

## Why

Owner: "we should not be capping the seconds because the video models don't respect the length we set". A customer's
15 s presenter video (Brett, ws 79) was built 16.3 s long so the presenter finished her call to action, and the render
check (the chosen length within 0.15 s) refused it on every retry, charging each time (1,000 credits refunded). The
same day the check was loosened to 25% over (render-length.mjs); this plan removes the cap as a pass/fail rule.

Measured on prod (last 45 days, 42 clips): Omni and Seedance clips come back at the length asked (within 0.06 s);
lip-synced takes drift (15 s asked, 19.5 s back; 17 s asked, 13.9 s back). Brett's take was 17 s because the plan asked
for 17 s inside a 15 s video: the planner sizes a take to the time its script needs to be said.

## What changes

1. **The plan shows the real length.** When the takes or the script need more time than the chosen length, the plan
   says so before anything is bought ("about 17 s: the presenter's lines need it"), so the price and the length are
   seen together.
2. **The build follows the content.** The builder lets the video run as long as its takes, clips and speech need,
   instead of stretching or squeezing to an exact number.
3. **The render checks the file, not the target.** It confirms the video matches what was built (size, sound, plays to
   the end) and is no more than 30% over the target (15 s → 19.5 s, which covers every clip measured); past that the
   plan is wrong, and the message says what to shorten.
4. **The real length is shown** on the version and in its summary.
5. **The price does not change** when a video runs longer: building costs barely depend on length, and rendering is
   free to the user.

## Checked by

- Tests: the render check passes a page up to 30% over and refuses past it, with the measured lengths in the message;
  the plan's length note for a script longer than the chosen length.
- Replay of Brett's build (16.3 s) and a lip-synced take that came back 19.5 s for 15 s.

## Size

About half a day: worker (render check, builder instruction), API (plan length note, version length), web (show it).

## Built (2026-10-19, owner: "go build it")

- **Builder:** the beat sheet from the presenter's or narrator's timed words (narration-timing.mjs) runs to the last word
  when the voice runs past the chosen length, up to 30% over (15 s → at most 19.5 s), and says so (`chosen_seconds`);
  the builder is told to make the page that long and never cut or speed up the speech.
- **Render:** the page's own length is rendered and checked when it is up to 30% over the chosen length
  (render-length.mjs); past that it is refused with the measured lengths and "retrying makes the same video".
- **Real length:** stored with the version (`delivery_checks.seconds`) and shown on it ("16 s").
- **Plan card:** when the presenter's takes need longer than chosen, a "Length" note says how long the video runs and
  that the price is the same.
- Tests: worker (beat sheet stretch, render length 30%), API (stored length). Price unchanged.
