# Create: everything pending

One sheet for every open Create item. Detail lives in the source doc; this sheet is what to look at first.
Last updated: 2026-10-06.

**Status:** `open` not started · `partly` some built · `blocked` waiting on the owner · `proposed` not approved ·
`ready` built, waiting for a run or deploy.

**Failure ledger** (`create:failures --days=7`, 2026-10-06): 52 of 120 runs failed or were held since 2026-09-29;
16,249 credits spent on them; 41 later recovered.

## Steps and asking

Plan: `create-steps-asking-plan.md` (order S11, S10, S9; three decisions).

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| S9 | Change… from the video: re-enter at Plan, Character or Storyboard; redo only later steps | open | — | build | progress S9 |
| S10 | Pause and ask inside a step (at most 2), resume without re-approval | open | — | build | progress S10 |
| S11 | Ask, don't assume: a vague change gets one question; the plan card shows what was assumed | ready | — | check in the app | progress S11 |

## Reference following (from the b49db3b7 review, 2026-10-06)

Built 2026-10-06 (uncommitted): a similar video takes the reference's length (up to 30 s); running captions follow
the reference unless the user asks for none; proof only from real material; material asked for before the plan;
words on screen too briefly to read block delivery and go back to the build; the take's presenter follows the sheet.

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| RF1 | Re-run the WyvStudio UGC brief: length, captions, proof and sound confirmed in the plan; materials now asked by rule | partly | — | the video build | this review |
| RF2 | The reference study listens for sound effects; the sound pass follows them (none when the reference has none) | ready | — | prove on a real reference with effects | this review |
| RF3 | Captions in Details: Automatic, None, My exact text | ready | — | check in the app | this review |
| RF4 | A mascot for mascot moments (WyvBear) | parked | owner: not now | — | AB3 |

## Credits

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| CR1 | Calibrate Quick and Thorough multipliers from real runs | partly | — | Quick 0.4 confirmed (82, 102 cr); Thorough one clean run (420 cr, ~1.8×); Thorough's reviewer never runs (decide) | todo: credits |
| CR2 | Estimates learned per video type and new-vs-change (falls back to all runs until 3 of a kind exist) | ready | more typed runs | — | todo: credits |
| CR3 | Question, file-sorting and study calls counted in the planning charge (a study billed once) | ready | — | — | todo: credits |
| CR4 | "Never more than" shows a real hold while testing without limits | ready | — | check in the app | todo: credits |

## Vendor errors and alerts

Built 2026-10-06 (uncommitted), all of `create-vendor-errors-todo.md` except the line below.

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| V8 | Reference study and transcription record vendor failures (done); final checks still skip quietly | partly | — | final checks | vendor-errors |
| V9 | Prove an alert end to end (a real mail arrives) | ready | — | send a test alert | vendor-errors |

## Build guards and caps (2026-10-06)

Built (uncommitted): planning at half price with no cap; per-call limits $1.20 build / $0.30 reviewer; build ceiling
5× the estimate (floor 1,000); no-progress guard (8 calls without a change stop the build, 16 before a first draft);
reviewer rounds shrink to fit a low balance; empty text blocks and bad read paths no longer stop a build; the
reference study retries a busy model, says when it is partial, and asks before an exact or similar plan is made blind.

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| BG1 | Thorough builds are reviewed before they finish (up to 4 reviewer rounds, stops when two do not improve) | ready | — | prove on a Thorough run; Standard still reserves an unused reviewer | CR1 runs |

## Generated video

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| B2 | Bounded bake-off of engines | blocked | budget cap + bench inputs | run | todo B2 |
| B3 | Drafts: does a re-render keep the performance | blocked | budget cap | run | todo B3 |
| B1 | Generated sequence route (experimental) | open | B2 | build if B2 earns it | todo B1 |
| D3 | Sequence coverage check | open | B1 | build | todo D3 |
| D7 | Best seconds of a clip longer than its slot | partly | — | build | progress D7 |
| 0.4 | Image in flight when the worker dies is lost | partly | — | build | progress 0.4 |
| E1 | In-world screen UI proven on a real clip | ready | a real run with a screen | run | progress E1 |
| E2 | Split-screen UGC test | ready | consented photo | run (bench B7) | progress E2 |
| F1 | Speed targets on the bench (plan ≤ 3 min, look ≤ 6, video ≤ 15) | blocked | bench | run | progress F1 |
| F2 | Builder starts on code beats while clips render | partly | after the bench | deferred | progress F2 |
| G1 | Bench baseline round | blocked | budget cap + bench inputs | run | progress G1 |
| G3 | Sonnet builds | partly | bench | measure | progress G3 |
| G4 | Cost per accepted result per route and engine | partly | bench | measure | progress G4 |
| H1 | Motion-graphics regression rendered and scored | blocked | bench | run | progress H1 |

## Art and brand

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| AB1 | Art packs on the build worker (10,923 items, 3dicons included) | done | — | — | `create-go-live.md` |
| AB2 | 3dicons added: 120 objects × 4 finishes × 2 angles, CC0, 960 files | ready | — | — | todo: art |
| AB3 | WyvBear and GSAP plugins staged into the builder | open | — | build | todo: art |
| AB4 | Brand logo and cutout proven in a real build; the builder must now search the art library when the brief asks for icons | partly | — | a run that uses art | todo: art |

## From scratch

Plan: `create-from-scratch-plan.md` (order FS3, FS1, FS2, FS4; FS4 needs your videos).

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| FS1 | Direction cards: five ways to make it (the planned one + four), in a drawer that opens by itself; Preview on the plan card; "More ways" (3 new, billed like planning); picking or mixing re-plans; locks on plan approval | done 2026-10-07 | — | owner browser check | `a2330e19`, `53575d83`, `616ad7d3` |
| FS2 | Format playbooks (10) and motion voices (6); concept with alternatives; the builder pins them | done 2026-10-07 | — | — | `ab4678cd` |
| FS3 | Slideshow and finishing checks: slideshow, edge margin, overlapping words, lopsided frame (sandbox image rebuilt 2026-10-07). Still to add: constant-speed motion, a technique repeated, full-frame hit count, opening hero | partly | — | build the remaining signals | `cd5bb2cc`, from-scratch plan |
| FS4 | Exemplar library | blocked | 15–25 videos from the owner | collect | from-scratch plan |
| FS5 | 14 new moves (push in, pull back, dutch, cold open, text mask, ramp freeze, hidden cut, odometer, gauge, streak, smash, split, stack, parallax), 37 in all, each with energy and length | done | — | prove in a build (none used one yet) | `0abef9cb`, `3cda32fb` |
| FS6 | Create works out what each file is for, asks one question when unclear (answer cards), places every file in a beat; .mov accepted; SVG logos as pictures | done (live test with a logo) | — | — | `6f8c6163` |
| FS7 | A screenshot of the current video is read as "change this moment" | done (tests) | — | try live | `6f8c6163` |
| FS8 | The user's own audio: voice = narration word for word (no voiceover bought), music = the bed (no music bought), sounds placed; mishearings corrected, invented lyrics ignored | done (planning proven live) | — | a paid build with own audio (~250 credits) | `ea37fb0e`, `1fc8a865` |
| FS9 | Details: labelled output form, "from your brief" tags, reference controls only with a reference; mid-creation rules (locked while building, plan out of date on change, format locked once pictures exist, next-version note) | done | — | owner browser check | `87d12831`, `2905b2dc` |

## Infra and release

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| I1 | API image rebuild hung on the yt-dlp download | done | — | — | bounded, checksum-verified download deployed in `c335dcd7` |
| I2 | horizon and reverb run old images | open | I1 | rebuild | todo: infra |
| I3 | Create on production: live for the team; remaining go-live items tracked in `create-go-live.md` (L3, L7, L8, L9, L16) | partly | see go-live | — | `create-go-live.md` |
| I4 | Paid canary after each deploy | done | — | keep running after deploys | `create-go-live.md` L1 |
| I5 | Brief refused by the 24-requirement limit on a re-plan | open | — | look at | verify-and-teach step 4 |
| I6 | A test of a migrated video with expiring signed links failed once in a full run and passed on re-run (timing-sensitive) | open | — | make it deterministic | 2026-10-07 |
| I7 | The overlap and lopsided checks have not yet caught anything on a real video ("SHOOT" under a line, crammed endings) | open | — | tune on the next builds | `cd5bb2cc` |
| R1 | Local acceptance on the bench (8 of 9) | blocked | bench | run | progress: rollout |
| R2 | Limited audience with an off switch | open | R1 | build switch | progress: rollout |

## Waiting on the owner

| ID | Item | Unblocks |
|---|---|---|
| O1 | A consented photo of yourself | bench B6, B7, B9; E2 |
| O2 | A UGC reference video | bench B9 |
| O3 | A product photo (stock candle is the fallback) | bench B8 |
| O4 | Baseline budget cap: 7,000 credits? | B2, B3, G1, F1, H1, R1 |
| O6 | From scratch: send 15–25 exemplar videos | FS4 |
| O7 | Done 2026-10-07: stale 368 hold released, 2,000 credits granted to ws 1 | — |
| O8 | A real-browser check: page recovery after a deploy (L3), the directions drawer, Details | L3, FS1, FS9 |
| O9 | Go to open Create to the two real customers (ws 28, ws 27) | L8 |

## Older docs: open lines that are no longer live

These are still unticked in older docs but are done or superseded; not carried here.

| Where | Item | Why it is not live |
|---|---|---|
| agent-integration-progress V3 | Character approval before storyboard | done: S1 |
| agent-integration-progress V6, V7 | Review the encoded result; bounded repairs | done: D checks and repair policy |
| agent-integration-progress V1, V2, V4, V5, V8 | Reference inspection, durable requirements, performance mapping, scene motion, regression | carried by M1, A, D and the bench |
| agent-integration-progress | Sound pass; motion techniques (camera zoom, flood, edges) | done: H2 |
| agent-integration-progress | "Refresh the local API/worker" lines | done many times since |
| reference-parity slice D | Talking-shot provider decision | OmniHuman in use |

Still open in those docs and **not** carried here (parked, owner to revive): library music once licensed tracks exist;
generate-then-trace for characters; Maya's layered artwork and prepared rig; ears part for mascots; reference sound
study; image-to-3D for detailed characters; motion rule per object kind; speech-probe cuts and crossfaded joins;
failed loads stop the render; the awesome-opus benchmark set.
