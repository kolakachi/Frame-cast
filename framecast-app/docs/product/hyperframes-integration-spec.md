# Hyperframes integration — implementation specification

> **UX update (September 28):** Use the [conversation-first experience](archive/create/hyperframes-agent-experience.md) and [agent mockup](create-ui/agent.html) as the primary interaction design. Earlier separate wizard screens are supporting state references. Accounting, isolation and revision guarantees below remain required.

Status: proposed implementation contract; not implemented or deployed.
Date: 28 September 2026.
Owner: WyvStudio product/engineering.
Companion: [ad-creative-cookbook.md](ad-creative-cookbook.md).

This document supersedes the cookbook where its cost, architecture, scope or
platform assumptions differ. Named new services, routes, tables, tools and flags
below are proposed additions, not claims about current capabilities. Product
limits are initial engineering defaults; public pricing requires the measured
cost gate in §10. E0 local render evidence is recorded in [local verification](archive/create/hyperframes-local-verification.md); production and agent gates remain open.

## 1. Outcome and first release

Let a customer describe a designed video, use their existing media and brand,
review it, and revise its composition without regenerating the underlying footage.
Hyperframes is the integrated authoring/rendering framework. WyvStudio supplies
user experience, agent orchestration, media, authorization, jobs and billing.

Example: “Use this product photo and my existing UGC take. Add three benefit
callouts and an offer at the end. Make the introduction more energetic.”

Success is one useful finished video that survives follow-up edits. Batch ad
production is an optional extension, not the primary success metric. No claims
about increased conversions, winning creatives or ad optimization without data.

First release supports:

- Prompt-based creation and revision; uploaded/library images, videos and audio;
  brand logo, fonts and colours; approved offer and product copy.
- Designed product promos, typography-led explainers, and graphical packaging of
  an existing UGC/talking-head clip. Layout, motion, callouts and end cards.
- 5–30 second videos, 24 or 30 fps, 9:16, 1:1, 4:5 and 16:9. Delivery MP4 H.264
  with AAC audio when audio exists; muted videos remain valid.
- Browser preview, timed thumbnail/contact sheet, approved MP4 export and a PNG
  poster frame. MP4 is the first vertical slice; static ads alone do not prove it.
- Up to three explicitly requested composition variants per batch. Never infer a
  Cartesian product of supplied axes. Show the exact outputs before starting.
- Existing WyvStudio media-generation operations for missing assets, separately
  quoted and approved; reuse completed assets in the composition.

Out of scope initially: embedding the entire Hyperframes Studio, arbitrary
customer code uploads, plugins/npm packages selected by a customer, free-form
canvas editing, ads-manager attribution, automatic public posting, unlimited
variants, 3D-heavy scenes and custom shaders. These are scope limits, not claims
that Hyperframes cannot support them.

## 2. Upstream integration contract

Use upstream Hyperframes packages and documented CLI commands. Do not implement
an alternative HTML-to-video engine or fork its renderer as the default approach.

Verified upstream interfaces at review:

- `hyperframes` routing and domain skills teach intent, authoring and revision.
- `hyperframes-core` defines composition HTML, timing and seekable animation.
- `hyperframes-cli` documents `init`, `catalog`, `add`, `timeline --json`, `lint`,
  `check`, `snapshot`, `preview`, `render`, history and output verification.
- CLI requirements are Node >=22 and FFmpeg. The inspected CLI package on main
  reports 0.8.81; this is NOT an assertion that the matching public artifact has
  been installed/tested. Select and verify an exact published release in E0.

Implement `HyperframesAdapter` around a pinned CLI, using fixed argument arrays
and a controlled working directory. It normalizes upstream output into WyvStudio
results. No shell interpolation and no direct public shell endpoint. Upstream
Studio URLs and publishing commands are not customer delivery mechanisms.

E0 must produce `hyperframes-runtime.lock.json` recording package version, source
commit when available, dependency lock checksum, image digest, browser and FFmpeg
versions, bundled fonts, skill snapshot hashes and installed registry primitives.
A run persists this runtime ID. No `npx ...@latest`, skill updates, registry
installs or package downloads during a customer run. Upgrade in a separate tested
build, retain old runtime images for existing revisions, and compare fixtures.

Use the upstream skills as authoring context inside the bounded worker. Provide a
WyvStudio host policy above them: asset access only through approved manifests,
no independent purchases/generation/publishing, bounded repairs, no automatic
runtime upgrade. Import the relevant router/core/CLI/workflow instructions;
do not load every upstream workflow indiscriminately. Upstream update, telemetry,
feedback and publishing behavior must be explicitly disabled or excluded by the
adapter's command/network policy. Skills are guidance, not authorization.

Retain upstream license/notice files in the distribution and inventory licenses
for fonts, media and registry dependencies independently. Check the pinned release
before shipping; do not assume the repository's license covers every bundled asset.

## 3. Architecture and responsibility

```text
WyvStudio Vue UI / existing assistant
                |
Laravel: auth, brief, revisions, quotes, runs, asset ownership, events
                |
          dedicated queue
                |
Hyperframes coordinator -> isolated per-run agent/render sandbox
                |             | pinned Hyperframes CLI + browser + FFmpeg
                |             | project files + approved local media
                |
validated artifacts -> private object storage -> WyvStudio preview/export
```

The current `renderer/` is a Node 20 URL-grounding service. Leave its API and
workload alone. Introduce `framecast-app/hyperframes-worker/`, based on a tested
Node 22+ Debian image with the pinned runtime, fonts and FFmpeg. A worker service
is not by itself a tenant sandbox: use a per-run process/container isolation
boundary with filesystem/network/resource restrictions described in §11.

Laravel owns all durable state. The coordinator is private, authenticates to
Laravel with a service identity, and may operate only on an assigned run lease.
Its status callbacks include run ID, lease token, event sequence and output
manifest hash. A stale worker cannot overwrite results from a newer lease.
No customer OAuth tokens, production DB credentials or AI provider keys enter
renderable HTML or the render sandbox.

Proposed code boundaries:

| Component | Responsibility |
| --- | --- |
| `CompositionService` | Scope/ownership, brief and revision lifecycle |
| `CompositionRunService` | State transitions, leases, cancellation, event stream |
| `CompositionBudgetService` | Quote fingerprint, reservation and settlement integration |
| `CompositionAssetResolver` | Authorize and materialize assets; verify checksums |
| `HyperframesAdapter` (Node) | Fixed commands, version-aware parsers, normalized diagnostics |
| `CompositionAgentRunner` | Durable bounded tool loop, source patches, visual inspection |
| `CompositionValidationService` | Host constraints plus normalized upstream checks |
| `CompositionExportService` | Artifact verification and ordinary WyvStudio asset/export integration |

Do not put the whole loop inside the current synchronous Cruise `resolve()` call.
Cruise should route to one asynchronous composition operation and show its status.
The new agent's model is deployment-configured with image-inspection capability;
choose it using the fixture evaluation, not a hardcoded “cheap model” assumption.

## 4. Routing and media reuse

The assistant chooses a path based on the requested result and available assets:

| Request | Route |
| --- | --- |
| New presenter/performance, new physical action or camera shot | Existing UGC/video generation; reuse output in composition if requested |
| Typography, diagrams, product cards, logo animation, offer, graphical overlay | Hyperframes composition |
| Existing UGC with headline/benefit/end-card treatment | Hyperframes using the existing take unchanged |
| Ordinary scene narration/caption/visual replacement | Existing editor tools, unless the user is editing a composition |
| New spoken hook | Narration/lip-sync/video quote as required; never label it a free text change |

Show a plan stating what is reused, composed and generated. Asset shortage causes
`needs_input` or an explicit generation proposal. No silent substitution of a
user clip with a generated approximation. A source-range request preserves the
specified in/out points. Product/logo appearance must remain recognizable;
outpaint, cutout and relighting are future optional adapters, not first-release
prerequisites. Fit/crop/padding are valid with a visible preview.

Existing media operations must run through WyvStudio's owned asset APIs and
billing. A paid child operation has its own immutable approval/quote. The parent
composition pauses until it is approved and completed. Do not charge the same
media through both the child operation and the parent render budget.

## 5. Source of truth and data model

A composition is NOT an editable conversion of arbitrary HTML into Scene rows.
Store it as a separate document attached one-to-one to a Project with a new
`editor_kind = composition` discriminator (existing rows default to `scene`).
Scene-only operations on composition projects return `unsupported_editor_kind`.
Creating a composition from an existing scene/UGC project creates a new project
with `source_project_id`; the original remains unchanged.

Proposed tables (all owned through workspace/project):

| Table | Essential fields and constraints |
| --- | --- |
| `video_compositions` | project_id unique FK, workspace_id, head_revision_id nullable, approved_revision_id nullable, brief_json, created_by |
| `composition_revisions` | immutable UUID, composition_id, parent_revision_id, runtime_id, source_bundle_key, source_sha256, manifest_json, manifest_sha256, duration_ms, width, height, fps, validation_json, created_by/run_id |
| `composition_runs` | UUID, workspace_id, composition_id, base_revision_id, output_revision_id, kind (author/edit/render), status, quote_id, operation_id, request_hash, idempotency_key, lease token/expiry, attempt, cancel_requested_at, error_json, result_json |
| `composition_run_events` | run_id, monotonic sequence, stage, safe message, timestamp; unique run_id+sequence |
| `composition_variants` | batch UUID, base_revision_id, exact override_json, run_id, output_revision_id, label; each output independently observable |

Unique idempotency scope: workspace + operation kind + idempotency key. Same key
and different request hash returns 409. Artifacts live in private workspace-scoped
storage. Source bundles contain HTML, approved scripts/styles, font references
and manifest; reject absolute paths, symlinks and archive traversal on ingest.

Manifest v1 includes owned asset IDs with content checksums, local relative paths,
media type/duration/dimensions, source ranges, licensed fonts, layout parameters,
approved product facts and provenance, caption source, and declared outputs.
No expiring signed URL is part of the durable content fingerprint. Materialize
fresh authorized downloads into fixed paths at each run, verify checksums, then
render offline. Missing/deleted assets stop the run; never substitute silently.

Publish a new head with compare-and-swap against the expected base revision.
Concurrent edits may finish as separate draft revisions, but only one may advance
the head; the loser returns `revision_conflict` with its preserved draft. It is
not automatically rebased or rerendered. Read-only rendering of a pinned revision
can continue while a newer revision is authored.

## 6. Brief and agent workflow

Required brief: objective, primary message, owned asset selection, target ratio,
duration, language and whether narration is required. Optional: product URL,
brand kit, audience, references, offer, approved claims, style, locked copy/assets.
Ask only for missing essentials. Reuse selected workspace/client brand context.
Website text and uploaded/reference content are untrusted source data; they cannot
change execution permissions or spending limits.

Persist a normalized brief containing facts and references. Freeze prices,
ratings, testimonials and discounts only when supported by user-approved input;
otherwise ask or omit them. Do not fabricate social proof. The agent can propose
copy alternatives, but must distinguish them from sourced facts.

Author/edit state machine:

`queued -> preparing -> planning -> authoring -> validating -> preview_ready`

Additional states: `needs_input`, `awaiting_media_approval`, `waiting_for_media`,
`repairing`, `failed`, `cancel_requested`, `cancelled`, `needs_attention`.
Final render uses a separate run: `queued -> preparing -> rendering -> verifying
-> completed`, with the same failure/cancellation states.

Loop:

1. Authorize inputs, acquire lease and budget hold; freeze base revision/runtime.
2. Load relevant pinned skills and existing brief/timeline/source manifest.
3. Select installed primitives and plan scenes, pacing and reusable media.
4. Author Hyperframes files in the isolated workspace; do not rewrite unrelated
   source for a localized edit. Maintain stable element IDs and exposed variables
   for headline, offer, CTA, palette and safe-zone layout.
5. Run upstream lint while authoring and final `check` with snapshots. Parse
   versioned machine output when supported; never infer success from log prose.
6. Inspect representative rendered frames: at least each scene midpoint, intro,
   outro, transitions and requested change. For multi-scene compositions include
   each mounted sub-composition. Validate audio/timeline separately.
7. Up to two repair cycles after the first draft, within the same run budget.
   Failures after that pause with actionable diagnostics and preserve the last
   passing revision. No automatic switch into paid media generation.
8. Produce isolated browser preview plus contact sheet. User approves that exact
   revision and output specification before a delivery render.
9. Verify exported media with ffprobe and representative decoded frames before
   registering the output as ready. Never report ready from process exit alone.

A validation pass is not a claim of factual correctness or artistic quality.
Business facts remain grounded in the brief; user review approves the result.

Allowed internal agent tools: read/write constrained project files, inspect
manifest/timeline, search installed catalog, apply validated patch, run approved
lint/check/snapshot commands, view generated frames and request media proposal.
The agent has no arbitrary shell, package installer, public publish tool or raw
billing/credential tool. Render starts via the approved coordinator operation.

## 7. API and assistant contracts

Implement app routes under `/api/v1/compositions`; expose developer equivalents
under `/api/developer/v1/compositions` only after app acceptance tests pass. Both
call the same domain services, permissions and budgeting implementation.

| Method/path under that prefix | Contract |
| --- | --- |
| `POST /` | Create an empty composition project from brief and owned asset IDs; no generation or rendering; idempotency key required |
| `GET /{id}` | Brief, head/approved revision, active runs, supported actions and output freshness |
| `POST /{id}/quotes` | Quote author/edit/render; binds expected revision, prompt/overrides, selected assets, output options and runtime |
| `POST /{id}/runs` | Start approved quote with idempotency key; 202 run/operation IDs; never wait for render in HTTP |
| `GET /{id}/runs/{runId}` | Stage, stage-local progress where known, diagnostics, resulting revision/artifacts, accounting state |
| `GET /{id}/runs/{runId}/events?after={sequence}` | Resumable event polling; authorize every request |
| `POST /{id}/runs/{runId}/cancel` | Explicit cancellation request; cooperative stop/lease fencing; return pending until termination acknowledged |
| `GET /{id}/revisions/{revisionId}/preview` | Short-lived authorized preview capability; no public sharing |
| `POST /{id}/approvals` | Save approval of a passing immutable revision + output-spec hash |
| `POST /{id}/variants` | Register explicit override combinations, max three, from a base revision; no execution; each requires a quote/run |
| `GET /{id}/exports/{exportId}` | Exact export, revision, freshness and private download; no implicit latest-file substitution |

Quote kinds: `composition_author`, `composition_edit`, `composition_render`.
Approval is bound to revision, asset manifest hash, runtime, dimensions, fps,
duration and watermark policy. Rendering requires both the revision approval and
the render quote; changing any bound field requires a new quote/approval.
Errors use existing structured conventions: not_found (404), forbidden (403),
upgrade_required/insufficient_credits (402), revision_conflict/quote_expired/
project_busy (409), invalid_assets/unsupported_editor_kind (422), rate_limited
(429). Renderer/provider failures surface on run status, with `retryable` and
`next_action`; do not return a false successful artifact.

Proposed Cruise tools: `plan_composition`, `revise_composition`,
`render_composition`. They return asynchronous proposals/run references, never
execute an unapproved spend. Existing assistant auto-apply does not bypass
composition budget or final-render approval. MCP wrappers should mirror the
public contracts, use write annotations for persistence/spend and preserve IDs
for replay. Public API exposes domain actions, not filesystem or CLI commands.

## 8. UI and editing experience

Keep WyvStudio's navbar, workspace switcher, colours and asset library. Add one
entry, “Design a video”, behind the composition entitlement. Do not ask users to
choose rendering engines. Existing creation lanes can offer “Design with this
video” after generation, which opens a new composition using the finished asset.

Workspace layout: prompt/conversation panel, large preview, and a compact scene
outline. First prompt can include library assets and brand selection. Show a plan
with reused assets, missing assets, output size/duration and maximum spend.

Progress labels reflect actual stages; no invented global percentage. Show frame
progress only when the renderer provides a trustworthy total. Users can leave
and return; server run state survives browser disconnects. Do not restart a
preview's playback on polling updates when its revision has not changed.

Preview supports play/pause, seek, mute, fullscreen and safe-zone overlay.
Revision history offers restore-as-new-revision, compare and retry. A draft edit
never replaces the approved export until verified completion. Download/share/
schedule from a stale export uses the existing explicit version warning.

Initial direct controls: edit exposed headline/CTA/offer text, choose palette,
choose approved ratio and toggle safe-zone guides. These become validated
parameter changes and new revisions, not raw DOM edits. Drag handles are deferred
until element-to-source mapping and round-trip behavior are proven. User-locked
copy and asset identity must survive subsequent prompt revisions.

## 9. Preview, export, captions and delivery

Preview runs on a separate origin in a sandboxed iframe without WyvStudio cookies
or same-origin privileges. Serve only the revision's permitted bundle/media through
scoped short-lived capability URLs; no arbitrary remote fetch. Apply CSP and
message-origin validation. Preview and export must use matching runtime/fonts/
media checksums and seek logic. A browser preview is not accepted as proof that
an MP4 can be rendered.

Prefer a composition-owned audio timeline using existing narration/music assets.
Do not synthesize new narration merely to render. Stage media locally, define
source ranges, trims and gain in the manifest. No automatic clip looping: reject
inadequate footage coverage or ask for an explicit hold/loop/new-clip decision.

V1 captions: retain the existing ASS-based caption renderer as an optional final
pass. Hyperframes creates designed headlines and callouts, not a competing hidden
caption track. When captions are enabled, show an encoded draft through the same
caption pass before final approval so the preview is representative. Record font,
timing, safe-zone and render settings in the revision/export fingerprint. Deduct
caption/export costs at most once through the approved render operation.

Outputs register ordinary owned Asset records and compatible export records with
composition revision/hash metadata. Audit all export consumers before reuse;
where they assume Scene rows, introduce an explicit composition adapter. Apply
watermark, plan limits, workspace permissions, sharing and scheduling safeguards
server-side. Public delivery is a separate confirmed action and never an agent
render-side command. Audit project routing and existing auto-export rules so a
composition project cannot accidentally enter the scene generation pipeline.

## 10. Cost, limits and operation accounting

“Reuses footage” means no new footage-generation charge, not zero infrastructure
cost. Separate internal meters for agent tokens, media generation, CPU/render
seconds, storage and transfer. Customer-visible quote shows any composition fee,
media fee and export fee distinctly. A zero-credit action can still consume a
bounded included quota.

Initial technical limits (server-enforced, configurable):

- One active composition run per workspace; two render slots per initial worker
  host, reduced to one if E0 memory/latency measurements require it.
- 30-second duration; 1080p delivery; 24/30 fps; up to 20 media inputs, 500 MiB
  staged total, 10 MiB source bundle excluding approved media/fonts.
- One initial authoring pass + two repairs; maximum 24 agent tool calls and
  10 minutes authoring wall time. Initial aggregate token cap 60,000; stop earlier
  on the approved monetary budget. Provider transport retries count toward budget.
- 10 minutes render timeout, 4 GiB RAM and 2 vCPU per sandbox as initial caps;
  measure and tune before public launch. 2 GiB temporary disk cap per run.
- Three variants, each its own bounded run and output status. Initial private
  pilot quota: ten author/edit runs and ten delivery renders per workspace/day.
  Retries caused by verified infrastructure failures do not consume another
  customer quota unit, but are bounded operationally.

Map approved work into existing CreditService/OperationAccounting holds, linked
by run ID. This service is currently under Developer; reuse it only after tests
prove session-origin operations have the same protection. Add a domain facade,
not a second balance ledger. Accounting must be enabled for this feature; fail
closed if the feature is enabled without it. API key attribution is nullable for
session runs, but workspace, billing root, actor and operation attribution are
mandatory. Do not classify composition capacity as UGC takes: introduce a separate
capacity class and preserve existing UGC reservations/limits.

Never hold a DB transaction during rendering/provider calls. Reserve under a short
lock, dispatch an outbox/after-commit job, then settle actual accounted work within
the approved ceiling. Release unused hold only when no live worker can charge.
A worker timeout without confirmed termination is `needs_attention`; do not start
replacement paid work. Late callbacks from expired leases cannot publish outputs.
Cancellation preserves spent credits and prior results and releases unused holds
once fenced. Retry known failed renders can reuse source/assets without rerunning
authoring or generation; an ambiguous provider request requires reconciliation.

Cache key: tenant scope + source/manifest hashes + runtime ID + output options +
caption/watermark policy. Cache hits still require authorization and artifact
existence; never reuse cross-tenant files or bypass watermark changes. Agent edit
results are not assumed deterministic; the immutable source revision is cached.

Pricing decision gate: measure at least 20 representative full cycles including
repairs and exports. Publish neither “unlimited” nor “free variants” before
measuring p50/p95 cost, render time, failure rate and support/retry overhead. For
private testing, allow explicit bounded zero-customer-credit quotas while tracking
provider costs. Public launch requires a reviewed rate card and margin model.

## 11. Isolation and operational safeguards

Treat generated HTML/JS as executable untrusted code. Use an ephemeral non-root
sandbox with no Docker socket, host directories, cloud metadata, private-network
access or inherited provider secrets. Read-only runtime filesystem; write only to
run scratch/output. Separate tenant scratch directories and browser processes.
Use OS/container CPU, memory, disk, process and wall-time limits; kill the whole
process group on termination. Do not copy the scraper's `--no-sandbox` settings
and treat that as sufficient isolation. Validate the browser/container sandbox
combination in the actual hosting environment before rollout.

Asset fetches happen in a controlled resolver, not from generated scripts. Check
ownership and URL redirects/DNS targets; reject private/link-local addresses,
unsupported protocols and decompression bombs. Network-off render should succeed
with the materialized manifest. Pre-approved framework scripts/fonts are bundled.
The agent's provider access is mediated by a budgeted coordinator outside the
sandbox. No arbitrary module imports or on-demand dependency installation.

Retain project source/revisions until project deletion under the existing retention
policy. Delete scratch within one hour of terminal success/cancellation; failed
scratch may be retained privately up to 24 hours for debugging. Store redacted
run diagnostics for 30 days initially. No raw prompts, signed asset URLs or tokens
in routine logs; artifact/debug access follows workspace and support permissions.

Monitoring: queue wait, authoring/repair count, stage duration, memory, render fps,
cache hits, cancellation latency, validation codes, accounting holds/settlement,
source/output hashes and late callback rejection. Alert on stranded leases/holds,
repeated crashes, output verification failures and unexpected provider spend.
Deploy composition workers separately from normal generation workers so load or
browser failures cannot consume all existing video capacity.

## 12. Acceptance tests and release gates

Use synthetic/owned fixtures and mocked media generation first. Any real paid
provider smoke requires an agreed budget. Record fixture, runtime digest, revision,
output checksum, timing, resource usage and test outcome.

| Test | Required result |
| --- | --- |
| Product photo + brand + prompt | Passing composition and playable 15s MP4, correct text/logo, no invented offer |
| Existing 15s UGC + callouts | Original selected clip/range preserved; no media provider request |
| “Change CTA only” | New revision changes CTA; asset checksums and narration unchanged |
| Three text variants | Exactly three independently tracked outputs; no full-video regeneration |
| Unsupported new spoken hook | Explicit media proposal; no unapproved provider call |
| Missing font/asset or overflowing text | Validation/repair then pause if unresolved; never ready with broken output |
| Out-of-order frame seeks | Same source yields consistent frames within agreed perceptual tolerance |
| Captioned preview vs delivery | Same caption timing/layout; representative frame and audio comparison |
| Concurrent edits | Only one advances expected head; preserved conflicting draft; no lost edits |
| Timeout/replay/cancel | Original run/charges preserved; no duplicate provider/render dispatch |
| Worker crash/late callback | Fenced publication; stranded hold observable and reconciled safely |
| Cross-workspace IDs/preview token | Access denied; no asset/source leakage |
| Malicious HTML/source URL | No secret/metadata/private-network read, runaway process or path escape |
| Stale result/watermark/downgrade | Explicit export version; server plan/watermark policy respected |
| Export consumers | Composition asset downloads; share/schedule only after explicit confirmation |
| Isolation under load | Existing video generation remains healthy at configured composition concurrency |

No “all green” declaration from one happy-path render. Required release evidence
includes a real short video, a follow-up edit, a render failure/recovery, a concurrent
edit, a scoped asset denial and an accounted run with unused reservation released.

## 13. Implementation sequence and checklist

Open work is tracked in [the Create todo](create-todo.md); the old [implementation TODO](archive/create/hyperframes-implementation-todo.md) is history. Its expanded E0–E6 phases supersede the abbreviated sequence below; these older headings are an architectural summary, not a separate completion record. The latest UI reference is [Create](create-ui/agent-new.html).

### E0 — prove the upstream integration
- [ ] Pin an available Hyperframes release and skill/runtime/dependency manifest.
- [ ] Implement adapter smoke: init/author/check/snapshot/preview/render/ffprobe.
- [ ] Render product promo and footage overlay fixtures; edit CTA and rerender.
- [ ] Verify offline media, preview parity, sandboxing and resource measurements.
- [ ] Record SDK/CLI incompatibilities and exact command/output schemas.

Exit: integration works without a fork or bespoke renderer; measured short-video
performance fits caps. If not, stop and revise hosting/scope before product build.

### E1 — domain, storage and execution
- [ ] Migrations, project discriminator and authorization/ownership tests.
- [ ] Immutable revisions, CAS head updates, source/artifact storage and retention.
- [ ] Quote/run APIs, shared accounting facade, dedicated capacity and cancellation.
- [ ] Private worker leases, after-commit dispatch, recovery and observability.

### E2 — bounded authoring agent
- [ ] Brief normalization, pinned skill loading and route selection.
- [ ] Controlled file/CLI/media-proposal tools and bounded visual repair loop.
- [ ] Grounded claims, locked inputs, stable IDs and edit-only source patches.
- [ ] Regression fixtures for no asset regeneration on composition-only edits.

### E3 — user-facing pilot
- [ ] Prompt/preview workspace within the existing app shell.
- [ ] Costs, stage progress, missing-media approval, history and revision comparison.
- [ ] Representative captioned draft and final approval/export integration.
- [ ] Three-variant cap, partial results and explicit stale-export delivery.
- [ ] Private pilot behind workspace allowlist; production limits/alerts verified.

### E4 — public and MCP release
- [ ] Measured pricing decision and support/recovery runbook approved.
- [ ] API/MCP wrappers and tool annotations; schema/error/replay tests.
- [ ] Current account plan gates, ratio consistency, docs and screenshots.
- [ ] Real connector quote → approval → run → preview → export test.
- [ ] Expand enablement only after pilot output quality/cost results are reviewed.

Flags: `HYPERFRAMES_ENABLED=false` globally by default, workspace allowlist and
separate `HYPERFRAMES_PUBLIC_API_ENABLED=false`. Rollback stops new admission,
drains/cancels safely and keeps completed artifacts readable. Never revert schema
or disable accounting beneath active operations. No existing project is migrated
into this lane automatically.

## 14. Corrections to the original cookbook

- Replace “CPU only/free” with separately metered composition/render work and no
  repeated media-generation cost when assets are reused.
- 4:5 exists in current project/editor/export paths; developer creation capability
  and MCP creation enums still omit it. Test and align paths rather than rebuild.
- Do not treat all hooks as composition: spoken performance changes may cost media.
- Node 20 scraper reuse is not the runtime plan; use the isolated Node 22+ service.
- Start with a measured short video vertical slice; static output is complementary.
- Keep placement limits/versioned safe-zone profiles separate from recommended ad
  lengths. Source profiles from platform documentation; do not promise performance
  from duration. The cookbook's blanket 60s Shorts limit is outdated.
- Defer new generator families and generic Replicate adapter refactoring unless a
  pilot fixture actually needs them. They are not prerequisites for integration.

## References inspected

- [Hyperframes README](https://github.com/heygen-com/hyperframes)
- [Agent router](https://github.com/heygen-com/hyperframes/blob/main/skills/hyperframes/SKILL.md)
- [Core composition contract](https://github.com/heygen-com/hyperframes/blob/main/skills/hyperframes-core/SKILL.md)
- [CLI workflow](https://github.com/heygen-com/hyperframes/blob/main/skills/hyperframes-cli/SKILL.md)
- [CLI package/runtime requirements](https://github.com/heygen-com/hyperframes/blob/main/packages/cli/package.json)

These main-branch URLs explain the reviewed design; E0 must record immutable
release/commit references for implementation and regression fixtures.
