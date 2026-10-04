> Current routing (2026-10-03): VEED Fabric is the only supported audio-driven lip-sync engine. ByteDance OmniHuman is removed; mentions below describe historical decisions. Native speech uses normal video models (Gemini Omni, Veo, Seedance).

# Create agent integration and creative quality progress

Updated: 2026-10-03

## P0 — Visual detail and motion fidelity (urgent, owner priority)

Owner priority, 2026-10-03: this is the next implementation work, ahead of additional engine integrations, model comparisons, reusable recipes and broader Godmode features. Applies to all Create videos, with or without a reference; it is not a Maya-specific template. Infer format from the requested output: education, source-footage editing, talking heads and still slides do not automatically need animation or generated performers. Existing accounting, consent and recovery protections remain in effect. **Status: open; partial implementation and offline checks below, general workflow not yet completed or creatively accepted.**

This is the canonical checklist for this priority. It extends H2/R1/W1 (inspection), W2 (character approval), L6/Q2 (comparison) and Q1/W6/Q3 (review and acceptance). Their earlier implementation labels describe narrower capabilities, not completion of this work. The animated-mascot audit below supplies the failure evidence.

- [ ] **V1 — Adaptive reference inspection before planning.** Reuse existing FFmpeg/ffprobe tools to map metadata and the entire timeline; produce timestamped overview sheets, then consecutive-frame windows and full-resolution crops for important expressions, gestures, typography and transitions. Allow bounded exhaustive extraction for short clips, not mandatory all-frame model uploads. Record source hash, frame/time ranges, resolution, sampling coverage and limitations; cache by source and extraction settings. Keep CPU/storage/image-context limits. Acceptance: the 15-second reference exposes facial animation separately from position/scale movement; a brief blink/transition missed by coarse sampling can be inspected on demand. Local extraction and paid model interpretation are accounted separately.
- [ ] **V2 — Durable requirements from references or plain descriptions.** **Core implementation complete; runtime and live acceptance pending.** Preserve requested identity, appearance, framing, actions, action order, text, colour, pacing, transitions and audio as traceable requirements. Label user instructions, observed evidence, inferred choices and unknowns separately; explicit user changes override reference details. With no reference, derive requirements from the brief and approved design, asking only about consequential ambiguity. Propagate IDs through plan, quote, tool tasks, revisions and review; invalidate affected approvals when inputs change. Acceptance: “clay mascot opens a box, looks surprised, then points to the price” retains all three actions and their order; unsupported promises cannot disappear into a generic “character visible” requirement.
- [ ] **V3 — Character design approval before storyboard.** Show and approve the actual master design before building a character-dependent storyboard; bind approval to identity/style/asset hashes. Reuse a matching approval, and request a new one only for affected changes. The storyboard is a still carousel, clearly separate from motion acceptance; defer motion/audio purchases to their quoted stage. Acceptance: fresh and follow-up flows preserve one approved character and never present an unseen design as approved. Existing post-storyboard approval does not close this item.
- [ ] **V4 — Performance requirements mapped to executable work.** Distinguish still illustration, facial/gesture performance, full-body action and scripted talking. Require suitable generated clips or a supported articulated implementation for the requested actions; image translate/scale/rotation alone cannot satisfy internal facial/limb motion. Bind each task to the approved master, scene, duration and required coverage rather than the first uploaded photo. Use app-controlled tools only: supported native-speech video routes for ordinary talking, VEED Fabric where an explicitly selected cloned voice needs lip-sync; no OmniHuman or arbitrary vendor calls. Acceptance: catch missing tasks before purchase; an unavailable capability yields a clear limitation/replan, not a silently substituted still.
- [ ] **V5 — Scene-to-scene motion and asset delivery.** Plan start/end states, timing, visual hierarchy and how scenes connect; let Hyperframes compose performance footage with editable UI, typography and transitions using integrated upstream capabilities. Verify that required clips actually appear for the intended duration, rather than merely existing in the library. Address cropping, background treatment and character identity across clips. Scope pose/bust guidance to the brief; neither full-body framing nor fixed scene recipes are universal defaults. Acceptance: a representative sequence shows internal character movement and coordinated UI transitions, with no unintended looping or frozen fallback.
- [ ] **V6 — Review the encoded result spatially, temporally and with audio.** Reuse inspection on the final artifact: detail crops for text/identity/colour/cropping; sequences for expressions, actions, timing, continuity and transitions; separate checks for expected audio, speech/script fidelity and synchronization. Align reference/output by corresponding beats, allowing approved timing changes; without a reference compare against V2 and the approved design. Classify required failures versus optional polish, attach timestamped evidence, and do not claim exhaustive verification from samples or infer speech from an audio stream. Acceptance: detect the current five-PNG substitute and a missing promised audio track even when individual stills look good; do not incorrectly reject a deliberately still scene.
- [ ] **V7 — Bounded repairs and visible unresolved requirements.** Reserve review/repair capacity, carry findings across revisions, and repair affected parts without repurchasing unchanged assets. Re-review the exact delivered revision. If cost/time/call limits prevent completion, preserve the draft with specific unmet requirements and a clear continuation path; no false “finished” status or silent fallback. Include requirement → evidence → task/asset → review outcome in existing trajectories, with inspected coverage and costs; broader Godmode reporting can follow. Acceptance: budget exhaustion, interrupted review and partial task failure remain recoverable and cannot produce a false pass.
- [ ] **V8 — Regression and creative acceptance.** First prove offline extraction/caching/limits, requirement propagation, master binding, missing-task rejection, clip usage, review freshness and recovery. Include reference-inspired mascot/UI, description-only character action, non-character product/typography, intentional still scenes and targeted edits. Then run an authorized short motion sequence before a full video; record rendered artifacts, runtime/model versions, actual spend, known limitations and owner playback/creative verdict. No new paid test, production rollout or budget increase is authorized by prioritizing this list. Manual inspection of the reference is evidence for requirements, not implementation acceptance.

Execution order: **V1 + V2 → V3 + V4 → V5 → V6 + V7 → V8 acceptance**. Add regression checks with each implementation slice. Creativity remains open in the choice of composition and technique; required user details must be fulfilled or explicitly reported unresolved. Perfect pixel-level fidelity is not promised.

### V2 completion checklist — current

- [x] Stable requirement IDs, provenance, source evidence, separate actions and explicit ordering.
- [x] Follow-up preservation, versioned amendments/removals and visible change history.
- [x] Requirement links through scenes, quotes, planned and in-build media purchases, revisions and trajectories.
- [x] Per-requirement review results; missing, stale or unsupported checks cannot pass. Frame-only checks leave audio unverified.
- [x] Offline verification: 142 API tests / 909 assertions, 106 worker tests and frontend production build passed. See the [sixth slice](#sixth-implementation-slice--durable-requirement-contract-2026-10-03) for evidence and limits.
- [ ] Refresh the local API and Create worker to load the new code, checking active jobs first.
- [ ] Verify a fresh reference-based and description-only workflow, including an amendment, quoted targets and delivered review statuses; record any authorized paid spend and owner verdict.

V2 remains unchecked at the acceptance level until these last checks pass. **Next implementation: V3 — approve the character master before building its storyboard**, followed by remaining V4 performance-task coverage. V6 audio/temporal verification and V7 reserved review/repair capacity remain separate work; repeated call-limit drafts are not completed reviews.

### Seventh slice — the planner sees the whole reference (2026-10-03, owner: "go")

Evidence: conversation `dd410f7a`, full build `7e766c06` ($6.99, 49 calls). The planner chose from 16 single frames of a 26 s / 790-frame reference, so moments under a second were never seen (the cat sticker at 2.8–3.5 s, the 0→10k counter, text typing on, the blurred push into the profile). The uploaded reference's audio was never studied. Result: a plan of generic step cards, placeholder UI, 85% static frames, ~9 s without voice, and narration chopped mid-word.

1. **Reference study at attach time** (V1). Study each video reference once, in the background, when it is attached (uploads and links), cached by source hash:
   - [x] every shot: cut detection, frames inside every shot, denser where shots are short;
   - [x] short moments: five frames across each moment where the picture changes inside a shot (stickers, counters, typing);
   - [x] a per-shot list of what is on screen (text, stickers/memes, UI, camera move, transition), with times (one model call; retried if it fails);
   - [x] sound: word-timed transcript, pauses, words per second, speech share, cut rate, text-to-speech delay (music and beat map still to add);
   - [x] planning reads the finished study and its sheets (studied in the background at attach, or before planning if not yet done).
2. **Plans that use the study** (V2):
   - [x] every reference moment gets an explicit keep / replace / drop with the beat that carries it; undecided moments are listed on the plan card;
   - [x] real UI: when page captures exist the plan names the screens to rebuild; placeholders only when nothing real exists (instruction; no check yet);
   - [x] length from narration: a script much shorter than the video leads to a stated choice, shown on the plan card.
   - [x] first real re-plan (conversation dd410f7a, 2026-10-03): three planner faults found and fixed. Replies were cut at 8,000 output tokens (a plan that decides 26 moments is ~17,000; now 24,000 per call, 32,000 when unlimited). A full plan written beside an inspection request was discarded, and a reply that said "the plan above is final" was retried blind (now kept as a fallback, and the model is asked once for the plan). Plans were cut to 8 beats, silently dropping the last step and the close (now 16). A plan whose beats stop short, leave holes, point at beats that do not exist or leave moments undecided goes back once with the list of problems. Result: 26 of 26 moments decided (8 keep, 12 replace, 6 drop), beats 0–30 s, one inspection, 17k output tokens, 172 s.
   - [x] coverage calibration (2026-10-03): a second coverage mode, every_look, takes one frame for every distinct look (a frame differing from the last kept one by over 2%), uncapped, all sheets in the moment call, no moment cap. Default while CREATE_UNLIMITED is on; CREATE_REFERENCE_COVERAGE=standard|every_look overrides. Busy 15 s 60 fps reference: 900 frames, 194 looks, 10 sheets, 72 s, $0.19, 29 moments including 6 transitions and the signature (the blue script word becomes each transition); the standard 40-frame study (36 s, $0.09) found 1 transition and missed the signature. Instagram reference: 784 frames, 100 looks.
   - [ ] user-facing effort (proposal, not approved): Standard = today's 40 frames; High = every look at a 4% threshold (busy 112, calm 67); Maximum = every look at 2% (busy 194, calm 100), no cap until calibrated. Planner still sees only 3 sheets beside the moment list; at Maximum it may need more.
3. **Build checks** (V5): still stretches over ~1.5 s, text too small or frames mostly empty, audio cut only between words, fades on every audio edge, music under the voice and faded at the end, continuous narration preferred, brand pronunciation (WyvStudio, not "Weave Studio").
   - [x] audio edges (2026-10-03): every audio clip must start and stop where its file is quiet, measured on the 60 ms its edge throws away (below -35 dBFS). Narration edges must sit in a pause (a fade does not excuse a cut word); music and effects may fade instead (media op fade, or a volume lane reaching 0). New media ops: levels, fade; silences down to 0.1 s pauses. Instruction: one continuous narration clip, split only inside pauses, music ends with a fade. Run 7e766c06 replayed: 6 voice edges mid-word (8.7, 8.8, 12.1, 12.1, 18.95, 19.0 s) and the music stopping at 30 s are all caught; its clean edges pass.
   - [x] pronunciation: workspace 1's own rule said WyvStudio is spoken "Weave Studio"; changed to "Wiv Studio" (as documented in the UGC pronunciation map). Editable from the plan card's Pronunciations.
   - [x] music under the voice: already enforced (music_not_ducked).
   - [x] still stretches, text too small, mostly empty frames (2026-10-04): from the same 0.1 s samples as the reading-time check. Still: nothing moves, fades, scales or changes words for over 1.5 s (playing video counts as change; data-hold allows 3 s; a final hold up to 3 s). Small text: a sentence-sized line under 3% of the short side for 0.5 s or more. Mostly empty: content under 15% of the frame and nothing spanning half of it for 1.5 s. Finish is refused once per draft while any pacing error is open; finishing again with a reason is accepted. On run 7e766c06 it finds 5 of the 7 mid-video stills the render shows (the other two are 1.55 and 1.59 s). Shown to the user in Before you post.
4. **Review that hears and keeps time** (V6): the export's audio against the script (complete, in sync, voice clear over music) fills the sound requirements; timing compared with the reference (cut rate, pace, text-to-speech delay).
   - [x] stopgap (2026-10-04): requirements about narration, sync, music or sound are marked by_ear during the build and no longer block the visual review (they made every narrated build end on the stop rule).
   - [x] listening check on the export (2026-10-04): the export's soundtrack is transcribed by the app (new lease-bound listen endpoint, free, rate-limited) and aligned with the approved script as the voice was asked to say it (coverage, missing passages, double playback); text tied to spoken words is timed against what is heard; the mix is measured (voice over music, dead air, abrupt ending). Results settle the by_ear requirements (source audio_review) and appear in Before you post in plain words. The same endpoint transcribes narration the build edited itself.
   - [ ] timing compared with the reference (cut rate, pace, text-to-speech delay).
5. **Reliability** (V7): Stop keeps the last draft that passed checks; a briefly unavailable model is retried instead of ending the build.
   - [x] Stop keeps the last checked version (2026-10-04): while designing, Stop lets the step in progress finish (no model call is cut off, so nothing is left in doubt), then delivers the last version that passed every check, rolling back any later unchecked edit; with none, the build ends cancelled. A render in progress finishes and is delivered. Hard stop after 6 minutes, on shutdown or a lost lease. The app accepts a delivered version while stopping.
   - [x] busy model (2026-10-04): a call the app confirms was never sent (overloaded or unreachable after the app's own two quick retries) is asked again after 20 s, 1 min and 2 min; the local reservation is given back each time; Stop ends the wait. Calls that may have been sent are never repeated.

6. **Ask for what only the user has** (proposed 2026-10-04, owner: "add it to todo"; not started, decisions open):
   - [ ] the planner lists real things the reference shows that we cannot truthfully make (product screens, logos, product photos, people, short screen recordings), each with its beat, the moments it serves, why it helps and the fallback without it (rebuilt from a page capture, or illustrative);
   - [ ] the plan card shows them as "Could you upload these?" with an upload per item and "Go without"; nothing blocks the build, a skipped item uses its fallback;
   - [ ] an upload attaches as a source tied to its item; the build uses it on that beat without re-planning, and the review checks it appears;
   - Open decisions (recommended): ask on the plan card only, not mid-build; only real product/brand/people items, not stickers, memes or backgrounds; accept screen recordings up to ~30 s; at most ~5 items, ranked by impact. About half a day.

7. **Read the reference as systems tied to words** (learned from hypit-ai/hypit `skills/hypit/references/creation/reference-video.md`, `transformations.md`, `script-and-time.md`; owner: "do so" 2026-10-04; not started). Ideas only: its licence is Apache-2.0 with added conditions, so no code is copied.
   - [x] **Systems, not only moments.** The study names each recurring visual system once (step card, UI board, caption, emphasis colour) with its look and lifecycle (entry, active behaviour, hold, exit) and lists its occurrences as moment ids. The planner and builder reuse one spec per system instead of re-deriving it per moment.
   - [x] **Purpose per moment, and dropped jobs are carried.** Each moment records what it does for the viewer (sets up the promise, proves, hands attention on). A dropped or replaced moment's purpose must be carried by something else in the plan, or the plan says why not (the cat sticker's beat of fun was dropped with nothing in its place).
   - [ ] **Word-anchored beats.** Beats are planned against narration lines and phrases, not fixed seconds; after the voice is made, the build re-times beats to its word timings (the transcript and data-spoken already exist). Durations follow the real voice, which also closes the narration-shorter-than-video gap.
   - [x] **Words under the frames.** Study sheets label each frame with the words being spoken at that moment, so the moment list links picture to speech. Built 2026-10-04 with systems and purpose (study version 2): the study returns systems (look, entry, active, hold, exit) and each moment's purpose and system; the plan keeps one spec per kept or adapted system (reference_systems) and a dropped moment's carried_by (a plan without it goes back once); the builder builds each system once as a reusable piece; the plan card lists the repeated elements and each dropped job.
   - [ ] (with the effort setting) **Two passes at Maximum.** A first pass lists open questions with their times; a second pass reads those stretches frame by frame (for example the busy reference's "word becomes the transition").

Order: 1 and 2 (built 2026-10-03, commits 2249c9a4, 96e8b9bb; 804 API / 209 worker tests), then the audio part of 3, then the rest. First real study of the Instagram reference (asset 1523): 9 shots, 40 frames, the sticker window at 2.7–3.1 s found, speech 94% of the video at 3.6 words a second; the moment list waits on the Anthropic account's credit. Live acceptance pending.

### First implementation slice — prepared mascot rig (2026-10-03)

Following the owner's request to begin the rig approach, V4/V5 now have a **locally verified optional adapter**, not a completed general character workflow. The canonical V1–V8 items remain open.

- [x] Prepared layered-SVG contract and bounded motion score: blinks, gaze, head translation/tilt and four mouth-expression states. No timers/network, automatic image-to-rig conversion, lip-sync claim or full-body-action claim. Invalid/missing layers and unsupported/overlapping cues are rejected before timeline mutation.
- [x] Integrate as `wyv-mascot.js` into the existing Hyperframes/GSAP timeline, sandbox runtime staging and agent runtime catalogue. `kit/mascot.md` is available through the real agent read tool; includes eligibility, source preservation, limits and sequence-review instructions. This is an optional WyvStudio adapter, not a replacement rendering engine.
- [x] Correct shared planner/craft guidance: still-pose transforms do not fulfil facial/body performance; framing follows the brief rather than compulsory bust/two-column layouts. These are instruction improvements, not yet hard V2/V4 requirement enforcement.
- [x] Offline fixture: original mint robot artwork, explicitly not Maya, six seconds at 1280×720/24 fps, silent by design. Browser checks prove internal blink/gaze/head/mouth changes while the outer mascot remains stationary; backward and cue-boundary seeks preserve exact layer state, including suppressed-event seeks. Screenshot checks allow mean RGB difference <0.01/255 and changed-channel fraction <0.001 for browser antialiasing. Encoded eye-region comparison verifies motion survives the actual render; FFmpeg decode passes. Fixed overlapping zero-duration mouth writes exposed by the rewind test.
- [x] Focused agent/rig/tool tests: 27 passed; planner PHP syntax passes. Network-disabled fixture used existing image `2ee3ffea09de` with the new script/runtime/fixture mounted read-only. No model/provider call or paid media generation.
- [ ] Prepare and approve Maya's layered artwork without replacing her identity/treatment. Arbitrary PNG/photo uploads remain ineligible for this rig; generated performance footage remains a separate supported route.
- [ ] Carry prepared-rig eligibility/asset hashes and explicit performance requirements through planning, approval, composition and semantic temporal review (V2–V7). No automatic rig-asset onboarding UI was added in this slice.
- [ ] Refresh the local app/worker runtime when appropriate and verify a real agent-selected prepared-rig build. This slice was tested in an isolated container; the running app/API/worker was not rebuilt or restarted. Public/production deployment and owner creative acceptance remain open.

Evidence: [adapter guide](../../hyperframes-worker/agent/mascot-kit.md), [offline fixture script](../../hyperframes-worker/scripts/mascot-fixture.mjs), [rendered proof](../../hyperframes-worker/artifacts/mascot-proof/mascot-proof.mp4), [verification report](../../hyperframes-worker/artifacts/mascot-proof/verification.json). Artifacts are local ignored outputs; rerun the fixture to reproduce. No commit or push.

### Second implementation slice — character performance contract (2026-10-03)

**Partial V2/V4/V6 implementation in source; no new creative acceptance or runtime activation.** Maya's inspected master is a flat halftone PNG, without separate eyes/mouth/head layers. It has not been converted into approved rig artwork, and the robot proof is not a replacement for her.

- [x] Normalize explicit character actions with stable IDs, exact user-source quotes, facial/body/speech classification, timing and intended tool. Preserve them through short follow-ups, selections and the frozen quote. Multiple actions in one source sentence survive; invalid timing remains unresolved instead of being silently shortened.
- [x] Show character actions and missing-work explanations on the plan card. The user can explicitly leave an action out; the original stays visible, and the builder/critic receive the exception to earlier brief wording. This does not automatically remove already planned media or refund previous work; replan to revise purchases.
- [x] Full-video quotes reject declared actions without matching selected work, unsupported prepared-rig onboarding, insufficient declared coverage, missing approved-character tasks and speech without a script/audio. Storyboards can defer performance. Generic product animation does not satisfy character motion; body action is not treated as guaranteed by a talking-head task. A single character `animate_image` task currently supplies five seconds; longer/multiple performance coverage remains to implement.
- [x] Character image animation explicitly selects the verified approved master rather than the first unrelated source photo. The quote/cache binds to the master hash; approval and dispatch re-check it before purchasing. Generic source animation remains separate, including when selected through a plan option.
- [x] The critic must return a unique per-action pass/fail/unverified/deferred result and evidence. Missing checks or unresolved full-video actions prevent a creative pass; only storyboard checks may defer motion. Persist bounded findings for the reviewed revision, labelled `critic_interpretation`; recovered/stale revisions do not inherit successful evidence. This is a review contract, **not proof that coarse sampled frames catch every blink or that audio has been heard**.
- [ ] General adaptive reference inspection and evidence-derived requirements (V1/V2): this slice checks declared, source-grounded actions; it cannot guarantee the planner noticed every requested/reference detail. Existing plans without these fields are not retroactively migrated.
- [ ] Prepared Maya artwork/approval and pre-storyboard character approval (V3); multiple performance clips and asset/scene IDs; actual clip placement/coverage (V4/V5); targeted final-video frame sequences and audio/speech verification (V6); owner creative acceptance (V8). In particular, speech should remain unverified when the critic lacks audio evidence, rather than being inferred from frames.
- [ ] Refresh the local API/worker and run the fresh end-to-end workflow after the remaining artwork/evidence work. No container restart, paid provider call, commit, push or deployment in this slice.

Evidence: [performance contract](../../api/app/Services/Create/CharacterPerformance.php), [integration regressions](../../api/tests/Feature/CreateIntegrationTest.php), [unit checks](../../api/tests/Unit/CreateCharacterPerformanceTest.php), [critic tests](../../hyperframes-worker/agent/tests/critic.test.mjs), [review freshness tests](../../hyperframes-worker/agent/tests/review-status.test.mjs). Validation: 117 API tests / 778 assertions passed (CreateIntegrationTest, CreateCharacterPerformanceTest, CreateCharacterTreatmentTest); 37 focused worker tests passed, with the affected 10 critic/review tests rerun after the final user-facing wording change. Frontend Vite build passed (existing bundle-size/dynamic-import warnings). `git diff --check` passed for the touched implementation areas.

### Third implementation slice — intent-aware workflow and full-duration review (2026-10-03)

**Partial V2/V6/V8, source changes and offline verification only.** Investigated local conversation `3352f022-ee72-47cb-b11f-03be65a760b4` and the supplied Instagram tutorial reference. This remains part of the urgent workflow priority, ahead of more engine integrations.

Findings:

- The 26.24-second, 1276×718 reference uses educational kinetic text, profile UI, held reading moments, zooms and transitions. The brief explicitly requested a 25–30-second vertical educational adaptation. No performing avatar was requested; the saved plan correctly avoided character generation. Sampled overview and a consecutive-frame window were inspected, not every frame; audio-stream presence was verified, not listened to.
- Shared craft and critic instructions still imposed motion-led advertising expectations on all videos. A timing correction also changed Puck to Achird and lost the closing narration line while claiming to keep approved copy.
- The saved latest render has genuinely blank middle beats and a counter stuck at 0%. Its source stacks timing remappers around GSAP methods. The critic also incorrectly reported a missing closing CTA: the CTA appears near the end, but the old strip capped itself at 30 half-second samples, only covering the first 15 seconds of a 30-second video.

Implemented:

- [x] Store a source-grounded `creative_intent` (format, movement level, timing driver and explanation) in the plan and frozen quote. Carry it through follow-ups and show the explanation on the plan card. Missing/legacy intent remains neutral; this is planner interpretation, not a guarantee that every brief is classified correctly.
- [x] Route educational, footage and still-image/slideshow work to neutral editorial guidance. Stop guessing the output format from topic words such as “UGC”. Motion skills remain available; narration alone no longer recommends a motion-broll workflow. Remove compulsory continuous motion, two-second moving hooks, frequent changes and signature moves from universal planning/author/reviewer guidance.
- [x] A declared timing-only follow-up, grounded in the latest user message, preserves the previous selected narration, voice and on-screen copy through normalization and quote creation, including the closing CTA. The flag is not inherited by unrelated edits. This does not yet prove speech alignment or cross-plan reuse of previously purchased audio.
- [x] Review-strip timestamps span the complete supported duration within the existing 30-frame limit (30 seconds: 0.5–29.5 seconds). Check actual captured timestamps, remove stale snapshot frames on repeat reviews, and pass coverage/limitations through both normal and final-reserve critic paths. Unknown coverage is no longer described as a whole-video inspection. This remains a sampled composition overview, not encoded-result or audio verification.
- [x] Offline regressions: **120 API tests / 796 assertions**, **47 focused worker tests**, frontend Vite build (existing bundle/import warnings). Real network-disabled Hyperframes fixture captured 30 frames and verified that a CTA introduced after 20 seconds appears in the closing samples; stale earlier timestamps were removed. No provider calls.
- [ ] Complete V1–V8 above: adaptive/encoded-output inspection, general evidence-derived requirements, pre-storyboard character approval, required clip placement/coverage, speech synchronization and bounded repair/creative acceptance remain open. Keep timing fixes from repurchasing unchanged media; handle overlong new/content-edit scripts explicitly rather than silently dropping their tail.
- [ ] Repair and re-review the saved customer's draft when requested/authorized for that run. No stored plan, run or output was changed by this investigation. Refresh the local API/worker before claiming these source changes are active; no restart, paid generation, commit, push or deployment in this slice.

Evidence: [intent normalization](../../api/app/Services/Create/CreativeIntent.php), [API regression](../../api/tests/Feature/CreateIntegrationTest.php), [sampling fixture](../../hyperframes-worker/scripts/review-sampling-fixture.mjs), [offline verification](../../hyperframes-worker/artifacts/review-sampling-proof/verification.json), [30-frame strip](../../hyperframes-worker/artifacts/review-sampling-proof/live/review-sampling-proof/strip/strip.jpg). Local proof outputs are ignored artifacts. Reproduce in image `2ee3ffea09de`, mounting current `agent/`, `runtime/` and the fixture script read-only, with a fresh `/output`, no network and no credentials.

### Fourth implementation slice — consecutive reference frames and evidence history (2026-10-03)

**V1 extraction/tool foundation implemented; pre-planning adaptive inspection remains open.** The ongoing user generation was left running; no app or worker restart, stored conversation edit, provider call or paid generation was performed.

- [x] Extend `inspect_reference` with `sequence` + `every_frame:true` and optional `page`. Decode one continuous interval of at most two seconds / 120 frames, return up to eight consecutive frames per page, and disclose the total/next page. No fps resampling: timestamps come from decoded frames and account for nonzero media start times. Existing sampled/crop/cut modes remain available.
- [x] Cache by immutable source hash, extraction version, interval and crop. Later pages reuse extraction; validate cached image integrity. Maximum eight cached intervals / 64 MiB per run, with the existing eight-inspection and 90-second per-inspection ceilings unchanged. Oversized windows fail with a shorter-window instruction, leaving no partial cache. Reused images still consume model context when viewed; local extraction itself makes no provider call.
- [x] Source ownership/hash checks and the existing network-disabled app-tool route remain mandatory. Evidence stays outside renderable assets and cannot satisfy the output review gate. Tool results disclose crop, source dimensions, 512-pixel maximum frame edge, actual timestamps, shown page and unverified intervals/audio.
- [x] Persist bounded inspection history beside the run, and include source hash, interval/page coverage and cache-hit status in existing trajectory details. No new Godmode surface or source/image bodies in telemetry.
- [x] Validation: **94 focused worker tests passed**, including real FFmpeg extraction that captures a one-frame colour event, variable-rate timing, nonzero timestamp origin, cache reuse/invalidation, page bounds, frame/cache budgets, native/JSON action compatibility and output-review separation. The seven real extraction/validation tests present before the final cache-budget regression also passed in the isolated pinned proof image `2ee3ffea09de`; the added cache-budget case passed locally. Syntax and diff-whitespace checks pass.
- [x] Bounded pre-plan inspection, frozen source evidence and multi-call usage receipts are implemented in the fifth slice below for the Anthropic planner. The fourth slice alone did not provide these capabilities. Local runtime activation and live acceptance remain open.
- [ ] V2 must connect inspected observations to durable requirement IDs; V6 must apply corresponding temporal/audio checks to the encoded output. Viewing every frame in one interval is not full-video semantic or audio verification. Owner creative acceptance remains open.

Evidence: [consecutive extractor](../../hyperframes-worker/agent/reference-sequence.mjs), [real extraction regressions](../../hyperframes-worker/agent/tests/reference-sequence.test.mjs), [agent handoff](../../hyperframes-worker/agent/tests/tool-mode.test.mjs), [trajectory checks](../../hyperframes-worker/agent/tests/trajectory.test.mjs). Test fixtures use synthetic media and temporary directories. No Hypit code or skills were incorporated.

### Fifth implementation slice — reference inspection during planning (2026-10-03)

**Partial V1/V2 in source, offline verification only.** The running user's video was left untouched. No API/worker rebuild, restart, paid provider call, commit or push.

- [x] Anthropic planning now exposes the host's existing read-only `inspect_reference` tool before returning a plan with media purchases. For attached image/video references, the first turn requests inspection; the model chooses timestamps, a crop, a sampled sequence, consecutive-frame pages or a heuristic cut window. Follow-up calls receive the image plus its coverage/limits. References remain untrusted data, not executable instructions or renderable footage.
- [x] Hard ceilings: three model requests, four extraction requests, two inspection turns and 20,000 aggregate output tokens. The final turn disables tools. Model requests and extraction share the remaining 100-second synchronous planning budget, counted from before context preparation; existing storage/context preparation still uses its own I/O limits. This avoids extending an individual provider timeout beyond the API proxy's 120-second window, but is not an asynchronous planning implementation or a latency guarantee. Slow/incomplete plans fail without starting media generation.
- [x] Managed, workspace-scoped attachments are snapshotted privately for the request; source hashes are verified by the shared Node/FFmpeg inspector. Pages reuse the same local extraction cache. Temporary media is removed in `finally`. API packaging uses byte-identical inspector modules with a sync script and parity regression because its Docker build context excludes the worker directory. No model shell commands, arbitrary URLs or additional generation providers.
- [x] Persist host-authored source/image hashes, requested times/crops, decoded coverage, page limits and extraction elapsed time in the plan; accept model evidence links only when they match real receipts (and the same asset for reference observations). Explicit requirements retain exact user quotes and optional evidence IDs. Pass observations, requirements and coverage to the builder/final critic; a receipt is not proof of interpretation correctness or output fidelity. Full requirement IDs/task mapping and individual reference-result enforcement remain V2/V6 work.
- [x] Carry those receipts into the frozen quote and reject a quote when the studied source hash/purpose no longer matches its input snapshot. Existing plans are not retroactively labelled as inspected.
- [x] Usage records retain every provider receipt, input/output/cache tokens and inspection outcomes; missing token usage stays unknown instead of becoming zero. A later error logs earlier receipts and whether an unreceipted request may exist, without prompt/media/key content. Malformed-plan recovery keeps prior inspection results. Planning remains app-funded; no new user credit debit or estimated-dollar claim was added.
- [ ] Replicate's text-only planning adapter does not gain native reference tools from this change. Its notes-only limitation remains; it must not be presented as having performed this inspection. The fixture planner remains offline.
- [ ] Refresh the local runtime after the active run finishes, then verify live tool selection and owner creative acceptance under a separately authorized test budget. Actual all-detail recall, full-video frame/audio analysis, source-to-output semantic parity and remaining V1–V8 acceptance are not claimed.

Validation: **129 API tests / 854 assertions and 25 focused worker tests passed**. The 9 new planner/inspector tests (58 assertions) were rerun after the final receipt-timing change. Covers real extraction/cache reuse/cleanup, workspace and reference-only scope, fabricated IDs, changed-source quote rejection, bounded/failed calls, missing usage, malformed-plan recovery, shared deadline and bundle parity. Tests use HTTP fakes and synthetic local video; no paid generations. Evidence: [planner](../../api/app/Services/Create/Planning/AnthropicPlanner.php), [host inspector](../../api/app/Services/Create/Planning/PlannerReferenceInspector.php), [offline regressions](../../api/tests/Feature/PlannerReferenceInspectionTest.php), [shared bundle instructions](../../api/resources/create-reference-inspection/README.md).

### Sixth implementation slice — durable requirement contract (2026-10-03)

**V2 core implemented in source and verified offline; live acceptance remains open.** No paid provider calls, runtime rebuild/restart, commit, push or deployment in this slice. Existing saved plans and paused runs were left untouched.

- [x] Give each requirement a host-owned stable ID, category, version, source quote, provenance and optional inspection evidence. Keep inferred suggestions and unknowns separate. Preserve earlier requirements across short follow-ups; require an explicit, latest-message-grounded amendment to replace/remove one and retain change history. Exact quotations establish provenance, not semantic correctness of the planner's interpretation.
- [x] Preserve distinct actions and predecessor IDs. Reconnect ordering after explicit removals; mark unknown/cyclic ordering unresolved. Cover “opens a box, looks surprised, then points to the price” with separate persistent requirements and order checks.
- [x] Bind scene, planned-media, decision-option and character-performance targets to requirement IDs. Carry targets through frozen quotes, purchase context, cached-media identity, successful/failed media receipts, composition context and admin trajectories. In-build purchases may link only to requirements in the approved plan. Optional creative additions still work without mandatory targets; tool routing and spending ceilings remain unchanged.
- [x] Retire inherited character-performance entries when their linked requirement is explicitly replaced/removed; keep the amended general requirement mandatory. Fresh performance planning is still needed for its new action. Explicitly omitted performance requirements are recorded separately in the quote and excluded from active delivery checks.
- [x] Require individual, version-matched final review checks. Missing/duplicate checks, unknown provenance, unresolved order, out-of-order actions and stale checks cannot count as fulfilled. Storyboards may defer production-only action/audio/timing/transition checks, but not appearance. Frame-only review cannot verify speech or audio. Persist requirement statuses with the reviewed source revision and show them, their sources and amendment history in Create.
- [ ] Refresh the local API/worker and verify a fresh end-to-end plan, amendment, quote, build and review. Extraction completeness, semantic interpretation and owner creative acceptance remain open; this slice does not prove every reference detail was noticed or delivered.
- [ ] V3–V7 remain: pre-storyboard character approval, executable performance coverage, actual asset placement, encoded-video temporal/audio evidence and bounded repair. In particular, an audio requirement stays unverified until real audio evidence is available. V2's top-level acceptance stays open pending the live workflow check.

Validation: **142 API tests / 909 assertions, 106 worker tests and the frontend production build passed.** Regression coverage includes stable IDs, follow-up inheritance, amendments, ordering, source evidence, omitted actions, cache invalidation, planned/ad-hoc purchase links, forged purchase IDs, native/envelope tool compatibility, versioned reviews and audio honesty. Tests use fake providers and synthetic local media, with no paid generation. See [contract](../../api/app/Services/Create/RequirementContract.php), [contract tests](../../api/tests/Unit/CreateRequirementContractTest.php), [worker review](../../hyperframes-worker/agent/requirement-review.mjs) and [integration regressions](../../api/tests/Feature/CreateIntegrationTest.php).

Read-only local queue snapshot at **2026-10-03 18:35 UTC**: no active Create run or queued/reserved/delayed job in default/generation/exports. Two Create runs need reconciliation; five older projects still have stale `generating` labels. Latest conversation `0ae68e42-a250-46ff-9cd6-778ef19238cd` has a preview with incomplete review after the call limit. No recovery or status mutation was performed.

## Goal and scope

Integrate upstream creative workflows, skills, examples, tools and rendering engines into WyvStudio's existing Create agent. Keep one conversational interface, asset library, version history and delivery experience. The agent should select appropriate capabilities rather than reproduce a fixed WyvStudio formula.

First acceptance target: a reference-inspired WyvStudio promo using the motion language of https://x.com/achxvi/status/2103918792845963545 and verified information from https://wyvstudio.com. Preserve the requested techniques while replacing brand-specific content. Creative acceptance requires owner review of the rendered result.

This is the current tracker for the new integration/quality work. The existing [implementation TODO](hyperframes-implementation-todo.md) and [reference parity TODO](create-reference-parity-todo.md) retain historical evidence; their completion claims still need reconciliation. This document does not close their production, billing or acceptance gates.

Current status: colour/review improvements, upstream guidance, Barty adapter and initial Remotion clip adapter are implemented locally. Offline renders and agent contract tests pass; live model creative acceptance and production gates remain open. Earlier integration runtime images were rebuilt; the latest V1/V2 source changes still need a local API/worker refresh. No paid generation, push or deployment in these integration slices. Historical spending approvals are not a new test budget.

## Verified baseline

- [x] Audit current integration code, both TODOs and saved run evidence.
- [x] Confirm registry catalogue/staging, routed doctrine cards and critic are implemented.
- [x] Run focused offline cards, registry, critic and grounding tests: 12 passed on 2026-10-02. These are supporting unit tests, not creative acceptance.
- [x] Inspect saved comparison contact sheet: run `d501d4cb-0459-4fde-8731-50107b0f7eeb` reached `preview_ready` through a time-budget fallback after 14 calls, with no critic verdict, reviewed revision -1, no catalogue actions and no registry mounts in its saved composition.

Direct X playback was unavailable during the original audit. On 2026-10-03 the uploaded reference's full 900-frame sequence was inspected locally (see the animated-mascot audit below). Audio listening and reference-to-output creative acceptance remain pending; V1 covers making adaptive inspection part of the agent workflow.

## Work tracker

Every completion entry must link a commit or artifact and relevant test evidence. Implemented, tested and creatively accepted are distinct states.

| ID | Work | Acceptance evidence | Status |
| --- | --- | --- | --- |
| A1 | Reconcile the older TODOs and establish one current release status | Historical entries retained; contradictory current claims corrected; unresolved gates linked here | Pending |
| A2 | Distinguish recovered drafts from reviewed results | Revision metadata carries bounded creative_review status/findings; Create shows incomplete drafts and follow-up action; recovery copy no longer claims all checks passed | Implemented locally; browser acceptance pending |
| A3 | Replace blanket four-of-six fingerprint divergence with brief-specific preserve/replace choices | Numerical quota removed from builder instructions; rendered creative acceptance still required | Implemented; creative acceptance pending |
| H1 | Expose versioned upstream Hyperframes workflows, references and worked examples on demand | 11 workflows, 408 readable files vendored with commit/license/hash metadata; paginated read access wired to agent | Guidance access implemented; runtime/tools acceptance pending |
| H2 | Complete supported capture and motion-inspection tools; assess media-treatment support | Targeted reference frames, crops, sequences and cut candidates pass offline extraction and agent image-routing tests; capture/media-treatment remain; planning-stage visual evidence is covered by W1 | Partial; composition inspection implemented locally |
| H3 | Evaluate a compatible Hyperframes runtime upgrade | Existing compositions, selected registry items, seeking, audio and render tests pass against the pinned version | Pending |
| H4 | Record capability selection and execution | Observed skill reads, catalogue searches and tool calls with success/failure/counts saved beside limitation reports; creative rationale remains model prose | Implemented locally; selection quality needs acceptance |
| S1 | Integrate selected iart product-demo, motion-design, typography and ad packages | Four pinned MIT packages, 13 workflows and root verification script references readable; optional recommendations by task; initial Remotion clip adapter verified offline | Guidance and basic native runtime integrated; live acceptance pending |
| S2 | Integrate Barty's motion-broll workflow and supporting engine/tools | Pinned guidance plus seek-driven Hyperframes adapter, runtime staging and agent instructions. Offline seek/colour fixture and real runner build/edit render pass with local narration, supplied transcript timings, source hashes and audio correlation; live model selection/creative acceptance pending | Offline integration verified; live acceptance pending |
| S3 | Add skill discovery and compatibility routing | Package/runtime metadata and optional task recommendations in context; every read includes compatibility warning; host tool allowlist unchanged | Implemented for guidance; live selection pending |
| C1 | Correct style/colour precedence | Saved default no longer overrides valid planner routes; composer pin preserved; colour precedence aligned in planner/builder/cards. Structured provenance and end-to-end colour evidence remain | Partial; routing tested |
| C2 | Introduce a per-run colour treatment | Normalized proposal stores source/note, background/text/accent/secondary roles, locks and usage; shown in plan card and passed into quote/agent context | Implemented locally; live acceptance pending |
| C3 | Make repeat avoidance sensitive to actual treatment | Recent palette/background/layout use can inform requested alternatives; no forced random variation or override of brand locks | Pending |
| C4 | Verify colour choices reach the final composition | Offline route, palette normalization, replan inheritance, locked-role preservation and quote handoff tests pass; rendered enforcement/contrast and free-edit reconciliation still pending | Partial |
| R1 | Improve reference motion evidence | Builder inspection plus pre-purchase planner cut windows and structured preserve/replace/uncertain handoff | Implemented locally; live interpretation acceptance pending |
| R2 | Prepare verified WyvStudio product demonstration assets | Real UI captures/demo states and approved claims available; landing-page copy is not mistaken for product proof | Pending |
| R3 | Prove a representative 5–8-second sequence | Character/product interaction/transition rendered and reviewed by owner before full promo acceptance | Pending |
| Q1 | Reserve execution capacity for review | Five-minute review window for critic-enabled paid builds; host reviews a current checked snapshot before another author call near time/call limits; existing critic cap retained | Implemented locally; live timing acceptance pending |
| Q2 | Preserve unresolved findings across review/repair | Critic pass must match delivered authored revision; recovered outputs remain incomplete; directives/advisories reach result card | Implemented locally; full rendered acceptance pending |
| Q3 | Complete full reference-inspired promo acceptance | Playback/listening, factual/source checks, motion comparison and owner creative verdict recorded with actual cost | Pending |
| M1 | Scope Remotion adapter and applicable commercial licensing | Product-card workflow and pinned dependencies documented in remotion-integration.md; evaluation scope established, commercial eligibility still to confirm | Scoped; commercial gate open |
| M2 | Implement Remotion author/preview/render/edit adapter | Native React clip/still creation and edits through real runner; editable source retained, generated clips join Hyperframes preview/export; offline fixture passes | Clip adapter implemented locally; broader acceptance pending |
| M3 | Benchmark engines on the selected workflow | Compare quality, editability, latency and cost; route by demonstrated suitability | Pending |

## Reusable creative workflows backlog — 2026-10-03

Added from the owner's supplied Remotion/skills YouTube transcript. These are pending product improvements, not prerequisites for testing the current local harness. Keep the conversational UI and existing upstream integrations; recipes are optional starting points, never a universal visual formula.

Priority: complete the urgent V1–V8 workflow above and its representative sequence acceptance (R3/Q3), then save successful structure (L1/L2). Add preference/variation and batch features only after the single-output workflow is reliable.

- [ ] **L1 — Reusable, editable compositions.** Save an owner-approved result with preview, versioned source, engine/skill provenance, asset bindings and a validated input schema for product, headline, images, colours and timing. Prove “use this for another product” preserves approved motion/layout, handles longer text and retains source identity. Link Remotion source/render freshness work in M2.
- [ ] **L2 — Deterministic reuse without a full authoring loop.** Separate creative selection from repeatable execution. Validated input-only edits should render through existing app tools without an unnecessary model rewrite or repurchasing unchanged media. Invalidate affected renders when inputs/source change; show applicable costs and retain layout/audio/export checks. Start a quoted creative revision when an edit exceeds the recipe's supported inputs. Rendering/transcription/provider work is not assumed free.
- [ ] **L3 — Feedback becomes optional workspace preferences.** Offer to save accepted feedback (for example less jitter, stronger typography or a particular character treatment) as scoped, editable preferences/recipes. Record provenance and allow reset. Explicit brief and brand locks take precedence; do not turn one customer's taste into global instructions or silently modify upstream skills.
- [ ] **L4 — Defined variation controls.** Offer variation in layout, type scale and motion intensity with reproducible seeds and clear previews. Preserve approved claims, product/character identity and locked colours. Verify repeatable output and that increased variation does not imply increased quality or relax constraints.
- [ ] **L5 — Evaluate skill/component selection.** Extend H4/G1 with versioned recipe/skill usage linked to output revision, review/owner acceptance, cost, latency and repair count. Compare representative tasks; distinguish observed use from evidence of usefulness. Keep guidance on demand and propose reviewed improvements instead of automatic self-modification.
- [ ] **L6 — Reference-to-output motion comparison.** Extend R1/Q2: inspect the reference transition and the corresponding rendered segment with aligned timestamps and comparable contact sheets. Report differences in timing, movement, layout and requested treatment; carry findings into repair. Sampling is not exhaustive semantic verification, and audio needs its own check.
- [ ] **L7 — Batch variants from an approved result.** After L1/L2 acceptance, support several products or supplied data rows with a batch quote/ceiling, shared asset reuse, per-item status, cancellation and idempotent recovery. Validate each item and retain automated checks on every output; deeper review can prioritize exceptions. One failure must not repurchase or overwrite successful siblings.
- [ ] **L8 — Small proven component library.** Begin with accepted talking-character introductions, product reveals, browser demonstrations and kinetic headlines. Store previews, supported inputs, dependencies and compatibility limits. Let the agent discover/reuse or author something new; avoid fixed palettes and compulsory templates. Promote only reviewed examples, with provenance and applicable asset permissions.

## Harness and workflow closeout — 2026-10-03

Local engineering is implemented; fresh paid creative/playback acceptance is **not passed**. No new provider budget, commit, push or production deployment was part of this work. Existing unknown billing is not silently cleared.

| ID | Work | State and evidence |
| --- | --- | --- |
| W1 | Reference evidence before purchases | Implemented for the configured Anthropic planner. The existing planning turn receives overview frames plus bounded before/at/after cut windows and uploaded reference images. Source-hashed contact sheets prevent stale cache reuse. Structured observed/preserve/replace/uncertain notes are saved in the plan and quote; interpretations are labelled, not treated as exhaustive frame/audio analysis. |
| W2 | Approve the actual character before talking generation | Implemented. Review dialog shows purchased pose images; approval binds to plan, treatment, script/voice/aspect context, asset IDs and hashes. Missing/stale approval blocks quotes, approval and planned/ad-hoc talking purchases. The executor uses the exact approved pose snapshot. A new storyboard is offered if images are not available. |
| W3 | Recover without duplicate purchases | Implemented for the app dispatch boundary: atomic dispatch claims, durable provider responses, same-attempt replay and a saved-receipt reconciliation command. Confirmed stopped hosts can be released while their conversations/credit holds remain paused. Completed media files survive interruption before asset settlement. Unknown catalogue outcomes stop the build; character POSTs are not blindly retried after connection loss. |
| W4 | App-controlled Replicate tools | Implemented for Create app runs. Agent and direct image/animation requests, review-image uploads and prediction polling go through authenticated app endpoints, bound to the frozen model/input/attempt. The worker reads no provider credential. Existing standalone provider fixtures are not the app execution path. |
| W5 | Diagnose creative constraints | Implemented locally: limitation reports plus observed skill/catalogue/tool-use records, success/failure and counts. Removed mandatory colour-field flips and minimum sound-cue recipes. Consent, source identity, approved content and spending boundaries remain. Tool use is evidence of execution, not proof of creative quality. |
| W6 | Workflow verification | Offline API/worker tests and frontend build; no paid generation. Browser acceptance and a fresh storyboard → character approval → full audio/motion → reviewed edit/export remain the final owner acceptance run. |
| G1 | Godmode diagnostics view | **Partial, implemented locally:** Create trajectories exposes request/run timelines, receipts, tool observations and reported limitations. Broader rollups and decision tracking remain below. |

### Godmode diagnostics backlog (G1)

- [ ] Authenticated ingestion of bounded limitation/capability summaries with run, conversation, stage, revision and evidence source. Include planning, media purchase, authoring, review and export failures, not only authoring logs.
- [ ] Idempotent persistence, schema version, retention/size limits and redaction. Keep prompts, media, credentials and signed URLs out of rollups. Account for retained interrupted media separately.
- [x] Admin-only API and Godmode Create trajectories view, with server-side admin/IP authorization. Ordinary workspace owners are denied; authorized platform admins can inspect across workspaces.
- [ ] Filter by stage, tool, provider, status and date; distinguish observed failures, agent claims and inferred causes. Show whether there is a reviewed deliverable.
- [ ] Detail links to the authorized conversation, attempted recovery and receipt state; no one-click blind provider retry or credit-hold release.
- [ ] Evidence-led proposals for narrower new tools or budgeted experiments, with human decisions logged. Never let a report expand its own authority.

### Remaining acceptance and external gates

1. Run the owner's reference-inspired brief fresh, approve the actual stylized character, and inspect motion, speech, script fidelity and final export. This requires an explicitly authorized paid run; none was started here.
2. Historical attempt `1eaf4e9e-a564-45d6-b918-34e73db24e63` has no recorded Anthropic ID/response. The new journal cannot retroactively recover it. Keep its hold until provider/billing evidence is located; missing ID is not proof of non-billing.
3. Broader engine work remains in H2/H3/M2/M3: upstream capture/media-treatment, runtime upgrades, Remotion internal-content/freshness/audio stress checks, comparison and commercial licensing. These are not silently marked complete by the workflow changes.
4. Public/production/API-MCP release gates remain separate. G1 is an admin product task, not a reason to delay local creative testing.

### Recovery runbook (local only)

- Verify that the **original host process and named Docker sandbox** stopped before confirming `--worker-stopped`. Do not infer this from lease expiry alone.
- `php artisan create:quarantine-run <run> --worker-stopped`: revoke its lease and release the local queue slot; preserves conversation pause and billing holds. Does not restart generation.
- `php artisan create:recover-provider-receipt <attempt> --worker-stopped`: reconcile a successful app-saved Anthropic response and its saved metering rates. Refuses missing receipts or over-ceiling usage. No provider POST.
- Existing `create:reconcile-attempt` handles provider-verifiable Replicate receipts with explicit billing evidence. Unknown outcomes without receipts remain held.
- Existing `create:recover-finished` can import a verified local rendered result after all attempts are reconciled. New paid work still requires a new approved run.
- Interrupted catalogue artifacts remain private under `storage/app/private/create/media-attempts/<attempt>/`; `completed.json`, when present, records output before asset/ledger settlement. File existence alone is not proof of a provider charge.

### Limitation reporting — implemented locally

- `report_limitation` accepts category, summary, evidence, impact, workaround and requested_change (bounded text). It returns advisory triage without changing authority or making a provider call.
- Runtime tool failures, validation/critic findings, budget/call/context/deadline stops and recovery pauses are recorded independently of model self-reporting. An observed event is not proof of its root cause; self-reports are explicitly unverified.
- Records include call/revision, timestamps and occurrence counts. Sixty distinct records per run; repeated records increment counts. No change to generation, approval or review gates.
- Saved in `agent-state.json` and a derived `agent-state.limitations.json`, alongside local run artifacts. Common secrets, URLs, email addresses and local paths are redacted in diagnostic copies. Owner-only; model prose still needs review before sharing. The existing full execution journal remains separate.
- Read-only rollup: `node hyperframes-worker/scripts/limitation-report.mjs` (from the app folder). Groups by category/tool/evidence source, shows affected runs and whether runs ended with a reviewed result, and includes narrow proposals. Older journals contribute recorded terminal outcomes only. Local fixtures/experiments are included, so these counts are not production failure rates.
- Offline validation: 86 focused tests passed for reporting/schema/redaction/aggregation plus agent, tool and composition regressions. No paid tests or permission changes.
- Coverage: composition-agent execution. Planning/media-purchase failures and final export failures still use their existing service/worker logs; a central admin report spanning these phases is pending. Local journaling is not a server-side analytics dashboard.

### How to decide whether to grant more freedom

| Evidence | Response |
| --- | --- |
| A reproducible task cannot be done with available tools | Add one bounded capability; compare output, latency and cost offline |
| Useful work consistently stops at an execution limit | Review repetitive steps first; test a higher limit under an explicit spending ceiling |
| The agent follows an unnecessarily rigid creative recipe | Test a less prescriptive instruction while preserving approved content, colours and user requirements |
| Poor visual output without a demonstrated tool restriction | Improve reference grounding, assets, prompts/model choice and review; extra permissions alone are not a fix |
| Missing facts, invalid arguments, provider failure or uncertain billing | Correct inputs/invocation or recover the existing attempt |
| A request to bypass consent, source protection, credential isolation, app-controlled providers or approved spend | Keep the boundary; offer a supported path |

Reports propose experiments for owner review. They never grant the agent unrestricted network/shell access, permission to purchase media, automatic budget increases or authority to retry an uncertain paid call.

## Colour repetition: evidence and intended correction

Confirmed contributing code paths:

1. `api/app/Services/Create/StylePacks.php::route()` forces the saved route when house_style exists, unless a valid pack is pinned. This can override a planner-selected reference route.
2. `Planning/PlanPrompt.php` directs the model to follow the house palette unless the brief overrides it, and to match the brand page. The prompt's override intent therefore does not fully match the route resolver.
3. `hyperframes-worker/agent/guidance/cards/design.md` says brand colours win; `composition-agent.mjs` also prioritizes brand look over style-pack colour.
4. `agent/craft.md` defaults to at most two background colours plus near-black/near-white, with a colour-field flip. This can encourage a repeated treatment; it does not itself force orange.
5. `PlanService.php` supplies recent style-pack choices. That is not evidence of tracking the actual rendered palette or its distribution.

These are contributors, not proof that every repeated output has the same cause. Runs should record palette provenance so repetition is diagnosable.

Proposed behaviour:

- Explicit instructions and explicit brand locks take precedence; surface an actual conflict instead of silently overriding either.
- A saved style is a default, not an unconditional route override.
- A request to borrow motion from a reference can keep brand colours; a request to borrow its palette should be represented separately.
- Palette variation can change light/dark balance, neutral fields, accent placement and coverage while keeping the brand recognizable.
- WyvStudio's application UI palette must not become a universal output palette for unrelated customer brands.
- Follow-up edits preserve approved colours unless the requested change requires otherwise.

## Integration boundaries

Upstream packages:
- Hyperframes: https://github.com/heygen-com/hyperframes
- Remotion: https://github.com/remotion-dev/remotion
- iart skill catalogue: https://github.com/iart-ai/motion-skills (selected packs live in separate repositories)
- Motion B-roll: https://github.com/Barty-Bart/motion-graphics

Version and attribute packages. Expose their actual dependencies/tools through controlled adapters. Do not assume adding SKILL.md to this repository automatically makes the application agent load it. Keep renderer-specific authoring instructions separate; retain editable source in the originating engine. Rendered clips may be composed together without promising automatic source conversion.

## Execution order and evidence log

Start with A1–A3 and C1–C4, then H1–H4 and S1–S3. Owner subsequently requested Remotion implementation before R/Q acceptance; its local adapter work proceeds while those acceptance gates remain open. Review fixtures and compatibility offline before proposing budgeted provider runs. No push or deployment is part of this tracker update.

| Date | Evidence | Result |
| --- | --- | --- |
| 2026-10-02 | Current source audit, saved comparison sheet, 12 focused offline unit tests | Baseline established; colour-routing conflict identified; new work remains pending |

### 2026-10-02 — First local implementation slice

- `StylePacks::route()` preserves deliberate reference/free/pack choices with a house style present; missing route uses the saved default. Composer pack pins retain precedence.
- Planner, builder and design guidance align saved colour defaults with explicit brief/approved treatment. Removed the universal two-background-colour quota and four-of-six fingerprint divergence requirement.
- Updated the design card integrity manifest.
- Verification: targeted `CreateIntegrationTest` route/freeze test passed (16 assertions); 12 focused Node tests passed. No live model or creative-output acceptance claimed.
- C2–C4 remain open: structured palette provenance, recent actual treatment history and rendered colour verification have not been implemented. A2 review-status changes are also still pending.

### 2026-10-02 — Structured colour treatment

- Added `ColourTreatment` normalization: bounded source descriptions, hex-only role values, optional role locks, inheritance on missing treatment and preservation of recorded locked roles during replanning. No hard-coded fallback palette.
- Planner now receives actual brand-kit colours scoped to the workspace (previously it received names only). Multiple available kits are not treated as automatically selected.
- Planner schema and instructions request source, roles and usage. Plan storage, previous-plan context and quote handoff retain the treatment. Builder receives it explicitly.
- Approval card displays swatches, role labels, fixed-role labels and usage text. Users can request a changed direction through the conversation.
- Verification: full CreateIntegrationTest passed, 91 tests / 582 assertions; frontend production build passed; worker syntax and diff whitespace checks passed. Build retains existing chunk-size/dynamic-import warnings.
- Limits: provenance is a planner proposal visible to the user, not independently proven attribution. Locks are preserved in plan normalization and instructed to the renderer; pixel-level enforcement is not yet implemented. Explicit unlock UX, free-variable-edit palette reconciliation, palette-history diversity and live visual acceptance remain pending. No paid calls, push or deployment.

### 2026-10-02 — Honest review status

- Added worker review-status derivation: only a critic pass on the delivered authored revision, with no unresolved advisory or recovery fallback, yields `passed`.
- Persist bounded creative-review status/findings on revision metadata, independently of render completion. Existing revisions without metadata are not retroactively labelled as reviewed.
- Create displays Draft — review incomplete or Creative review passed. Delivery checks no longer claim Ready to post. Keep improving is available for incomplete current outputs and includes their findings in the follow-up brief.
- Downgraded layout findings remain visible; a subsequent clean check clears them. Missing review metadata on newly settled outputs defaults to incomplete.
- Validation: 72 Node tests passed (runner, tool mode, status); PHP settlement test passed with 5 assertions; frontend build and worker syntax check passed. No paid calls or deployment.
- Q1 remains pending: protected authoring/review time allocation has not changed. This slice makes exhaustion honest; it does not ensure the critic runs before exhaustion. Browser verification, broader history/library badges and creative acceptance remain outstanding.

### 2026-10-02 — Protected final review

- Critic-enabled paid builds reserve the last five minutes of the existing run deadline for review. The author sees this allowance in its call context.
- Near that boundary, or with one author call remaining, the host reviews a current checked/snapshotted draft directly using remaining approved critic calls. A valid draft can also receive review immediately after the final author call.
- Passes bind to the reviewed revision; revise verdicts deliver an explicitly incomplete draft. Unknown critic-call outcomes retain a provider fence for reconciliation rather than being retried or delivered as success.
- Existing time and spend caps remain unchanged. No current checked snapshot or no remaining critic allowance means review cannot be guaranteed: preserve an incomplete draft where available. Slow in-flight calls can still overrun the reserve; live timing acceptance remains pending.
- Verification: 74 runner/tool-mode/review-status tests passed, including host-triggered pass/revise and uncertain critic outcomes. No paid generation or deployment. User will batch browser and creative acceptance later.

### 2026-10-02 — Upstream Hyperframes guidance access

- Vendored 408 readable files from 11 upstream workflow/domain folders at `385e9cf337c103d0918f9ea962c4e3859a2cc75f` with Apache-2.0 license and per-file hashes (about 4.5 MB). Binary example assets are excluded and explicitly identified as unavailable.
- Added a package/workflow catalogue to agent context. `read` can load `skills/index`, package file listings, full SKILL.md files and references/examples/scripts. Long files expose numbered continuation pages.
- Read access rejects traversal/unlisted files and verifies hashes. Scripts are readable reference material, not newly installed executables. Source version differs from the pinned 0.8.82 renderer; the capability warning is supplied to the agent.
- Added a reproducible vendor command requiring the source checkout HEAD to match the recorded commit. Existing Docker COPY of agent includes the library on the next build; no image build/deploy was performed here.
- Verification: 22 focused skill-library, composition-agent and tool-mode tests passed, including routing upstream reads through the guidance loader. Full live skill selection remains unverified. iart/Barty packages, capture and runtime upgrades remain pending.

### 2026-10-02 — iart and Barty specialist guidance integration

- Added four selected iart packages (ads, motion design, kinetic typography, product demos/promos) and Barty motion-broll: 14 additional workflows, 93 readable files, exact source commits and license files recorded in the upstream manifest.
- Included original skill guidance, available text examples, script/engine source and root verification scripts. Binary assets and fonts are not installed; the package warning says so.
- Optional recommendations follow product/ad/motion/mascot routes; Barty is suggested when narration/talking-take or source audio is present. Recommendations do not force a palette, a workflow or a renderer switch.
- Each read identifies repository/commit and runtime compatibility. Upstream default palettes yield to the approved colour treatment. Native Remotion and Barty commands are explicitly not advertised as callable host tools.
- Vendor script now updates one package while preserving other manifest entries, verifies checkout HEAD and enforces specialist commit pins. Source packages are listed in `scripts/specialist-skills.json`.
- Validation: 24 focused tests passed, including recommendation selection, missing-runtime warnings, license/engine reads and hashes for all 501 vendored reference files. No paid calls, installations into the render runtime, push or deployment.
- S1/S2 are not closed end-to-end: using these workflows through their original renderers still requires executable adapters and rendered acceptance. The next Barty work should adapt its seek-driven engine to our render lifecycle, disable its autonomous preview loop, map transcript/approved colours, and verify a speech-timed fixture before exposing execution.

### 2026-10-02 — Barty executable Hyperframes adapter

- Added the pinned upstream Barty engine to runtime with its MIT license; the only engine change disables autonomous preview playback. A provenance regression checks the exact source hash and derivation.
- Added `WyvBroll.scene` to connect the engine to a paused GSAP timeline registered with Hyperframes. Uses a property setter so seeking works even when callbacks are suppressed. Enforces one scene per document and matching root dimensions/duration; leaves colour choices with the authored approved plan.
- Existing live-tool staging provides both runtime files. Agent context advertises the adapter and `kit/barty.md`; guidance reads route correctly. No standalone Barty installer or renderer execution is exposed.
- Added repeatable offline fixture `scripts/barty-fixture.mjs` and authored source `fixtures/barty/index.html`. Ran in the existing local Hyperframes image with networking disabled, readonly source mounts and no provider credentials.
- Verification: 26 focused Node tests passed. Browser checks passed for identical frames after backward seeking, paused stability, chosen background colour and six-second timeline duration. Hyperframes check/render/probe/decode passed: 1920×1080, 24 fps, six seconds. Inspected the middle frame. Local evidence: `/tmp/wyv-barty-proof/barty-seek.json`; render `/tmp/wyv-barty-proof/barty-render/d91107f6-6252-4bc0-bb94-ed1ca44743e8/video.mp4`.
- Limits: synthetic silent fixture, not model-authored creative or speech alignment proof. Alpha overlays, multiple independent Barty scenes and end-to-end agent selection remain unverified/unsupported. Speech timing uses existing transcript/audio tools and authored SEQ/layer times; no automatic alignment claim. Runtime needs the normal worker image rebuild before app runs can load these files. No paid calls, commit, push or deployment.

### 2026-10-02 — Barty offline agent build/edit acceptance

- Rebuilt the standalone local worker image with the adapter and guidance. No API restart, queue consumption, paid provider call or deployment.
- Added `hyperframes-worker/scripts/barty-agent-fixture.mjs`: drives the real `executeCompositionAgent`, its guidance/transcript/actions, preflight, timeline validation, live-tool staging, snapshots and final Hyperframes renders with a scripted zero-cost provider. Both initial creation and a source-preserving edit complete.
- Locally synthesized three speech phrases using macOS Samantha. Padded each to a two-second slot; supplied known fixture transcript timings. This checks timing plumbing, not transcription accuracy or automatic creative decisions.
- Initial and edited exports are six-second 1920×1080 / 24 fps videos. Browser assertions verify the intended phrase alone is visible in each speech slot, the chosen background survives, and the product image loads. Inspected an actual rendered-video contact sheet.
- Original source audio/image SHA256 values remain unchanged after editing; HTML changes only the requested label. Decoded export audio correlates 0.9997956 with original narration on both renders, with no detected timing offset. 13 scripted provider responses, two transcript-tool invocations, $0 provider spend.
- Fixed a false preflight warning: the supported Barty bridge now counts as timeline registration when its runtime script and scene call are present. Regression also verifies a missing bridge still warns.
- 49 focused tests pass across adapter provenance/preflight, agent/tool routing, skill library, critic, timing and transcript mapping. Local evidence: `hyperframes-worker/artifacts/barty-agent-20261002/` (ignored artifacts; report, original/edited MP4s, rendered contact sheet).
- Still pending: live model workflow selection, creative quality acceptance, real ASR alignment, transparent overlays and multiple independent Barty scenes. Existing long-running host workers must be restarted when idle to load changed host modules; this test did not consume the app queue.

### 2026-10-02 — Remotion clip adapter

- Added Remotion/bundler/renderer 4.0.532 and React 19.2.0 with lockfile pins. Existing installed dependency versions unchanged; Babel parser pinned directly for pre-bundle import validation.
- Agent discovers `kit/remotion.md` and can author native React `.js`, request a still or MP4 via validated `run remotion` arguments, and use returned media in the existing Hyperframes composition. iart package reads identify the installed host adapter and its limits.
- Host fixes composition registration, geometry, FPS and new output names. Authored source imports are checked before tree shaking and during bundling. No arbitrary configs/installers/loaders are exposed. Failed/timed-out outputs are not published; timeout kills the native render process group.
- Source retention uses existing JS revision bundles. Last-good recovery now snapshots all source files and removes post-checkpoint source additions when restoring. Existing media remain immutable.
- Real runner fixture passes using a scripted offline provider: read adapter/iart, write React, still/render, embed/preview/export, patch React, render/replace/export. Both exports are six-second 1920×1080 / 24 fps. Native source, original rendered clip and supplied product integrity verified. Invalid unused Node import rejected with no output. 16 scripted responses; $0 provider spend.
- 90 focused container tests passed across source validation, adapter contract, agent recovery, tool routing, skill reads and run behavior. A separate CSS probe exposed unsupported loader imports; first adapter explicitly permits inline styles and sibling JS only. Regression covers stylesheet rejection.
- Local evidence: `hyperframes-worker/artifacts/remotion-20261002/` (ignored report, original/edited MP4 and stills). [Implementation and boundaries](remotion-integration.md).
- Still open: model selection and creative acceptance, native internal text/claim/timing checks, source-to-render freshness enforcement across revisions, native audio/video and cancellation stress coverage, alpha, bundle caching, engine benchmark and commercial license confirmation. This is a native clip adapter, not a full project-engine migration. No paid calls, application queue processing, commit, push or deployment.


## Conversation workflow correction — 2026-10-02

Investigated conversation `be8a3f70-3849-4e0b-a1aa-c48277bda55c`. The only rendered run was a silent storyboard (`look_first=true`), not a completed motion video. The follow-up and its second plan were saved; no second generation ran.

- [x] Quote-bound storyboard/full-video stage replaces chat-keyword approval. The full-video action reviews cost and respects unsaved plan edits. Storyboard selection remains editable on follow-ups.
- [x] Storyboard quotes defer voice, music, effects, animation and talking-video purchases; the API also rejects those purchases during that stage. Original plan item indexes survive filtering for reuse.
- [x] Selected media options feed the execution list as well as pricing. Talking video requires planned poses, narration and voiceover; no silent dropping at six items.
- [x] Result appears chronologically between messages; stale refresh responses cannot roll conversation state backward. Storyboards are explicitly labelled silent/unfinished.
- [x] Previous creative-review findings travel with the next quote to the composition agent. Building motion does not imply findings were fixed.
- [ ] Live acceptance: verify halftone Maya, full talking performance, motion fidelity and audible finished output against the supplied reference. No paid generation was run for this correction.
- [ ] Browser acceptance on the signed-in conversation. The available browser session redirects to login; database inspection confirmed both user messages and plans remain saved.

Validation: local Create integration suite and focused conversation-order tests; frontend production build. These are local source changes; deployment/runtime refresh is separate, and the earlier generated file remains unchanged.


### Talking presenter routing — 2026-10-02

- Default talking clips use native video + speech from the approved script; local default is Gemini Omni 1.1 (not OmniHuman). `CREATE_NATIVE_TALKING_ENGINE` supports `omni`, `veo`, `seedance25`; Veo requests longer than 8 seconds select Omni before quoting. Cloned voice explicitly selected uses the existing audio-driven lip-sync path. There is no provider-switch retry on moderation rejection.
- Quotes freeze the speech mode, engine, duration and host-calculated tariff. A native full take excludes duplicate TTS; a native hook's separate narration, if present, contains only subsequent script lines. Worker instructions keep native speech audible and preserve one speech track.
- Native clips must contain both video and audio streams. Landscape is passed through to the provider; unsupported aspect ratios are refused before generation. Existing quotes retain their previously approved lip-sync behavior.
- Automated routing/provider-contract checks are local and mocked. Real pronunciation, script fidelity, visual character preservation and audio quality still need paid acceptance; no new paid runs were made for this change.

### 2026-10-03 — Brief fidelity harness
- Plans expose explicit requirements with exact user-message evidence and separate character treatment. Quote/context/critic carry them; new planning receives prior requirements.
- Pose generation changes requested rendering style while preserving identity, rather than requiring original texture. Asset cache identity includes treatment.
- Critic revisions and unmet requirements cannot become a pass merely because scores are high; findings carry into repairs and delivery status.
- Sending a brief uploads selected files first; failed uploads or missing reuse permission prevent sending.
- Offline validation: 95 API/unit tests plus the new requirement-grounding case; 10 worker tests; frontend build.
- Still requires visual acceptance on a new run. This does not guarantee model fidelity or add a dedicated character-approval checkpoint before purchases. Use storyboard review for staged approval. Provider-gateway consolidation and unknown-call recovery remain separate open work.

### 2026-10-03 — Targeted reference inspection

- [x] `inspect_reference` is available in native tool calling and the validated JSON action protocol. The composition agent can inspect chosen timestamps, crop an image/frame, sample 2–8 frames across a transition of up to two seconds, and locate heuristic cut candidates in a window of up to 30 seconds. Eight inspections per run bound extra work.
- [x] Inspection uses authenticated staged reference files, verifies their hashes and rejects source files, traversal, symlinks, arbitrary filters and out-of-range requests. FFmpeg runs locally inside the network-disabled sandbox. Temporary frames are removed; the latest sheet and metadata remain beside the run for diagnosis.
- [x] Reference sheets reach the model as images with cell/timestamp metadata. They never enter the composition asset list and cannot satisfy the rendered-output review gate. Native and JSON-mode regression tests cover this distinction.
- [x] Instructions ask the author to inspect distinctive reference details before implementing them and choose registry components, authored motion or generated media accordingly. Removed the contradictory instruction to fix an incorrect halftone character with a CSS overlay.
- [x] Validation: 83 focused worker tests passed, including real FFmpeg extraction/crops/cut detection and protocol/agent regressions. Offline Docker smoke passed against the uploaded reference (1920×1080, 60 fps, about 15 seconds). Local sandbox rebuilt and Create worker refreshed. No paid model calls or media purchases.
- [ ] Creative acceptance remains open. This adds **composition-stage** inspection; the planning/media-purchase stages still use their existing reference analysis. It does not prove the initial character-generation prompt was visually grounded, add a pre-purchase character approval checkpoint, or resolve the existing unknown-provider-call hold.

These are targeted samples, not exhaustive pixel-by-pixel or all-frame semantic analysis. Use the reported frame rate to request adjacent frames around a particular event; cut detection is downsampled and heuristic. Crops improve access to source detail, but model fidelity and motion/audio acceptance still require review. Extraction makes no external call; sending the resulting images in an already-authorized agent turn consumes that turn's model context/budget.

### 2026-10-03 — Harness implementation evidence

- API source: `CharacterApproval`, `DispatchJournal`, `ReplicateGateway`, `RecoverCreateProviderReceipt`, `QuarantineCreateRun`, reference-sheet and planning updates. New additive migration `2026_10_03_120000_add_create_dispatch_journal` is required before using the new worker.
- Worker: app gateway transport, capability evidence, uncertain-media stop behavior; host stopped acknowledgement retains billing holds.
- UI: actual character-image review dialog before full-video costing; storyboard path when no current poses exist. Known reusable non-talking media quotes zero credits and refuses a replacement purchase if that cache changes.
- Offline validation: **101 API tests passed (646 assertions)** across `CreateIntegrationTest` and `CreateCharacterTreatmentTest`; **101 worker tests passed** across agent, composition-agent, tool-mode, limitations, gateway-workflow, reference-inspection and accounted-call suites. Real FFmpeg extraction is included. Frontend production build passed; existing chunk-size/dynamic-import warnings remain.
- Refreshed local API image `sha256:2fbd1114db8466142e434546e45cbb60cb59c9ceebc8693a57bec50451d1099b` and sandbox `sha256:86764cc32489204917d467a59ae29ac3aef092063502e828ffbbd962a228b302`; applied the additive migration and restarted exactly one idle host worker on the updated modules. No generation was submitted.
- Verified the original host and named sandbox for run `a5a42cca-89e4-43f1-b42e-53cc52095c44` were stopped, then quarantined it locally. Its conversation and billing hold remain paused; it no longer blocks different conversations from using the worker.
- Provider boundary: visual pre-purchase planning is verified on the configured Anthropic adapter. The legacy `ReplicatePlanner` is text-only and does not forward `_images`; switching the planner to that adapter does not preserve W1 visual grounding and needs its own capability integration/acceptance. Composition-stage Replicate review images use the new authenticated app gateway.
- Fresh signed-in browser/paid creative acceptance remains pending. Offline success is not a claim that a model will reproduce every requested stylistic detail.


### 2026-10-03 — Shared capacity admission correction

- Owner testing exposed a second concurrency counter: `api_operations.capacity_slots` still counted a stopped Create run even after its host slot was released. The workspace's three-operation limit caused `too_many_active_videos` to become a generic 500 during approval.
- Authenticated stopped-host acknowledgement and local quarantine now set execution capacity to zero while keeping `needs_attention`, reserved credits and uncertain provider attempts intact. Existing worker leases are revoked; billing reconciliation remains separate.
- Create approval maps expected capacity/credit/busy failures to readable 429/402/409 responses instead of a generic server error. Failed approvals roll back without consuming the quote. Other operation families retain their existing admission rules.
- Verification: **102 Create integration tests passed, 657 assertions**. Added coverage at a one-slot limit for authenticated stop, idempotent quarantine, preserved holds and readable capacity rejection with no consumed quote. No paid generation was started.

### 2026-10-03 — One approved character design before poses

Conversation `3245ac68-d822-4bd7-b6fb-8af17db77f3e` exposed independently restyled Maya poses: photographic halftone, cartoon and comic treatments were mixed in one storyboard.

- [x] `character_poses` now purchases one master preview (35 credits), reused across storyboard beats. Full-video quotes require approval of that exact preview before adding separately priced poses (35 credits each, up to five). Each pose uses the same approved image, never the original photograph or another pose.
- [x] Character preview generation receives the identity image plus up to three style images extracted from frozen reference attachments. Brand-page captures are excluded from character treatment. Local extraction is verified by snapshot hash; preparation failure settles at zero rather than falsely claiming an uncertain paid outcome.
- [x] Versioned character contract excludes legacy independently generated poses from approval/cache reuse. Rebuilding a storyboard without a current master excludes old generated character assets and their source composition; earlier results remain saved.
- [x] Planning and composition instructions preserve the reference's visual baseline and describe user substitutions. A dark brand-page capture does not implicitly override a bright reference. Explicit user choices and brand locks still apply.
- [x] UI explains the character approval and subsequent pose/full-video step. Agent instructions reuse the master during storyboarding and compare later poses for identity/style drift.
- [x] Offline validation: 103 API tests/679 assertions, plus the additional local-reference-failure test (7 assertions); 28 focused worker tests; frontend production build. Real FFmpeg reference-frame extraction and mocked model input lineage covered. No paid generation started.
- [x] Local API and sandbox rebuilt; idle Create host restarted. Runtime reports `approved-master-v2`, 35-credit preview and unchanged $100 total local cap. No active run was interrupted. No commit, push or production deployment.
- [ ] User acceptance on a fresh storyboard: reference-faithful Maya treatment, then consistent approved poses and final motion/audio. Prompting and a shared image anchor improve consistency; these checks do not prove perfect model fidelity.

Current limitation: style extraction samples two early video frames (or supplied reference images), rather than automatically finding/cropping the character in arbitrary reference footage. The agent's composition-stage inspection remains available for targeted detail. Character approval precedes pose/video purchases, but the storyboard still incurs its disclosed preview/composition cost.


### 2026-10-03 — Request trajectories

- God Mode → **Create trajectories**: search conversation/run ID or title, filter by run or failures, inspect a chronological timeline, download bounded JSON. Includes requests, plan snapshots, approved inputs/media and their hashes, provider/model/prediction IDs, recorded charges, unknown outcomes, output hashes and review findings.
- New quotes preserve user message IDs/sequences for explicit request → plan → run correlation. Older snapshots lacking IDs remain identified by conversation/run; no invented historical action sequence.
- New runs append bounded local `trajectory.jsonl` action start/outcome records, call/revision numbers, duration, selected tool parameters and input/output hashes. Authenticated lease-bound batches persist to `composition_trace_events`; retries are idempotent and cannot overwrite events. Reporting outages retain the local journal/queue and never replay paid work. Hard-stop records not yet synced remain local; no automatic journal import after lease revocation.
- Reports exclude raw source, full provider responses and credentials. Common secrets, URLs, email addresses and local paths are redacted. User/plan text is available only to authorized admins; model prose is not safe for public sharing merely because it passed pattern redaction. Model-reported limitations are explicitly unverified. No hidden model reasoning is captured.
- Per-run cap: 2,000 events; at most 50 per upload. Conversation inspection includes latest 20 runs/200 messages/100 plans and labels these limits. No time-based purge yet. G1's cross-run aggregates, tool/provider/date filters, complete structured limitation taxonomy and human decision log remain open.
- Validation: 105 API tests / 704 assertions and 84 worker tests passed. Frontend build passed after correcting an invalid option tag caught during live development. No paid model call was started for this work.
- Local activation completed after the owner's in-flight test ended: additive migration `2026_10_03_150000_create_composition_trace_events` applied, API and sandbox rebuilt, idle host worker restarted. Verified the historical Maya conversation produces 23 database timeline entries and correctly reports absent tool history. No active render interrupted, paid test submitted, commit or push.

### 2026-10-03 — Available credits and stranded reservations

- Investigated the 2,510-credit account: reservations were 1,889, leaving 621 available against a 625-credit quote. The old run `8f1dcd15-63a9-4537-a486-b2c71c379e8b` had been manually labelled failed without a settlement hash, while its operation job stayed pending and retained the entire 1,037-credit quote.
- Added total/reserved/available breakdown to conversation and quote responses, Create header and approval card. Insufficient-credit responses now identify required credits, available credits and shortfall. Approval still checks balances transactionally.
- `closeSettled` refuses terminal labels lacking settlement receipts; repairs pending jobs only from settled attempts and refuses unrelated unresolved jobs instead of silently claiming the run is closed.
- Added operator-only local `create:quarantine-run --worker-stopped --release-unstarted`: validates run/job linkage, revokes the stopped lease and retains full remaining uncertain-call ceilings. It is idempotent, logs recovery amounts and does not change balances or repeat work.
- Confirmed the old host/container had stopped, then released **827** unstarted credits while retaining **210** for its uncertain call. Other holds **816 + 36** remain unchanged. Verified local total **2,510**, reserved **1,062**, available **1,448**. The unresolved calls still need separate receipt reconciliation; they were not assumed free.
- Validation: integration suite had 106 passing tests and one new test with a mistyped route; corrected route and reran that test successfully (12 assertions). Recovery coverage includes missing receipts, stranded jobs, unmapped jobs, idempotency and no balance mutation. Frontend build and diff checks passed. API rebuilt/activated while no generation was active. No paid call, commit, push or production deployment.

### 2026-10-03 — Storyboard approval → full-video quote

- Conversation `79f387af-d194-4dee-ace3-c2d3a61dbcbb` failed during cost review with PostgreSQL `22003`: the character-variant cache namespace starts at 1,000,000, but `create_plan_media.item_index` was a smallint. Added/applied migration `2026_10_03_160000_widen_create_plan_media_item_index` to widen it to integer, preserving cached media and indexes. Rollback deliberately retains the wider compatible type.
- The storyboard now displays the character master inline beside its approval action. That action approves only the displayed token and proceeds to cost review; a changed/missing preview still requires explicit review. Plan-card entry points retain the review dialog where the character has not been shown. Existing server token/asset validation remains enforced.
- Verified the exact conversation's full-video quote with external HTTP blocked and test writes rolled back; quote succeeded and PostgreSQL wrote/read cache index 1,000,000. Current maximum reservation was 1,943 credits versus 1,286 available, so admission still correctly requires sufficient funds. No quote approval or paid generation performed.
- Four targeted API tests passed (31 assertions), frontend build passed, local API refreshed while idle, migration applied. No commit or push.

### 2026-10-03 — Animated mascot intent lost in planning (open)

Audited conversation `eaa9ef9b-16a7-4191-be01-7cde989fda2b`, full-video run `d0f88e5a-36ae-4894-a531-630809efc150`. User explicitly requested walking, gestures, facial expression, body movement and talking/communicating. The plan retained “visible across multiple scenes” but omitted those performance requirements from its structured requirements. It promised walking/pointing in scene prose while purchasing character stills only.

- Evidence: full-video quote had character master reuse, UI image reuse, music, SFX, voiceover and five character variants (175 credits). No `animate_image`, `talking_shot` or `talking_take`. Composition uses five PNG `<img>` elements, animated with GSAP translate/scale/rotation. These are moving cutouts, not articulated performances. No rejected avatar-video call caused this outcome.
- Shared guidance encourages pose cuts plus tiny scale/tilt changes and applies bust framing broadly; that guidance is insufficient for an explicitly full-body moving mascot. The generic `animate_image` executor currently selects the first supplied photo, not an explicit approved character master.
- The critic flagged static holds and missing halftone treatment. The final artifact was preserved as review-incomplete when the call limit was exhausted; required corrections were not completed.
- [ ] Preserve and validate character performance requirements: still illustration vs continuous body/gesture animation vs scripted talking, with coverage across requested scenes. Do not silently weaken a request to “visible.”
- [ ] Bind motion tasks to an explicit approved master/asset and scene duration. Distinguish gesture clips from speaking clips; native speech for ordinary talking, Veed Fabric for an explicitly selected clone. Do not assume talking-head footage fulfils walking/pointing requirements.
- [ ] Validate that motion promises have suitable motion assets or an actual articulated animation implementation. General image transforms cannot satisfy required mouth, limb or facial performance.
- [ ] Scope pose-cut/bust guidance to suitable briefs. Let the reference and explicit body-motion requirements determine framing and animation.
- [ ] Verify motion over time and required clip usage in the composition; reserve enough review capacity or pause with specific unmet requirements. Keep recovered drafts clearly unfinished.

Investigation only: no generation, paid call, runtime change, commit or push performed for this audit.

Full-reference visual inspection: extracted and inspected all 900 frames (15 seconds at 60 fps) as chronological contact sheets. This was frame-sequence inspection, not real-time playback or audio listening. The file contains an AAC audio stream; speech and lip synchronization have not been verified.

- 0–2.2s: grayscale textured mascot bust enters beside the hook, with changing eyes, mouth and expression.
- 2.2–4.9s: blue circular wipe, sequential product cards and rotating illustrations; the mascot shrinks/repositions, and the panel transforms into a phone.
- 5–7.4s: kinetic text beside the phone, followed by a fast zoom into checkout with directional blur.
- 7.4–11s: modular checkout/dashboard composition, animated figures, mascot facial changes and a large “Handled” stamp.
- 11–15s: dark circular wipe, staged closing typography, white reveal, animated wordmark and mascot peeking above the logo.

Correction to interpretation: the observed reference predominantly uses a bust with internal facial animation, not full-body walking. Walking/pointing are additional requirements in the user's brief. The original production technique cannot be established from the rendered file alone. Plan and review must distinguish internal character performance from composition-level position/scale changes; the present five-PNG implementation only supplies the latter. Preserve both motion layers when adapting this reference, without forcing a full-body generation where the requested framing does not need it.
