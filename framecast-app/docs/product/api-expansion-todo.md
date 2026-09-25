# API/MCP expansion — phase tracker

Created 25 September 2026. The customer is contacted only when every phase
below is shipped. Order: 1 → 3 → 2 → 4. Detailed items live in each scope
doc and in [api-access-todo.md](api-access-todo.md); this page is the
one-glance status.

| Phase | Scope | Status | Effort |
|---|---|---|---|
| 1. Creation settings | [api-phase1-creation-settings.md](api-phase1-creation-settings.md) | Scoped, awaiting two decisions | ~3 days |
| 3. UGC | [api-phase3-ugc.md](api-phase3-ugc.md) | Scoped | ~4 days |
| 2. Characters | [api-phase2-characters.md](api-phase2-characters.md) | Scoped | ~2 days |
| 4. Editor operations | [api-phase4-editor-operations.md](api-phase4-editor-operations.md) | Scoped | 2–3 weeks |
| Outreach | send the customer "Connect an AI assistant" | Blocked on 1–4 | — |

## Decisions needed

- [ ] Phase 1: store `caption_preset_id` at creation as the project's default
      caption settings (new behaviour), or leave captions to phase 4?
- [ ] Phase 1: character reference cost is charged by the job but absent from
      the estimate (dashboard too). Carry as a known under-quote until the
      mid-pipeline reservation, or fix the estimate first?
- [ ] Phase 3: footage restyle stays deferred past phase 4 — agree?
- [ ] Phase 4: is a small disclosed cost for model-planned proposals
      acceptable, or must proposals always be free?

## Phase checklists

### Phase 1 — creation settings
- [ ] Lookups: options, brand kits, channels, niches, caption presets, characters, library (image, music)
- [ ] Quote widened to every New Video setting with ownership and mode validation
- [ ] Sources: url, product_description, images
- [ ] Resolved choices echoed by name in the quote
- [ ] MCP tools + widened `estimate_video`; docs; OpenAPI; tests
- [ ] Deployed and smoke-tested on the owner's account

### Phase 3 — UGC
- [ ] `plan_ugc`, `estimate_ugc`, `create_ugc`, `get_ugc_allowance`; library video type
- [ ] Take reservation by request id; own-face and cast-style pricing; consent required
- [ ] Composed and one-shot paths
- [ ] MCP; docs; OpenAPI; tests; deployed and smoke-tested

### Phase 2 — characters
- [ ] `create_character` with limits and consent; `update_character`
- [ ] Image quote → create → poll; set as reference
- [ ] MCP; docs; OpenAPI; tests; deployed and smoke-tested

### Phase 4 — editor operations
- [ ] 4a read: `get_project`, `get_project_schema`
- [ ] 4b proposals: propose, apply with revision precondition, expiry, single use
- [ ] 4c families: script/structure · visuals · narration · captions · music/sound · motion/timing · project/hooks · export
- [ ] 4d parity tests per family; partial failure reporting
- [ ] MCP; docs; OpenAPI; deployed and smoke-tested

### Then
- [ ] Owner test of the full surface on production
- [ ] Contact the customer; record their first run (closes A4)
- [ ] Submit to the ChatGPT app directory
