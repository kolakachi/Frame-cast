# Phase 1 — full creation settings through the API and MCP

25 September 2026 · First of the four expansion phases that gate customer
outreach (tracker: [api-access-todo.md](api-access-todo.md)). Baseline `4812812`.

Goal: an assistant can make any standard video the New Video flow can, with
the same validation, the same estimate and one create. The assistant may
choose options itself from the lookups; the quote shows what it chose.

## Lookups (new, read-only, all workspace-scoped)

| Endpoint / tool | Returns |
|---|---|
| `GET /options` · `get_options` | Static option keys with labels: visual styles (20 keys from `ImageStyleDescriptors::META`), animate tiers, pacing, aspect ratios, platform targets (`tiktok`, `youtube`, `youtube_shorts`, `instagram_reels`, `instagram_post`, `facebook`), languages (`en es fr de it pt ar hi ja zh`), source types, visual modes, tones (free text, examples), audiogram settings. Replaces the ad-hoc lists in capabilities. |
| `GET /brand-kits` · `list_brand_kits` | id, name, colours, fonts, default caption style, default voice. |
| `GET /channels` · `list_channels` | id, name, description, default language, platform targets, default voice, default caption preset, brand kit, status. |
| `GET /niches` · `list_niches` | id, name, description, defaults (template type, visual style, caption preset, tone, music mood). |
| `GET /caption-presets` · `list_caption_presets` | id, name, preset type, font, highlight mode and colours, position, animation; catalogue and workspace. |
| `GET /characters` · `list_characters` | id, name, description, gender, age group, situations, status, scenes count, thumbnail. Active only. Selecting one is phase 1; creating one is phase 2. |
| `GET /library?type=image\|music` · `list_library` | Library assets by type: id, title, type, duration, thumbnail. Paginated, 50 per page. |

Voices already exist (`GET /voices`). Templates have no listing route in the
app; they are chosen through a channel's allowed templates or a niche's
default, so the API does the same and never takes a template id directly.

## Quote request, widened

Everything `ProjectController::store` validates, with the API's names:

| Field | Values | Validation |
|---|---|---|
| `source_type` | `prompt`, `script`, `url`, `product_description`, `images` | Same source-content rules as the dashboard (url needs a URL or 50+ chars; images need 1–15 library image ids). Upload sources stay out. |
| `content` | text or URL | as today |
| `image_asset_ids` | list of library image ids | `images` only; must be this workspace's image assets |
| `visual_mode` | `stock`, `ai_images`, `ai_video`, `waveform` | `waveform` unlocks `audiogram` |
| `visual_style` | one of the 20 style keys | AI modes only |
| `custom_visual_style` | free text ≤500 | AI modes only |
| `animate_tier`, `animation_pacing` | as today | `ai_video` only |
| `audiogram` | `{ style, color, bg }` | `waveform` only; strings, same limits as the dashboard |
| `duration_seconds`, `aspect_ratio`, `tone`, `title`, `content_goal` | as today | |
| `voice_id` | from `/voices` | as today |
| `brand_kit_id`, `channel_id`, `niche_id`, `character_id`, `caption_preset_id` | ids from the lookups | owned by this workspace (or catalogue where the dashboard allows); channel/template conflicts refused like the dashboard |
| `music_asset_id` | library music id | owned |
| `languages` | list, first is primary | from the option list |
| `platform_target` | option key | |
| `allow_script_edit` | boolean | |

Defaults resolve exactly as the dashboard's create does (channel → brand kit,
niche → tone/style/music/template, series untouched), because the same
service does it. The quote response echoes the resolved choices — voice,
brand kit, channel, niche, character, caption preset, music — by name, so
the assistant can show the user what will be made.

`caption_preset_id` is new to creation: today the dashboard applies a preset
in the editor. Phase 1 stores it as the project's default caption settings
so every scene starts from it. Confirm this is wanted.

## Not in phase 1

Uploads (audio, video, PDF, new images), series episodes, one-shot UGC
(phase 3), creating characters (phase 2), any editing after create (phase 4).

## Estimate

Estimate is unchanged: the cost drivers are visual mode, animation tier,
voice engine and scene count. Character reference generation adds per-scene
cost in some modes; phase 1 prices that the way the dashboard's create does
today, which is not at all — the job charges it. Flagged as a known
under-quote to fix with the mid-pipeline reservation.

## Tests

Each lookup: workspace-scoped, catalogue included where the dashboard does,
inactive excluded. Quote: every id validated for ownership (foreign → 422),
mode-conditional fields refused out of mode, defaults resolved from channel
and niche match the dashboard, a create carries every setting onto the
project. MCP: tools listed, `estimate_video` accepts the widened schema.

## Effort

About three days: lookups and validation one, quote widening and resolution
echo one, MCP schema, docs, schema and tests one.
