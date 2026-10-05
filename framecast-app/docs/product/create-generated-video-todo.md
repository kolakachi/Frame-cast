# Create: generated video, direction and production quality — to do

Started 2026-10-05. Replaces the generated-video items scattered through `create-agent-integration-progress.md`.

## Goal

Create makes story, UGC and mixed videos (drawn or filmed worlds, people, products) as reliably as it makes motion
graphics, without slowing or breaking the motion-graphics path. The user's experience stays four steps:

**describe → review the look and story → create → refine.**

Every production decision (cast, boards, engines, timing, checks) sits behind that.

## How we judge every item

| Measure | What counts |
|---|---|
| Reliability | Runs that finish without a hold or manual reconciliation; no paid clip left uncollected; no charge for undelivered work |
| Visual integrity | The approved cast, look and actions appear in the video; no baked-in text; no continuity breaks; no shot that contradicts the brief |
| Speed | Time from approval to preview, with provider queue time reported separately |
| Cost | **Cost per accepted result**, including rejected generations, redos and repairs. Never a single attempt's price |

Claims about models are hypotheses until the bake-off (B2) measures them on comparable tasks with repeated attempts.

## What exists now (built 2026-10-04/05, local only)

- Generated shots, UGC takes, cast/world sheet, routing by fit (`ShotRoute`); the user's avatar never on Seedance; Veo's reference limits.
- Generated clips start and are collected (no request waits for minutes); a stopped run's pending clip is collected by the next run.
- A request that never connected is retried, not held. Slow animations in the main editor keep their clip (polling fix).
- Mixed layouts (`WM.layout`), UGC take as the voice track, Standard/Premium switch, spend confirmation over 300 credits.
- Exact copies take the reference's length; the match reply accepts typos.

**First real test (DistroKid, inspired):** the pipeline worked end to end, but the video was weak:
- The sheet showed the creator at the desk looking at the viewer, and every shot copied that gaze.
- The shots had camera notes but no actions.
- Half the video was flat UI cards that broke the drawn world.

---

## A. Direction: the cast and storyboard (do first)

**A1. Separate identity from direction.**

| Asset | Establishes |
|---|---|
| Character reference | Identity, proportions, clothing, style |
| Environment reference | Place and recurring objects, without characters |
| Product reference | Product angles |
| Storyboard panel | Framing, pose, gaze, light, the moment |
| Motion direction | What happens during the shot and how it ends |

- Character references come in the shape the video needs: a neutral three-quarter view, a portrait for a presenter, several angles for a product. "Standing on a plain background" is an option, not a rule.
- Done when: no reference image contains a pose or gaze meant for a specific shot.

**A2. Every shot is directed.** Each shot states:
- an action with a purpose;
- where the person looks;
- the camera move;
- the end state.

Example: "She watches the upload finish, stops typing and leans forward with relief; her gaze stays on the laptop; the camera moves closer as the confirmation appears."

- A plan check rejects shots with no action or no gaze, and sends them back to the planner.
- People never look into the camera unless the shot says so; every prompt states this.

**A3. Storyboard panels drawn from the cast.**
- One panel per generated shot, drawn with the cast as references.
- Composed stills for the code-drawn beats.
- Cast and panels appear on **one** review screen, never two approvals.
- Each panel can be redone with a note ("looking at her screen, not at us").

**A4. Approval versions.**
- A change to the cast, script or one shot's direction marks only the affected panels and clips as needing review.
- Unaffected approved work is kept and not repurchased.

**A5. The board is read, but checked against the brief.**
- A vision pass describes the approved panels and writes the video prompt.
- Anything in a panel that contradicts the brief or plan (wrong product, wrong action, wrong text) is flagged to the user, never silently adopted as the new instruction.

**A6. Planner model by job.**
- Opus: creative plans (generated video, new stories, exact copies).
- Sonnet: edits and timing fixes.
- Recorded per plan.

**A7. Pacing is a creative choice.**
- The planner sets shot count and length from the format: a montage can cut every 1.5 s; a presenter or emotional beat needs longer.
- Nine shots in 15 s is not a default.

## B. Generation routes

**B1. Three routes, mixable in one video:**
- **Individual generated shots:** precise actions, product shots, performances. The approved panel is the starting image, and the cast is passed as references where the engine allows.
  - Wording to users and in prompts: the clip *starts close to* the approved panel, not on exactly that frame.
- **Generated sequence** (experimental, gated on B2): short stories and montages where some interpretation is fine. A grid board plus the cast as references; one generation per segment.
- **Code composition:** typography, UI, diagrams, controlled motion graphics.
- A generated presenter clip does not move the whole project off HyperFrames or Remotion.

**B2. Bake-off before routing rules harden.**
- Same tasks for each engine (Seedance 2.5, Omni, Veo 3.1 HQ, Kling), equal durations, 3 attempts each.
- Tasks:
  1. drawn-world action;
  2. avatar presenter;
  3. product in a real place;
  4. a 6-panel sequence board.
- Score each result:
  - panel coverage and order;
  - identity hold;
  - action correctness;
  - gaze;
  - no text artefacts;
  - **cost per accepted result**.
- The routing table in `ShotRoute` and the planner prompt is updated from the results, with dates.

**B3. Drafts.**
- Low-resolution drafts are offered only where a re-render keeps the performance. Until that is tested, drafts are for checking boards and sequence coverage, labelled "the final render may differ".
- Done when: we know per engine whether a seed and the same input reproduce the performance.

**B4. Timing driver decided in the plan,** shown on the plan card:
- **speech** (approved dialogue or narration: pictures fit the words);
- **music** (cuts on the beat);
- **action** (montage: narration fits the generated cuts).

A UGC take or approved dialogue is never re-timed to fit generated cuts.

## C. Reliability

**C1. Done:** start-and-collect; adopting a stopped run's pending clip; retry of unsent requests; slow polling in the main app; progress broadcasts never fail paid work.

**C2. Automatic recovery for pending clips.**
- A run that stops with clips still pending settles its own attempts as "handed over" and records them as pending.
- No operator command is needed (today this is manual).

**C3. Moderation and refusal fallback.**
- When an engine declines a shot, offer the next-best engine with its price.
- Never retry silently, and never send an avatar to Seedance.

**C4. Remaining provider fixes (main app):**
- retry the input download from B2 with a fresh link;
- warn when a model is slow right now ("Seedance is taking about 10 min").

**C5. Long waits.**
- `maxWaitMs` (45 min) ends the run cleanly with clips still pending.
- The user sees "still rendering, we'll finish it" and gets a notification, not a failure.

## D. Visual integrity checks before delivery

All automatic. Results go into "Things to check" and block delivery only for named, approved items.

**D1. Continuity:** the same character across shots (compare frames to the cast with a cheap vision model; flag drift with frames as evidence).

**D2. Required actions:** each directed shot's action and gaze are visible in its frames; misses are flagged with the frame.

**D3. Sequence coverage:** detected cuts are matched to board panels; a skipped or reordered panel is flagged, never silently accepted.

**D4. No generated text:** no letters, logos or UI garbage baked into generated frames.

**D5. Audio:**
- speech is intelligible and is the approved words;
- ambience sits below narration;
- no generated music clashes with the bed;
- loudness is levelled.

**D6. Named moves blocking (motion graphics):** a move the plan explicitly named (a reference decision's move, the signature move) that is missing blocks delivery. Everything else stays advisory.

**D7. Build hygiene:** no black frame at the end; clips trimmed to their beats; the shot's best seconds are used, not just the first ones.

## E. Composition with generated worlds

**E1. App UI inside the world, stable screens first.**
- The planner asks for a steady shot of a clearly visible device screen.
- The builder places the real UI on it with a corner pin.
- Camera-tracked and occluded screens come later.
- Floating UI stays available when the direction calls for it.

**E2. Mixed layouts in a real test:** a split-screen UGC ad (take on top, animation below; take full screen at the hook and the end).

**E3. Generated ambience:**
- levels are set per shot's audio intent;
- a shot that speaks a line plays it fully;
- generated music is never kept under our bed.

## F. Speed

**F1. Targets** (excluding provider queue, which is shown separately):
- plan ≤ 3 min;
- look stage ≤ 6 min;
- full video ≤ 15 min.

**F2. Parallelise:**
- sheet images and panels are generated together;
- all clips start together (done);
- the builder starts on code beats while clips render.

**F3. Measure each stage on the bench (G1)** and publish times per run in the trajectory.

## G. Cost

**G1. Bench and baseline first.**
- Fixed briefs:
  - DistroKid inspired;
  - DistroKid exact;
  - the 14 s UI-motion video;
  - the WyvStudio motion-graphics promo (regression);
  - an avatar UGC ad;
  - a product ad.
- Each run records time, credits, provider cost, rejected generations and the acceptance checklist.
- Every change below is judged against it.

**G2. Reference analysis split** (estimates with confidence and frame evidence, never bare numbers):
- **Local tools:** frames, candidate cuts, change windows, loudness, OCR text boxes. Speech transcription is also a model, run locally or by API; it is listed honestly.
- **Motion measurement:** position, scale and opacity estimates for text and large elements in change windows. Each estimate carries a confidence and its source frames. Illustrated or noisy footage falls back to model reading.
- **Cheap vision on held frames:** what is on screen and where; full-resolution text crops for type style. Font *identification* is a guess and is labelled as one.
- **Opus keeps the important frames:** it sees the motion windows at full frame rate and the key frames directly, plus the cheaper facts and measurements as aids. It is never handed only another model's summary.
- **A/B on DistroKid and the UI-motion reference,** with a detail checklist: fonts, text animation, transitions, easing, timing. Switch only where the cheaper path matches or beats Opus.
- The reference's resolution caps detail; prefer the highest-resolution download available.

**G3. Build model by job:** Sonnet for small edits and free edits; Opus for new compositions. Measured on G1.

**G4. Cost per accepted result** is reported per route and engine, and drives B2's routing table, not list prices.

## H. Protect what works

**H1. The motion-graphics path is unchanged** unless a plan includes generated kinds. Every phase runs the motion-graphics regression from G1 (including a free re-plan of earlier briefs that must still plan as code).

**H2. Carried over from earlier todos, still wanted:**
- the sound pass (cues placed on measured peaks);
- leading/trailing-edge springs;
- the 60 fps option;
- camera zoom and flood transition techniques.

## Order

1. **G1:** the bench and baseline. Without it nothing below can be judged.
2. **A1–A7:** cast and storyboard separation, directed shots, one review screen, versions, Opus planner.
3. **D1–D5, D7:** delivery checks, so failures are visible before the user sees them.
4. **B2:** the bake-off. Then B1 sequence route if it earns it, B3, B4.
5. **C2–C5:** recovery and provider fixes.
6. **E1–E3:** in-world UI, mixed-layout test, ambience.
7. **G2–G4:** cost work against the working baseline.
8. **F:** speed targets checked at every step; parallelisation as found.

## Open decisions (owner)

- Image model for cast and panels: Nano Banana Pro (35 credits each) or a cheaper one for panels.
- Whether the look stage is mandatory for every generated-video plan, or skippable for small edits.
- Spend confirmation threshold (300 credits today).
- The planner on Opus: set `CREATE_PLANNER_MODEL` in `api/.env`, or route by plan type in code (A6).
