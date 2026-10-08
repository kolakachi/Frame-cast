# Create: the todo

Only what is still open. Add new items here and nowhere else; when one is done, delete it and note it (date and
commit) in the newest archive file. History, the GTM-1 results and everything finished up to 2026-10-08 are in
`archive/create/create-todo-2026-10-08.md`.

Last updated: 2026-10-08 (early morning).

**Status:** `open` not started · `partly` some built · `ready` built, needs a real run or a look · `blocked` waiting
on the owner or another item · `proposed` not yet approved.

**Live reference (not todos):** `create-drain-runbook.md`, `create-worker-recovery-runbook.md`,
`create-web-release-runbook.md`, `create-local-testing.md`, `create-bench.md`, `hyperframes-integration-spec.md`,
`create-ui/` (mockups the build must match).

**Production today:** live for the team; builds on Opus 5.5 (a super admin can try another model on one
conversation with `settings.model_test`; Haiku 5.5 was tried 2026-10-08 and not adopted); planning billed at half
cost with no daily cap, 3 planning workers, at most 2 plans per workspace; 3 builds at once, one per workspace;
deploys drain Create around the restart.

## 1. Running now

| ID | Item | Next |
|---|---|---|

## 2. Launch must-haves

All closed 2026-10-08 (I7, G-REC, G-FAIR, G-DEP, L16); evidence in the archive.

## 3. Build next

| ID | Item | Status | Next |
|---|---|---|---|
| PLAN-DEAD | A planning worker that dies mid-plan is caught in a minute or two: a forked watcher touches a file every 20 s while the plan's process lives (no shared connections); the recovery treats a stale file as interrupted (`fd4a3803`; real-process check passed) | ready | deploy, then repeat the planning-kill drill |
| VOICE-ONLY | A voice-only change swaps the voice without the builder: quoted at the voice alone (about 3 credits), the new lines placed where the old ones began (pauses trimmed first, then up to 10% quicker), the timeline unchanged; a line that cannot fit goes to the builder next time (`5ec5d473`) | ready | deploy (waiting for an idle moment), then prove on brief 2's pending "weave studio" re-voice |
| FS3 | Finishing checks still to add: constant-speed motion, the same technique repeated, full-frame hit count (the Laban plan covers the first two) | partly | build |
| ASK-FILE | No product photo: a generated product image is the fallback, the photo is still asked for | ready | prove on a product brief |
| D7 | Pick the best seconds of a clip that is longer than its slot | partly | build |
| 0.4 | An image in flight when the worker dies is lost (generated video is already recovered) | partly | build |
| AB3 | Stage WyvBear and the GSAP plugins into the builder | open | build |
| AB4 | The builder searches the art library when the brief asks for icons | partly | build, then a run that uses art |
| S10 | Pause and ask inside a step (at most 2 questions), resume without re-approval | open | build |
| HF-BILL | Reconcile real provider invoices against our cost records | open | compare a week |
| HF-CASES | Hard test briefs: mismatched product photo, long text, missing claims, contradictory ask, footage too short, a new spoken hook | open | add to tests and the bench |
| I2 | Local stack: rebuild reverb (api, queue workers and scheduler rebuilt 2026-10-08) | partly | rebuild |

## 4. Needs a real run or a look

| ID | Item | Next |
|---|---|---|
| CR1 | Thorough costs about 1.8× (one run) | more Thorough runs |
| CR2 | Estimates learned per video type and new-vs-change | needs 3 runs of a kind |
| CR4 | "Never more than" shows a real hold | check in the app |
| S11 | A vague change gets one question; the plan card shows what was assumed | check in the app |
| RF2 | The reference study hears sound effects and the sound pass follows them | a reference with effects |
| RF3 | Captions in Details: Automatic, None, My exact text | check in the app |
| FS5 | 37 motion moves (14 new) | a build that uses a new one |
| FS7 | A screenshot of the current video is read as "change this moment" | try live |
| E1 | App screens shown on in-world devices | a run with a screen |
| E2 | Split-screen UGC | needs O1 |

## 5. Waiting on the owner

| ID | Item | Unblocks |
|---|---|---|
| LABAN | Laban efforts per beat (mockup `create-ui/laban-efforts.html`): show efforts to users? use them to direct generated takes? add the Laban video to the exemplars? Then the go to build | FS3, motion variety |
| O11 | Pricing, customer limits and quality bar, from the GTM-1 numbers | O9 |
| API-BAL | Turn on Anthropic auto-reload; give the current balance so it can be recorded (`create:model-balance`) | the low-balance warning |
| O9 | Open Create to the two real customers (ws 28, ws 27) | L8 |
| O10 | Customer acceptance: the GTM-1 round covered motion graphics, UGC and footage edits; confirm it counts | O9 |
| S9 | Browser check of "Change…" from the video | S9 done |
| O8 | Browser checks: a tab left open across a deploy (L3), the directions drawer (FS1), Details (FS9) | L3, FS1, FS9 |
| O1 | A consented photo of yourself | E2; bench B6, B7, B9 |
| O2 | A UGC reference video | bench B9 |
| O3 | A product photo (stock candle is the fallback) | bench B8 |
| O4 | Bench budget cap: 7,000 credits? | section 6 |
| O6 | 15–25 exemplar videos | FS4 exemplar library |

## 6. The bench (blocked on O4 and the bench inputs)

| ID | Item | Status |
|---|---|---|
| G1 | Baseline round | blocked |
| F1 | Speed targets: plan ≤ 3 min, look ≤ 6, video ≤ 15 | blocked |
| G4 | Cost per accepted result, per route and engine | partly |
| H1 | Motion-graphics regression rendered and scored | blocked |
| G3 | Sonnet builds measured | partly |
| B2 | Bounded engine bake-off | blocked |
| B3 | Does a re-render keep the performance | blocked |
| B1 | Generated sequence route (only if B2 earns it), then D3 sequence coverage | open |
| F2 | Builder starts on code beats while clips render | deferred until after the bench |
| R1 | Local acceptance (8 of 9) | blocked |
| R2 | Wider audience behind an off switch, then the bench again before widening | open |

## 7. Later (parked; revive with the owner)

- **Decisions API (DEC):** failed as a taste judge on the 39 library videos (2026-10-07); re-test only on generated
  clips and images, once there are labelled rejects.
- **Characters:** WyvBear mascot moments (RF4); ears part for mascots; Maya's layered artwork and prepared rig; a
  character-kit plan item (pose + expression sheets); generate-then-trace; image-to-3D for detailed characters.
- **Sound and editing:** licensed library music; speech cut points read from levels; crossfaded, frame-snapped joins;
  effects checked to land on their cuts; a motion rule per kind of object.
- **Rendering:** failed asset loads stop the render; re-render only what changed; re-compose each aspect ratio
  instead of cropping; 3D, shader and particle blocks.
- **Reuse:** save an approved result as a reusable, editable composition; input-only re-renders without a full build;
  feedback saved as workspace preferences; variation controls with seeds; batch variants; a library of proven
  components.
- **Measurement:** which skills and components lead to accepted outputs; reference-to-output motion comparison;
  the awesome-opus benchmark set.
- **Ops:** a limitations log with filters and evidence, proposals logged with human decisions.
- **HyperFrames depth (slice G, proposed):** the block registry, doctrine skills, capture and keyframes; upgrade
  from 0.8.82.
- **API/MCP:** Create through the public API and MCP after app acceptance, verified end to end before outreach.
- **After the pilot:** watch first output, corrections, spend, recovery and repeat use; widen in stages.
