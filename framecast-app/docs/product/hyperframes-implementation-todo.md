# Create / Hyperframes — implementation TODO

Status: **E0 complete; E1 creative/model evaluation deferred by user; E2 local/offline engineering complete; E3–E6 pending. Paid-provider acceptance remains gated.**
Created: 2026-09-28.

Sequencing update, 2026-09-28: the user requested finishing integration before comparing models and applying their strengths. E1 creative acceptance is therefore deferred, not passed, and does not block local E2/E3 engineering. Production deployment and public MCP access remain separate gates. Paid calls stay disabled; the existing $5 test cap has not been increased.

## Sources and decisions

- Creative requirements and benchmark: [Create agent creative direction](create-agent-creative-direction.md). Treat these as acceptance criteria, not just prompting suggestions.

- Technical contract: [Hyperframes integration spec](hyperframes-integration-spec.md).
- UX contract: [conversation-first experience](hyperframes-agent-experience.md).
- Latest user-supplied UI: [new creation](create-ui/agent-new.html), [conversation](create-ui/agent.html), [states](create-ui/agent-states.html), [All Videos](create-ui/all-videos.html), [Assets](create-ui/assets.html). These replace earlier prototype entry points as the implementation reference.
- Entry point is **Create**, supporting images and videos in one conversation. Hyperframes handles composition/animation; existing media tools handle image and video generation where appropriate.
- Conversation history is distinct from version history. Finished videos appear in All Videos, images in Assets; both link back to their conversation. Intermediate previews do not flood the finished-video listing.
- Replicate agent candidate: `anthropic/claude-opus-4.6`; comparison candidate: `anthropic/claude-4.5-sonnet`. These are candidates, not proven compatibility or quality decisions.
- Preserve concurrent work. Review Git changes before editing; stage only this feature's files. Do not alter production compose, deploy workflows or running production services during local proof.

## E0 — prove the integration locally

Progress: E0 local acceptance completed 2026-09-28. Human playback confirmed by the user; machine evidence covers render/decode, source/audio fidelity, sampled parity, missing assets/font/overflow, cancellation, hard-crash recovery, network/path denial, resource use and concurrency. See [local verification](hyperframes-local-verification.md). Exact replay uses the immutable image in `compose.pinned.yml`; a fresh Dockerfile rebuild requires re-verification. This does not approve untrusted customer HTML or production deployment.

### E0.1 Reproducible runtime, no AI calls

- [x] Inspect current local Docker services, architecture, resource headroom and port usage. Record baseline health of existing app services.
- [x] Verify an available upstream Hyperframes release and license; pin package, dependencies and skill snapshots. Record verified commands and output schemas instead of assuming main matches the installed release.
- [x] Add `hyperframes-worker/` with a tested Node 22+ image, browser, FFmpeg and local fonts. Use a separate opt-in local Compose overlay/profile, not the existing URL renderer service.
- [x] Limit concurrency to one initially; set CPU, memory, scratch-disk, process and wall-time limits. (Retained artifact quotas belong to E2/E6.) No Docker socket or broad host mount inside a render sandbox.
- [x] Install dependencies at image build time. Customer runs cannot install packages or upgrade skills.
- [x] Write `hyperframes-runtime.lock.json` with package/skill versions or hashes, browser/FFmpeg versions, fonts and image digest.
- [x] Add a fixed-command adapter for checks, timeline inspection, snapshots and rendering. Pass argument arrays; validate workspace-relative paths.
- [x] Provide one documented local smoke command that uses owned/synthetic fixtures and no paid provider calls. See `hyperframes-worker/README.md`.

### E0.2 Render actual media

- [x] Fixture A: product photo + local logo + approved text → 15s portrait composition, MP4 and poster.
- [x] Fixture B: existing talking-head footage + original audio + timed callouts → MP4 using the selected source range unchanged.
- [x] Inspect output with ffprobe and decoded frames: duration, dimensions, FPS, audio presence where expected, text, logo and source fidelity.
- [x] Play the full outputs and listen to audio; inspect opening, ending and transition points. Save observations, not merely process exit status.
- [x] Change only the CTA and render a new revision. Synthetic product fixture passed with unchanged source checksum; intentionally silent in both revisions.
- [x] Exercise representative frame seeks in different orders; confirm timing is stable within a recorded tolerance.
- [x] Compare preview and encoded output, including captions and fonts; no premature claim of exact parity.
- [x] Measure fresh-process/subsequent render duration, peak memory, disk and output size. Verify existing local services remain healthy. (No cold-machine benchmark claim.)

### E0.3 Failure and recovery proof

Local checks pass for cooperative cancellation, deadlines, missing assets/font/overflow and hard container interruption. Offline recovery requires the worker to be stopped. Path helper and no-network namespace checks passed; production sandbox certification remains E6.

- [x] Missing media, missing font and overflowing text produce actionable diagnostics, not a ready status.
- [x] Stop the worker during a render; no partial output is published as complete. Retry safely in a fresh run workspace.
- [x] Cancellation stops or fences work and retains the last valid artifact.
- [x] Test path traversal, external resource loading and private-network access denial in the sandbox.
- [x] Record exact commands, versions, fixture hashes, results and resource measurements in `hyperframes-local-verification.md`.

**E0 exit:** two playable videos plus a CTA-only revision, measured resource usage and demonstrated failure recovery. No AI model is necessary to prove the renderer. If this fails, fix runtime/adapter issues before building the app integration.

## E1 — prove the agent locally

Progress: verified public endpoint schema snapshots, strict JSON action loop, scoped source tools, local journal, bounded execution and disabled-by-default Replicate adapter implemented. 45 deterministic tests pass; scripted and real providers completed offline Hyperframes renders. Latest scripted smoke verifies rejected-edit recovery and exact source preservation at zero provider cost. See [E1 verification](hyperframes-e1-verification.md). Measured test costs and sampled visual review exist, but creative acceptance and the model comparison remain incomplete.

### E1.1 Replicate endpoint contract

- [ ] Inspect actual schemas for shortlisted endpoints: image input, prompt/system context, output limits, structured output/tool support, streaming, cancellation and usage metadata.
- [x] Implement a provider adapter against verified capabilities. If native tool calls are unavailable, use a validated action envelope executed by our runner; never execute free-form model output as shell commands.
- [x] Use fake provider responses first to prove tool dispatch, malformed-output handling, context carryover and bounded retries.
- [x] Establish explicit credential and total test-budget authorization. User approved the configured credential instead of a separate credential, with a $5 total cap. Reservations and requests are recorded without secrets; latest instruction is offline work only.
- [ ] Reconcile actual provider billing and unknown requests; current token estimates are not invoice-confirmed charges.

### E1.2 Bounded tool loop

- [ ] Load pinned router/core/CLI and relevant creative/animation guidance on demand; host authorization and budget rules take precedence.
- [x] Implement scoped read/write/patch, asset manifest, timeline, installed primitive lookup, validation, snapshot inspection and media-proposal tools. Bounded local tools and combined preview implemented.
- [ ] Supply the brief, approved facts, brand, transcript, asset manifest and base revision; preserve stable IDs and locked inputs.
- [x] Enforce maximum calls, tokens, elapsed time, cost and two repair cycles after the first draft. Pause rather than retry indefinitely. Local limits verified; cost uses conservative per-call reservations, not assumed billing metrics.
- [ ] Inspect sampled output frames using a verified image-capable endpoint. Validate audio and timing separately; never claim every frame was checked from a few samples.
- [x] Recover from rejected locked-source edits and stale exact patches within the shared two-repair cap; keep source reads complete within context limits. 45 tests and a real scripted offline render pass.
- [x] Persist progress so restarting a job does not lose the current revision or repeat completed paid steps. Local journal/no-replay tests pass; unknown outcomes pause for reconciliation. Production leases remain E2.

### E1.3 Quality and cost fixtures

- [ ] Implement context-aware creative direction and retrieval of reviewed visual examples; include failure examples and grounded-claim checks.
- [ ] Run the same-footage three-style benchmark (educational, energetic social, restrained product) and opening-only follow-up edit. Record human creative review separately from technical checks.
- [ ] Add the supplied mismatched-bottle case: no false product identity, unauthorized substitution or invented endorsement.

- [ ] Run the same five briefs through each candidate: product promo, footage overlay, typography explainer, reference-inspired layout, and targeted follow-up edit.
- [ ] Include difficult cases: long text, missing benefit claims, contradictory request, too-short footage and a requested new spoken hook.
- [ ] Score instruction adherence, source preservation, readability, audio alignment, repair count, latency and total cost per acceptable output.
- [x] Confirm composition-only requests make zero image/video-generation calls. Local provider permits only the two inspected text/vision endpoints; media proposals pause, rendering is offline.
- [ ] Record the model choice and measured ceilings. Do not assume a cheaper token rate gives a cheaper successful video.

**Latest E1 checkpoint:** offline hardening verified. No new paid calls; the $5 cap remains unchanged. Real creative acceptance, presenter/audio fidelity and benchmark coverage below remain open. Do not mark this phase complete from the scripted smoke.

**E1 exit:** a real prompt produces an acceptable local video and a follow-up prompt modifies it without losing source fidelity. Provider compatibility and measured costs are documented.

**Current evidence:** real product teaser and opening edit rendered; 45 E1 deterministic tests pass. Second user review: “Still too basic—improve the creative direction.” Creative acceptance and the incomplete benchmark remain open. Paid tests stopped at the original $5 reservation cap; see round-up evidence and per-case costs. Approved live testing uses the app credential and explicit image-transfer permission within a shared $5 cap; this is an authorized exception to the separate-credential plan.

## E2 — app domain, execution and accounting

### E2.1 Local app-to-worker slice

- [x] Add a separate, allowlisted local Create surface, off by default and unavailable in production.
- [x] Persist conversations, ordered messages, source/reference attachment roles, runs and immutable revisions.
- [x] Exercise quote expiry, approval/replay, shared operation capacity, cancellation, lease fencing, conflicting drafts and restore-as-new with isolated tests.
- [x] Run the real API → host coordinator → offline Hyperframes renderer → private MP4 → download → restore flow without paid calls.
- [x] Add an explicit operator recovery command for interrupted **fixture** runs; never treat lease expiry as proof the worker stopped.
- [x] Freeze supported managed attachments and the exact base revision into local quote inputs; verify hashes, retain source/reference roles, and require a live worker lease for downloads. Node, API and real HTTP/render checks pass.
- [x] Record attempts before external execution; deny replayed execution, settle confirmed receipts idempotently through shared accounting and retain unknown holds. Offline render lifecycle and synthetic debit tests pass.
- [x] Connect the E1 action runner to frozen app context and per-call accounting. Verify offline read → patch → check → snapshot → render and follow-up edits through the real HTTP coordinator. The injected provider is explicitly scripted; this is not creative/model acceptance.
- [x] Inherit immutable input snapshots through edits/restores, protect source bytes, keep reference media outside the render workspace, cap local input storage, and clean expired orphaned files while retaining saved/unknown work.
- [x] Verify real PostgreSQL concurrency for same-key approval, worker claims and attempt admission, plus cancellation/lease expiry without replay or release.
- [x] Add a reconciliation boundary: durably bind prediction IDs, verify terminal Replicate identity/model/input, require operator-attested billing evidence, fence the old lease, settle once, and retain unknown holds. Mocked provider and PostgreSQL race checks pass. Automatic billing verification and live paid activation remain release gates below.
- [x] Explicitly save a verified local sample through Project/Asset/ExportJob contracts; mark composition projects, route them back to Create, and guard scene-only mutation/export paths. Files remain private; no scene rows or duplicate renders.

See [local app integration evidence and setup](hyperframes-app-integration-verification.md). The final evidence section closes E2 engineering in the authorized local/offline scope. This is not E1 creative acceptance, E3 UI completion, or paid/production readiness.


- [x] Add workspace-scoped conversations, messages, attachments, compositions, immutable revisions, runs and shared Project/Asset/ExportJob relationships.
- [x] Preserve source/reference roles and immutable inputs; register outputs only through an explicit save action.
- [x] Enforce local/workspace/write access, export allowance and watermark restrictions; signed delivery expires in five minutes. Cross-workspace/viewer checks pass. Publishing is denied until E3 supplies composition-aware delivery.
- [x] Implement expected-revision checks, preserved conflicting drafts and restore-as-new; never overwrite a completed version.
- [x] Add quote fingerprints, expiry, explicit approval, credit reservation and settlement through the shared accounting service. Offline and synthetic charged-receipt tests pass; verified paid-provider receipts remain gated above.
- [x] Attribute agent/media/render attempt costs separately; release only confirmed-unused reservations. Verify unknown receipt recovery with synthetic billing; media generation and paid tariffs remain disabled.
- [x] Persist durable transitions, fenced leases and idempotent admission. The host claims committed rows; no pre-commit queue dispatch.
- [x] Verify disconnect/replay/restart/timeout/late callbacks/cancellation and stranded holds without repeating work. Recovery is operator-driven and requires proof of stopped work.
- [x] Register verified private output; deliver expiring signed URLs, protect saved revisions from asset deletion and orphan cleanup. Full user-facing deletion/retention policy remains E6.
- [x] Add local feature flag/workspace allowlist; default production and public API admission off.

**E2 local exit met (2026-09-29):** accounted, authorized offline runs survive the covered retries/crashes, save into shared video contracts and preserve previous versions. 134 API tests pass (one existing skip), 57 Node tests pass, PostgreSQL process races and real HTTP/render/browser checks pass. Installed locally for workspace 1; no push or production change.

**Paid/production release gates, deliberately not marked complete:** live receipt/billing contract acceptance, customer-approved pricing (including render/media cost), real provider execution and creative evaluation, production isolation/retention/monitoring. `paid_execution_enabled` remains hard-disabled; the local agent is a scripted contract provider, not a prompt-following AI. Operator billing attestation is not automatic invoice verification.

## E3 — Create UI and multimodal routing

- [ ] Implement latest `create-ui/` designs within the real app shell using existing components; retain the Create label.
- [ ] New creation empty state, examples, persistent composer and resumable conversation list with search/rename/archive.
- [ ] Upload/library/reference attachments with inline previews, progress, remove/retry and clearly communicated limits.
- [ ] Distinguish inspiration from authorized source reuse; normalize essential settings conversationally and ask for unsupported claims.
- [ ] Route image generation/editing to existing image operations. Reuse source ownership, edit masks/reference rules, consent and pricing gates.
- [ ] Image results: inspect, download, edit, explicit variations and animate; each operation stays linked to its source and conversation.
- [ ] Video results: stable custom playback, seek, volume, fullscreen, inline follow-up edits and optional safe zones.
- [ ] Compact plan/quote approval, current stage, cancellation and actionable error/recovery messages in the conversation.
- [ ] Details panel hidden by default; mobile drawer and keyboard/focus behavior verified. Keep duration, language, voice, music and captions available without a mandatory form.
- [ ] Distinguish current revision, preview and completed export. Download/share/schedule target exact artifacts and warn about newer unexported changes.
- [ ] Add history inspect/compare/restore, concurrency conflict flow and retention of previous working outputs.
- [ ] Add explicit variant setup/preview/quote approval, maximum three outputs and retry-only-failed behavior.
- [ ] Finished videos appear in All Videos; images in Assets. Use shared artifact records, not duplicate files. Link both to their originating conversation; group variants.
- [ ] Reuse publishing/account restrictions and separate confirmation for share/schedule. Rendering never automatically publishes.
- [ ] Replace illustrative prices, timing guarantees and prototype notices with verified values and accurate UI copy.

**E3 exit:** local user can create an image or video, revise it, return later, find it in the proper library and perform the permitted delivery actions. Tests use mocks except explicitly budgeted live smoke runs.

## E4 — speech-timed editing and reusable styles

- [ ] Timestamped transcription linked to source ranges; preserve a source-to-output map through trims and cuts.
- [ ] Non-destructive silence/mistake cuts with checks against changing meaning or audio/video synchronization.
- [ ] Bind overlays to spoken moments; recompute output timestamps after cuts.
- [ ] Explicit hold/shorten/loop/replace behavior for short clips; never silently restart animation.
- [ ] Reference-style analysis and workspace-scoped saved preferences with explicit user approval, versions and edit/delete controls.
- [ ] Generated media dependencies have separate approvals and resumable parent runs; reuse completed assets after failures.
- [ ] Representative captioned encoded preview before approval, with crop/readability/audio checks on delivery.
- [ ] Test the transcript-inspired workflow: upload talking-head footage → timed explanatory visuals → prompt correction → final artifact.

**E4 exit:** speech-timed editing works on the bounded pilot clips. Large event archives, arbitrary web capture and long-form editing remain deferred until separately scoped and measured.

## E5 — local acceptance before any pilot deployment

- [ ] Run all acceptance cases in spec §12 plus image routing and conversation/library navigation.
- [ ] Exercise browser flows at desktop and mobile sizes; verify dialogs, keyboard access, attachment previews and video playback continuity during polling.
- [ ] Verify source-specific edits, grounded claims and version/export consistency with actual outputs, not mock screenshots alone.
- [ ] Concurrent admission/cancellation/replay tests prove credit and capacity limits.
- [ ] Record outstanding defects and exact passing commit/runtime/model configuration in the verification document.
- [ ] Write local setup, rebuild, smoke, reset-test-data and troubleshooting instructions. Reset commands must target isolated test data only.
- [ ] Agree measured pricing, customer limits and quality thresholds before enabling paid customer use.

**E5 exit:** local acceptance evidence is complete; unresolved blockers are explicitly listed. A UI demo or one successful render does not close this gate.

## E6 — controlled pilot, then public/API access

- [ ] Prepare production capacity/isolation review, migrations, observability, support runbook and rollback plan.
- [ ] Deploy with admission off; verify schema, worker/runtime versions and existing-service health before allowlisting pilot workspaces.
- [ ] Monitor first usable output, corrections, total spend, failure recovery and second-creation/7-day/30-day return behavior; distinguish subscription and lifetime accounts.
- [ ] Expand only after reviewing pilot quality and cost evidence.
- [ ] Add public API/MCP wrappers after app acceptance: scopes, quotes/approval, polling, retries, schemas/annotations and documentation.
- [ ] Verify real connector end-to-end behavior before public outreach.

## Evidence and completion rules

Use stable task references such as E0.2/CTA revision in commits and verification notes. Tick a task only when its implementation and required checks pass. Every completed phase records commit, runtime identity, commands, outputs and limitations. Do not put credentials or private customer media in the evidence document.

Immediate next implementation slice: **E3 Create UI and multimodal routing, then E4 timed editing. E2 local engineering is complete. Model comparisons remain deferred; E1 creative acceptance and paid/production release gates stay open.**
