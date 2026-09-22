# Editor review — 22 September 2026

Scope: static review of the current local editor, scene endpoints and export pipeline, including pending animation/pacing changes. This is not a production UI walkthrough or an exhaustive security audit. No customer media was modified and no cross-workspace access was attempted. Findings below are code-confirmed paths; exploitation and browser reproduction are not claimed.

## What is offered

- Scene selection, ordering, addition, duplication and deletion; scene and full-video preview.
- Script editing, AI rewrites, hooks and Cruise conversational editing.
- Stock/library visual replacement, AI images, characters, animation, animation history and original-still restoration.
- Voice selection/settings, regeneration, uploaded/recorded audio, voice cloning and bulk rerecording.
- Captions with styles, fonts, colors, positioning, presets and bulk application; UGC headlines.
- Image motion, music selection/generation, audio levels, scene sound effects and waveform presentation.
- Export in multiple aspect ratios, completed-export actions, download, scheduling, approval and public sharing.
- Pending local work adds non-looping clip endings, preview synchronization, short-clip warnings and explicit wizard animation pacing.

The editor is a scene-based finishing tool. A timeline does not by itself provide frame-accurate trimming, arbitrary multi-track composition or general-purpose undo. Those should be treated as separate product decisions, not implied existing capabilities.

## Findings in priority order

### 1. High: narration asset ownership is not validated on scene updates

`SceneController::update` accepts `voice_settings_json` as an arbitrary array. It scopes `visual_asset_id`, `sound_asset_id` and `character_id`, but does not scope nested `voice_settings_json.audio_asset_id`. `RenderSceneSegmentJob::handle` loads that audio using an unscoped `Asset::find`.

A caller permitted to update their own scene can submit an audio ID outside its workspace. The downstream rendering path can consume that asset. This is a potential cross-workspace media disclosure path; no exploit was performed.

Fix: allowlist nested voice fields, validate referenced audio ownership/type and voice permissions, and repeat workspace checks when consuming assets in render/preview jobs. Add two-workspace regression tests, including direct HTTP requests.

### 2. High: an edited script can export with the old narration

The editor sets `is_outdated` when a script or voice changes, but `exportBlockerMessage` only checks that an audio ID exists. The manual export controller and `ProjectExportService::assertExportable` likewise do not reject stale voice. The renderer uses current script text for captions and the existing audio file for narration.

Result: clicking Update video can produce new captions with old spoken words. The stale-export warning solves an older-file problem; it does not repair stale source audio.

Fix: expose a preflight list of stale narration/animation, offer explicitly priced rerecording, and block export server-side until dependencies match or an explicit supported override is chosen. Changing a script through the API must mark dependent media stale on the server, not rely on browser flags.

### 3. High: exports read mutable scenes rather than a frozen revision

`ProcessExportJob` loads the current scenes and schedules segment jobs by ID. Each `RenderSceneSegmentJob` later reloads the scene and project. The editor remains editable during export. `source_fingerprint` records a comparison hash, not the content required to reproduce the render.

Result: edits during a queued/parallel export can mix revisions, change music between jobs or remove a scene before its segment starts. The freshness warning can detect subsequent differences, but cannot guarantee a consistent file.

Fix: capture immutable scene/project settings and asset references for each export; have all segments and concatenation read that revision. Keep the source fingerprint tied to the same snapshot. Test edit/delete/reorder and music changes after enqueueing.

### 4. Medium: the frontend blocks silent content accepted by the backend

`exportBlockerMessage` requires a script and voice for every scene. The backend explicitly allows visual/headline-only silent scenes and only requires narration audio when script text exists.

Result: supported silent cards or silent visual scenes can be impossible to export through the editor even though the server can render them. Whole-video takes also need their own preflight semantics because picture and dialogue are baked into the asset.

Fix: share explicit scene capability rules and cover narrated, silent, waveform, headline and whole-video cases in frontend/backend tests.

### 5. Medium: scene duration can save successfully without controlling playback/export

`saveSceneDuration` patches `duration_seconds`; preview `sceneDuration` prioritizes audio asset duration. The renderer prioritizes audio duration and probes the actual audio file. Changing the scene length therefore does not shorten or extend a narrated scene as a user may expect. Save errors are silently swallowed.

Fix: label narrated scene length as controlled by narration, explain how to shorten it, and separate visual trim/hold from spoken duration. Show save failures. If manual timing is supported, define audio trim/stretch behavior explicitly and use identical timing rules for preview and export.

### 6. Medium: navigation can discard pending edits

The browser `beforeunload` guard covers script, voice and captions only. There is no Vue route-leave guard in the editor. `onBeforeUnmount` clears pending save timers without flushing them.

Result: leaving via in-app navigation during the debounce interval can discard an edit; other pending setting saves have inconsistent protection. A browser unload listener does not intercept SPA navigation.

Fix: one save coordinator for all editor fields, route-leave flush/confirmation, visible failed-save state and guarded refresh/close behavior. Test navigation before debounce and during a failed request.

### 7. Medium: manual export quota reservation differs from automatic export

The automatic service locks the workspace and considers queued/processing exports. The manual controller checks remaining completed-export allowance and creates jobs without the corresponding workspace lock/in-flight reservation.

Result: concurrent requests or tabs can queue more exports than the remaining allowance. Disabling the current browser's export button is insufficient.

Fix: route all export creation through one transactional quota/reservation service and add concurrent-request tests.

### 8. Medium: whole-video export bypasses the guarded service path

The manual controller directly returns a completed export pointing at the original asset for `one_shot`/`restyle`. That branch sets `watermark_enabled=false`, bypassing the free-plan watermark refusal present in `ProjectExportService`. It also means scene composition changes and requested output ratios are not rendered into this original file.

Result: downgraded accounts may receive a non-watermarked original, and controls can imply changes that this path will not apply.

Fix: unify authorization and export paths. Either restrict whole-video editing to genuinely supported actions and clearly label original-file delivery, or implement a composition path that preserves native dialogue. Do not silently claim a revised export while returning the unchanged source.

## Suggested repair order

1. Scope narration assets and protect export authorization.
2. Add unified export preflight: missing, outdated and currently generating dependencies; silent-content support.
3. Snapshot exports and reserve quota in the same service.
4. Make save/navigation behavior reliable.
5. Clarify duration and whole-video editing controls; then evaluate trimming/splitting and broader undo as product additions.

## Verification boundary

This review did not run new tests or make implementation changes. The earlier successful animation tests and frontend build validate that separate change only; they do not establish that the gaps above are fixed. A repair should include targeted regression tests and a browser walkthrough of narrated, silent and whole-video projects.

## Repair status — local implementation

The findings above describe the pre-repair code. The current local changes add scoped narration assignment and export asset consumption, server-side stale-voice marking, matching silent/narrated preflight checks, immutable render settings/assets for new standard exports, shared transactional quota checks, route-leave save protection, duration-save errors and narration-controlled duration UI. Whole-video takes now show a review-only editor screen, reject scene-setting updates and enforce original-ratio/language delivery and watermark eligibility through the shared export service.

Validation: 35 targeted backend tests (122 assertions), 36 frontend tests and the production frontend build passed. New tests cover source changes/deletion after export creation, foreign narration, stale narration, silent content, quota reservation, whole-video restrictions and duration editing. SQLite tests cover reservation logic, not a simultaneous multi-process PostgreSQL load test. A complete browser walkthrough is still advisable before release.

Deployment prerequisite: apply `2026_09_22_090000_add_export_render_snapshot` before new exports run on the updated workers. It adds a nullable column and does not rewrite old files. Previously queued exports without a snapshot retain the legacy read path; existing completed exports are unchanged. Deployment status must be verified separately.
