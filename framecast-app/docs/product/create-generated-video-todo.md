# Create: generated video, direction and production quality — to do

Started 2026-10-05. Replaces the generated-video items scattered through `create-agent-integration-progress.md`.
Progress is ticked in `create-generated-video-progress.md`; the bench is `create-bench.md`.

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

- **External review 2026-10-05** found that approved intent does not yet survive every handoff (section 0). Those fixes come before new features or paid comparisons.

---

## M. What the system must cover (the frame for everything below)

Two independent choices, never one pipeline per type. A video is a description of what to make, and each moment (and each **layer** inside a moment) gets the production method that fits.

**Video types** (descriptions for the planner, not rigid pipelines):

| Type | Includes |
|---|---|
| Motion graphics / animation | Animated text, UI, diagrams, illustrations or characters (built in code, or generated when the world calls for it) |
| UGC / presenter | A person speaking, demonstrating or reacting |
| Footage-based | Filmed or generated scenes, product shots, stories, the user's footage, stock |
| UGC + motion graphics | Presenter footage with animated text, UI or illustrations |
| Footage + motion graphics | Scenes with graphics, transitions and overlays |

**Creation modes:**

| Mode | The agent |
|---|---|
| From scratch | Develops direction from the brief, brand and supplied assets |
| Exact | Matches the reference's important composition, timing, actions and transitions; makes only the requested substitutions. Never promised pixel-identical: wording, typography and timing are controllable, a generated person's movement is not |
| Similar | Keeps selected traits (pacing, visual treatment, structure) with room to change execution |
| Inspired by | Takes the general creative idea and develops a distinct execution |

Today the code has only `exact` and `inspired`, and the user's "similar" is mapped to `inspired`. Add `similar` as its own mode (planner rules, the match question, Details).

**M1. The intent agreement.** For any reference (and for a from-scratch brief with required elements), the plan states, in a short summary the user can correct:
- **Preserve:** identity, style, timing, composition, actions, sound;
- **Replace:** people, products, branding, text, setting;
- **Flexible:** details the agent may reinterpret;
- **Required:** elements whose absence makes the result incomplete.

It is inferred from the brief, never a questionnaire.

Example: "Keep the reference's illustrated world, camera journey and pacing. Replace the character with Maya and the product with WyvStudio. Use our colours. Keep Maya looking at the laptop until the final reaction."

- "Required" items feed D (blocking checks).
- "Preserve" items feed the reference comparison.
- Builds on today's `reference_decisions` (keep, replace or drop per moment) and `reference_observations` (preserve, replace, uncertain).

**M2. Layer-level routing.**
- One moment can hold generated presenter footage, a real product image and code-rendered captions at once.
- Routing works per shot **and per layer**, never one engine per project.
- A generated presenter clip never moves the rest of the video off HyperFrames or Remotion.
- Partly there today: the composition layers clips and code, and `WM.layout` makes mixed layouts.

**M3. Coverage matrix.** The 5 types × 4 modes, each cell with:
- its expected route (which layers are code, generated, the user's footage or stock);
- its acceptance criteria;
- a representative test in the bench (G1).

Not every cell needs its own paid test: cover pure graphics, pure footage and both hybrids across scratch, exact and similar or inspired.

---

## 0. Handoff integrity (done 2026-10-05: 0.1 to 0.5 built and tested locally; not yet exercised on a paid run)

**0.1 Every approved word survives.** `ShotRoute::take` loses words in two ways:
- it keeps at most 6 segments and drops the rest;
- it clamps a long line to the engine's maximum (a 50-word line gets 10 s for about 21 s of speech).

The fix:
- split long lines at clause, then word, boundaries into segments that fit;
- never drop a segment;
- if the script cannot fit within the allowed segments, the plan says so and asks; it never truncates;
- the speaking rate is per language, not a fixed 2.4 words a second for every script;
- the delivered take is transcribed and compared to the approved words (part of D5).

**0.2 Explicit input contracts for generated shots.** Today a first frame forces a first-frame engine and drops the references: Omni with a board and an avatar becomes Kling with no references. An unknown first-frame name falls back to the first sheet image, which can be the wrong subject. The fix:
- items name inputs by asset id: `start_frame` (a board panel), `references` (cast and place ids), `end_frame`;
- a table per engine of supported combinations: Omni and Veo take a start frame plus references; Seedance takes one or the other; Kling takes a start frame only;
- an unsupported combination re-routes with a stated reason, or is refused;
- an unresolved reference is an error, never a fallback.

**0.3 Cloned voice in UGC takes.**
- No clone selected: native speech, as now.
- Clone selected: the approved cloned narration plus the existing lip-sync route.
- Never a native take and an unused cloned purchase side by side.
- A plan check enforces this.

**0.4 Per-segment records.** (Sheets: a partial failure is now a plain failure, since the user pays only for a delivered sheet; per-panel records come with A3.)
- Each provider job of a take (and of any multi-job item: sheet images, panels) is recorded the moment it is submitted.
- Each job is collected, retried or cancelled on its own.
- A failure after some jobs started leaves the started ones recorded and collectable, never "unknown".
- Done before any paid comparison.

**0.5 Real provider cost.** (Built as `provider_cost_usd` beside the charge: the receipt's cost field drives charging, so it keeps the tariff.)
- Generated-media receipts record the provider's actual cost: prediction metrics and billed seconds by model price, kept separate from the credit tariff.
- Cost per accepted result is measured from those, not derived from credits.

---

## A. Direction: the cast and storyboard

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
- the gaze **when a person is in it**;
- the camera move;
- the end state.

Example: "She watches the upload finish, stops typing and leans forward with relief; her gaze stays on the laptop; the camera moves closer as the confirmation appears."

- A plan check sends back shots with no action, and people-shots with no gaze. Products, landscapes and deliberate stillness need neither.
- People never look into the camera unless the shot says so; every prompt states this.
- The shot prompt no longer forces "subject centred": the approved panel's composition rules, so an off-centre framing is kept.

**A3. Storyboard panels drawn from the cast.**
- One panel per generated shot, drawn with the cast as references.
- Composed stills for the code-drawn beats.
- Cast and panels appear on **one** review screen, never two approvals.
- Each panel can be redone with a note ("looking at her screen, not at us").

**A3a. Generation order with one screen.**
- Independent cast and place images generate together.
- Panels generate after the identity they depend on, and appear on the same screen as they land.
- If the user rejects an identity, the panels that depend on it are not bought.

**A4. Approval versions.**
- A change to the cast, script or one shot's direction marks only the affected panels and clips as needing review.
- Unaffected approved work is kept and not repurchased.

**A5. The board is read, but checked against the brief.**
- A vision pass describes the approved panels and writes the video prompt.
- Anything in a panel that contradicts the brief or plan (wrong product, wrong action, wrong text) is flagged to the user, never silently adopted as the new instruction.

**A6. Planner model by job,** routed in code, not by one global env value:
- configurable model ids;
- the stronger model for new creative direction (generated video, new stories, exact copies);
- Sonnet for edits and timing fixes;
- the model used is recorded per plan.

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

**B2. Bounded bake-off before routing rules harden.**
An exploratory comparison, not a definitive ranking. It needs everything below before it runs:
- **Test groups** each engine can actually serve: no 16:9-only Veo reference task in a portrait group.
- **Written acceptance thresholds and human review.**
- **A spending cap.**
- **Disqualifying failures:**
  - lost approved words;
  - wrong identity;
  - a refusal with no fallback.
- **Scenarios beyond a clean run:**
  - partial provider failure;
  - cancellation;
  - stale approvals;
  - a targeted edit.

The original sketch below (4 tasks × 4 engines × 3 attempts) is up to 48 generations before boards or repairs, so the cap decides how much of it runs.
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

All automatic, and all run on the **final encoded video**, not the source clips: an action trimmed out in the edit is a miss. Each finding carries timestamps and frames as evidence. Builds on V6 in `create-agent-integration-progress.md`.

**Failure and repair policy:**
- **Blocking:** missing required content (approved words, a required action, the approved identity) and technical failures (black or broken frames, missing audio).
- **Advisory:** optional polish.
- **Repairs:**
  - at most 2 automatic repairs per run, within the approved spending ceiling;
  - a repair is not charged when it fixes our own mistake;
  - beyond that, the user is asked, with the evidence and the cost.
- **An unavailable or inconclusive check is shown as "unverified"; it never counts as a pass.**

**D1. Continuity:** the same character across shots (compare frames to the cast with a cheap vision model; flag drift with frames as evidence).

**D2. Required actions:** each directed shot's action (and gaze, where required) is visible across a frame sequence of the final cut. Misses are flagged with timestamps.

**D3. Sequence coverage:** detected cuts are matched to board panels; a skipped or reordered panel is flagged, never silently accepted.

**D4. No unintended lettering:** no generated letters, fake logos or garbled UI baked into frames. Real product packaging and approved logos are expected, not flagged.

**D5. Audio:**
- speech is intelligible and is the approved words;
- ambience sits below narration;
- no generated music clashes with the bed;
- loudness is levelled.

**D6. Named moves blocking (motion graphics):** a move the plan explicitly named (a reference decision's move, the signature move) that is missing blocks delivery. Everything else stays advisory.

**D7. Build hygiene:** no *unintended* blank frames (an approved fade is fine); clips trimmed to their beats; the shot's best seconds are used, not just the first ones.

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

**F2. Parallelise without breaking dependencies:**
- independent cast and place images run together, then independent panels (see A3a);
- all clips start together (done);
- the builder starts on code beats while clips render.

**F3. Measure each stage on the bench (G1)** and publish times per run in the trajectory.

## G. Cost

**G1. Bench and baseline first,** chosen to cover the M3 matrix:

| Brief | Type | Mode |
|---|---|---|
| WyvStudio motion-graphics promo | Motion graphics | Exact (regression) |
| 14 s UI-motion video | Motion graphics | Exact or similar |
| Motion-graphics product explainer | Motion graphics | From scratch |
| DistroKid | Footage-based (drawn world) | Inspired |
| DistroKid | Footage-based | Similar |
| Avatar UGC ad | UGC / presenter | From scratch |
| Split-screen UGC with animated product demo | UGC + motion graphics | From scratch |
| Product ad in a real place with overlays and end card | Footage + motion graphics | From scratch or inspired |
| A UGC reference copied for the user's avatar | UGC | Exact |
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

## Order (revised after the 2026-10-05 review)

1. **G1, M3 and acceptance rules:** the bench briefs mapped to the coverage matrix, the scoring and thresholds, and the release gate.
2. **Section 0:** word survival, input contracts, cloned voice, per-segment records, real provider cost.
   Then **M1** (the intent agreement on the plan card) and the `similar` mode, so every later step has explicit preserve, replace and required items to honour.
3. **A:** cast and storyboard direction, generated in dependency order on one screen.
4. **D:** final-output checks with the repair policy and the "unverified" state.
5. **B2:** the bounded bake-off. Then B1 sequence route if it earns it, B3, B4.
6. **C2–C5:** remaining recovery and provider fixes.
7. **E:** composition improvements.
8. **G2–G4:** cost optimisation against the working baseline.
9. **F:** speed checked at every step.

## Rollout gate

- Local acceptance on the G1 bench first.
- Then a limited enabled audience, with a switch to turn generated video off.
- Passing unit tests alone does not unlock general use.

## Decisions (recommended by review, pending owner confirmation)

| Decision | Choice |
|---|---|
| Image model | Nano Banana Pro is the baseline. Cheaper panel models are compared later on identity and direction adherence, not price. |
| Look approval | Required for a new identity, a new visual treatment or a materially changed generated shot. Unchanged assets and small edits reuse the approval. |
| Cast and boards | Generated in dependency order and shown progressively on one review screen. |
| Planner model | Routed by task in code (A6). |
| Spend confirmation | 300 credits stays as an extra warning. The authorisation is the server-side quote and its maximum spend. Changed scope and repairs stay within that ceiling. |
| Sequence mode | Experimental and selectable per segment. Individual shots and code stay available in the same video. |
| Delivery checks | Missing required content and technical failures block. Polish is advisory. Unverified is never a pass. |
