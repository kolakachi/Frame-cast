# Create: generated video, direction and production quality — to do

Started 2026-10-05. Replaces the generated-video items scattered through `create-agent-integration-progress.md`.
Progress is ticked in `create-generated-video-progress.md`; the bench is `create-bench.md`. Every open item across
Create is on one sheet: `create-pending.md`.

## Goal

Create makes story, UGC and mixed videos (drawn or filmed worlds, people, products) as reliably as it makes motion
graphics, without slowing or breaking the motion-graphics path. The user's experience is durable steps, each
re-enterable after the video:

**describe (questions first) → plan → character → storyboard → video → change.**

Every production decision (cast, boards, engines, timing, checks) sits behind that.

## How we judge every item

| Measure | What counts |
|---|---|
| Reliability | Runs that finish without a hold or manual reconciliation; no paid clip left uncollected; no charge for undelivered work |
| Visual integrity | The approved cast, look and actions appear in the video; no baked-in text; no continuity breaks; no shot that contradicts the brief |
| Speed | Time from approval to preview, with provider queue time reported separately |
| Cost | **Cost per accepted result**, including rejected generations, redos and repairs. Never a single attempt's price |

Claims about models are hypotheses until the bake-off (B2) measures them on comparable tasks with repeated attempts.

## Done (details and commits in `create-generated-video-progress.md`)

M (types × modes, the intent agreement, layer routing, coverage matrix); section 0 (handoff integrity, except the
image-job gap below); A (cast and storyboard direction); C (reliability); D1, D2, D4 to D6 (delivery checks); B4 and
E3; F3; the G1 bench; G2 (tested and not adopted); H2. The full original spec of the done items is in git history
(`65521bba`). Removed from this list on 2026-10-06 rather than finished: the build order (all reached), the second
audit's regression list (built; tracked in the progress file), and "one review screen for cast and panels" (the
owner chose separate character and storyboard approvals).

## The frame: two independent choices

A video is a description of what to make; each moment (and each layer inside it) gets the production method that
fits. Never one pipeline per type.

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

## Open: generated video

- **B2. Bounded bake-off** before routing rules harden. Needs the owner's cap and bench inputs. Test groups each engine
  can serve; written thresholds and human review; disqualifying failures (lost words, wrong identity, a refusal with
  no fallback); scenarios beyond a clean run (partial provider failure, cancellation, a stale approval, a targeted
  edit). Results update `ShotRoute`'s table and the planner prompt, with dates. Scored on **cost per accepted result**.
- **B3. Drafts:** per engine, does the same input and seed reproduce the performance (about 8 short clips). Until
  known, a draft is labelled "the final render may differ".
- **B1. Generated sequence route** (experimental, only if B2 earns it), then **D3** sequence coverage (cuts matched
  to board panels).
- **D7. Best seconds:** a clip longer than its slot plays its best seconds, not its first.
- **0.4. Image jobs in flight:** a cast or panel image in flight when the worker dies is lost and drawn again.
- **E1** proven on a real generated clip; **E2** a real split-screen UGC test (bench B7).
- **F1.** Plan ≤ 3 min, look stage ≤ 6, full video ≤ 15, checked on the bench. **F2** builder overlap: deferred
  until after the bench.
- **G3.** Sonnet builds, measured on the bench (needs per-model gateway rates). **G4.** Cost per accepted result per
  route and engine.
- **H1.** Bench B1 to B3 rendered and scored (the motion-graphics regression).

## Open: steps and asking (2026-10-06)

- **Change… from the video:** re-enter at Plan, Character or Storyboard; only the later steps are redone, unchanged
  approved work is kept.
- **Pause and ask inside a step** instead of reporting a "Known gap": the worker asks (`needs_input`), resumes without
  re-approval; at most 2 questions per step.
- **Ask, don't assume:** an unclear change request becomes a question (the clarifier runs only for new creative
  briefs today); the planner's unknowns become questions, not guesses.

## Open: credits (2026-10-06)

- Calibrate the Quick and Thorough multipliers from real runs (Standard is calibrated on 8 runs: median 404 credits).
- Learned estimates per video type, not only per effort and length.
- Count the question, file-sorting and reference-study calls in the planning charge.
- Unlimited test mode still shows a huge "never more than".

## Open: art and brand (2026-10-06)

- Fetch the art packs on prod (`scripts/fetch-art-packs.mjs` in the worker setup); a bigger 3D pack.
- Stage WyvBear (`runtime/wyv-bear.js`) and the GSAP plugins into the builder.
- One full real run that uses an art pick, a brand item and a cutout.

## Open: from scratch (proposed 2026-10-06, not approved)

Ideas only from OpenMontage (AGPL v3): nothing copied, clean-room.
- Three direction cards (concept, look, hook) before the plan for a brief with no reference.
- Format playbooks (UGC ad, explainer, launch promo, testimonial…): structure, pacing and must-haves per format.
- A slideshow-risk check before the render: flags beats that are static cards with text.
- An exemplar library of good videos the planner studies: needs 15 to 25 videos from the owner.

## Open: infra

- API image build: local ARM64 rebuild passed on 2026-10-06 after bounding and checksum-verifying the yt-dlp download.
  An intermittent GitHub connect timeout was reproduced; the retry passed. Production AMD64 verification and rollout
  remain open. Local containers still carry copied-in changes and lose them if recreated; horizon and reverb need
  rebuilt images. See `create-production-plan.md` for evidence and the separate Docker build-history crash.

Vendor errors and admin alerts: `create-vendor-errors-todo.md`.

## Rollout gate

- Local acceptance on the G1 bench first.
- Then a limited enabled audience, with a switch to turn generated video off.
- Passing unit tests alone does not unlock general use.

## Decisions (in effect)

| Decision | Choice |
|---|---|
| Image model | Nano Banana Pro is the baseline. Cheaper panel models are compared later on identity and direction adherence, not price. |
| Look approval | Two steps: the character, then the storyboard after it. Required for a generated person, optional for motion graphics. A step that has been approved is not approved again on retry. |
| Cast and boards | Subjects are drawn independently in a shared style; changing one redraws only it and its panels. |
| Planner model | Routed by task and effort in code (Opus for new direction, Sonnet for Quick and short edits). |
| Spend | The estimate ("about N cr, never more than M") and effort level on the plan; billed per stage; planning at half price, capped at 100. The 300-credit confirmation stays as an extra warning. |
| Sequence mode | Experimental and selectable per segment. Individual shots and code stay available in the same video. |
| Delivery checks | Missing required content and technical failures block. Polish is advisory. Unverified is never a pass. |
| Plans | A plan freezes on approval; a built plan is read-only; an earlier plan can be viewed, not acted on. |
