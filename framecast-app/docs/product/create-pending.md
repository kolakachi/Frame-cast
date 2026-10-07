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

## From scratch (proposed)

Plan: `create-from-scratch-plan.md` (order FS3, FS1, FS2, FS4; FS4 needs your videos).

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| FS1 | Three direction cards before the plan | proposed | owner's approval | scope | todo: from scratch |
| FS2 | Format playbooks | proposed | owner's approval | scope | todo: from scratch |
| FS3 | Slideshow check: mostly still text cards are sent back to the builder once | ready | sandbox image rebuild | rebuild, then a real run | todo: from scratch |
| FS4 | Exemplar library | blocked | 15–25 videos from the owner | collect | todo: from scratch |

## Infra and release

| ID | Item | Status | Waiting on | Next | Source |
|---|---|---|---|---|---|
| I1 | API image rebuild hangs on the yt-dlp download | local ARM64 verified | production AMD64 build | bounded download + checksum patch; rollout separate (containers carry copied-in changes) | production plan: build diagnostics |
| I2 | horizon and reverb run old images | open | I1 | rebuild | todo: infra |
| I3 | Create on production: live for the team; L1 to L10 left (B2 files, worker concurrency, auto worker deploy, stale-page reload) | partly | see `create-go-live.md` | L1 | `create-go-live.md` |
| I4 | Paid canary after the deploy: plan passed; build 1 failed (per-call hold), fix `304200b9` deployed | partly | owner: credits in ws 1 (L1) | re-run | `create-go-live.md` |
| I5 | Brief refused by the 24-requirement limit on a re-plan | open | — | look at | verify-and-teach step 4 |
| R1 | Local acceptance on the bench (8 of 9) | blocked | bench | run | progress: rollout |
| R2 | Limited audience with an off switch | open | R1 | build switch | progress: rollout |

## Waiting on the owner

| ID | Item | Unblocks |
|---|---|---|
| O1 | A consented photo of yourself | bench B6, B7, B9; E2 |
| O2 | A UGC reference video | bench B9 |
| O3 | A product photo (stock candle is the fallback) | bench B8 |
| O4 | Baseline budget cap: 7,000 credits? | B2, B3, G1, F1, H1, R1 |
| O6 | From scratch: approve FS1 and FS2; send 15–25 exemplar videos | FS1, FS2, FS4 |
| O7 | Credits for the canary re-run: release the stale 368 hold or top up ws 1; planning on an empty balance (L2) | I4 |

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
