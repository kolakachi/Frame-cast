# Framecast MVP Stage Z — The Full Creator Loop

## What Stage Z Is

Stage Z is the complete content creation loop — from discovering what to make, to knowing if it worked.

Most tools own one step. Framecast owns the full loop.

```
Find a niche
     ↓
Research competition → what's working, who the audience is
     ↓
Generate → script, hook, voice, visuals, thumbnail
     ↓
Export → schedule → auto-post
     ↓
Track → what performed → feed results into the next video
     ↑_______________________________________________|
```

Every feature in Stage Z serves this loop. If a feature does not move a creator around the loop faster, it does not belong here.

---

## Product Architecture — Standalone Tools + Linked Creation

The Stage Z tools are **not steps inside the video creation wizard**. They are standalone pages with their own sidebar navigation. The video creation flow (existing wizard → editor → export) stays exactly as it is.

```
Sidebar nav                     Creation flow (unchanged)
────────────────────────        ─────────────────────────
🔍 Niche Intelligence  ──→      "New Video" wizard
📊 Competitor Research ──→      (context pre-filled)
🎣 Hook Lab            ──→      ──→ Editor
📺 Series              ──→      ──→ Export
🖼️ Thumbnail Studio ←──────────────── (post-export)
📅 Calendar
```

**Why separate:**
- Research is a different headspace from creation. Niche intelligence is done once, maybe weekly — not inside the creation flow.
- Forcing research into the wizard adds friction for users who already know what they're making.
- Each tool has value on its own — a user can spend 20 minutes in Competitor Research with no intention of creating right now.
- The tools compound over time: saved niches, saved hooks, saved competitor analyses all become context that makes future creation faster.

**How they link:**
- Every standalone tool has one primary CTA: **"→ Create Video"** — opens the existing wizard with context pre-filled (niche, hook, style, series defaults, audience description).
- Pre-fill is additive — whatever context the tool gathered is passed as optional defaults. The user can override anything in the wizard.
- Nothing is mandatory. A user can ignore all the tools and create directly from the dashboard as they do today.

---

## The Four Creation Entry Points (unchanged wizard, different pre-fills)

The "New Video" button on the dashboard opens the existing creation wizard. It now supports four entry contexts:

| Entry | How they got there | What's pre-filled |
|---|---|---|
| **Direct** | Dashboard "New Video" button | Nothing — wizard defaults |
| **From Niche Intelligence** | "Start creating in this niche" | Niche, tone, style, format, audience profile |
| **From Competitor Research** | "Generate my version" | Tone, style, hook pattern, audience profile, length |
| **From Hook Lab** | "Use this hook" | Hook text, niche, audience |
| **From Series** | "New Episode" | All series defaults + episode number |

The wizard doesn't change — it just accepts pre-fills. Everything is still editable.

---

## Audience Context — Free Text Over Forced Taxonomy

The current niche model is a fixed list. That works for browsing but breaks for:
- Hyper-specific niches ("true crime stories about forgotten female killers")
- Community-specific content ("Indian tech workers on LinkedIn")
- Agencies with a client-defined brief

**Solution:** Accept audience as free text alongside (or instead of) the niche dropdown.

Field label: *"Who is this for? (optional — the more specific, the better)"*

Placeholder examples:
- "18–25 year olds who like horror and can't sleep at night"
- "Small business owners learning finance for the first time"
- "Gym beginners intimidated by weightlifting"

This text is passed verbatim into the AI script and hook generation prompts. No classification, no taxonomy matching — direct prompt injection. The niche dropdown stays for users who want to browse, but is never required.

---

---

## Step 1 — Find a Niche

### What exists
- Niche model, seeded niches, niche selection in wizard
- Niche used to set default visual style and tone

### What to build

**Niche Intelligence Page**

A browsable niche directory that helps a new creator pick the right category before they create anything.

Each niche card shows:
- Niche name and description
- Typical video format (length, tone, pacing)
- Common hook patterns used in that niche
- Example titles that perform well
- Suggested voice tone, visual style, caption style, music mood
- Audience profile: age range, platform preference, why they watch
- Difficulty level: competition density vs. content volume gap

Actions:
- "Explore this niche" — shows example script structure and hook patterns
- "Start creating in this niche" — opens wizard with all niche defaults pre-filled

**Niche Brief**

When a creator selects a niche, generate or show a short brief:
> "Horror story channels typically post 4–7 minute videos, 3–5 times a week. Hook style: open with a threat or unsolved mystery. Tone: calm narrator, slow dramatic pacing. Top hooks start with: 'Nobody knows what really happened to…', 'This was found at 3am and nobody can explain it', 'The last thing she recorded before she disappeared was…'. Audience: 18–34, predominantly mobile, high rewatch rate."

**Data needed:**
- `niche_briefs` — one per niche, manually curated or AI-generated, includes hook patterns, format recommendations, audience profile, example title formats
- Niche difficulty score (manual or from YouTube Data API volume estimates)

---

## Step 2 — Research Competition

### What exists
- URL source type — paste a YouTube video URL and the system fetches content from it
- OpenAI-backed script extraction from URL content

### What to build

This is the biggest differentiator. No creation tool does this. Research tools don't create. Framecast bridges both.

---

### 2A — Competitor Channel Import

User pastes a YouTube channel URL or handle.

**What it does:**
1. Fetch channel metadata via YouTube Data API v3 (channel name, subscriber count, total views, upload frequency)
2. Fetch the top 20–50 videos by view count (title, thumbnail URL, view count, duration, publish date, description)
3. AI analysis pass:
   - Hook patterns extracted from video titles
   - Most common topics and themes
   - Typical video length range
   - Posting frequency
   - Engagement rate estimate (views / subscribers)
   - Tone and style inference from titles + descriptions

**Output — Channel Analysis Card:**
```
Channel: @DarkMysteries (2.1M subs)
Posts: 4x/week | Avg length: 5.2 min | Avg views: 340K

Top hook patterns:
  • "Nobody can explain why [X] happened"
  • "The [person/place] that disappeared without a trace"
  • "What they found inside [location] shocked everyone"

Top topics: haunted places (34%), disappearances (28%), unexplained deaths (22%)
Tone: calm, dramatic, slow build
Visual style: dark, atmospheric, archival footage
```

**Actions from the analysis card:**
- "Generate a video in this style" → wizard pre-filled with inferred tone, length, format, hook style, niche
- "Save as inspiration" → stored in a workspace Inspiration Board
- "Analyze another channel"

---

### 2B — Single Video Analysis

User pastes a YouTube video URL (also works as a source type for content).

**What it does:**
1. Fetch video metadata: title, description, view count, duration, tags
2. If transcript available via YouTube API, fetch it
3. If not, fall back to existing URL content extraction
4. AI analysis:
   - Hook structure (first 15 seconds)
   - Emotional arc (how tension builds and resolves)
   - Script format (story, list, explainer, case study)
   - Pacing (word count per minute)
   - Call to action style

**Output:**
```
Video: "The Haunting of Poveglia Island — Italy's Most Cursed Place" (4.2M views)
Hook style: Mystery open — leads with unexplained deaths before naming the location
Format: Case study — historical facts → eyewitness accounts → modern investigation
Length: 6:14 | Pace: 142 words/min (moderate)
Tone: Dramatic but factual — narrator withholds conclusion until end
CTA: "Subscribe for more unexplained history every week"
```

**Actions:**
- "Generate your version" → uses the video's structure as a template, user provides their own angle/content, AI rewrites it
- "Use this as a source" → existing URL source type flow

---

### 2C — Target Audience Profile

Built from niche + competitor data, used to shape the script.

When a user selects a niche and/or imports a competitor channel, generate an audience profile:

```
Your target audience:
  Age: 18–34 (primarily)
  Platform: TikTok (first), YouTube Shorts (second)
  Why they watch: escapism, curiosity, can't-look-away tension
  Watch behavior: high rewatch rate, share to friends, late night viewing
  What they skip: slow intros, over-explained backstory, weak hooks
  What they love: mystery setup, real facts, satisfying or unsettling ending
```

This profile is:
- Shown to the user during script generation
- Passed as context to the AI script prompt ("write for an audience of 18–34 year olds who want escapism and tension")
- Saved on the Series so it's used for every episode

---

### Data model additions

```
competitor_analyses
  - workspace_id
  - type (channel, video)
  - source_url
  - platform
  - platform_channel_id / platform_video_id
  - display_name
  - subscriber_count, total_views, avg_views_per_video
  - upload_frequency_per_week
  - analysis_json (hook patterns, topics, tone, style, format)
  - fetched_at

inspiration_items
  - workspace_id
  - type (channel_analysis, video_analysis, hook, title_format)
  - label
  - source_competitor_analysis_id (nullable)
  - content_json
  - created_at
```

---

## Step 3 — Video Creation

### What exists
- Script generation from 7 source types
- Scene breakdown, hooks, visuals, TTS, music, captions, editor, export
- Hook variants (A/B test)
- AI image generation
- Localization

### What to build

---

### 3A — Hook Lab

The hook determines whether anyone watches the video. It should be picked before the full script is written, not generated as an afterthought.

**Flow:**
1. User enters topic, niche, and optional competitor inspiration
2. Before full generation: AI generates 8–12 hook options
3. Each hook is labeled by style: `mystery open`, `threat/warning`, `shocking stat`, `story open`, `question hook`, `contradiction hook`
4. User picks one (or enters their own)
5. Script generation begins from the chosen hook — the rest of the narrative is built to pay it off

**Hook options example:**
```
Pick your hook:

[mystery open]    "Nobody has been able to explain what happened at the old Weston estate — until now."
[threat/warning]  "If you ever visit Poveglia Island, here's what they don't tell you on the tour."
[shocking stat]   "Over 160,000 people died on this island. The locals call it cursed. The scientists have no explanation."
[story open]      "In 1968, a doctor disappeared from a psychiatric ward on a remote Italian island. His body was never found."
[question hook]   "What would you do if you found out the city you live in was built on a mass grave?"
[contradiction]   "Italy's most beautiful lagoon hides the darkest secret in European history."
```

After the video is posted and analytics are pulled, the winning hook style per niche is tracked and surfaced on future creation:
> "Your mystery open hooks average 2.3× more views on this channel. Use it?"

---

### 3B — Thumbnail Studio

The thumbnail drives click-through rate. It is often more important than the video itself.

**What to build:**

After export completes, a "Create Thumbnail" button appears.

**Thumbnail flow:**
1. User picks a source: AI image from any scene, or upload their own image
2. Add text overlay: primary hook text (auto-filled from project hook), optional sub-text
3. Style: font (from brand kit or free pick), text position, text color, shadow, background darkening
4. Platform safe zones: TikTok (no text in top 20%), YouTube (standard 1280×720), Instagram (square crop)
5. Preview per platform
6. Export as image file

**Thumbnail variants:**
- Generate 3 thumbnail options with different text placements and cropping
- A/B test thumbnails the same way hooks are tested (upload different thumbnails, compare CTR from analytics)

**Data model:**
```
thumbnails
  - project_id
  - source_asset_id (scene AI image or uploaded)
  - text_primary, text_secondary
  - style_json (font, color, position, shadow, bg_darkness)
  - platform_variants_json (youtube, tiktok, instagram crop/size)
  - output_asset_id
  - status (draft, exported)
```

---

### 3C — Scene Pacing Controls

Creators in different niches need different pacing. A horror story needs a slow build. A facts video needs fast cuts.

Add to scene editing:
- Scene duration control (override the auto-calculated duration)
- Pacing mode per project: `slow (8–12 sec/scene)`, `medium (5–8 sec)`, `fast (3–5 sec)`
- Visual edit style: `hold` (static image), `slow zoom`, `pan left/right`, `pulse` (subtle scale pulse)
- These feed into FFmpeg export as Ken Burns-style motion effects

---

### 3D — One-Click Rewrites

In the editor, per scene:
- "Make it shorter"
- "Make it scarier / more dramatic"
- "Make it simpler"
- "Make it more documentary"
- "Add a cliffhanger ending"

These are pre-built prompt wrappers around the existing scene rewrite endpoint. No new backend needed — just UI additions.

---

### 3E — Policy-Safe Prompt Rewriting

When an AI image or script generation hits an OpenAI content policy limit:
- Show exactly what phrase triggered the refusal
- Suggest 2–3 rewritten alternatives that achieve the same intent safely
- "Use this instead" button — applies the rewrite and regenerates

Instead of: *"Content policy violation. Try again."*
Show: *"The phrase 'dismembered body' triggered a content limit. Try: 'the remains that were discovered', 'what investigators found at the scene', or 'evidence of what had happened'."*

---

## Step 4 — Export and Schedule

### What exists
- Export to MP4
- Download completed export
- Variants + batch export

### What to build (Stage Z additions)

**Complete package export:**
- Export bundle: MP4 + thumbnail image + caption text file + hashtags file
- One download, everything a creator needs to post manually
- One click to schedule all three (video + thumbnail + caption) to a platform

**Platform-optimised export:**
- YouTube: 1920×1080 or 1080×1920, max 12 hours, thumbnail as separate 1280×720
- TikTok: 1080×1920, max 10 min, caption ≤2200 chars
- Instagram Reels: 1080×1920, max 15 min, thumbnail as cover frame
- Auto-transcode to platform spec if the export dimensions don't match

---

## Step 5 — Track and Feed Back

### What exists
- Export completion events
- Basic usage display

### What to build

**Analytics pull-back (Phase C):**
- After a video is published via Framecast, pull platform analytics daily:
  - Views, likes, comments, shares, watch time, CTR (where API provides it)
- Store in `post_analytics` table
- Show on Calendar post detail

**Performance dashboard per series:**
- Episodes list with views, watch time, and hook style used
- Best performing hook style for this series
- Best performing topic cluster
- Suggested next episode based on highest performing past episodes

**Feed results into the next video:**
- When creating a new episode in a series: "Your last 3 best performers used mystery open hooks on haunted location topics. Start there?"
- AI script prompt enhanced with: "This series' best performing tone is X, pacing is Y, top topics are Z"
- Thumbnail CTR from YouTube → "This text style got 8.2% CTR, try it again"

---

## What Stage Z Adds to the Data Model

```
niche_briefs
  - niche_id
  - hook_patterns_json        — array of example hooks with style labels
  - format_recommendations_json — typical length, pacing, structure
  - audience_profile_json     — age, platform, motivations, watch behavior
  - example_title_formats_json
  - difficulty_score

competitor_analyses
  - workspace_id, type, source_url, platform
  - display_name, subscriber_count, avg_views_per_video
  - upload_frequency_per_week
  - analysis_json             — hook patterns, topics, tone, style
  - fetched_at

inspiration_items
  - workspace_id, type, label
  - source_competitor_analysis_id
  - content_json

thumbnails
  - project_id, source_asset_id
  - text_primary, text_secondary
  - style_json, platform_variants_json
  - output_asset_id, status

post_analytics
  - scheduled_post_id
  - fetched_at
  - views, likes, comments, shares, watch_time_seconds
  - ctr_percent (nullable, YouTube only)
  - raw_json
```

---

## Phased Build Order for Stage Z

### Z1 — Hook Lab + One-Click Rewrites (build first)
These improve content quality before anything is published. Better content = better results = users stay.

- Hook options before full generation
- One-click scene rewrites (shorter, darker, more dramatic, simpler, more documentary)
- Policy-safe rewrite suggestions

### Z2 — Niche Intelligence Page
Acquisition feature — helps new users find their niche inside Framecast rather than Googling it.

- Niche briefs (hook patterns, format, audience profile)
- Niche directory browse
- "Start in this niche" pre-filled wizard

### Z3 — Competitor Channel and Video Import
Differentiation feature — no creation tool does this.

- YouTube Data API integration
- Channel analysis (hook patterns, topics, frequency, tone)
- Single video analysis (hook structure, format, pacing)
- Inspiration Board (save analyses to reference later)
- "Generate your version" entry point

### Z4 — Thumbnail Studio
Upsell feature — complete ready-to-post package.

- Scene image → thumbnail with text overlay
- Platform-safe crop variants (YouTube, TikTok, Instagram)
- Thumbnail variants for A/B testing
- Export alongside MP4

### Z5 — Analytics Pull-Back + Feed Forward
Retention feature — reason to return every week.

- Platform analytics fetched daily for published posts
- Series performance dashboard
- Hook and topic performance by series
- Suggested next episode based on best performers

### Z6 — Scene Pacing Controls + Platform Export
Polish layer.

- Per-scene duration override
- Project-level pacing mode
- Ken Burns motion effects in FFmpeg (slow zoom, pan)
- Platform-optimised transcode per destination

---

## Feature Competitive Map

| Feature | Framecast Stage Z | InVideo | Pictory | Opus Clip | VidIQ | CapCut |
|---|---|---|---|---|---|---|
| Script from URL/script/audio | Yes | Yes | Yes | No | No | No |
| AI image generation | Yes | Partial | No | No | No | No |
| Niche intelligence | Yes | No | No | No | Partial | No |
| Competitor channel import | Yes | No | No | No | No | No |
| Hook lab (pick before generation) | Yes | No | No | No | No | No |
| Thumbnail generation in-tool | Yes | No | No | No | No | Partial |
| Auto-publish to platforms | Yes | Yes | No | No | No | Yes |
| Series / episode management | Yes | No | No | No | No | No |
| Analytics → feed next video | Yes | No | No | No | Yes | No |
| Localization (re-TTS) | Yes | Partial | No | No | No | No |
| Batch export (variants) | Yes | Partial | No | No | No | No |

---

## Stage Z Success Criteria

A creator using Framecast in Stage Z mode should be able to:

1. Pick a niche they've never created in before and understand what works in it — in under 5 minutes
2. Paste a competitor's channel and generate a video in that style — in under 15 minutes
3. Have a complete ready-to-post package (MP4 + thumbnail + caption + hashtags) — in under 20 minutes
4. Look at last week's performance and know exactly what to make next — in under 2 minutes
5. Keep a content series running consistently for 30 days without running out of ideas or energy

If a creator can do all five, Framecast has genuine retention. That is what a subscription product needs.

---

## The One-Line Sell per Audience

| Audience | Line |
|---|---|
| New creator | "Tell us your niche. We'll show you what's working. You make the next video in 15 minutes." |
| Established creator | "Paste your competitor's channel. Generate your version. Schedule it. Done." |
| Agency | "Run 10 client content series from one dashboard. Research, create, approve, post, track." |
| Brand | "Turn your product page into 30 platform-ready videos this month — no camera, no editor." |
