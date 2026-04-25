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

**Active phase:** MVP Exit Gate QA
**Last updated:** 2026-04-14
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

- [x] Scene CRUD endpoints (update, reorder, delete, duplicate)
- [x] Scene rewrite endpoint — `scene_rewrite` prompt, respects `locked_fields_json`
- [x] Voice override per scene — saves `voice_settings_json`
- [x] Visual swap per scene — calls visual adapter, updates `visual_asset_id`
- [x] Caption settings per scene — persistence, style presets, highlight mode, and position controls shipped
- [x] Preview endpoint — returns scene audio + visual URLs
- [x] Voice regenerate per scene — regeneration, outdated-state handling, and pre-regenerate draft flush shipped
- [x] Auto-save — debounced PATCH, draft flush on scene switch/export/regenerate, and unload protection shipped
- [x] Export flow — validates per rules, creates ExportJob record
- [x] FFmpeg rendering job — scene composition, captions, and audio/visual sync fixes shipped
- [x] Export Reverb events — editor now updates from Reverb events and fetches once on terminal states
- [x] Editor screen (Vue) — phase 2 editor interactions and save/export flows shipped
- [x] Scene sidebar — add scene, reorder, duplicate, delete, and outdated-state cleanup shipped

**Phase 2 exit gate passed:** [x]

**Notes:**

- Added authenticated Scene CRUD endpoints under `/api/v1/scenes` for update, reorder, duplicate, and delete with strict workspace scoping through project ownership.
- Rebuilt `/framecast-app/web/src/views/EditorView.vue` to match the `framecast-2.html` editor composition more closely: fixed left rail + sticky topbar, three-column editor layout, inline add-scene panels, mobile preview canvas, collapsible right-side sections, rewrite controls, and reference-aligned tokens/styles.
- Added authenticated scene rewrite endpoint and editor wiring for AI rewrite preview/apply, including `voice_settings_json.is_outdated` when a rewrite changes script text.
- Added authenticated scene preview endpoint returning resolved scene audio + visual URLs for editor consumption.
- Added scene-level voice regeneration endpoint and editor wiring to refresh TTS for one scene and clear the outdated state.
- Added per-scene voice override controls in the editor backed by `GET /api/v1/voice-profiles` and debounced `PATCH /api/v1/scenes/{sceneId}` persistence.
- Added scene-level visual swap endpoint and editor wiring to rematch a single scene visual from the active query.
- Added project export queue endpoint and editor export button wiring to validate export readiness and create an `export_jobs` record.
- Added debounced script autosave in the editor with save-state feedback (`Unsaved changes`, `Saving...`, `Saved`) backed by `PATCH /api/v1/scenes/{sceneId}`.
- Added caption-settings persistence plumbing in the editor for enabled/highlight/position controls backed by `PATCH /api/v1/scenes/{sceneId}` and `caption_settings_json`.
- Fixed editor hydration for AI image scenes by including `visual_style` and `image_generation_settings_json` in `GET /api/v1/projects/{id}` scene payloads, so the last selected style is visible immediately on load.
- Added editor export-status polling so queued/processing exports keep updating in-place and completed renders open the finished MP4 automatically.
- Upgraded export rendering from a placeholder file to a real scene-based FFmpeg composition that stitches scene visuals, audio, and caption text into a downloadable MP4 uploaded to B2.
- Fixed segment stream mapping and exact per-scene trim timing so narration and scene boundaries stay aligned through concat.
- Added real scene insertion support in the API and editor sidebar, plus reorder, duplicate, and delete controls in the Vue editor.
- Removed editor export polling in favor of Reverb-driven status updates with terminal refresh.
- Added active-draft flush before scene switch, voice regenerate, visual swap, and export so autosave cannot silently drop changes.
- Phase 2 declared complete and closed before starting variants work.

---

## Phase 3 — Variants

Exit gate: User generates 5+ variants, selects a subset, exports as batch, retries failures independently.

- [x] VariantSet and Variant models, creation endpoint
- [x] Variant generation job chain — respects `lock_rules_json`
- [x] Variants creation drawer (Vue) — dimension picker, lock controls, batch summary
- [x] Variant cards grid — status per card, selection checkboxes
- [x] Batch export — one ExportJob per selected variant, BatchJob parent
- [x] Queue Detail screen (Vue)
- [x] Failed Job Detail modal (Vue)
- [x] Retry Confirmation modal (Vue)
- [x] `partial_success` state handling throughout

**Phase 3 exit gate passed:** [x]

**Notes:**

- Added backend Phase 3 foundations: `VariantSet`, `Variant`, and `BatchJob` models; `GET/POST /api/v1/projects/{projectId}/variants`; `POST /api/v1/variant-sets/{variantSetId}/export`.
- Added `GenerateVariantSetJob` to clone derived projects/scenes and generate hook, voice, visual, and format variants from one base project.
- Added `batch_job_id` to `export_jobs` and batch status rollup in `ProcessExportJob` so batch export can move toward queue-detail support.
- Added first-pass Vue Variants screen at `/projects/{id}/variants` with generation drawer, variant cards grid, selection state, and batch export actions wired to the new API.
- Current variant generation intentionally rejects `language` as a dimension until Phase 5 localization work lands.
- Refactored variant generation into one child job per variant, added retry-failed flow, and added visual fallback behavior so one slow or failing variant no longer blocks the whole batch.
- Added variant deletion with confirmation and safe blocking for in-flight variants.
- Added queue detail, failed-detail, retry-confirmation, and `partial_success` UI states to the Variants view using latest batch-job and export-job data.

---

## Phase 4 — Asset Library and Settings

Exit gate: User creates a channel with brand kit, produces a project using presets, reuses them on a second project.

- [x] Asset library endpoints (list, upload, search, filter, tag)
- [x] Collection model, CRUD
- [x] Asset library screen (Vue)
- [x] Asset detail drawer (Vue)
- [x] Channel create/edit form (Vue)
- [x] Brand kit create/edit form (Vue)
- [x] Account settings (Vue)
- [x] Usage and billing display (Vue)
- [x] Over-limit / paywall modal (Vue)
- [x] Delete confirmation modal (Vue)
- [x] Thumbnail generation job on asset upload

**Phase 4 exit gate passed:** [x]

**Notes:**

- Activated Phase 4 after variants work and started replacing the dead sidebar placeholders with real `/assets` and `/settings` screens in Vue.
- Added authenticated asset-library API surface under `/api/v1/assets` with list/show/upload/update/archive behavior and workspace scoping.
- Added authenticated collections API surface under `/api/v1/collections` with list/create/update/delete to support asset grouping.
- Added first-pass `AssetLibraryView` with search, type filter, collection filter, upload panel, asset grid, and asset detail drawer.
- Added first-pass `SettingsView` with Channels, Brand Kits, Account, and Usage & Billing sections wired to live API data.
- Added `PATCH /api/v1/me` and enriched `/api/v1/me` with timezone + usage summary so the Settings screen has a real account/usage source.
- Added channel-limit enforcement in the API, a Settings paywall modal, shared archive/delete confirmation modals, and a queued asset-thumbnail job on upload.
- Phase 4 was kept open until preset reuse and collection management were verified; it is now closed.
- Settings now persists channels, brand kits, usage preferences, and user account preferences. Brand kits use card-style management, color pickers, and constrained font dropdowns.
- Video creation and editor project metadata now allow selecting persisted channels and brand kits so new projects can inherit saved presets.
- Asset library now supports paginated list/search/filter/upload/archive/detail drawer flows.
- Media ingestion/transcription has been added for uploaded audio/video sources, with queued transcription and safe fallback behavior when provider transcription is unavailable.
- Completed asset collection UI: create, edit, delete, filter by collection, upload into collection, and assign/reassign asset collections from the detail drawer.
- Hardened asset collection validation so assets cannot be assigned to collections outside the user's workspace.
- Deleting a collection now removes that collection reference from assigned assets instead of leaving stale IDs behind.
- Verified Phase 4 implementation on 2026-04-14:
- PHP syntax passed for `AssetController` and `CollectionController`.
- Web production build passed with Vite.
- Docker images rebuilt and `api`, `worker-default`, `worker-generation`, and `horizon` restarted.
- `/api/v1/assets` and `/api/v1/collections` route surfaces confirmed in the rebuilt API container.
- Preset reuse smoke passed through `ProjectController::store`: two projects were created with the same saved channel and brand kit defaults, then cleaned up.
- Collection cleanup smoke passed: deleting a collection removes its ID from assigned assets.

**Phase 4 remaining before exit gate:**

- [x] Runtime-test: create channel + brand kit → create project with those presets → create second project reusing the same presets without manual reconfiguration
- [x] Complete or defer collection UI: create/edit/delete collections, assign assets to collections, filter assets by collection
- [x] Confirm asset upload thumbnail generation and transcription events behave consistently in the UI
- [x] Confirm usage/paywall values match backend limits after several renders/uploads/channels

---

## Product Hardening Backlog

These are not blockers for the current Phase 4 exit gate, but they are important for market competitiveness before public launch.

- [ ] Guided faceless creation wizard — project type, content type, source, channel/brand, duration, visual style, caption style, voice, and final generation review
- [ ] Background music — upload/select music bed, per-template default music, volume controls, audio ducking, and render mixing
- [ ] AI image generation provider — adapter-backed image generation for scenes when stock footage is weak or unavailable
- [ ] Rich caption style picker — visual presets closer to high-performing faceless creator workflows
- [ ] Template marketplace/manager — reusable scene structures, intros/outros, caption styles, and brand defaults
- [ ] Better visual style controls — stock, realistic, anime, line-art, dark fantasy, product, finance, etc.
- [ ] Auto-post/scheduling strategy — deferred from MVP, but useful for competitive positioning later

---

## Phase 5 — Localization

Exit gate: User produces 3 language variants from one source project.

- [x] LocalizationGroup and LocalizationLink models
- [x] Translation job — calls translation adapter per scene and title
- [x] TTS re-generation per target language
- [x] Localized variant cards in Variants screen
- [x] Failed language retryable independently

**Phase 5 exit gate passed:** [x]

**Notes:**

- Added `LocalizationGroup` and `LocalizationLink` Eloquent models using the existing localization tables from the base domain migration.
- Added translation provider adapter contract and OpenAI-backed translation adapter with deterministic local fallback when OpenAI is unavailable.
- Added `GenerateLocalizationLinkJob` on the `translation` queue. Each target language runs independently, translates title + scenes, clones the source project, reuses visuals/caption settings, regenerates TTS in the target language, and links the localized project back to the localization group.
- Added authenticated localization API endpoints:
- `GET /api/v1/projects/{projectId}/localizations`
- `POST /api/v1/projects/{projectId}/localizations`
- `POST /api/v1/localization-links/{localizationLinkId}/retry`
- Added localization controls to the Variants screen: target language selection, generate localized versions, localized cards, open localized project, and retry failed language.
- Verified Phase 5 implementation on 2026-04-14:
- PHP syntax passed for localization models, controller, job, translation adapter, provider bindings, and API routes.
- Web production build passed with Vite.
- Docker images rebuilt and `api`, `worker-default`, `worker-generation`, and `horizon` restarted.
- Localization route surfaces confirmed in the rebuilt API container.
- Backend smoke passed: one source project produced three completed localized projects for `es`, `fr`, and `de`.

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
