# Create bench: briefs, coverage and acceptance rules

Started 2026-10-05 (G1 and M3 in `create-generated-video-todo.md`). Every change to Create's generated video is judged
on this bench. Each run is measured with `php artisan create:bench-report {conversation}`, plus the owner's scores.
Runs use the test account only (kolakachi@gmail.com, workspace 1).

## Coverage: video type × creation mode

Each cell names its expected route per layer. **Bold** cells are on the bench; the others are covered by a neighbour
with the same routes.

| Type | From scratch | Exact | Similar | Inspired |
|---|---|---|---|---|
| Motion graphics / animation | **B3**: all code (HyperFrames, Remotion, 3D) | **B1**: code, reference scaffold | **B2**: code | code, as B3 |
| UGC / presenter | **B6**: take (Omni with avatar) + code captions | **B9**: take from reference timing + code captions | take, as B9 with freedom | take, as B6 |
| Footage-based | generated shots or sequence + code end card | generated shots matched to reference moments | **B5**: generated sequence or shots | **B4**: generated shots or sequence |
| UGC + motion graphics | **B7**: take + code panels (mixed layouts) | take + code, reference layout | as B7 | as B7 |
| Footage + motion graphics | **B8**: generated shots + code overlays and end card | shots + code, reference layout | as B8 | as B8 |

## Acceptance per cell (M3)

A cell passes when its type's checks and its mode's checks pass, on top of the blocking items under Acceptance.
**Auto** means the build or the final checks test it on every run; **Owner** means it is judged in the 1-to-5 scores.

By video type:

| Type | Must hold | How it is checked |
|---|---|---|
| Motion graphics | Every approved line on screen long enough to read; real UI from the page capture, never placeholders; the named moves built; effects on the moves | Auto: reading time, named moves, layout; Owner: motion, sound |
| UGC / presenter | The person is the user (or the approved creator) throughout; every approved word spoken; captions match the speech; lips plausibly in sync | Auto: listening, identity; Owner: direction |
| Footage-based | The same people and places across shots; each shot's directed action visible; no generated lettering; no blank frames; ambience under the voice | Auto: identity, per-shot action frames, lettering, blanks, ambience level; Owner: look, direction |
| UGC + motion graphics | One continuous take across the layout changes; each panel lands on the words that mention it; graphics never cover the face | Auto: take plays at least 90%, spoken cues; Owner: direction |
| Footage + motion graphics | The product recognisable in every product shot; overlays legible and drawn in code, not baked into the clip; end card held at least 2 s | Auto: required items, lettering; Owner: brand fit |

By creation mode:

| Mode | Must hold | How it is checked |
|---|---|---|
| From scratch | The brief's required items, in the order asked | Auto: required items |
| Exact | Every reference moment kept or replaced (none silently dropped); each at its reference time, with its elements in their reference slots; the reference's length | Auto: moment coverage, layout check, compare sheet |
| Similar | The reference's pacing within 20%; its transitions and type animation style; its camera journey where it has one | Auto: pace comparison; Owner: look, motion |
| Inspired | The reference's format kept (a story stays a story, a talking head stays one); everything else the brand's own | Owner: direction |

## The briefs

All 9:16 unless stated. Approved facts for WyvStudio:
- Paste a script, link, or idea
- Voiced, captioned, ready-to-post video
- Schedule to YouTube, TikTok, or Instagram
- Start with the $9 Test Pass

| # | Brief | Inputs | Type, mode | Required (absence = incomplete) |
|---|---|---|---|---|
| B1 | Copy the reference video exactly, with WyvStudio's content and our 3D mascot from the avatar | Reference asset 1590 (15 s, dithered 3D mascot promo); the bear avatar; wyvstudio.com | Motion graphics, exact | Every reference moment kept or replaced with its move; mascot speaks the narration; length matches the reference |
| B2 | Something like this for WyvStudio, same pacing and type animation | `~/Downloads/ssstwitter.com_1791140902231.mp4` (14 s, 1:1 UI motion) | Motion graphics, similar | The reference's text-animation style, transitions and pacing; real WyvStudio UI from the page capture |
| B3 | A 20 s explainer of how WyvStudio turns a link into a video, kinetic type and UI, no reference | wyvstudio.com | Motion graphics, scratch | Three steps shown in order; the call to action held at least 2 s |
| B4 | I love this video, something similar for WyvStudio, keep the drawing style | Reference asset 1635 (DistroKid, 34 s, drawn world); wyvstudio.com | Footage, inspired | A drawn world throughout; one character consistent across shots; the glow turning point; every approved word |
| B5 | As B4, but similar: keep the camera journey and pacing | Reference asset 1635 | Footage, similar | As B4, plus a continuous camera journey and the reference's pacing within 20% |
| B6 | A 15 s UGC ad: me talking to camera about WyvStudio | The owner's own photo (consented); wyvstudio.com | UGC, scratch | The avatar's identity held; every approved word spoken; captions match the speech |
| B7 | A UGC ad where I talk on top and the app demo animates below, full screen for the hook and the end | Same photo; wyvstudio.com | UGC + motion graphics, scratch | Take continuous across the layout changes; demo panel synced to the words that mention it |
| B8 | A 15 s product ad: a candle on a shelf in a warm shop, with price and offer overlays and an end card | A product photo (owner supplies, or a stock candle); approved offer text | Footage + motion graphics, scratch | The product recognisable in every product shot; overlays legible; no generated lettering |
| B9 | Copy this UGC ad exactly for me | A UGC reference video (owner supplies); the owner's photo | UGC, exact | The reference's beats, timing and gestures; the avatar's identity; every approved word |

**Inputs still needed from the owner:** a consented photo of yourself (B6, B7, B9), a UGC reference video (B9), and a product photo (B8; a stock candle is the fallback).

## What every run records

From `create:bench-report`:
- **Time:** plan, look stage and full build (each from request to result), plus provider queue time where known.
- **Credits:** charged, by item and by stage.
- **Provider cost:** real cost in USD once section 0.5 lands; until then marked "tariff estimate".
- **Rejected or failed generations,** holds, manual recoveries, retries and repair runs.
- **The plan's routes:** which layers were code, generated (engine), the user's footage or stock.

From the owner, scored 1–5:
- **Direction:** the story and actions make sense, gaze and timing are natural.
- **Look:** style, the reference match where asked, consistency.
- **Motion:** acting, camera, transitions, type animation.
- **Sound:** voice, music, effects, mix.
- **Brand fit:** the claims, colours and product right.

## Acceptance

**A run is accepted when** every blocking item passes and the owner's scores average at least 4 with none below 3.

**Blocking items:**
- **No lost words:** the delivered speech contains every approved word, checked by transcript against the script.
- **Every Required item for the brief is present in the final encoded video,** with a timestamp.
- **Identity holds:** the same character and product throughout; the avatar recognisable.
- **No unintended lettering, blank frames or broken audio.**
- **No hold or manual recovery.**
- **Credits charged no more than the approved quote.**

**A brief passes** when it is accepted within 2 attempts. A first-attempt failure is recorded, not hidden.

## Release gate (generated video beyond the test account)

- 8 of 9 briefs pass, and B1 to B3 (motion graphics) all pass, so the regression is clean.
- Across all attempts: 0 lost words, 0 identity failures, 0 manual recoveries.
- Cost per accepted result recorded for every brief. The targets are set from this first baseline round, then held.
- After that: a limited audience with an off switch, and this bench re-run before widening.

## Budget

- **Baseline round: at most 7,000 credits** across all briefs and attempts (owner to confirm).
- Expected costs:

| Briefs | Credits each |
|---|---|
| Motion-graphics briefs | about 100–300 |
| Generated briefs | about 500–900 |

- The round stops and reports if the cap would be crossed.

## Results

### 2026-10-05: DistroKid, similar (B5-like; creation `cfb5e61f`)

Settings: 9:16, 15 s. Plan on Opus 5.5: a cast of 3 (creator, house, room), a storyboard of 3 panels, 3 Seedance 2.5 shots.

**The owner's verdict:** "this is a good result".

| Stage | Time | Credits |
|---|---|---|
| Plan | about 3 min | free |
| Look stage | about 9 min | 347 |
| Full video | 12 min | 641 (3 shots 462; real provider cost $3.24) |
| **Happy path** | **about 21 min** | **about 988** |
| Lost to 6 bugs found and fixed that day | | 454 |

**Final checks:**
- Passed: the wordmark, the real input screen, the orange glow, the same person throughout.
- Unverified: the spoken words (listening hit a provider hiccup; it now retries).

**Not yet scored:** the five 1-to-5 scores.
