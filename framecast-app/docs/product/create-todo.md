# Create: the todo

The one list of everything still open for Create. Add new items here and nowhere else; tick them off by moving them
to "Done lately" with the date and commit. Detail for older items lives in `archive/create/` (history only, not
kept up to date).

Last updated: 2026-10-07.

**Status:** `open` not started · `partly` some built · `ready` built, needs a real run or a look · `blocked` waiting
on the owner or another item · `proposed` not yet approved.

**Live reference (not todos):** `create-drain-runbook.md`, `create-worker-recovery-runbook.md`,
`create-web-release-runbook.md`, `create-local-testing.md`, `create-bench.md`, `hyperframes-integration-spec.md`,
`create-ui/` (mockups the build must match).

**Production today:** live for the team (kolakachi@gmail.com and `@wyvstudio.com`); private B2 bucket
`wyv-create-private`; durable planning; 3 builds at once, one per workspace; health alerts every 5 min; vendor alerts
and a 09:05 digest; planning billed at half cost (needs 60 credits); no spend cap (watch the digest and
`create:failures`).

## Go-to-market gate

Sections 1 and 2 make Create safe to run; they do not prove it is good enough or that it pays. We go to market when
all of these hold (agreed 2026-10-07):

1. **Launch must-haves done:** G-REC, G-DEP, L16, G-FAIR, S9, I7 (section 1) and BG1, FS8 proven (section 2).
   AB3, I2, D7, 0.4 and HF-BILL are not launch-blocking.
2. **Acceptance round (GTM-1):** about 10 realistic customer briefs across motion graphics, UGC and footage edits
   (~2,500 credits, instead of the full 7,000-credit bench).
3. **Pass marks, set before the round:** at least 7 of 10 the owner would publish after at most one change; none
   lost or charged twice; median time under 20 min; model cost per accepted video comfortably below what it is
   charged.
4. **Pricing and limits (O11)** set from those numbers.
5. **Then** open to ws 28 and ws 27 (O9), watch their first week, and widen in stages.

## 1. Build next (no owner input needed)

| ID | Item | Status | Next | Detail |
|---|---|---|---|---|
| S9 | "Change…" from the video: go back to Plan, Character or Storyboard and redo only the later steps | open | mockup first, then build | archive: steps-asking-plan |
| S10 | Pause and ask inside a step (at most 2 questions), resume without re-approval | open | build | archive: steps-asking-plan |
| FS3 | Finishing checks still to add: constant-speed motion, the same technique repeated, full-frame hit count, opening hero | partly | build | archive: from-scratch-plan |
| I7 | Tune the overlap and lopsided checks: they have not caught a real case yet ("SHOOT" under a line, crammed endings) | open | tune on the next builds | `cd5bb2cc` |
| D7 | Pick the best seconds of a clip that is longer than its slot | partly | build | archive: generated-video-progress D7 |
| 0.4 | An image in flight when the worker dies is lost (generated video is already recovered) | partly | build | archive: generated-video-progress 0.4 |
| I5 | A brief refused by the 24-requirement limit on a re-plan | open | look at | archive: verify-and-teach-scope step 4 |
| AB3 | Stage WyvBear and the GSAP plugins into the builder | open | build | archive: generated-video-todo (art) |
| AB4 | The builder must search the art library when the brief asks for icons | partly | build, then a run that uses art | archive: generated-video-todo (art) |
| L16 | Restore drill from the private DB backups (`wyv-create-private/db-backups/`), plus a secrets review | open | run the drill | archive: go-live L16 |
| G-REC | Recovery drills on production: API restart during planning, worker killed mid-build, duplicate approval, cancel while queued and while running; no double charge, uncertain work stays held | open | run when idle | archive: go-live gates |
| G-DEP | Rollback drill: record API, web, worker, sandbox and art-pack revisions; roll back without touching active builds | open | run when idle | archive: go-live gates |
| G-FAIR | Several workspaces at once: one busy workspace cannot hog the queue; cancelling frees a slot; waiting users see a useful status | open | test | archive: go-live gates |
| HF-BILL | Reconcile real provider invoices against our cost records (estimates are not invoices yet) | open | compare a week | archive: hyperframes-implementation-todo |
| HF-CASES | Hard test briefs: mismatched product photo (no false identity or invented endorsement), long text, missing claims, contradictory ask, footage too short, a new spoken hook | open | add to tests and the bench | archive: hyperframes-implementation-todo |
| I2 | Local stack: rebuild horizon and reverb (they still run old images) | open | rebuild | archive: generated-video-todo |

## 2. Built, waiting for a real run or a look

| ID | Item | Next |
|---|---|---|
| BG1 | Thorough builds get up to 4 reviewer rounds; only Thorough holds credits for the reviewer | a Thorough run |
| CR1 | Quick and Thorough cost multipliers (Quick 0.4 confirmed; Thorough ~1.8× from one run) | more Thorough runs |
| CR2 | Estimates learned per video type and new-vs-change | needs 3 runs of a kind |
| CR4 | "Never more than" shows a real hold | check in the app |
| S11 | A vague change gets one question; the plan card shows what was assumed | check in the app |
| RF1 | Re-run the WyvStudio UGC brief (length, captions, proof, sound now confirmed in the plan) | the video build |
| RF2 | The reference study hears sound effects and the sound pass follows them | a reference with effects |
| RF3 | Captions in Details: Automatic, None, My exact text | check in the app |
| FS5 | 37 motion moves (14 new) | a build that uses one |
| FS7 | A screenshot of the current video is read as "change this moment" | try live |
| FS8 | The user's own voice, music and sounds used as given | a paid build with own audio (~250 credits) |
| E1 | App screens shown on in-world devices | a run with a screen |
| E2 | Split-screen UGC | needs O1 |

## 3. Waiting on the owner

| ID | Item | Unblocks |
|---|---|---|
| O1 | A consented photo of yourself | E2; bench B6, B7, B9 |
| O2 | A UGC reference video | bench B9 |
| O3 | A product photo (stock candle is the fallback) | bench B8 |
| O4 | Bench budget cap: 7,000 credits? | section 4 |
| O6 | 15–25 exemplar videos | FS4 exemplar library |
| O8 | Browser checks: a tab left open across a deploy (L3), the directions drawer (FS1), Details (FS9) | L3, FS1, FS9 |
| O9 | Go to open Create to the two real customers (ws 28, ws 27), after customer acceptance below | L8 |
| O10 | Customer acceptance: review outputs for a motion-graphics brief, a UGC brief and a footage edit (fidelity, consistency, text, audio, revisions, time, cost) | O9 |
| O11 | Pricing, customer limits and quality bar agreed before paid customer use | O9 |

## 4. The bench (blocked on O4 and the bench inputs)

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
| — | After the pilot: watch first output, corrections, spend, recovery and repeat use; widen in stages | later |

## 5. Later (parked or proposed; revive with the owner)

- **Characters:** WyvBear mascot moments (RF4); ears part for mascots; Maya's layered artwork and prepared rig; a
  character-kit plan item (pose + expression sheets); generate-then-trace; image-to-3D for detailed characters
  (needs pricing).
- **Sound and editing:** licensed library music; speech cut points read from levels; crossfaded, frame-snapped joins;
  effects checked to land on their cuts; a motion rule per kind of object.
- **Rendering:** failed asset loads stop the render; re-render only what changed; re-compose each aspect ratio
  instead of cropping; 3D, shader and particle blocks.
- **Reuse:** save an approved result as a reusable, editable composition; input-only re-renders without a full build;
  feedback saved as workspace preferences; variation controls with seeds; batch variants from an approved result;
  a small library of proven components.
- **Measurement:** which skills and components lead to accepted outputs; reference-to-output motion comparison;
  the awesome-opus benchmark set (10–15 prompts).
- **Ops:** a limitations log with filters and evidence, proposals logged with human decisions.
- **HyperFrames depth (slice G, proposed):** the block registry, doctrine skills, capture and keyframes; upgrade
  from 0.8.82.
- **API/MCP:** Create through the public API and MCP after app acceptance, verified end to end before outreach.

## Done lately

- 2026-10-07: V8 vendor failures in the final look and reference reads (`b6e58aa5`); I6 test disks isolated
  (`dbe5f341`); BG1 reviewer held only on Thorough (`582da7b5`); 3 builds at once on production (L7); dropdowns
  inside drawers; FS1 direction cards, FS2 playbooks, FS5 moves, FS6 file roles, FS8 own audio, FS9 Details rules;
  private B2 storage, durable planning, worker ownership, drain, alerts (L2–L15); planning billed at half cost.
- 2026-10-07: V9 a real health alert reached the owner; I1 API image build no longer hangs.

**Failure ledger** (`create:failures --days=7`, 2026-10-06): 52 of 120 runs failed or were held since 2026-09-29;
16,249 credits spent on them; 41 later recovered.
