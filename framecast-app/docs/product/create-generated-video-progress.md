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
| M. Coverage and intent | 2 | 2 | 1 |
| 0. Handoff integrity | 5 | 0 | 0 |
| A. Direction (cast and storyboard) | 7 | 1 | 0 |
| B. Generation routes | 0 | 1 | 4 |
| C. Reliability | 5 | 0 | 0 |
| D. Delivery checks | 5 | 2 | 1 |
| E. Composition with generated worlds | 1 | 2 | 0 |
| F. Speed | 0 | 1 | 2 |
| G. Cost | 1 | 2 | 2 |
| H. Protect what works | 0 | 1 | 1 |
| Rollout gate | 0 | 0 | 2 |

**Next:** C3's button on the result, A3a (wait for the cast before drawing panels), F2 (parallel cast and panels), G2 (the reference-analysis split). The B2 bake-off needs the owner's budget cap. A DistroKid test of A and D is running (2026-10-05).
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

- [~] **M. Video types × creation modes as separate choices.**
  - Done: `similar` is its own mode beside `exact` and `inspired`: settings, inference ("something similar", "keep the drawing style", typos), the question, Details, the planner prompt (`82a78006`).
  - Missing: video type as an explicit plan field. Today it is `creative_intent.format`.
- [x] **M1. Intent agreement** (preserve, replace, flexible, required):
  - inferred by the planner;
  - shown and editable on the plan card;
  - kept on follow-ups;
  - required items never empty (falling back to the user's requirements);
  - passed to the build, which must deliver or report each required item (`82a78006`).
  - The checks against it come with D.
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

- [x] **A1. Identity separate from direction.** Sheet subjects have a kind:
  - a character is drawn neutral (full figure or portrait, three-quarter view, plain backdrop, posed for no scene);
  - a place is drawn empty;
  - a product is drawn alone;
  - looks describe appearance only (`ce165eb0`).
- [x] **A2. Every shot directed.**
  - Action, gaze for people, camera and end state, in the plan and in every prompt (`ce165eb0`).
  - The plan is sent back for a shot with no action, or a person with no gaze.
  - "People never look into the camera" unless the gaze is to camera.
  - The forced "subject centred" is gone.
- [x] **A3. Storyboard panels drawn from the cast** (`437b2302`):
  - one panel per generated shot (its opening moment), in the look stage;
  - composed stills for code beats (builder);
  - cast and panels on one review screen;
  - a note on a panel redraws only that panel.
- [~] **A3a. Dependency order.**
  - Done: panels are drawn after the cast in the same look run, and shown together.
  - Missing: panels are drawn without waiting for the user, so a rejected identity's panels are still bought; cast images are not yet drawn in parallel.
- [x] **A4. Approval versions.**
  - Unchanged media carries across plans of a creation by content (`437b2302`).
  - A cast change redraws its panels.
  - A clip depends only on the images it uses, so redrawing one panel re-buys only that shot (`f628614a`).
- [x] **A5. Board checked against its direction and the agreement** by a cheap vision pass (Haiku 4.5).
  - Contradictions are shown under each panel.
  - A check that could not run says so (`437b2302`).
- [x] **A6. Planner model by task in code:** Opus for new creative direction, the configured model for short follow-up edits (`ce165eb0`).
- [x] **A7. Pacing as a creative choice** in the planner prompt (`ce165eb0`).

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
- [x] **C2. Automatic recovery for pending clips** (`062fbbef`).
  - A stopped run hands its still-rendering clips to the next run, with nothing charged.
  - It closes by itself when nothing else is in doubt.
  - The user is told the clips are still being made and that Try again collects them.
- [x] **C3. Refusal fallback** (`562590cb`).
  - Done: a declined shot is never retried silently; the version reports it with the next-best engine that takes the same inputs and its price; a per-shot engine override is kept on the plan.
  - The result offers "Make it on <engine> · N cr" for each declined shot (`26c556bf`).
- [x] **C4. Main-app provider fixes** (`72bd776d`).
  - An input image the provider cannot fetch from B2 is handed over through Replicate's own file store and started once more (the production error was `NewConnectionError` to `s3.us-east-005.backblazeb2.com`).
  - The editor's model picker flags a model that has been slow for the last 6 hours, with its recent minutes.
- [x] **C5. Long waits:** a run that waits past 45 minutes closes with "your clips are still being made", and they are collected by Try again (with C2).
- [x] **Opus planner fix** (`2a8347b0`): Opus 5.5 refuses a forced tool choice. Creative plans with a reference failed with "An unexpected error occurred" after A6; models that always think now get "auto", and planner refusals are logged.

## D. Delivery checks (on the final encoded video)

First real run (2026-10-05): the checks crashed (`planMedia is not defined`), were reported as unavailable, and were fixed in `26c556bf`. Shots also failed to find their panels by label, fixed in `f3253ae6`.

Built in `final-checks.mjs` and `FinalLook` (`3d8735cd`).

- [x] **Failure and repair policy** (`db20ed21`).
  - Blocking vs advisory; a blocked version is marked "not ready" with timestamps.
  - Unverified checks are never a pass.
  - Up to 2 automatic repair rounds for what the build can fix (a required item, blank frames, a planned move, our narration cut short), within the approved agent calls, never charged.
  - A changed person or a take's words go to the user.
  - Listening retries a provider hiccup once (`c8cbe2a6`).

- [x] **D1. The same people throughout:** the final frames are compared with the approved cast images. Drift blocks.
- [~] **D2. Required actions.**
  - Done: "must appear" items and each directed action are judged on frames sampled from the final cut. A missing required item blocks; a directed action is advisory.
  - Missing: per-shot frame sequences inside each shot's window.
- [ ] **D3. Sequence coverage** (waits for the sequence route, B1).
- [x] **D4. No unintended lettering:** garbled letters or fake logos in the picture are flagged (advisory); real packaging and overlays are fine.
- [x] **D5. Audio:**
  - lost approved words block (listening to the export, script coverage at least 90% with no missing passage);
  - mix, dead air and abrupt endings are flagged by the existing listening review;
  - loudness is levelled.
- [x] **D6. Named moves block:** a reference or signature move the composition never builds blocks delivery.
- [~] **D7. Build hygiene.**
  - Done: unintended blank frames block (a fade of up to 0.6 s at the start or end is allowed); bought clips must appear; a take must play at least 90%.
  - Missing: using a shot's best seconds.

## E. Composition with generated worlds

- [x] **E1. App UI on stable in-world screens** (`e2d79222`).
  - A shot planned with `screen: true` asks for a locked-off camera on a blank, glowing device screen.
  - The media op `screen` finds its corners (stable within 1.5%, with a confidence).
  - `WM.pinToClip` maps the real UI onto it in perspective.
  - An untracked screen falls back to a framed panel and is reported.
  - Floating UI stays an option.
  - Not yet proven on a real clip.
- [~] **E2. Mixed layouts.**
  - Done: `WM.layout` and the builder guidance.
  - Missing: a real split-screen UGC test (bench B7).
- [~] **E3. Generated ambience.**
  - Done: guidance in `craft.md` (ambience low, a spoken line full).
  - Missing: levels checked.

## F. Speed

- [ ] **F1. Targets checked on the bench:** plan ≤ 3 min, look stage ≤ 6, full video ≤ 15.
- [~] **F2. Parallel work.**
  - Done: all clips start together; cast images are drawn in parallel, then the panels in parallel (`7c638502`).
  - Missing: the builder starting on code beats while clips render.
- [ ] **F3. Stage times per run** in the trajectory. `create:bench-report` gives run and plan times today.

## G. Cost

- [x] **G1. Bench and baseline:** briefs, coverage, acceptance rules, release gate, budget, and `create:bench-report` (`abb9cb55`). First "before" record: DistroKid, 943 credits over 4 runs, 2 held.
- [ ] **G1. Baseline round run** (needs the owner's inputs and cap).
- [~] **G2. Reference analysis split** (`26c556bf`).
  - Done: behind `CREATE_STUDY_MODE=split`. Haiku reads every sheet in parallel into per-frame facts (text, type, elements, changes); Opus reads the facts plus the strongest motion windows frame by frame and the first sheet. `create:study-ab {asset}` compares both readings.
  - **A/B on DistroKid, 2026-10-05:** not adopted; the default stays `opus`.

    | Reading | Time | Model cost | Moments | Systems |
    |---|---|---|---|---|
    | Opus reads every sheet | 114 s | $0.44 | 22 | 2 |
    | Split | 81 s | $0.42 | 18 | 1 |

    - The facts text cost Opus about as many tokens as the images it replaced (about 40k either way), so nothing was saved.
    - The split merged the closing logo, globe and tagline into one moment.
    - It misread three subjects (a man whose glasses reflect waveforms became "a robot head"; a woman at the keys became "a character in a server room").
  - Missing: a real saving needs motion *measurement* (positions and timing from the video, with confidence), not cheaper summaries. Open.
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
