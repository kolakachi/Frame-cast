# Framecast Build Progress

## How to use this file

This is the execution record for the Framecast build. It mirrors the phases in `spec/MASTER.md`.

**Rules:**
- Update this file as you complete work — mark tasks `[x]` when done
- Record blockers, decisions made during implementation, and anything that deviates from the spec
- Any agent or engineer picking up this work reads this file first to understand current state
- Do not mark a task complete if it is partially done — use the notes field
- Do not mark a phase complete until its exit gate passes

**Status key:**
- `[ ]` — not started
- `[~]` — in progress
- `[x]` — complete
- `[!]` — blocked (add a note)

---

## Current State

**Active phase:** Phase 0 — Foundation  
**Last updated:** 2026-04-02  
**Last updated by:** Codex

---

## Phase 0 — Foundation

Exit gate: A user can register, receive a magic link, log in, receive a JWT, and make an authenticated API request.

- [x] Monorepo initialised — `/api` (Laravel 11) and `/web` (Vue 3 + Vite)
- [~] Docker Compose — all services running locally (api, worker, scheduler, reverb, web, postgres, redis)
- [ ] Database migrations — all entities from `DATA_MODEL.md` created
- [ ] Auth — AuthSession, MagicLinkToken, JWT middleware implemented
- [ ] Auth endpoints — all 6 routes working (`/login`, `/magic-link`, `/magic-link/verify`, `/refresh`, `/logout`, `/logout-all`)
- [x] Vue SPA — router, Pinia auth store, Axios interceptor with token refresh
- [~] Reverb — connection from Vue to Reverb established, private channel auth working
- [~] Horizon — running, accessible at `/horizon`
- [~] B2 storage — Flysystem adapter configured, test upload + signed URL working
- [~] Mail — `log` driver in dev, `MagicLinkMail` mailable sending

**Phase 0 exit gate passed:** [ ]

**Notes:**

- Created `/framecast-app` as the implementation root so the original repository root can continue holding specs and progress tracking.
- Installed Laravel 11 in `/framecast-app/api` and Vue 3 + Vite in `/framecast-app/web` using framework generators rather than hand-created scaffolding.
- Added phase 0 package baseline: Horizon, Reverb, Flysystem S3 adapter for B2, JWT library, Redis client, Vue Router, Pinia, Axios, Echo, Pusher, and Tailwind v4.
- Added initial Dockerfiles and `docker-compose.yml` / `docker-compose.prod.yml` scaffolding for api, worker, scheduler, reverb, horizon, web, postgres, and redis.
- Added versioned API routing with a JSON health endpoint and replaced the default Vue starter with a router/auth/realtime shell.
- Remaining phase 0 work is still substantial: full domain migrations, JWT issuance and refresh flow, magic-link auth, broadcasting auth, Horizon/Reverb runtime verification in Docker, and B2/mail end-to-end tests.

---

## Phase 1 — Project Creation and Generation

Exit gate: User submits a script → watches generation progress in real time → lands in Editor with scenes, visuals, and audio populated.

- [ ] Workspace CRUD endpoints
- [ ] Channel CRUD endpoints
- [ ] BrandKit CRUD endpoints
- [ ] VoiceProfile model — seeded with OpenAI TTS voices
- [ ] CaptionPreset model — seeded with 3 default presets
- [ ] Template model — seeded with 2 default templates (Explainer, Listicle)
- [ ] Project creation endpoint — validates per `INTERACTIONS_AND_RULES.md`
- [ ] `GenerateScriptJob` — OpenAI adapter, `script_from_prompt` / `script_from_url` templates
- [ ] `BreakdownScenesJob` — OpenAI adapter, `scene_breakdown` template, creates Scene records
- [ ] `GenerateHooksJob` — returns 3–10 hook options
- [ ] `MatchVisualsJob` — visual adapter per scene
- [ ] `GenerateTTSJob` — TTS adapter per scene, stores audio asset
- [ ] Reverb events — `generation.progress` emitted from each job on `project.{id}`
- [ ] Generation Progress screen (Vue) — consumes Reverb events, 5-stage pipeline
- [ ] New Video modal (Vue) — all 7 source types with conditional panels
- [ ] Notification system — Notification records, Reverb delivery, toast + bell drawer (Vue)

**Phase 1 exit gate passed:** [ ]

**Notes:**

---

## Phase 2 — Editor

Exit gate: User can adjust scenes, queue export, and download a rendered MP4.

- [ ] Scene CRUD endpoints (update, reorder, delete, duplicate)
- [ ] Scene rewrite endpoint — `scene_rewrite` prompt, respects `locked_fields_json`
- [ ] Voice override per scene — saves `voice_settings_json`
- [ ] Visual swap per scene — calls visual adapter, updates `visual_asset_id`
- [ ] Caption settings per scene — saves `caption_settings_json`
- [ ] Preview endpoint — returns scene audio + visual URLs
- [ ] Auto-save — debounced PATCH, optimistic UI
- [ ] Export flow — validates per rules, creates ExportJob record
- [ ] FFmpeg rendering job — full pipeline from `PROVIDERS.md`, uploads to B2
- [ ] Export Reverb events — `export.progress`, `export.complete`, `export.failed`
- [ ] Editor screen (Vue) — scene sidebar, preview canvas, controls panel
- [ ] Scene sidebar — scene list, active state, add-scene panel, overflow menu

**Phase 2 exit gate passed:** [ ]

**Notes:**

---

## Phase 3 — Variants

Exit gate: User generates 5+ variants, selects a subset, exports as batch, retries failures independently.

- [ ] VariantSet and Variant models, creation endpoint
- [ ] Variant generation job chain — respects `lock_rules_json`
- [ ] Variants creation drawer (Vue) — dimension picker, lock controls, batch summary
- [ ] Variant cards grid — status per card, selection checkboxes
- [ ] Batch export — one ExportJob per selected variant, BatchJob parent
- [ ] Queue Detail screen (Vue)
- [ ] Failed Job Detail modal (Vue)
- [ ] Retry Confirmation modal (Vue)
- [ ] `partial_success` state handling throughout

**Phase 3 exit gate passed:** [ ]

**Notes:**

---

## Phase 4 — Asset Library and Settings

Exit gate: User creates a channel with brand kit, produces a project using presets, reuses them on a second project.

- [ ] Asset library endpoints (list, upload, search, filter, tag)
- [ ] Collection model, CRUD
- [ ] Asset library screen (Vue)
- [ ] Asset detail drawer (Vue)
- [ ] Channel create/edit form (Vue)
- [ ] Brand kit create/edit form (Vue)
- [ ] Account settings (Vue)
- [ ] Usage and billing display (Vue)
- [ ] Over-limit / paywall modal (Vue)
- [ ] Delete confirmation modal (Vue)
- [ ] Thumbnail generation job on asset upload

**Phase 4 exit gate passed:** [ ]

**Notes:**

---

## Phase 5 — Localization

Exit gate: User produces 3 language variants from one source project.

- [ ] LocalizationGroup and LocalizationLink models
- [ ] Translation job — calls translation adapter per scene and title
- [ ] TTS re-generation per target language
- [ ] Localized variant cards in Variants screen
- [ ] Failed language retryable independently

**Phase 5 exit gate passed:** [ ]

**Notes:**

---

## MVP Exit Gate Checklist

All must be true before public launch. See `spec/MASTER.md` for full criteria.

- [ ] New user creates first publishable video in under 10 minutes
- [ ] User generates at least 5 variants from one script
- [ ] User exports a batch of selected variants
- [ ] User reuses a saved channel preset and brand kit on a new project
- [ ] Failed batch item retryable without affecting successful siblings
- [ ] Export blocked (not silently failed) when required voice or visual is missing
- [ ] Over-limit actions show correct limit, usage, and upgrade path
- [ ] All Reverb events fire correctly
- [ ] All rendered outputs pass aspect ratio, caption rendering, watermark rules
- [ ] All acceptance criteria in `spec/ACCEPTANCE_CRITERIA.md` pass

---

## Decisions Made During Build

Record any implementation decisions that deviate from or extend the spec here. Include the reason.

| Date | Decision | Reason | Spec section affected |
|---|---|---|---|
| — | — | — | — |

---

## Blockers

| Blocker | Phase | Raised by | Status |
|---|---|---|---|
| — | — | — | — |
