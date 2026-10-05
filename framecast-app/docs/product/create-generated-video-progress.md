# Create generated video: progress

Tracks `create-generated-video-todo.md`, item by item. Bench: `create-bench.md`.

**Legend:**
- **[x]** done and tested locally.
- **[~]** partly done (what is missing is said).
- **[ ]** not started.

"Local" means committed, unpushed and running in the local stack: not deployed, and not yet proven on a paid bench run.

Last updated: 2026-10-05.

## Summary

| Section | Done | Partly | Open |
|---|---|---|---|
| M. Coverage and intent | 1 | 1 | 3 |
| 0. Handoff integrity | 5 | 0 | 0 |
| A. Direction (cast and storyboard) | 0 | 1 | 7 |
| B. Generation routes | 0 | 1 | 4 |
| C. Reliability | 1 | 1 | 3 |
| D. Delivery checks | 0 | 2 | 6 |
| E. Composition with generated worlds | 0 | 2 | 1 |
| F. Speed | 0 | 1 | 2 |
| G. Cost | 1 | 1 | 3 |
| H. Protect what works | 0 | 1 | 1 |
| Rollout gate | 0 | 0 | 2 |

**Next:** M1 (intent agreement) and the `similar` mode, then A.
**Waiting on the owner:** bench inputs and the budget cap (see the end of this file).

---

## Built before the todo (the base this work starts from)

- [x] Generated shots, UGC takes, cast/world sheet, best-fit routing (`ShotRoute`), avatar never on Seedance: `4b035f30`.
- [x] Builder places generated clips; UGC take as the voice track; `WM.layout` mixed layouts; Standard/Premium switch; spend confirmation over 300: `57946153`.
- [x] Polling fix in the main editor's Animate (slow clips are kept, refund once, 3 h ceiling; broadcasts never fail paid work): `66320cdb`.
- [x] A request that never connected is retried, not held: `f8420f4f`.
- [x] Generated clips start, then are collected; a stopped run's pending clip is adopted: `79081f28`.
- [x] Exact copies take the reference's length; the match reply accepts typos: `4b035f30`.

## M. What the system must cover

- [ ] **M. Video types × creation modes as separate choices,** with a `similar` mode beside `exact` and `inspired`. Today: only exact and inspired; "similar" maps to inspired.
- [ ] **M1. Intent agreement** (preserve, replace, flexible, required) inferred and shown on the plan card. Today: `reference_decisions` and `reference_observations` cover part of it, for references only.
- [~] **M2. Layer-level routing.**
  - Done: clips and code layers compose together; `WM.layout` makes mixed layouts.
  - Missing: the planner choosing per layer inside a moment.
- [x] **M3. Coverage matrix,** with an expected route per cell: `create-bench.md` (`abb9cb55`).
- [ ] **M3. Acceptance criteria per cell**, beyond the bench briefs' Required items.

## 0. Handoff integrity

All built and tested locally on 2026-10-05; none exercised on a paid run yet.

- [x] **0.1 Every approved word survives:** split long lines, no dropped or clamped segments, rates per language, the delivered take transcribed and compared (`2de661aa`).
- [x] **0.2 Explicit inputs per engine** (`ShotRoute::INPUTS`); an unresolved reference is a plan problem, never a fallback (`8e9dd394`).
- [x] **0.3 A cloned voice lip-syncs the take** to the approved cloned narration; the quote refuses a cloned take without it (`b76e7e3f`).
- [x] **0.4 Every provider job recorded on submission;** restart once; cancel when abandoned; a sheet partial failure is a plain failure (`a2693652`). Per-panel records come with A3.
- [x] **0.5 Real provider cost** recorded beside the charge (`provider_cost_usd`); the bench report shows it (`83857541`).

## A. Direction: the cast and storyboard

- [~] **A1. Identity separate from direction.**
  - Done: the sheet prompt asks for each subject whole (a character full figure, a place as an establishing view, a product centred).
  - Missing: neutral poses, places without people, presenter portraits, product angles; and the check that no reference carries a shot's pose or gaze.
- [ ] **A2. Every shot directed:**
  - action, gaze for people, camera, end state;
  - the plan check;
  - "never look into the camera";
  - drop the forced "subject centred".
- [ ] **A3. Storyboard panels drawn from the cast;** composed stills for code beats; one review screen; redo a panel with a note.
- [ ] **A3a. Dependency order:** cast first, then panels shown as they land; panels from a rejected identity are not bought.
- [ ] **A4. Approval versions:** a change marks only the affected panels and clips.
- [ ] **A5. Board read by vision and checked against the brief.**
- [ ] **A6. Planner model routed by task in code.** Today: one env value, Sonnet 5.
- [ ] **A7. Pacing as a creative choice** in the planner.

## B. Generation routes

- [~] **B1. Three mixable routes.**
  - Done: individual shots (start frame plus references per engine); code composition.
  - Missing: the generated-sequence route (experimental).
- [ ] **B2. Bounded bake-off:** test groups, thresholds, cap, failure scenarios.
- [ ] **B3. Drafts:** does a re-render reproduce the performance, per engine.
- [ ] **B4. Timing driver** (speech, music or action) set in the plan and shown on the card.
- [ ] **B1 sequence route,** gated on B2.

## C. Reliability

- [x] **C1. Base reliability:** start-and-collect, adoption of a stopped run's clips, retry of unsent requests, slow polling in the main app, broadcasts never fail work.
- [ ] **C2. Automatic recovery for pending clips** when a run stops (today an operator settles the attempt).
- [ ] **C3. Refusal fallback:** offer the next-best engine with its price; never retry silently.
- [ ] **C4. Main-app provider fixes:** retry the B2 input download with a fresh link; slow-model warning.
- [~] **C5. Long waits.**
  - Done: a 45-minute cap, after which clips stay pending and a retry collects them.
  - Missing: "still rendering, we'll finish it" with a notification, instead of a failure.

## D. Delivery checks (on the final encoded video)

- [ ] **Failure and repair policy:** blocking vs advisory, at most 2 repairs, the "unverified" state.
- [ ] **D1. Character continuity across shots.**
- [ ] **D2. Required actions visible** across a frame sequence of the final cut.
- [ ] **D3. Sequence coverage** (panels vs detected cuts).
- [ ] **D4. No unintended lettering.**
- [~] **D5. Audio.**
  - Done: the take's speech checked against the approved words (from 0.1), recorded on the item.
  - Missing: surfacing and blocking, intelligibility, ambience levels, music clash, loudness.
- [ ] **D6. Named moves blocking** (motion graphics).
- [~] **D7. Build hygiene.**
  - Done: bought clips must appear; a take must play at least 90%.
  - Missing: unintended blank frames (the DistroKid cut ended on black); using a shot's best seconds.

## E. Composition with generated worlds

- [ ] **E1. App UI on stable in-world screens** (corner pin); floating UI kept as an option.
- [~] **E2. Mixed layouts.**
  - Done: `WM.layout` and the builder guidance.
  - Missing: a real split-screen UGC test (bench B7).
- [~] **E3. Generated ambience.**
  - Done: guidance in `craft.md` (ambience low, a spoken line full).
  - Missing: levels checked.

## F. Speed

- [ ] **F1. Targets checked on the bench:** plan ≤ 3 min, look stage ≤ 6, full video ≤ 15.
- [~] **F2. Parallel work.**
  - Done: all clips start together.
  - Missing: cast images in parallel; panels after their identity; the builder starting while clips render.
- [ ] **F3. Stage times per run** in the trajectory. `create:bench-report` gives run and plan times today.

## G. Cost

- [x] **G1. Bench and baseline:** briefs, coverage, acceptance rules, release gate, budget, and `create:bench-report` (`abb9cb55`). First "before" record: DistroKid, 943 credits over 4 runs, 2 held.
- [ ] **G1. Baseline round run** (needs the owner's inputs and cap).
- [ ] **G2. Reference analysis split:** measurement with confidence; cheap vision on held frames; Opus keeps the key frames; A/B on DistroKid and UI-motion.
- [ ] **G3. Build model by job.**
- [~] **G4. Cost per accepted result.**
  - Done: real provider cost per generated item (0.5).
  - Missing: per route and engine across the bench.

## H. Protect what works

- [~] **H1. Motion-graphics path unchanged.**
  - Done: the full API (854) and worker suites green after every change.
  - Missing: the free re-plan regression of earlier briefs; bench B1 to B3 run.
- [ ] **H2. Carried over:** sound pass, edge springs, 60 fps, camera zoom and flood transitions.

## Rollout gate

- [ ] **Local acceptance on the bench** (8 of 9; B1 to B3 all pass; zero lost words, identity failures or manual recoveries).
- [ ] **Limited audience with an off switch,** then the bench re-run before widening.

## Waiting on the owner

- [ ] A consented photo of yourself (bench B6, B7, B9).
- [ ] A UGC reference video (B9).
- [ ] A product photo (B8; a stock candle is the fallback).
- [ ] Confirm the baseline budget cap: 7,000 credits.
- [ ] Confirm the recommended decisions in the todo: image model, look approval, planner by task, spend confirmation as a warning, sequence mode experimental, delivery-check policy.
