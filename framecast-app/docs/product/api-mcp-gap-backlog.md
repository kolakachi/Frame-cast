# API/MCP gap backlog — editor, UGC, voices and characters

Date: 25 September 2026
Audit baseline: local commit `9b26301`
Status: Phases A, B, C, D and E local implementation and acceptance checks complete; production rollout remains gated. Existing caption-render parity failures are recorded below.

Checkbox meaning: `[x]` = the stated local implementation or check is complete;
`[ ]` = still pending. A checked implementation task does not imply its section
has passed all acceptance criteria or production verification. Accounting changes
remain behind a disabled rollout flag.

## Purpose and evidence

One actionable checklist for the API/MCP audit and the follow-up comparison with
`/projects/{id}/editor`. This supplements `api-access-todo.md` and
`api-expansion-todo.md`; checked items in those documents do not override the
unresolved findings here.

Distinguish **bugs** (existing behavior is incorrect), **parity gaps** (app
capabilities not exposed), **schema gaps** (supported inputs are hard to discover),
and **scope decisions** (deliberately excluded features). Not every missing app
control must become an MCP tool.

The initial seven findings and reproduction evidence are in
[the audit report](../audits/mcp-api-review-2026-09-25.md). Follow-up editor/UGC
findings are code comparisons, not successful paid-generation tests. No production
changes or paid renders were made during this review. Recheck current code before
implementing each item because other development is active.

## Implementation log

### Batch 5 — Phase B local closure (not committed/deployed)

- B1/B2: real MCP HTTP/SDK tests recorded forwarded null selections and image
  options. Those exact payloads pass through Laravel to persisted project fields
  and GenerateAIImageJob arguments. Unknown operation/nested inputs are rejected.
- B3: typed settings discovery includes ranges, enums, defaults, units, object
  semantics, provider limitations, current animation qualities/engines, stale
  media, locks and whole-video restrictions. Internal image-generation state is
  read-only. Fixed shared scene validation dropping voice ID/provider/speed/
  direction/language/stability when only nested audio/volume rules were defined.
- B4: exposed per-scene animation history and free owned history selection;
  foreign/missing clips and in-progress replacement are refused.
- B5: deliberately retained direct-apply rewrite semantics, now disclosed in the
  catalogue/MCP/guide. Exact reviewed wording uses update_scene.script_text.
  Fixed rewrite mode drift and missing rewrite controller dependency.
- B6: rerecord_all/restyle_all/animate_all use dashboard previews and dispatch,
  preserving selection, skip reasons, per-scene pricing and shared-render savings.
  Added relevant-lock exclusions, whole-video/foreign-scope rejection, frozen
  preview checks, aggregate authorization and per-scene dispatch outcomes.
  Retries can select failed scene IDs without redoing completed scenes.
- Other mapping failures found by real-controller tests: missing voice-regeneration
  and stock-replacement dependencies; library asset selection incorrectly sent
  to stock search. Corrected those paths. Spokesperson pricing now follows each
  selected/saved lip-sync engine in API, single-scene and bulk previews.
- MCP version 1.4.0. Also corrected the missing explicit export selection inputs
  in the sidecar to match Phase A's already guarded result endpoint.

Guide: [Editor/MCP workflows](api-editor-mcp-guide.md). Tests use fake providers;
no real generation, commit, push, deployment or running-service change occurred.
The optional recorded-transport PHP test was explicitly enabled for this run.

Validation: accounting-enabled developer suite **60 tests / 598 assertions**;
real MCP HTTP discovery/proposal/apply transport test passed. Targeted broader
regression suite: **121 tests / 918 assertions passed** (caption parity failures
listed separately below). PHP and JavaScript syntax and
whitespace checks pass.

Existing failure disclosure: expanded testing also ran CaptionExportParityTest.
Three tests fail on comic/glitch font-size/animation expectations. Both that test
and RendersExportScenes.php are unchanged from current HEAD `518859b`; the unit
harness directly calls that unchanged renderer. These are not Phase B mapping
regressions and remain an editor/render parity follow-up, not a green full-suite
claim. Historical audit baseline `9b26301` is not the current tested HEAD; this
batch is an uncommitted working-tree patch on `518859b`.

### Batch 4 — Phase A local closure (not committed or deployed)

Supersedes the outstanding **local** Phase A items in batches 1–3. The accounting
flag remains disabled. Production validation and broader parity work remain open.

- Reservations now count UGC takes individually. All quote-backed creation,
  editor, assistant, character-image and retry endpoints share the operation
  context. An accounted credit refusal throws before a caller can report success.
- Ledger tests cover originating keys, rotation, dashboard isolation, shared
  agency pools, refunds and migration backfill. Historical attribution remains
  best effort; missing historical initiating keys cannot be reconstructed.
- Replay targets/selections are immutable; only the current unused claim token
  can reopen a quote. Canonical content revisions detect same-second edits.
- PostgreSQL session fences span editor revision checks and mutation/dispatch
  for developer-API requests. (The project/scene write triggers described in
  the original batch were removed at review before push; see A4.)
- Results identify exports and fingerprints, select the latest export even while
  pending/failed, and require explicit export ID plus acceptance for stale files.
  Export lists expose metadata; download goes through the guarded result endpoint.
- Queue execution is fenced. Redelivery after an ambiguous crash does not rerun
  the handler, chain or failure callbacks. Uncertain work retains its hold and
  reports `needs_attention`. Explicit cancellation requires no active producer or
  worker, fences old queue deliveries, and releases only unused credits.
- Recovery is **inspect → cancel remaining work → explicitly authorize new work**
  for ambiguous external side effects. It is not a promise of automatic exactly-once
  execution at third-party providers. Saved results/checkpoints/ledger survive;
  a provider result lost before persistence is reported as unknown, never invented.
- Failed composable retries require a new quote after previous work is resolved;
  replaying that retry returns its recorded result. UGC retry uses its creation
  flow rather than this composable endpoint.
- Dependent paid edits must be staged. Current pricing is rechecked before each
  editor action; voice settings and character-reference pricing are retained.

Validation:
- **102 tests / 804 assertions**: developer API, OAuth, API keys, editor integrity,
  export freshness and UGC execution suites.
- **49 tests / 499 assertions**: developer API suite with accounting enabled,
  including real synchronous parent/child queue dispatch.
- Disposable **PostgreSQL 16 + Redis 7** probe passed: concurrent key-cap admission,
  per-take slots, competing writes, async attribution/settlement, killed worker
  redelivery, active-worker cancellation refusal, unused-hold recovery, released
  jobs, cancelled deliveries and migration up/down/backfill.
- No real paid provider requests or production mutations. Full MCP client contract
  checks and deployment verification remain F/release gates.

Repeatable test and rollout instructions: [Phase A verification](api-phase-a-verification.md).

### Batch 3 — operation lookup and replay recovery (local, not deployed)

- Added workspace-scoped `GET /api/developer/v1/operations/{quoteId}` and MCP
  `get_operation`. The original quote/proposal/plan ID remains usable after a
  transport timeout; no new handle has to arrive in a lost response.
- Same-key replay while no result pointer exists returns 202 with recovery state;
  it never starts the operation a second time. Different-key attempts are refused.
- Assistant action selection is normalized and bound to the consumed plan. A
  replay with a different selection returns `idempotency_payload_mismatch`.
- Editor/assistant actions persist checkpoints before execution and after each
  outcome. An interrupted in-progress action is not automatically repeated.
- Old incomplete operations are reported as `needs_attention`; lookup is read-only
  and never releases holds based on elapsed time. This is detection, not automatic
  reconciliation or resume. Settlement is distinct from finished-media readiness.
- MCP transport failures explain uncertainty and point to original-operation
  lookup; removed the instruction to get a fresh quote for consumed operations.
  MCP default version bumped to 1.3.0 for refreshed tool discovery.

Validation: **59 tests, 655 assertions passed**, including pending replay,
workspace isolation, stale-hold preservation and changed assistant selection.
MCP JavaScript syntax and diff checks pass. Automatic crash reconciliation,
action-level resume and true asynchronous/concurrent verification remain pending.

### Batch 2 — reservation/accounting foundation (local, rollout disabled)

New migration: `2026_09_25_200000_create_api_operations.php`. New config flag:
`DEVELOPER_OPERATION_ACCOUNTING=false` by default. No production migration or
configuration change has been made.

Implemented behind the flag:
- Pool-aware durable holds at quote claim; pending operation capacity and pending
  key spend participate in admission. Holds do not deduct credits up front.
- CreditService debits enforce the operation maximum and consume the hold in the
  same transaction as balance/ledger writes. Dashboard debits respect other holds.
- Ledger entries identify the initiating operation/key; refunds are atomic and
  attributed, including shared pools. Rotation includes predecessor usage/holds.
- Hidden Laravel context carries the operation through queue payloads. Per-job
  records keep the hold until the request producer and all registered jobs finish.
- Terminal job failure releases unused capacity after other pending jobs finish.
  Repeated terminal events do not settle twice. Released jobs remain pending.
- Unquoted developer retry refuses with `retry_authorization_required` when enabled
  rather than dispatching outside accounting. The authorized retry UX remains open.

Rollout blockers (do NOT enable yet):
- Test real asynchronous worker restart, exception/release, chains/batches and
  concurrent requests against the production database/queue engines.
- Implement recovery/reconciliation for a process killed during a request or a
  failed queue publish. These conservatively retain holds today; never clear them
  merely by age while work might still execute.
- Complete durable action-level idempotency, safe replay/resume and status handles.
  Job retries still rely on each existing handler's duplicate-charge protection.
- Audit every generation path for charges after provider work and ignored false
  deduction returns; the credit ceiling alone does not bound upstream provider cost.
- Multi-take UGC admission must reserve capacity per take, not just per operation.
- Verify agency funding-mode changes while operations are active. The current
  operation refuses a charge when its credit pool changes.
- Validate historical attribution migration and maintenance timing. Existing
  project-linked entries are backfilled using their old attribution, which cannot
  reconstruct missing historical character charges or actual editing keys.
- Do not disable the flag with operations pending. Drain/reconcile first. Do not
  roll back the migration after attributed ledger entries exist.

Validation: focused developer/auth suites **57 tests, 636 assertions passed**,
including a real Laravel sync-queue parent/child propagation test. PHP syntax and
diff whitespace checks passed. Async production queues have not been verified.

This is A1/A2/A6 groundwork, not closure of their full acceptance criteria.

### Batch 1 — local changes, 25 September 2026 (not committed/deployed)

- A3 partial: frozen project/character targets are validated under the workspace
  lock before consumption or replay. HTTP regressions cover fresh and consumed
  quotes for editor, assistant and character-image endpoints. Claim ownership
  and assistant `only` replay binding remain open.
- A7 partial: spokesperson pricing now resolves audio duration using the editor's
  fallback order. Tests cover 30-second audio and scene-duration fallback.
  Dependency changes between quote and execution still need A4/A8.
- B1: dispatch now preserves explicit null selections for music, channel and
  brand kit. Controller-boundary regression added.
- B2: image generation accepts/forwards style and prompt override alongside model;
  controller-boundary regression added.
- D1 partial: MCP now exposes `voice_key`, `fidelity`, `product_asset_ids` already
  accepted by the API. Mode-specific generation verification remains open.
- F partial: removed outdated caption-presets description.

Validation: focused developer/auth suites **53 tests, 614 assertions passed**;
MCP JavaScript syntax and diff whitespace checks passed. No paid renders or
production actions. The release-blocking reservation, attribution, atomic
revision, freshness and recovery work remains outstanding. Checkboxes below record completed local work separately from remaining
acceptance criteria and release verification.

## Already implemented — preserve these paths

- Video estimation, quote-based creation, status and result retrieval.
- Editor state/schema discovery and proposed edits followed by application.
- Script/settings updates, add/duplicate/reorder, rewrites, voice regeneration,
  image generation/editing, visual replacement, animation/cancel/revert, music
  generation, project settings, hooks and export.
- Listing and selecting existing saved/cloned narration voices.
- Listing, creating and updating characters; quoting/generating character images;
  assigning characters to scenes.
- UGC plan → estimate → create → status/result, composed and one-shot modes,
  character casting, existing product/demo assets and alternate openings.
- Editor spokesperson generation from a scene image and existing narration.
- Bounded assistant planning and application. Its execution guarantees still need
  the fixes below.

## A. Release blockers: spending, consistency and recovery

### A1 — Enforce operation budgets and reserve capacity [bug, P1]
- [x] Implement pool-locked credit reservations and pending-operation/key-cap checks
  at quote claim (rollout flag off).
- [x] Enforce operation credit maximums in CreditService and record actual spend;
  release unused holds after the producer and registered jobs finish.
- [x] Verify concurrent admission and cancellation/async failure paths; reserve
  UGC capacity per take and recover stranded holds.
- [x] Apply this to creation, editor edits, assistant actions, character images,
  UGC and retry paths.

Acceptance: two queued requests cannot both consume a cap that permits only one;
concurrent requests cannot exceed capacity; actual debits never exceed the
approved operation maximum. Test terminal failure and refund paths too.

### A2 — Attribute usage to the initiating key/operation [bug, P1]
- [x] Implement operation/key ledger attribution, replacing creator-key-derived
  accounting when the rollout flag is enabled.
- [x] Attribute CreditService debits/refunds and reservations to the current
  operation; include predecessor key usage and holds after rotation.
- [x] Verify every expanded path, including app-created projects, character images,
  agency pools and authorized retries, plus historical migration behavior.

Acceptance: an edit from key B on key A's project counts against B; subsequent
dashboard edits do not count against A; character charges and retries are covered.

### A3 — Keep consumed proposals immutable [bug, P1]
- [x] Validate frozen target and quote kind before claim/replay; test wrong-target
  requests against fresh and consumed editor, assistant and character-image quotes.
- [x] Validate the complete replay payload before mutation.
- [x] Release only a claim acquired by this execution, never a completed claim.
- [x] Bind normalized assistant action selection (`only`) to the replay identity;
  reject changed selections on replay.

Acceptance: a completed proposal sent to another owned project/character remains
consumed; replay returns the original result without executing again.

### A4 — Make revisions reliable and application concurrency-safe [bug, P1]
- [x] Replace second-resolution timestamp-based revisions with a reliable version
  or canonical content fingerprint.
- [~] Atomically claim the revision for application; coordinate with dashboard
  edits and other proposals. **Review decision, 26 September 2026:** the
  session fence now applies to developer-API mutations only. The database
  write triggers were removed before push: they rejected every concurrent
  writer of a project's rows (three queue workers and editor autosaves
  included) with an immediate error, which would have surfaced as random
  generation failures for all users. Dashboard and worker writes are detected
  by the fingerprint at apply time; the window between check and apply is not
  closed for them.

Acceptance: same-second changes invalidate old proposals; simultaneous edits
cannot both apply against the same expected state unnoticed.

### A5 — Deliver the intended export version [bug, P1]
- [x] Include export ID, source revision and freshness in status/results/lists.
- [x] Poll a requested export explicitly. Do not report an old file as the new
  result while a newer export is running or failed.
- [x] Require explicit acceptance before delivering a stale export.

Acceptance: edits followed by result retrieval cannot silently deliver outdated
content; explicit old-version selection remains possible.

### A6 — Persist execution progress and recover after timeout [bug, P1]
- [x] Persist accounting operation state and registered queue-job terminal states.
- [x] Persist editor/assistant checkpoints before each action and after its result.
- [x] Implement fenced reconciliation for an action interrupted between its side
  effects and saved outcome; retain known results and never repeat uncertain work.
- [x] Expose pollable operation state through the original quote/plan ID, including
  pending same-key replay responses and the MCP `get_operation` tool.
- [x] Make transport retries retrieve the same operation safely; require new
  authorization for a failed-generation retry after reconciliation.
- [x] Remove advice to obtain a new quote solely because a consumed operation's
  result is pending or the MCP's 30-second transport timed out.

Acceptance: saved outcomes and charges survive timeout/crash; uncertain external
results are explicitly marked for inspection and never automatically repeated.
Callers can distinguish running, needs-attention, cancelled and settled execution.

### A7 — Price spokesperson generation from actual narration [bug, P1]
- [x] Use the same audio-duration resolution as `SceneController::animate`;
  test 30-second narration and scene-duration fallback.
- [x] Revalidate relevant image/audio inputs before spending; require a new quote
  if an earlier action changes the priced narration duration.

Acceptance: a 30-second narration is quoted as 30 seconds, not the default five;
the approved maximum covers the actual debit. Test absent/stale narration too.

### A8 — Price dependent edits against the state they will use [bug, P1]
- [x] Account for preceding operations when quoting a batch, or require staged
  proposals when the resulting cost cannot yet be determined.
- [x] Use consistent voice/provider and character-reference pricing inputs.

Acceptance: changing a character or voice before generating cannot produce an
underquoted operation. Generate narration, wait, then quote lip-sync against the
resulting audio rather than guessing its duration.

## B. Editor mapping and schema fixes

### B1 — Preserve explicit clearing of project settings [bug, P2]
- [x] Forward explicit `null` for music, brand kit and channel; preserve the
  distinction from omitted properties. Controller-boundary regression passes.
- [x] Verify the full MCP-to-persisted-project clearing workflow.

Acceptance: clearing each selection through MCP matches clearing it in the editor.

### B2 — Forward image-generation controls [parity gap, P2]
- [x] Expose and forward `prompt_override` and `style` alongside `model_key`;
  controller-boundary regression passes.
- [x] Document one-render overrides versus saved scene settings and verify the
  complete generation payload through MCP.

Acceptance: the same scene and explicit inputs reach the same generation request
from the editor and MCP; unsupported inputs cannot silently disappear.

### B3 — Publish complete typed settings [schema gap, P2]
- [x] Describe voice ID/provider, speed, stability, direction and volume with
  ranges and provider limitations.
- [x] Describe captions, motion/fit, transitions, sound and music settings,
  including valid enums, defaults, units, null behavior and merge/replacement rules.
- [x] Expose valid animation quality options per tier and configured lip-sync
  engines; do not require the assistant to invent strings.
- [x] Explain stale narration, stale lip-sync, locks and whole-video restrictions.

Acceptance: a client can configure these features using discovery responses alone;
invalid values are actionable errors or explicitly reported normalization.

### B4 — Restore prior animations [parity gap, P2]
- [x] Expose available animation history and the equivalent of
  `/scenes/{id}/animate/use-history`, preserving asset ownership checks.

Acceptance: selecting an earlier clip changes the scene without a paid regeneration.

### B5 — Preview rewrites before applying [parity gap, P2]
- [x] Provide a candidate/accept path or explicitly retain direct-apply semantics
  with clear disclosure. Current MCP `rewrite_scene` applies immediately.

Acceptance: if preview is offered, acceptance applies the exact reviewed candidate,
not a new unreviewed rewrite.

### B6 — Support deliberate bulk workflows [parity gap, P2]
- [x] Add or document equivalents of rerecord-all, restyle-all and animate-all.
- [x] Carry over skip rules, locks, scope, aggregate quotes and per-scene results.

Acceptance: failures/skips are visible and retryable without repeating completed
paid scenes. Generic operation batches alone are not proof of bulk parity.

## C. Voices, custom narration and characters

### C1 — Create and preview cloned voices [parity gap, P2]
- [x] Expose voice cloning, sample upload, consent and plan limits through a
  bounded API workflow; expose status/result if asynchronous.
- [x] Add voice preview and saving a reusable voice profile.
- [x] Clearly distinguish selecting an existing clone from creating a new clone.

Acceptance: an authorized client can upload a sample, create a clone, preview it,
select it and generate narration; errors, cost and quota behavior are explicit.

### C2 — Upload and discover narration/reference assets [parity gap, P2]
- [x] Add workspace-scoped uploads with type/size validation and processing status.
- [x] Include general audio/SFX in library discovery, not only image/music/video.
- [x] Expose transcription status and use-audio-only/use-audio-and-script choices.
- [x] Support new character/product/reference images and video assets without
  requiring a separate visit to the app library.

Acceptance: uploaded files are discoverable only in the authorized workspace and
can be used after processing. Microphone capture remains a client responsibility.

### C3 — Require consent when character references change [bug, P2]
- [x] Apply the creation consent requirement when references are introduced or
  replaced during update; expose it in the MCP schema and record the attestation.

Acceptance: creating a description-only character then adding photos cannot bypass
the requirement enforced by direct creation with photos.

Phase C implementation details and operational limits: [media and voice guide](api-media-voice-guide.md). Local regression and MCP transport checks pass; not deployed. Clone preview is explicitly a source-sample preview, not generated clone speech.

## D. UGC creation coverage

### D1 — Expose API-supported UGC settings in MCP [parity gap, P2]
- [x] Expose `voice_key` and plural `product_asset_ids`; remove misleading `fidelity` from MCP and reject it in the API because the native renderer never consumes it.
- [x] Document which modes actually consume each field and return resolved choices.
- [x] Verify supported voice types for composed UGC; do not imply that one-shot
  native speech preserves a selected cloned voice merely because editor TTS does.

Acceptance: supplied fields survive schema validation, quote freezing and
generation payloads; unsupported mode combinations fail clearly.

### D2 — Reference analysis and presenter previews [parity gap, P2]
- [x] Expose reference-video analysis and its usable planning output.
- [x] Expose the app's presenter-variant preview workflow with costs/consent where
  applicable; distinguish a preview from a guaranteed final identity match.
- [x] Combine with C2 so new footage/references can enter through the client.

Acceptance: the client can inspect reference analysis or a presenter preview before
approving generation. Do not label My Footage/restyle creation supported by these
two UGC modes; audit and scope that separate flow explicitly.

### D3 — Verify the complete UGC and spokesperson lifecycle [verification, P1]
- [x] Verify composed and one-shot quote/create/status/result behavior separately,
  including variants, take caps, insufficient credits and interrupted requests.
- [x] Verify image → animation → export and image + completed narration →
  spokesperson → export, including rerender after narration changes.
- [x] Ensure orchestration waits for asynchronous prerequisites; never lip-sync
  older narration while its replacement is still generating.

Acceptance: callers get accurate pending/failure states and the intended completed
file. Use mocked provider tests first; record any authorized paid smoke tests
separately with their cost and result.

Local verification and supported-mode contract: [Phase D guide](api-ugc-mcp-guide.md). Provider calls mocked; no paid smoke tests or deployment.

## E. Delivery, assistant and explicit scope decisions

### E1 — Stop representing scheduler navigation as scheduling [bug, P2]
- [x] Handle the assistant tool's `navigate` result rather than dropping it.
- [x] Return an explicit app handoff, or implement actual scheduling with a
  separate confirmation, permissions and durable result.

Acceptance: a response cannot imply a post was scheduled when the tool only asked
the UI to open a scheduling dialog.

### E2 — Define delivery actions [parity gap, P2]
- [x] Scope public sharing, approval requests and scheduling as explicit actions.
- [x] Apply version/freshness checks and recipient/destination confirmation before
  external delivery; carry over the app's plan and workspace permissions.

Acceptance: each exposed action returns its real outcome or an explicit handoff;
export/download support is not described as full publishing support.

### E3 — Decide what stays app-only [scope decision]
- [x] Record whether scene/character/profile deletion, preset creation/deletion,
  assistant undo/conversation reset, assistant brief editing and auto-apply
  preferences belong in the public interface.
- [x] Distinguish client conveniences from missing backend capabilities; deletion
  has been deliberately excluded and should not be added accidentally for parity.

Local contract and verification: [Phase E guide](api-delivery-mcp-guide.md). Delivery remains an explicit app handoff; no external delivery was performed.

## F. Documentation and verification closure

- [ ] Reopen contradictory completion claims in both existing TODO documents:
  reservation/settlement, attribution, revision safety, stale exports, recovery
  and animation history. Link them to the corresponding IDs here.
- [x] Remove the stale MCP caption-presets description that says caption editing
  has not shipped.
- [ ] Replace unconditional outreach readiness with release gates and one current
  tested commit/deployment status; keep historical evidence clearly historical.
- [x] Add regressions for target immutability, narration-based quotes, null/input
  forwarding, reservations, debit/refund attribution, rotation and sync child jobs.
- [ ] Complete remaining regression coverage for A1–A8, B1–B2, C3 and E1.
- [x] Exercise concurrency using a representative database/queue setup; sequential
  SQLite tests alone do not prove locking guarantees.
- [ ] Add repeatable MCP client contract checks for tool schemas, error handling,
  timeout/replay and successful operation polling.
- [ ] For each completed item record implementation commit, test evidence and
  deployment verification. Existing passing tests are not proof these gaps are fixed.

## Suggested delivery order

1. A1–A8 and stale-result/recovery regression tests: make execution trustworthy.
2. B1–B3, C3, D1 and E1: fix mappings and misleading capabilities.
3. C1–C2, B4–B6 and D2: complete the core creation/editing experience.
4. E2–E3 and F: finish deliberate scope, docs and release verification.

## Source map

Paths below are relative to `framecast-app/`.

| Surface | Main source |
| --- | --- |
| Editor controls and requests | `web/src/views/EditorView.vue` |
| Clone UI | `web/src/components/VoiceCloneModal.vue` |
| MCP inputs/descriptions | `mcp/server.js` |
| Developer routes | `api/routes/api.php` |
| Edit catalogue, dispatch and pricing | `api/app/Services/Developer/EditOperations.php` |
| Editor proposals, revision and exports | `api/app/Http/Controllers/Api/Developer/V1/EditorController.php` |
| Quote claiming | `api/app/Http/Controllers/Api/Developer/V1/ClaimsQuotes.php` (locate by class/trait if moved) |
| Key accounting | `api/app/Models/ApiKey.php` |
| Developer voice, character, UGC, assistant and result APIs | Corresponding controllers in `api/app/Http/Controllers/Api/Developer/V1/` |
| Actual animation/spokesperson behavior | `api/app/Http/Controllers/Api/V1/Scene/SceneController.php` |
| Actual UGC behavior | `api/app/Http/Controllers/Api/V1/Ugc/UgcController.php` |
