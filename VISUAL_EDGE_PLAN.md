# Framecast — Visual Edge Update Plan

**Created:** 2026-04-16
**Author:** Review of storyshort.png vs current Framecast implementation
**Status:** Planning — not yet started

---

## Why This Plan Exists

After reviewing StoryShort's feature set against the current Framecast implementation, there is a clear competitive gap. Framecast generates high-quality scripted videos but relies entirely on stock footage matching for visuals. Competitors are shipping AI-generated images per scene, niche-specific creation flows, background music beds, and hook scoring — all of which directly affect the output quality and speed-to-publish that operators care about.

The current spec covers these features in the Product Hardening Backlog but does not sequence or scope them. This document does that.

---

## Gap Analysis

| Dimension | Framecast Today | Competitor Edge |
|---|---|---|
| Visuals | Stock footage/image matching | AI-generated images per scene — custom, on-brand, no licensing concern |
| Creation entry | Generic prompt/script/URL | Niche wizard — category → style → auto-configured defaults |
| Background audio | Not implemented | Music bed per video, ducked under narration |
| Hook selection | User picks from list | Engagement score per hook (AI-rated), sorted by predicted performance |
| Visual style | No per-scene control | Style modifiers: cinematic, dark, anime, minimalist, documentary |
| Template depth | Explainer + Listicle (2) | Niche templates: horror, finance, motivation, history, science, product |

The biggest single gap is **AI image generation**. Everything else is table stakes for operator-level workflows. If two videos are compared side-by-side, custom generated images for the horror/motivation/history niches will win over stock footage every time.

---

## Scope of This Update

This update covers **Phase 6 — Visual Edge**. It is additive — it does not break existing flows. All new capabilities are opt-in at the scene or project level.

### Features in Scope

1. **AI Image Generation per scene** — highest priority
2. **Niche video wizard** — high priority
3. **Background music** — high priority
4. **Hook performance scoring** — medium priority
5. **Visual style picker** — medium priority (unlocks AI gen styles + stock style filters)
6. **Niche template library** — lower priority, depends on wizard being shipped first
7. **Ken Burns motion on still images** — medium priority, high perceived value

### Features Explicitly Out of Scope

- Social publishing / scheduler
- Team collaboration (Phase 4 Agency spec already covers this — do not conflate)
- Timeline/frame-level editor
- Any feature that requires a new billing tier to ship (we implement, billing wires up later)

---

## Feature Specifications

---

### Feature 1 — AI Image Generation

**What it does:** Generates a custom image for a scene via an image generation provider (Replicate/DALL-E/Stable Diffusion). The generated image becomes the scene's visual asset, stored in B2 and treated identically to a matched stock image.

**Why:** Stock footage for abstract topics (horror, motivation, finance, history) is generic. AI-generated images are on-topic, on-brand, and never licensed. This closes the quality gap vs StoryShort immediately.

**How it fits the existing model:**

- Scene already has `visual_type`, `visual_asset_id`, `visual_prompt`
- Add `visual_type = 'ai_image'` as a new valid enum value
- Add `image_generation_settings_json` on Scene for style modifiers
- `GenerateAIImageJob` follows the same pattern as `MatchVisualsJob`
- Output is stored as an Asset (type: `image`) in the workspace asset library — reusable

**Data model changes:**

```
scenes table
  + image_generation_settings_json (jsonb, nullable)
    {
      "style": "cinematic|dark|anime|documentary|minimalist|realistic",
      "negative_prompt": "",
      "aspect_ratio": "9:16|1:1|16:9",
      "provider_key": "replicate|dalle|stability",
      "generation_seed": null
    }
```

**New job:**

```
GenerateAIImageJob
  queue: visual
  input: scene_id, prompt, style, aspect_ratio
  output: Asset record (type: image, source: ai_generated), assigned to scene
  fallback: if generation fails → leave visual_type as current, mark scene with needs_visual flag
```

**New provider adapter:**

```
ImageGenerationAdapter (contract)
  generate(prompt, style, aspect_ratio, options) → { url, width, height, provider_key, seed }

Providers:
  - ReplicateAdapter (Stable Diffusion SDXL) — default
  - DalleAdapter (DALL-E 3) — quality tier
  - StabilityAdapter (Stability AI) — fallback
```

**API endpoints:**

```
POST /api/v1/scenes/{sceneId}/generate-image
  body: { style?, negative_prompt? }
  response: { job_id, status: "queued" }
  Reverb: generation.progress on project.{id}

GET /api/v1/image-generation/styles
  response: list of available style presets
```

**Editor UI changes:**

- In the Scene Visual panel, add a new option alongside `stock_video / stock_image / upload`:
  `AI Image` — when selected, shows style picker + "Generate" button
- Generation is async — shows spinner on scene card, updates via Reverb
- After generation: image renders in preview, user can regenerate with different style
- Regenerating creates a new Asset but keeps the old one in the asset library

**Queue:** Uses existing `visual` queue. No new queue needed.

---

### Feature 2 — Niche Video Wizard

**What it does:** An alternative entry point on the dashboard. Instead of starting from blank (pick source type), the user picks a niche category first. The wizard pre-configures template, visual style, voice, caption style, and music bed defaults.

**Why:** Operators running niche channels (e.g. horror storytime, finance tips, dark history) should not have to re-configure the same defaults every session. The wizard collapses 5 decisions into 1.

**Niches (initial set):**

| Niche | Template | Default Visual Style | Caption Style | Voice Tone |
|---|---|---|---|---|
| Horror / Dark Stories | Listicle | Dark, cinematic | White bold, word highlight | Deep male |
| Finance / Money | Explainer | Documentary, clean | Clean subtitle | Authoritative |
| Motivation / Mindset | Explainer | Cinematic, warm | Bold overlay | Energetic |
| History / Facts | Listicle | Documentary | Subtitle | Neutral male |
| Science / Explainer | Explainer | Minimalist | Clean subtitle | Clear female |
| Product Review | Explainer | Realistic | Standard | Friendly |
| True Crime | Listicle | Dark, gritty | Bold, high contrast | Dramatic |
| Self Improvement | Explainer | Warm, minimal | Soft subtitle | Calm female |

**Data model changes:**

```
niches table (seed data only — not user-editable in MVP)
  id
  name
  slug
  description
  default_template_slug
  default_visual_style
  default_caption_preset_slug
  default_voice_tone (maps to voice_profile tags)
  default_music_mood (maps to music asset tags)
  icon_emoji
  created_at

No migration change needed on projects — niche_id added as nullable foreign key:
projects table
  + niche_id (bigint, nullable, FK niches.id)
```

**API endpoints:**

```
GET /api/v1/niches
  response: list of niches with defaults

POST /api/v1/projects (extended)
  body: { ..., niche_id? }
  behaviour: if niche_id present, prefill template/voice/caption/music from niche defaults
             user can still override at creation or in editor
```

**Wizard UI flow:**

```
Dashboard → "Start from Niche" button (alongside "New Video")
  Step 1: Pick niche (grid of niche cards with emoji + name + short desc)
  Step 2: Pick source type (same 7 types as current modal, but pre-filtered for niche)
  Step 3: Enter content (same panels as current modal)
  → Submit → generation flow (unchanged)
```

The wizard is a new modal with 3 steps. Steps 2 and 3 reuse existing panels — no duplication.

---

### Feature 3 — Background Music

**What it does:** Allows a user to select a music bed for the full video. The music plays under the narration, ducked during speech, and fades out at the end. Mixed at FFmpeg render time.

**Why:** Short-form faceless content almost always has background music. Without it, videos feel flat compared to competitors who include it as a default.

**How it fits:**

- Music assets stored in Asset Library (type: `music`)
- Users can upload their own or select from a seeded royalty-free library
- Music selection is per-project (not per-scene)
- Volume and ducking configured at project level

**Data model changes:**

```
projects table
  + music_asset_id (bigint, nullable, FK assets.id)
  + music_settings_json (jsonb, nullable)
    {
      "volume": 0.3,          // 0.0–1.0, default 0.3
      "duck_during_voice": true,
      "duck_volume": 0.08,    // volume during speech
      "fade_in_ms": 500,
      "fade_out_ms": 1000,
      "loop": true
    }
```

**Seeded music library:**

- 10–20 royalty-free tracks seeded in the asset library as workspace-agnostic global assets
- Tagged by mood: dark, upbeat, calm, dramatic, corporate, epic
- Niche defaults map to a mood tag

**FFmpeg changes:**

```
ProcessExportJob (extended)
  - If music_asset_id present: download music file from B2
  - Mix music track under narration audio using amix filter
  - Apply volume envelope: full volume during silence, duck_volume during TTS
  - Fade in/out at video boundaries
  - Loop music if shorter than video duration
```

**API endpoints:**

```
PATCH /api/v1/projects/{projectId}/music
  body: { music_asset_id?, music_settings_json? }

GET /api/v1/assets?type=music&global=true
  response: global music library (royalty-free seeds + user uploads)
```

**Editor UI:**

- New "Music" section in the right panel of the editor (below Captions)
- Music selector: shows global library + uploaded music assets
- Volume slider (0–100%), duck toggle, fade controls
- Preview button plays a short clip of selected track

---

### Feature 4 — Hook Performance Scoring

**What it does:** When hook options are generated, each hook receives an AI-predicted engagement score (0–100). Hooks are displayed sorted by score. The highest-scoring hook is selected by default.

**Why:** Currently users choose hooks manually from a list with no signal. Scoring removes a decision and surfaces the best hook automatically — reducing time-to-first-publish.

**Data model changes:**

```
project_hook_options (jsonb column on projects, existing field)
  Each hook option extended with:
  {
    "text": "...",
    "score": 87,              // 0–100, AI-predicted engagement
    "score_reason": "..."     // one-line explanation
  }
```

**Job changes:**

```
GenerateHooksJob (extended)
  After generating hook options: pass all hooks to AI generation adapter
  with prompt template: hook_score
  Store scores on each hook option before saving to project
```

**New prompt template:** `hook_score`
```
Input: list of hook texts, niche/topic context
Output: JSON array with score (0–100) and one-line reason per hook
```

**UI changes:**

- Hook options in the generation flow show a score badge (e.g. "87")
- Hooks sorted highest-to-lowest by default
- Score reason shown as tooltip on hover
- No separate screen — this is an enhancement to the existing hook selection step

---

### Feature 5 — Visual Style Picker

**What it does:** Per-scene control over the visual approach: stock video, stock image, AI image, or uploaded asset. When AI image is selected, a style modifier is shown. When stock is selected, a mood/style filter (cinematic, documentary, etc.) narrows the search.

**This is already partially implicit in the existing visual_type field.** The change is surfacing it clearly in the UI and wiring it to AI image generation.

**Visual type options per scene:**

| Type | Description | Notes |
|---|---|---|
| `stock_video` | Current default — Pexels/Storyblocks | Unchanged |
| `stock_image` | Static image from stock | Unchanged |
| `ai_image` | Generated via image generation adapter | New — Feature 1 |
| `upload` | User-uploaded asset from library | Unchanged |

**Style modifiers (for ai_image and stock filtering):**

`cinematic`, `dark`, `anime`, `documentary`, `minimalist`, `realistic`, `warm`, `epic`

These map to:
- For AI image: injected into the generation prompt
- For stock: used as search keyword modifiers (e.g. append "cinematic" to Pexels query)

**No new data model changes needed** — `image_generation_settings_json.style` covers AI gen; `visual_prompt` can carry style modifier for stock.

---

### Feature 6 — Niche Template Library

**What it does:** Extends the current 2-template system (Explainer, Listicle) with 8 niche-specific templates. Each template defines scene structure (intro hook, body count, outro CTA), default visual style, and caption style.

**This depends on Feature 2 (Niche Wizard) being shipped first.**

**Templates to add:**

| Name | Scene Structure | Notes |
|---|---|---|
| Horror Storytime | Hook + 5–8 body scenes + twist outro | Dark visual style default |
| Finance Tips | Hook + 3–5 tip scenes + CTA | Documentary visual |
| Motivation | Quote hook + 4–6 insight scenes + call-to-action | Warm visual |
| Dark History | Hook + 4–7 fact scenes + conclusion | Documentary/dark |
| Science Breakdown | Hook + 5 explanation scenes + summary | Minimalist |
| True Crime | Intro + case scenes + resolution | Cinematic dark |
| Product Walkthrough | Hook + feature scenes + CTA | Realistic |
| Self Improvement | Hook + habit scenes + challenge CTA | Warm minimal |

**Data model:** Uses existing `templates` table and `scene_structure_json`. Seeded via migration. No schema change.

---

## Implementation Order

Build in this order. Each feature is independently shippable — do not wait for all 6 to complete.

```
Priority 1 (ship first — closes biggest gap):
  [1] AI Image Generation (adapter + job + editor UI)
  [2] Visual Style Picker (depends on [1], minimal extra work)

Priority 2 (ships second — creates the niche flywheel):
  [3] Niche Template Library (seed data only)
  [4] Niche Video Wizard (depends on [3])
  [5] Hook Performance Scoring (independent, low-risk)

Priority 3 (ships third — production quality):
  [6] Background Music (FFmpeg changes, most complex to get right)
```

---

## Mockup Recommendation

**Yes — create a new mockup file (`framecast-3.html`) before implementing.**

Here is why:

1. `framecast-2.html` is the current source of truth and has proven critical for pixel-perfect implementation. The same discipline should apply to new screens.
2. The new features introduce 4 new UI surfaces that do not exist in the current app:
   - Niche wizard modal (3-step flow)
   - AI image generation panel in editor (visual type picker + style picker + generate button)
   - Music panel in editor (selector + volume/duck controls)
   - Hook scoring display (score badges on hook list)
3. Designing these in HTML first is faster than iterating on Vue — you can validate layout, spacing, and interaction flow before writing component code.

**What `framecast-3.html` should contain:**

- Niche selection modal (step 1: grid of niche cards)
- Niche wizard step 2 (source type selection, pre-configured)
- Editor visual panel extended — visual type tabs + AI image style picker
- Editor music panel — music list + controls
- Hook selection step with score badges
- Asset library with music tab active (shows seeded tracks)

**Approach:**
- Copy the design token section from `framecast-2.html` into `framecast-3.html` verbatim — same CSS variables, same font stack
- Only add the new screens/panels — do not duplicate existing screens
- Mark each section with a comment indicating which feature it belongs to
- Once the mockup is approved, implement each feature against that mockup

---

## Build Progress Tracking

When implementation starts, add a **Phase 6 section** to `BUILD_PROGRESS.md` with this checklist:

```markdown
## Phase 6 — Visual Edge

Exit gate: User generates an AI image for a scene, creates a horror-niche video with music, and
sees hook scores before selecting.

- [ ] ImageGenerationAdapter contract + ReplicateAdapter
- [ ] GenerateAIImageJob
- [ ] POST /api/v1/scenes/{sceneId}/generate-image endpoint
- [ ] Editor: visual type picker with AI Image option + style selector
- [ ] Niche seed data (8 niches, migration)
- [ ] Niche template seed data (8 templates added)
- [ ] GET /api/v1/niches endpoint
- [ ] Niche wizard modal (Vue, 3 steps)
- [ ] Project creation: niche_id support
- [ ] Hook scoring in GenerateHooksJob (hook_score prompt template)
- [ ] Hook score display in generation flow UI
- [ ] Music asset seeding (10–20 royalty-free tracks)
- [ ] PATCH /api/v1/projects/{projectId}/music endpoint
- [ ] Editor: music panel (selector + controls)
- [ ] ProcessExportJob: music mixing via FFmpeg
- [ ] framecast-3.html mockup (before implementation starts)
```

---

## Music Discovery Flow

Music is not a standalone screen. It lives in two places:

1. **Editor → Music panel** (right sidebar, project-level). User selects a track from the 4–5 mood-matched suggestions seeded from the niche default. Volume, duck volume, fade controls inline. "Browse Full Music Library →" link opens the asset library filtered to Music.

2. **Asset Library → Music filter chip**. The existing asset library handles the full music catalog. When the Music chip is active the grid shows track cards (artwork, name, mood tags, duration, "Use in Project" / "Play" actions). No separate screen — same library, different filter.

This means no new route or Vue component is needed for music browsing. The Music panel in the editor is the primary touch point; the asset library is the fallback for browsing the full catalog.

## Image-to-Video Flow

When a user selects "Upload Images" as a source type (in the Niche Wizard or New Video modal):

1. User uploads 1–15 images (JPG/PNG/WEBP)
2. Each image is stored as an Asset (type: `image`, source: `user_upload`)
3. On project creation, `GenerateScriptJob` receives `source_type: images` + list of `image_asset_ids`
4. Instead of a text prompt, the job calls GPT-4o Vision on each image → generates a 2–4 sentence scene description + narration
5. `BreakdownScenesJob` creates one Scene per image, with `visual_asset_id` pre-assigned to that image (no stock matching needed)
6. TTS, captions, and export proceed exactly as normal
7. Users can swap a scene's image for AI-generated or stock in the editor

**Data model addition needed:**
```
projects table
  + source_image_asset_ids (jsonb, nullable) — array of asset IDs for image-to-video flow
```

**API change needed:**
```
POST /api/v1/projects
  body: { source_type: 'images', source_image_asset_ids: [1, 2, 3, ...], ... }
```

**Prompt template needed:** `scene_from_image` — takes image URL + niche context, returns narration text

This is additive to the existing `GenerateScriptJob` — handled as a branch on `source_type`.

---

### Feature 7 — Ken Burns Motion on Still Images

**What it does:** Applies a slow animated camera movement (zoom, pan, or both) to still images — stock, AI-generated, or user-uploaded — over the duration of a scene. The output is a short video clip composited in place of a static image during FFmpeg rendering. Makes every faceless video feel cinematic instead of like a slideshow.

**Why:** This is the single most visible quality signal in faceless content. Competitors do it on every scene by default. A static image with no movement reads as low production value; the same image with a 6-second slow zoom reads as intentional and professional. Zero extra API cost — pure FFmpeg math.

**How it fits the existing model:**

- Scenes already have `visual_type` and `visual_asset_id`
- Add `motion_settings_json` to the Scene — null means static (backwards compatible)
- The FFmpeg renderer reads `motion_settings_json` and wraps the image in a `zoompan` filter before compositing
- Applies to all still visual types: `stock_image`, `ai_image`, `user_upload` (image)
- Does not apply to `stock_video` or `user_upload` (video) — those already have motion

**Data model changes:**

```
scenes table
  + motion_settings_json (jsonb, nullable)
    {
      "effect": "zoom_in | zoom_out | pan_left | pan_right | pan_up | pan_down | pan_zoom | static",
      "intensity": "subtle | moderate | dramatic",
      "direction": "center | subject_left | subject_right"   // hint for pan origin
    }
```

`effect` defaults to `zoom_in` if `motion_settings_json` is null and the visual is a still image. This means the feature is on by default for all still image scenes — operators can disable per scene by setting `effect: "static"`.

**Intensity → FFmpeg zoom rate mapping:**

| Intensity | Zoom rate per frame | Total zoom over 6s @ 30fps |
|---|---|---|
| subtle | 0.0008 | ~1.15x |
| moderate | 0.0015 | ~1.27x |
| dramatic | 0.0025 | ~1.45x |

**FFmpeg filter implementations:**

```bash
# Zoom In (center) — moderate
zoompan=z='min(zoom+0.0015,1.5)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={fps*duration}:s={width}x{height}:fps={fps}

# Zoom Out — moderate
zoompan=z='if(lte(zoom,1.0),1.5,max(1.001,zoom-0.0015))':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={fps*duration}:s={width}x{height}:fps={fps}

# Pan Right — moderate
zoompan=z='1.2':x='if(lte(on,1),0,x+1.5)':y='ih/2-(ih/zoom/2)':d={fps*duration}:s={width}x{height}:fps={fps}

# Pan Left
zoompan=z='1.2':x='if(lte(on,1),iw,x-1.5)':y='ih/2-(ih/zoom/2)':d={fps*duration}:s={width}x{height}:fps={fps}

# Pan + Zoom (most cinematic)
zoompan=z='min(zoom+0.001,1.4)':x='if(lte(on,1),0,x+0.8)':y='ih/2-(ih/zoom/2)':d={fps*duration}:s={width}x{height}:fps={fps}
```

Variables `{fps}`, `{duration}`, `{width}`, `{height}` are resolved from the project's export settings at render time.

**Rendering pipeline change:**

In `ProviderRenderService` (or wherever scene clips are assembled), add a `applyMotion(scenePath, motionSettings, duration)` step that:
1. Checks `visual_type` — skip if video
2. Reads `motion_settings_json`, defaults to `{ effect: 'zoom_in', intensity: 'moderate' }`
3. Builds the `zoompan` filter string from the effect + intensity map
4. Runs FFmpeg on the still image to produce a `.mp4` clip of the correct duration
5. That clip is then composited with audio and captions as normal

No new queue or job needed — this runs inline in the existing render job as a per-scene preprocessing step.

**API endpoints:**

No new endpoints. Motion settings are updated via the existing scene PATCH:

```
PATCH /api/v1/scenes/{sceneId}
  body: { motion_settings_json: { effect: "pan_zoom", intensity: "subtle" } }
```

**Editor UI changes:**

In the Scene Visual panel (right sidebar), below the visual source tabs, add a **Motion** row — visible only when the visual is a still image:

```
Motion   [ Zoom In ▾ ]   [ Moderate ▾ ]   [ preview ▶ ]
```

- Dropdown 1: effect — Zoom In / Zoom Out / Pan Left / Pan Right / Pan Up / Pan Down / Pan + Zoom / Static
- Dropdown 2: intensity — Subtle / Moderate / Dramatic
- Preview button: triggers a short preview clip generation (or just animates the canvas in the mockup)

In the canvas preview, show the Ken Burns animation as a CSS `transform` animation so users get real-time feedback without a render — approximate, not pixel-perfect, but communicates the motion direction and speed.

**Niche defaults:**

Each niche preset in the wizard sets a default motion that matches the mood:

| Niche | Default motion |
|---|---|
| Horror & Dark Stories | Zoom In — dramatic |
| Finance & Money | Pan Right — subtle |
| Motivation & Mindset | Pan + Zoom — moderate |
| History & Facts | Pan Left — subtle |
| Science & Explainers | Zoom In — subtle |
| True Crime | Zoom In — moderate |
| Product Walkthrough | Pan Right — moderate |
| Self Improvement | Zoom Out — subtle |

This default is stored in `NicheTemplate.default_motion_json` and applied to all scenes on project creation. Users can override per scene in the editor.

---

## Font Library

### Overview

Caption fonts are selected by the user per-project (via `caption_style_json.font`) or per-scene (via `caption_settings_json`). The font value is a string matching one of the entries in the allowed font list below. The same list drives the font picker in the Vue caption settings panel and maps directly to the font file used by FFmpeg during rendering.

### FFmpeg Font Rendering

FFmpeg renders captions via `libass`. Fonts must exist as `.ttf` or `.otf` files on the server at render time. The approach:

1. All fonts are bundled into the Docker worker image at build time — no runtime downloads.
2. Font files are stored at `/usr/local/share/fonts/framecast/` inside the container.
3. `fc-cache -f` is run in the Dockerfile after font installation so fontconfig can resolve fonts by name.
4. The FFmpeg caption renderer passes the font directory to libass via `ASS_Library` font dir registration — fonts are resolved by family name, not file path, so the `caption_style_json.font` value maps directly.

### Allowed Font List

Three tiers based on bundling requirements:

#### Tier 1 — Google Fonts (28 fonts)

Free, open-source. Downloaded during Docker image build via `wget` from `fonts.gstatic.com`. No license concern for commercial use.

| Font | Category | Good for |
|---|---|---|
| Bebas Neue | Display | Horror, finance headers, all-caps impact |
| Montserrat | Sans-serif | General purpose, clean, modern |
| Raleway | Sans-serif | Lifestyle, self-improvement |
| Nunito | Rounded sans | Friendly, approachable topics |
| Lato | Sans-serif | Documentary, neutral narration |
| Roboto Mono | Monospace | Tech, data, source code topics |
| Roboto Slab | Serif | Authoritative, history, finance |
| Libre Baskerville | Serif | Editorial, long-form narration |
| Playfair Display | Serif | High-end, storytelling |
| Dancing Script | Script/Cursive | Lifestyle, beauty, soft topics |
| Fredoka One | Rounded display | Kids, gaming, fun |
| Sacramento | Elegant script | Fashion, wedding, luxury |
| Luckiest Guy | Playful display | Kids, comedy, entertainment |
| Orbitron | Sci-fi geometric | Tech, sci-fi, futurism |
| Satisfy | Script | Elegant casual, food, travel |
| Permanent Marker | Handwritten | Raw, DIY, commentary |
| Noto Sans | Universal sans | Multilingual — supports all locales |
| Amatic SC | Handwritten display | Artisan, indie, documentary |
| Days One | Bold sans | Sports, motivational, bold hooks |
| Rock Salt | Grunge | Punk, underground, dramatic |
| New Rocker | Gothic | Metal, dark, horror |
| Passion One | Condensed display | Urgency, news, fast pacing |
| Indie Flower | Casual handwritten | Personal vlog style, warm |
| Quicksand | Rounded sans | Minimal, startup, tech-lite |
| Shadows Into Light | Casual script | Inspirational quotes, soft |
| Source Code Pro | Monospace | Tech, coding, terminal style |
| Aladin | Decorative | Fantasy, mythology |
| Calligraffitti | Script-graffiti | Street, urban, hip-hop |

#### Tier 2 — Windows Core Fonts (7 fonts)

Available via `ttf-mscorefonts-installer` in Debian/Ubuntu. Requires accepting the Microsoft EULA during Docker image build (`DEBIAN_FRONTEND=noninteractive`). All are widely used and legally distributable in compiled form.

| Font | Category | Notes |
|---|---|---|
| Arial | Sans-serif | Universal fallback |
| Georgia | Serif | Body text, editorial |
| Times New Roman | Serif | Formal, news |
| Impact | Condensed display | Meme-style, high-contrast |
| Comic Sans MS | Casual | Niche use only |
| Trebuchet MS | Humanist sans | UI-style captions |
| Courier New | Monospace | Terminal, retro |

#### Tier 3 — Excluded (commercial)

| Font | Reason |
|---|---|
| Proxima Nova SemiBold | Commercial license required — not bundleable without a paid seat. Drop from font picker. |

### Docker Bundling Strategy

```dockerfile
# In worker Dockerfile:

# Tier 2: Windows fonts (EULA accepted non-interactively)
RUN echo "ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true" | debconf-set-selections \
    && apt-get install -y ttf-mscorefonts-installer

# Tier 1: Google Fonts (download subset used by Framecast)
RUN apt-get install -y wget unzip \
    && mkdir -p /usr/local/share/fonts/framecast \
    && cd /usr/local/share/fonts/framecast \
    && wget -q "https://fonts.google.com/download?family=Bebas+Neue" -O bebas.zip && unzip -q bebas.zip && rm bebas.zip \
    # ... repeat for each Google Font ...
    && fc-cache -f -v
```

In practice, use a pre-built font archive (a `.tar.gz` stored in B2 or the repo's Docker assets) to avoid 28 individual HTTP requests at build time.

### Data Model Impact

`caption_style_json.font` and `caption_settings_json.font` must validate against this list before persisting. Add an `ALLOWED_CAPTION_FONTS` constant in `app/Constants/CaptionFonts.php` containing all Tier 1 + Tier 2 font names. Validation rule: `Rule::in(CaptionFonts::ALL)`.

`CaptionPreset` seeds: add one preset per distinct font category to give users a starting point:
- **Bold** → Bebas Neue, white, word-by-word, pop animation
- **Subtitle** → Lato, white, line-by-line, fade animation
- **Handwritten** → Permanent Marker, white, word-by-word, none animation
- **Cinematic** → Playfair Display, white, word-by-word, fade animation

---

## Open Questions

These need a decision before implementation:

1. **Image generation provider for MVP:** Replicate (SDXL) is cheapest and fastest for 9:16 aspect ratio. DALL-E 3 has better prompt following. Recommend starting with DALL-E 3 for quality perception, add Replicate as fallback.

2. **Music licensing:** Seeded tracks must be royalty-free for commercial use. Pixabay Music and Free Music Archive have suitable tracks. Confirm before seeding.

3. **Hook scoring model:** Can reuse the existing `ai_generation` adapter (OpenAI gpt-4o). Adds ~1 API call per project creation. Cost is negligible — confirm this is acceptable.

4. **Niche wizard placement:** Should "Start from Niche" replace the current "New Video" button, or live alongside it? Recommendation: alongside, labeled "Quick Start" — do not remove the existing flow.

5. **AI image cost control:** Image generation is ~$0.04–0.08 per image (DALL-E 3). Free plan users — block or allow with watermark? Recommendation: block on Free tier, allow on Starter+ with a monthly generation cap.
