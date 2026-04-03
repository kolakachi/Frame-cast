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

**Active phase:** Phase 2 — Editor  
**Last updated:** 2026-04-03  
**Last updated by:** Codex

---

## Phase 0 — Foundation

Exit gate: A user can register, receive a magic link, log in, receive a JWT, and make an authenticated API request.

- [x] Monorepo initialised — `/api` (Laravel 11) and `/web` (Vue 3 + Vite)
- [x] Docker Compose — all services running locally (api, worker, scheduler, reverb, web, postgres, redis)
- [x] Database migrations — all entities from `DATA_MODEL.md` created
- [x] Auth — AuthSession, MagicLinkToken, JWT middleware implemented
- [x] Auth endpoints — all 6 routes working (`/login`, `/magic-link`, `/magic-link/verify`, `/refresh`, `/logout`, `/logout-all`)
- [x] Vue SPA — router, Pinia auth store, Axios interceptor with token refresh
- [x] Reverb — connection from Vue to Reverb established, private channel auth working
- [x] Horizon — running, accessible at `/horizon`
- [x] B2 storage — Flysystem adapter configured, test upload + signed URL working
- [x] Mail — `MagicLinkMail` mailable sending (local env currently uses SMTP/Mailtrap)

**Phase 0 exit gate passed:** [x]

**Notes:**

- Created `/framecast-app` as the implementation root so the original repository root can continue holding specs and progress tracking.
- Installed Laravel 11 in `/framecast-app/api` and Vue 3 + Vite in `/framecast-app/web` using framework generators rather than hand-created scaffolding.
- Added phase 0 package baseline: Horizon, Reverb, Flysystem S3 adapter for B2, JWT library, Redis client, Vue Router, Pinia, Axios, Echo, Pusher, and Tailwind v4.
- Added initial Dockerfiles and `docker-compose.yml` / `docker-compose.prod.yml` scaffolding for api, worker, scheduler, reverb, horizon, web, postgres, and redis.
- Added versioned API routing with a JSON health endpoint and replaced the default Vue starter with a router/auth/realtime shell.
- Added the full auth route surface and first-pass backend implementation for login, magic-link request/verify, refresh rotation, logout, logout-all, JWT verification middleware, and `MagicLinkMail`.
- Resolved JWT middleware compatibility issues with `lcobucci/jwt` v5 (`Parser` decoder + clock usage) so protected API calls and refresh recovery work reliably.
- Verified runtime checks in Docker on 2026-04-03:
- Auth login returns JWT and `/api/v1/me` succeeds.
- Refresh token rotation via `/api/v1/auth/refresh` returns a new access token and authenticated follow-up call succeeds.
- Magic-link verification endpoint returns a valid session payload.
- Reverb private channel auth endpoint (`/api/v1/broadcasting/auth`) returns auth signature with JWT.
- B2 smoke endpoint (`/api/v1/verification/storage-smoke`) succeeds and returns temporary URL.
- Horizon responds at `/horizon` (HTTP 200).
- Switched local Docker workflow to source bind mounts for API and web services to avoid rebuilds during development.

---

## Phase 1 — Project Creation and Generation

Exit gate: User submits a script → watches generation progress in real time → lands in Editor with scenes, visuals, and audio populated.

- [x] Workspace CRUD endpoints
- [x] Channel CRUD endpoints
- [x] BrandKit CRUD endpoints
- [x] VoiceProfile model — seeded with OpenAI TTS voices
- [x] CaptionPreset model — seeded with 3 default presets
- [x] Template model — seeded with 2 default templates (Explainer, Listicle)
- [x] Project creation endpoint — validates per `INTERACTIONS_AND_RULES.md`
- [x] `GenerateScriptJob` — OpenAI adapter, `script_from_prompt` / `script_from_url` templates
- [x] `BreakdownScenesJob` — OpenAI adapter, `scene_breakdown` template, creates Scene records
- [x] `GenerateHooksJob` — returns 3–10 hook options
- [x] `MatchVisualsJob` — visual adapter per scene
- [x] `GenerateTTSJob` — TTS adapter per scene, stores audio asset
- [x] Reverb events — `generation.progress` emitted from each job on `project.{id}`
- [x] Generation Progress screen (Vue) — consumes Reverb events, 5-stage pipeline
- [x] New Video modal (Vue) — all 7 source types with conditional panels
- [x] Notification system — Notification records, Reverb delivery, toast + bell drawer (Vue)

**Phase 1 exit gate passed:** [x]

**Notes:**

- Added authenticated Workspace CRUD API endpoints under `/api/v1/workspaces` with workspace scoping and archive-on-delete behavior.
- Added authenticated Channel CRUD API endpoints under `/api/v1/channels` scoped to the authenticated user's workspace.
- Added authenticated BrandKit CRUD API endpoints under `/api/v1/brand-kits` scoped to the authenticated user's workspace.
- Added `VoiceProfile` model and `VoiceProfileSeeder` with default OpenAI TTS voices (global records) wired into `DatabaseSeeder`.
- Added `CaptionPreset` model and `CaptionPresetSeeder` with 3 default global presets wired into `DatabaseSeeder`.
- Added `Template` model and `TemplateSeeder` with 2 default global templates (`Explainer`, `Listicle`) wired into `DatabaseSeeder`.
- Added authenticated `POST /api/v1/projects` endpoint with source-type/content validation, language/format/platform requirements, channel-template compatibility checks, and channel default prefills in response payload.
- Added queued `GenerateScriptJob` on `generation` queue and OpenAI-backed AI generation adapter layer with prompt template registry (`script_from_prompt`, `script_from_url`), including local fallback output when API key is not configured.
- Added queued `BreakdownScenesJob` chained after script generation, `scene_breakdown` prompt template support, and `Scene` model persistence (1–20 scenes) before transitioning project to `ready_for_review`.
- Added queued `GenerateHooksJob` chained after scene breakdown with `hook_options` prompt template support and durable storage in `project_hook_options` (enforcing 3–10 options).
- Added queued `MatchVisualsJob` and visual provider adapter layer, persisting matched visual assets and assigning `visual_type`, `visual_asset_id`, and `visual_prompt` per scene.
- Added queued `GenerateTTSJob` and TTS adapter layer, persisting per-scene audio assets and storing voice generation metadata in `voice_settings_json`.
- Added `GenerationProgressed` Reverb event (`generation.progress`) and stage-level emission across generation pipeline jobs on `project.{id}` private channel.
- Added Vue `GenerationProgressView` route (`/projects/:projectId/generation`) subscribing to `generation.progress` and rendering a 5-stage pipeline status UI.
- Added dashboard `New Video` modal wired to `POST /api/v1/projects` with all 7 source type panels and direct navigation to generation progress on success.
- Added workspace notification backend (`workspace_notifications` table, `NotificationService`, notification list/read endpoints) with Reverb broadcast event `notification.created` on `workspace.{id}` private channels.
- Added dashboard notification bell, right-side notification drawer, and live toast stack in Vue, including API hydration and mark-as-read actions.
- Added authenticated `GET /api/v1/projects/{projectId}` endpoint returning project + ordered scenes + hook options, including resolved visual/audio assets for editor hydration.
- Added Vue `EditorView` route (`/projects/:projectId/editor`) and wired generation progress completion to auto-navigate into the project editor.

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
