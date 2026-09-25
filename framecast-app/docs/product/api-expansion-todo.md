# API/MCP expansion — phase tracker

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
| Outreach | send the customer "Connect an AI assistant" | **Ready once the owner has tested the full surface in production** | — |

## Decisions needed

- [x] Phase 1: caption preset stays with captions in phase 4. (Decided 25 Sep.)
- [x] Phase 1: character reference cost carried as a known under-quote, stated
      in the docs, until the mid-pipeline reservation. (Decided 25 Sep.)
- [x] Phase 3: footage restyle deferred past phase 4. (Decided 25 Sep.)
- [x] Phase 4: proposals are always free; they are structured, not
      model-planned. (Decided 25 Sep.)

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
- [~] 4d parity: every operation executes the editor's own controller method, so state and accounting are the editor's by construction; the API test covers update, reorder, music, project settings, stale revision, whole-video refusal, replay and export. Per-family parity tests against dashboard fixtures remain a follow-up.
- [x] MCP; docs; OpenAPI
- [x] Deployed and smoke-tested (25 Sep, prod `782d840`: 29 tools listed, get_capabilities, get_options, get_ugc_allowance answer on the owner's workspace)

### Then
- [ ] Owner test of the full surface on production
- [ ] Contact the customer; record their first run (closes A4)
- [ ] Submit to the ChatGPT app directory
