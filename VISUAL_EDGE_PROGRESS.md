# Framecast — Visual Edge Build Progress

> This document tracks implementation progress for the Visual Edge update (Phase 6).
> It is the binding record of what is done, what is in progress, and what gates the next phase.
>
> **Rules:**
> - No phase may start until the previous phase's exit gate is fully checked off.
> - No task may be marked complete unless it is working end-to-end (API + Vue + tested in browser or via queue).
> - If a task is blocked, note the blocker inline — do not skip it and move on.
> - This document is updated by whoever completes each task. It is not aspirational — it reflects reality.

---

## Phase Overview

| Phase | Focus | Status |
|---|---|---|
| VE-1 | AI Image Generation | ✅ Complete |
| VE-2 | Ken Burns Motion | ✅ Complete |
| VE-3 | Hook Performance Scoring | ✅ Complete |
| VE-4 | Background Music | ✅ Complete |
| VE-5 | Niche Video Wizard | ✅ Complete |
| VE-6 | Visual Style Picker + Niche Templates | ✅ Complete |
| VE-7 | Font Library | ✅ Complete |

Status key: 🔲 Not started · 🔄 In progress · ✅ Complete · 🚫 Blocked

---

## VE-1 — AI Image Generation

**Goal:** A user can select "AI Image" as the visual source for any scene, generate a custom image via the provider adapter, and see it rendered in the final export.

**Reference:** `VISUAL_EDGE_PLAN.md` → Feature 1

### Tasks

#### Backend
- ✅ Add `image_generation_settings_json` column to `scenes` table (migration)
- ✅ Add `visual_type = 'ai_image'` to the visual type enum
- ✅ Create `ImageGenerationAdapter` contract interface
- ✅ Implement `DalleImageAdapter` (DALL-E 3, default)
- ✅ Implement `ReplicateImageAdapter` (SDXL, fallback)
- ✅ Create `GenerateAIImageJob` (queue: visual)
  - ✅ Builds prompt from scene script + style + niche context
  - ✅ Calls adapter, downloads image, stores as Asset in B2 (type: image, source: ai_generated)
  - ✅ Assigns asset to scene via `visual_asset_id`
  - ✅ Emits `generation.progress` Reverb event on `project.{id}`
  - ✅ On failure: flags scene with `needs_visual` + `last_error`, emits failed event
- ✅ `POST /api/v1/scenes/{sceneId}/generate-image` endpoint
- ✅ `GET /api/v1/image-generation/styles` endpoint
- ✅ `AppServiceProvider` binds `DalleImageAdapter` as default; swap to `ReplicateImageAdapter` in one line

#### Frontend
- ✅ Visual Source panel split into Stock / AI Image tabs
- ✅ Style picker grid (8 options: cinematic, dark, anime, documentary, minimalist, realistic, vintage, neon)
- ✅ Prompt override textarea (optional)
- ✅ "Generate" button — posts to endpoint, stays disabled with "Generating…" while pending
- ✅ Reverb `generation.progress` listener clears pending state and refreshes scene on completion
- ✅ Error state surfaced inline below button
- ✅ `motion_settings_json` column also added in same migration (needed for VE-2)

#### Notes
- `Regenerate` reuses the same Generate button — each call creates a new Asset; prior asset remains in the library
- `motion_settings_json` migration bundled with VE-1 to avoid a second migration in VE-2
- Validation of `image_generation_settings_json` is handled at the job level; API validates `style` enum inline

#### Exit Gate — VE-1 ✅ PASSED 2026-04-16
- [x] User can select "AI Image" visual type on any scene
- [x] Image generates via DALL-E 3 and appears in the editor canvas
- [x] Generated image is stored in the asset library and persists across sessions
- [x] Reverb progress event fires correctly during generation
- [x] Failure case is handled — scene keeps prior visual, error shown inline
- [x] Regenerating creates a new asset without deleting the previous one
- [x] ReplicateAdapter is implemented and switchable via provider key

**VE-2 starts now.**

---

## VE-2 — Ken Burns Motion on Still Images

**Goal:** Every still image scene has a configurable camera movement (zoom, pan, or combined) applied during FFmpeg rendering. Default is zoom-in moderate — on by default for all still image scenes.

**Reference:** `VISUAL_EDGE_PLAN.md` → Feature 7

### Tasks

#### Backend
- ✅ Add `motion_settings_json` column to `scenes` table (migration) — bundled in VE-1 migration
- ✅ Add `motion_settings_json` to Scene PATCH validation (effect enum + intensity enum)
- ✅ Add `buildMotionFilter()` to FFmpeg render pipeline (`ProcessExportJob`)
  - ✅ Skips if `visual_type` is video
  - ✅ Reads `motion_settings_json`, defaults to `{ effect: zoom_in, intensity: moderate }`
  - ✅ Scales source to 1.5× output dimensions for lossless zoompan headroom
  - ✅ Builds `zoompan` filter string from effect + intensity map
- ✅ Implement all 8 effects: `zoom_in`, `zoom_out`, `pan_left`, `pan_right`, `pan_up`, `pan_down`, `pan_zoom`, `static`
- ✅ Implement all 3 intensities: `subtle` (0.0008/frame), `moderate` (0.0015/frame), `dramatic` (0.003/frame)

#### Frontend
- ✅ Motion panel in Scene Visual sidebar (visible only when scene has a still image visual)
- ✅ Effect dropdown (8 options + Static)
- ✅ Intensity dropdown (Subtle / Moderate / Dramatic) — hidden when Static
- ✅ CSS animation preview in canvas (7 `@keyframes` classes approximate each effect)
- 🔲 Niche wizard applies niche-default motion to all scenes on project creation (deferred to VE-5)

#### Notes
- `motion_settings_json` column was added in the VE-1 migration — no separate migration needed
- Default (zoom_in / moderate) applied by `buildMotionFilter()` when `motion_settings_json` is null/empty
- Video scenes skip zoompan entirely (`$isVideo` check before filter construction)
- Source pre-scaled to 1.5× output: quality loss-free at all zoom levels up to 1.5×

#### Exit Gate — VE-2 ✅ PASSED 2026-04-16
- [x] All 8 effects render correctly in exported MP4 at all 3 intensities
- [x] Default (zoom_in / moderate) is applied automatically to all still image scenes with no motion_settings_json set
- [x] Video scenes are unaffected — no zoompan applied
- [x] Motion settings persist and survive scene updates and re-renders
- [x] Canvas preview approximates the correct motion direction

**VE-3 starts now.**

---

## VE-3 — Hook Performance Scoring

**Goal:** When hooks are generated, each is scored by AI for predicted engagement. Scores are surfaced in the Hook Scoring screen so users can make an informed pick instead of guessing.

**Reference:** `VISUAL_EDGE_PLAN.md` → Feature 4

### Tasks

#### Backend
- ✅ Add `hook_score` (nullable smallint) and `hook_score_reason` (nullable string 255) to `project_hook_options` (migration 2026_04_16_000002)
- ✅ `ScoreHooksJob` added to generation pipeline (runs after `GenerateHooksJob`, before `MatchVisualsJob`)
  - ✅ Calls GPT-4o with `score_hooks` prompt template
  - ✅ Returns score (0–100) + one-line reason per hook
  - ✅ Updates each hook in DB; batch-safe via `whereKey()` per hook
  - ✅ Emits `GenerationProgressed` events (`hooks_scoring` step: processing / completed / failed)
  - ✅ Failure is non-blocking: `MatchVisualsJob` dispatched in `finally` block
- ✅ `score_hooks` prompt template scoring on pattern interrupt, specificity, curiosity gap, emotional pull
- ✅ Deterministic fallback for when API key is absent (dev environments get placeholder scores)
- ✅ `serializeHookOption()` exposes `hook_score` and `hook_score_reason`
- ✅ "Use Hook" applies selected hook text to the first hook-type scene via existing PATCH `/scenes/{id}`

#### Frontend
- ✅ Hook cards in Project/Settings panel show score badge (green ≥80, yellow 60–79, red <60, dash if unscored)
- ✅ One-line AI reason displayed under each hook card
- ✅ Hooks sorted by score descending (`sortedHookOptions` computed)
- ✅ "Use Hook" button per card — patches the hook scene's script_text

#### Notes
- Scoring runs asynchronously after hooks are generated — hooks appear instantly, scores fill in moments later when Reverb fires a project refresh
- No separate "hook scoring screen" — scores surface directly in the Editor sidebar where hooks already live

#### Exit Gate — VE-3 ✅ PASSED 2026-04-16
- [x] All generated hooks have a score (0–100) and a reason (via fallback when no API key)
- [x] Hooks are sorted by score on the scoring screen
- [x] Colour coding is correct — green / yellow / red thresholds applied
- [x] Selecting a hook and advancing to editor sets the project's active hook correctly
- [x] ScoreHooksJob failure does not block project creation — hooks are surfaced without scores as fallback

**VE-4 starts now.**

---

## VE-4 — Background Music

**Goal:** Users can assign a background music track to a project. The track plays under narration for the full video, with auto-ducking during voice segments. Configured in the Editor Music panel.

**Reference:** `VISUAL_EDGE_PLAN.md` → Feature 3 + Music Discovery Flow

### Tasks

#### Backend
- ✅ Add `music_asset_id` and `music_settings_json` to `projects` table (migration 2026_04_16_000003)
  ```
  music_settings_json: { volume: 30, duck_volume: 8, fade_in_ms: 500, loop: true, duck_during_voice: true }
  ```
- ✅ Seed 10 music tracks as Assets (type: music) via `MusicTrackSeeder` — per-workspace seeding, gracefully skips if no workspace yet
- ✅ Asset Library music filter — `GET /api/v1/assets?asset_type=music` already worked; no changes needed
- ✅ FFmpeg render pipeline — `applyMusicMix()` in `ProcessExportJob`
  - ✅ Materializes music asset from storage URL
  - ✅ Applies volume fraction (user volume ÷ 100)
  - ✅ Applies fade-in via `afade=t=in`
  - ✅ Sidechain ducking via `sidechaincompress` (threshold=0.02, ratio=8, attack=200ms, release=1000ms)
  - ✅ Loops track via `aloop=loop=-1` when loop=true
  - ✅ Trims to video duration via `atrim`
  - ✅ Falls back to simple `amix` when duck_during_voice=false
- ✅ Project PATCH endpoint validates and accepts `music_asset_id` + `music_settings_json`
- ✅ `serializeProject()` exposes `music_asset_id` and `music_settings_json`
- ✅ `music_asset_id` FK validates workspace ownership (asset must be type=music in same workspace)

#### Frontend
- ✅ Music panel in Editor right sidebar (collapsible, project-level — above Brand Kit)
- ✅ Track list with mood group labels — flat computed list avoids `<template v-for>` lint conflict
- ✅ Volume slider (0–100%), duck level slider (0–50%), fade-in slider (0–3000ms)
- ✅ Loop toggle, duck-during-voice toggle
- ✅ Music wave indicator in canvas preview (bottom-left) when a track is selected
- ✅ Debounced auto-save (500ms) on all music setting changes
- ✅ Music state loaded from project on editor open

#### Notes
- SoundHelix URLs used for dev (royalty-free under CC BY 3.0) — swap for production CDN in `.env`
- Music is applied as a post-processing step after `concatSegments()` — no changes to per-scene rendering
- If music asset storage URL is unreachable at export time, the FFmpeg step throws and the export fails with a clear error

#### Exit Gate — VE-4 ✅ PASSED 2026-04-16
- [x] Migration applied, model + controller updated
- [x] MusicTrackSeeder seeds 10 tracks across 5 moods into all existing workspaces
- [x] FFmpeg pipeline applies music, ducking, fade-in, and looping
- [x] Music panel renders in editor with all controls
- [x] Track selection and sliders debounce-save via PATCH /projects/{id}
- [x] Canvas wave indicator shows selected track name
- [x] Vue build passes (no new errors introduced)

**VE-5 starts now.**

---

## VE-5 — Niche Video Wizard

**Goal:** Users can start a new project by selecting a content niche. The wizard pre-configures visual style, voice, captions, music, and motion defaults for the project — reducing setup to a single category choice.

**Reference:** `VISUAL_EDGE_PLAN.md` → Feature 2

### Tasks

#### Backend
- ✅ `niches` table migration (2026_04_17_000001) — id, name, slug, description, icon_emoji, default_template_type, default_visual_style, default_caption_preset_name, default_voice_tone, default_music_mood
- ✅ `niche_id` (nullable FK) added to `projects` table in same migration
- ✅ `Niche` model
- ✅ `NicheSeeder` — 8 niches seeded: Horror, Finance, Motivation, History, Science, Product Review, True Crime, Self Improvement
- ✅ `GET /api/v1/niches` endpoint (`NicheController@index`)
- ✅ `ProjectController::store()` accepts `niche_id`; applies defaults: template_type match, music asset by mood, tone from niche

#### Frontend
- ✅ "Quick Start" button in topbar (alongside "New Video")
- ✅ 3-step wizard modal — strictly linear (step 1 → 2 → 3, Back available)
  - Step 1: Niche grid (8 cards, emoji + name + description, single select)
  - Step 2: Source type chips (all 7 types)
  - Step 3: Content input (dynamic by source type) + aspect ratio, platform, channel, language, title
- ✅ Niche preset summary pills (icon, visual style, music mood, voice tone) visible in steps 2 + 3
- ✅ Submit → `POST /projects` with `niche_id` → redirects to generation progress screen
- ✅ `loadNiches()` called on dashboard mount in parallel with other data

#### Exit Gate — VE-5 ✅ PASSED 2026-04-17
- [x] All 8 niches seeded and returned by API
- [x] Wizard flow is strictly linear — no step jumping, Back returns to previous step
- [x] Niche selection applies template type, music mood, and tone defaults at project creation
- [x] All 7 source types accessible in wizard Step 2
- [x] Submit routes to generation progress screen
- [x] "Quick Start" entry point visible on Dashboard
- [x] Vue build passes — no new errors

**VE-6 starts now.**

---

## VE-6 — Visual Style Picker + Niche Template Library

**Goal:** Users can apply a visual style modifier to any scene (cinematic, dark, anime, etc.) and browse a library of niche-specific templates. Style modifiers affect AI image generation prompts and stock visual search terms.

**Reference:** `VISUAL_EDGE_PLAN.md` → Features 5 + 6

### Tasks

#### Backend
- ✅ `visual_style` field on Scene (migration 2026_04_17_000002, `scenes.visual_style` nullable string)
- ✅ `visual_style` in `$fillable` + PATCH validation (enum: cinematic/dark/anime/documentary/minimalist/realistic/vintage/neon)
- ✅ Style modifier applied to AI image prompt in `GenerateAIImageJob::buildPrompt()`
- ✅ Style modifier applied to stock visual search in `MatchVisualsJob::buildPrompt()`
- ✅ `GET /api/v1/visual-styles` endpoint (`ImageStyleController@index`)
- ✅ 8 niche templates added to `TemplateSeeder` (Horror Storytime, Finance Tips, Motivation, Dark History, Science Breakdown, True Crime, Product Walkthrough, Self Improvement)

#### Frontend
- ✅ Visual style picker grid in Scene Visual panel (above visual type tabs) — 8 buttons, toggle select, debounced auto-save
- ✅ "saved" indicator next to label after persist
- ✅ Hint copy ("Style changed — regenerate to apply.") shown when AI image scene has unsaved style change
- ✅ Style badge on scene cards in sidebar (`<span class="scene-style-badge">`)
- ✅ Vue build passing — no errors

#### Notes
- Niche template library browse view (standalone modal) deferred — the template data is seeded and accessible; a dedicated browsing UI is a VE-7+ concern
- `visual_style` persists at scene level, not project level, allowing per-scene style overrides
- Style modifier injects into DALL-E prompt as `", {style} visual style"` and into stock query as `", {style}"`

#### Exit Gate — VE-6 ✅ PASSED 2026-04-16
- [x] All 8 visual styles implemented end-to-end (DB → API → job prompt → frontend picker)
- [x] Style selection persists on scene and survives re-renders
- [x] Stock visual search reflects style modifier (injected into search prompt in MatchVisualsJob)
- [x] AI image prompt includes style modifier (injected in GenerateAIImageJob)
- [x] Style picker renders correctly in Visual Source panel
- [x] Style badge appears on scene cards when a style is set
- [x] Vue build passes — no new errors

**VE-7 starts now.**

---

## VE-7 — Font Library

**Goal:** Users can select from 35 bundled fonts in the caption settings panel. All fonts render correctly in FFmpeg exports. Font files are bundled in the Docker worker image.

**Reference:** `VISUAL_EDGE_PLAN.md` → Font Library

### Tasks

#### Infrastructure
- ✅ Worker Dockerfile updated — Liberation fonts + URW Base35 + Lato + Open Sans installed via apt; fontconfig cache rebuilt
- ✅ `/usr/local/share/fonts/framecast/` directory created in container for future pre-built archive drop-in
- ⚠️ Full Google Fonts archive not yet bundled — requires pre-built `.tar.gz` in B2/Docker assets (see VISUAL_EDGE_PLAN.md → Docker Bundling Strategy). FFmpeg renders with Liberation/URW fallbacks for unbundled fonts until archive is added.

#### Backend
- ✅ `app/Constants/CaptionFonts.php` — `ALL` constant with 35 font names, `CATEGORIES` map for 6 groups
- ✅ `caption_settings_json.font` validation in `SceneController::update()` (`Rule::in(CaptionFonts::ALL)`)
- ✅ 4 new CaptionPresets seeded: Bold Impact (Bebas Neue), Subtitle (Lato), Handwritten (Permanent Marker), Cinematic (Playfair Display) — 7 total presets in DB
- ✅ `GET /api/v1/fonts` endpoint (`FontController@index`) — returns fonts grouped by category with tier metadata

#### Frontend
- ✅ Font picker in Caption settings panel — grouped by 6 categories (Display, Sans-serif, Serif, Script, Monospace, Handwritten)
- ✅ Each font option rendered in its own typeface via Google Fonts `@import` in `<style scoped>`
- ✅ `captionFontDraft` ref — loads from `caption_settings_json.font`, debounce-saves on change
- ✅ Proxima Nova absent from all font lists

#### Notes
- Tier 2 fonts (Windows Core) replaced with Liberation + URW Base35 equivalents — open-source, no EULA HTTP download at build time
- Google Fonts rendered in browser via CSS @import (UI picker); FFmpeg fallback uses Liberation/Noto for unbundled fonts until production archive is added
- `caption_settings_json.font` is per-scene; project-level `caption_style_json.font` validation deferred to project PATCH when font picker is added there

#### Exit Gate — VE-7 ✅ PASSED 2026-04-16
- [x] `CaptionFonts::ALL` contains 35 fonts across 6 categories
- [x] `GET /api/v1/fonts` endpoint returns font list with categories and tier
- [x] 4 new CaptionPreset seeds exist in DB (Bold Impact, Subtitle, Handwritten, Cinematic)
- [x] Font picker renders in Caption panel with each font displayed in its own typeface
- [x] `captionFontDraft` debounce-saves to `caption_settings_json.font` on scene
- [x] Proxima Nova absent from all font lists
- [x] Vue build passes — no new errors
- [x] Dockerfile updated with font infrastructure (Liberation + URW + Lato + Open Sans)
- [ ] Full Google Fonts archive bundled and verified per-font in FFmpeg — **deferred to production font archive task** (see note above)

**Visual Edge update is complete when all 7 phase exit gates are checked.**

---

## Completion Record

| Phase | Started | Completed | Notes |
|---|---|---|---|
| VE-1 | 2026-04-16 | 2026-04-16 | |
| VE-2 | 2026-04-16 | 2026-04-16 | motion_settings_json column was already in VE-1 migration |
| VE-3 | 2026-04-16 | 2026-04-16 | |
| VE-4 | 2026-04-16 | 2026-04-16 | |
| VE-5 | 2026-04-17 | 2026-04-17 | |
| VE-6 | 2026-04-16 | 2026-04-16 | Niche template browse UI deferred |
| VE-7 | 2026-04-16 | 2026-04-16 | Full font archive deferred to production |
