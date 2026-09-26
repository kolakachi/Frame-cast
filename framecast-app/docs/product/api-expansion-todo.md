# API/MCP expansion — phase tracker

> **Current release status — 26 September 2026 (evening):** A–E (`ad4c498`) and the
> worker-path accounting fix (`91387e9`) are deployed; operation accounting is
> enabled on production and a paid smoke settled with attributed charges. A4 is
> closed between changes (a race inside one change is an accepted limitation).
> MCP 1.8.x added character reference edits, sharing, publishing and previews. Historical evidence
> below applies only to its stated build. The current source of
> truth is [release closure](api-release-closure.md); outreach is gated on its
> unchecked release steps.

## Reopened release verification

These are release gates; A4 also has an unresolved implementation gap:

- [ ] A1–A3: verify reservations, settlement, initiating-key attribution and key
  rotation with accounting enabled on the release deployment.
- [ ] A4: close the dashboard/worker check-to-apply race without restoring
  broad reject-on-write triggers. A5: verify stale-export selection through
  the deployed connector.
- [ ] A6–A8: verify recovery, concurrency, cancellation and async failures with
  the deployed queue/provider configuration.
- [ ] B1–B2/C3: verify editor option forwarding, narration and owned animation
  history through the deployed connector.
- [ ] E1–E3: verify delivery handoffs and app confirmation; no programmatic
  publishing or app-only destructive operations are promised.

Local evidence and exact backlog IDs: [A–F backlog](api-mcp-gap-backlog.md).


> **25 September Phase A correction:** the historical completion notes below do
> not establish current release readiness. Spending reservations, attribution,
> revision fences, stale-export guards and recovery are implemented and tested
> locally in [the A1–A8 backlog](api-mcp-gap-backlog.md), behind a disabled
> accounting flag. See [verification and rollout](api-phase-a-verification.md).
> Production migration/enablement and connector verification remain required.


Created 25 September 2026. The customer is contacted only when every phase
below is shipped. Order: 1 → 3 → 2 → 4. Detailed items live in each scope
doc and in [api-access-todo.md](api-access-todo.md); this page is the
one-glance status.

| Phase | Scope | Status | Effort |
|---|---|---|---|
| 1. Creation settings | [api-phase1-creation-settings.md](api-phase1-creation-settings.md) | **Built** (25 Sep); caption preset moved to phase 4 | ~3 days |
| 3. UGC | [api-phase3-ugc.md](api-phase3-ugc.md) | **Built** (25 Sep): delegates to the app's UGC controller | ~4 days |
| 2. Characters | [api-phase2-characters.md](api-phase2-characters.md) | **Built** (25 Sep): delegates to the app's character controller | ~2 days |
| 4. Editor operations | [api-phase4-editor-operations.md](api-phase4-editor-operations.md) | **Built** (25 Sep): read, schema, proposals with revision precondition, 15 operations, export, retry — all delegated to the editor's controllers | 2–3 weeks |
| 5. Assistant delegation | Cruise Control as a bounded planner | **Built and live** (25 Sep, `9b26301`): `ask_wyvstudio_assistant` → `apply_assistant_plan`; prod serves 31 tools, Cruise registry lists 19 | — |
| Outreach | send the customer "Connect an AI assistant" | **Gated on rollout and connector verification** | — |

## Decisions needed

- [x] Phase 1: caption preset stays with captions in phase 4. (Decided 25 Sep.)
- [x] Phase 1: character reference cost carried as a known under-quote, stated
      in the docs, until the mid-pipeline reservation. (Decided 25 Sep.)
- [x] Phase 3: footage restyle deferred past phase 4. (Decided 25 Sep.)
- [x] Phase 4: proposals are always free; they are structured, not
      model-planned. (Decided 25 Sep.)

## Follow-ups from the build (release implications tracked in A1–A8/F)

- [ ] Per-family parity tests for editor operations against dashboard fixtures
      (today: delegation to the editor's controllers, plus one API test per family).
- [ ] Bump `MCP_VERSION` whenever the tool set changes; ChatGPT snapshots tools
      per install and shows its own "1.0.0" regardless of what the server reports.
- [ ] `UgcOneShotPricing` mirrors `UgcController::generateOneShot`; a pricing
      change must land in both (the controller cross-check fails closed).
- [ ] Character reference cost is absent from every estimate (dashboard too).
- [ ] Hard mid-pipeline spend reservation.
- [ ] Sidecar `list_library` uses `ilike`; fine on Postgres, case-sensitive on
      sqlite (tests only).

## Phase checklists

### Phase 1 — creation settings
- [x] Lookups: options, brand kits, channels, niches, caption presets, characters, library (image, music, video)
- [x] Quote widened to every New Video setting with ownership and mode validation
- [x] Sources: url, product_description, images
- [x] Resolved choices echoed by name in the quote (`chosen`)
- [x] MCP tools + widened `estimate_video`; docs; OpenAPI; tests
- [x] Deployed and smoke-tested on the owner's account (25 Sep, prod: 29 tools, options, allowance)

### Phase 3 — UGC
- [x] `plan_ugc`, `estimate_ugc`, `create_ugc`, `get_ugc_allowance`; library video type
- [x] Take reservation by request id; own-face and cast-style pricing; consent required
      (via the dashboard's own generate/generateOneShot; one-shot pricing mirrored in `UgcOneShotPricing`)
- [x] Composed and one-shot paths
- [x] MCP; docs; OpenAPI; tests
- [x] Deployed and smoke-tested (25 Sep, prod `782d840`: 29 tools listed, get_capabilities, get_options, get_ugc_allowance answer on the owner's workspace)

### Phase 2 — characters
- [x] `create_character` with limits and consent; `update_character`
- [x] Image quote → create → poll; set as reference
- [x] MCP; docs; OpenAPI; tests
- [x] Deployed and smoke-tested (25 Sep, prod `782d840`: 29 tools listed, get_capabilities, get_options, get_ugc_allowance answer on the owner's workspace)

### Phase 4 — editor operations
- [x] 4a read: `get_project`, `get_project_schema`
- [x] 4b proposals: propose, apply with revision precondition, expiry, single use
- [x] 4c families: script/structure · visuals · narration · captions · music/sound · motion/timing · project/hooks · export
      (captions, motion and timing ride `update_scene`; scene deletion excluded)
- [~] 4d parity: every operation executes the editor's own controller method, so shared execution still requires input/output and accounting regression coverage; the API test covers update, reorder, music, project settings, stale revision, whole-video refusal, replay and export. Per-family parity tests against dashboard fixtures remain a follow-up.
- [x] MCP; docs; OpenAPI
- [x] Deployed and smoke-tested (25 Sep, prod `782d840`: 29 tools listed, get_capabilities, get_options, get_ugc_allowance answer on the owner's workspace)

### Then
- [x] Owner test of the full surface on production (25 Sep: via ChatGPT — lookups by name, UGC plan from the real planner, consent gate, character #116 created; via the live API on project #226 — read, schema, a two-change proposal at 0cr, apply, export #166 rendered to a new file, exports listed)
- [ ] Contact the customer; record their first run (closes A4). **Ready: send the "Connect an AI assistant" page.**
- [ ] Submit to the ChatGPT app directory

### Phase C — local completion (25 September 2026)

- [x] Sample uploads and status; audio/SFX discovery.
- [x] Consent-bound zero-shot clone registration, preview and reusable profiles.
- [x] Approved audio-only or audio-and-script narration attachment.
- [x] Consent on character reference changes; native checkbox reset for new files.
- [x] MCP 1.5.0, OpenAPI entries, regression/transport coverage.
- [ ] Deploy voice-consent migration and API/MCP, then run authenticated media smoke checks.

See [Phase C workflow and limits](api-media-voice-guide.md). This local completion
does not change the historical production entries above.

### Phase D — local completion (25 September 2026)

- [x] Mode-aware UGC inputs and resolved quote choices; unsupported fidelity/clone claims removed.
- [x] Reference analysis and planning context via API/MCP.
- [x] Quoted, consent-bound presenter previews with durable replay.
- [x] UGC lifecycle fixtures and narration readiness/rerender checks.
- [x] MCP 1.6.0, contract tests and workflow documentation.
- [ ] Deploy and run separately authorized provider smoke checks.

See [Phase D guide](api-ugc-mcp-guide.md) for tested boundaries.

## Phase E — current delivery scope (local, not deployed)

Delivery is an explicit authenticated app handoff, not programmatic publishing.
The delivery preflight validates revision and export freshness; the user confirms
recipient/destination and rechecks the version in the app. Assistant scheduler
navigation is preserved and never reported as a completed post. Destructive
actions, preset authoring and assistant UI preferences remain app-only.
See [the Phase E contract](api-delivery-mcp-guide.md) for the current scope; this
supersedes any earlier wording implying full delivery or deletion API parity.
