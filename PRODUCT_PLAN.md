# Framecast Product Plan

## Positioning

Stop calling it an "AI video generator." Position it as a **Content OS for faceless creators and brand teams.**

**The hook:** *"Idea to scheduled post — script, scenes, voice, captions, music, and auto-publish — in one workflow. No camera. No editor."*

The difference from competitors: you don't just export, you **publish and plan.**

---

## The Three Layers of the Product

### Layer 1 — Create (built)
Script → AI scenes → voice → visuals → captions → music → export. Done. Needs stability hardening.

### Layer 1.5 — Repeat (must ship early)
Series Lite + saved defaults. Users should not rebuild the same setup every time.
Voice, visual style, caption style, music mood, aspect ratio, and niche should carry into the next episode.

### Layer 2 — Publish (missing, high value)
Connect social accounts → schedule posts → auto-publish when the time comes.

### Layer 3 — Plan (high retention)
Posting calendar + series mode. See all content in one place, plan episodes weeks ahead, track what's posted vs. pending.

---

## Core Value Loop

1. Pick a repeatable content format.
2. Generate a strong script, hook, scenes, voice, visuals, captions, and music.
3. Edit quickly without leaving the workflow.
4. Export, schedule, or publish.
5. Track what was created, posted, and failed.
6. Use saved defaults and winning formats to make the next episode faster.

Framecast becomes valuable when it helps users keep posting consistently, not only when it creates one video.

The focused MVP scope lives in `PRODUCT_MVP_CORE.md`. Use this plan for product direction beyond the core.

---

## Product Metrics

| Metric | Meaning |
|---|---|
| Activation | User completes first export |
| Strong activation | User schedules or publishes first video |
| Retention | User creates a second video within 7 days |
| Habit | User has 3+ scheduled posts in the calendar |
| Series adoption | User creates a series or uses "New Episode" |
| Cost health | Cost per completed export stays below plan margin |
| Reliability | Failed jobs are retryable without support intervention |

These metrics should be visible in god-mode before public launch.

---

## Content Quality Layer

Publishing weak content faster is not enough. Before auto-publish becomes the main value, the generated videos must feel post-worthy.

### Quality features to add early

- Hook alternatives before full generation
- Niche-specific script templates
- Scene pacing controls
- One-click rewrites: shorter, scarier, more dramatic, simpler, more documentary
- Regenerate one scene without regenerating voice/music for the whole project
- Saved caption/voice/visual/music presets
- Preview parity: editor preview should closely match export
- Friendly rewrite suggestions when AI image or script prompts hit policy limits

---

## Social Publishing

### Platforms (priority order)

| Platform | API | Difficulty | Why first |
|---|---|---|---|
| YouTube Shorts | YouTube Data API v3 | Low | Huge creator market, stable API |
| TikTok | TikTok Content Posting API | Medium | Core audience |
| Instagram Reels | Meta Graph API | Medium | Needed for brand clients |
| Facebook Reels | Meta Graph API | Low (same app as Instagram) | Free with Instagram |
| LinkedIn | LinkedIn Videos API | Low | B2B angle |

### Platform approval risks

Social publishing is high-value but high-risk. Treat it as an integration and approval track, not just upload code.

- YouTube uploads may require Google API audit before public posting is allowed.
- TikTok direct posting may require API client audit before public posting is allowed.
- TikTok and other platforms can reject unwanted third-party branding or watermarks.
- Each platform has separate caption limits, token refresh rules, rate limits, media requirements, and moderation rules.

### Watermark rules

- Free/Creator downloaded exports can include a Framecast watermark.
- Auto-published videos should obey platform branding rules.
- If a platform rejects third-party watermarks, disable auto-publish for watermarked plans or render a platform-compliant version.
- Studio/Agency should default to clean white-label exports.

### Data Model

```
social_accounts
  - workspace_id
  - platform (youtube, tiktok, instagram, facebook, linkedin)
  - access_token, refresh_token
  - platform_user_id, display_name, avatar_url
  - scopes, token_expires_at
  - status (active, expired, revoked)

scheduled_posts
  - project_id, export_job_id
  - social_account_id
  - scheduled_at
  - status (draft, scheduled, published, failed)
  - caption, hashtags
  - platform_post_id (set after publish)
  - failure_reason
```

### Flow
1. User connects account via OAuth in Settings
2. After export completes → "Schedule Post" button appears
3. User picks platform, writes caption, picks date/time
4. `PublishVideoJob` fires at `scheduled_at` with retry on failure
5. Calendar reflects status in real time via Reverb

---

## Posting Calendar

- Month/week view
- Each cell = a scheduled post (platform icon + video title)
- Color coded: draft (grey), scheduled (blue), published (green), failed (red)
- Click → modal to edit caption, reschedule, or retry
- Series episodes show as linked items
- "Add to calendar" from any exported video in one click

This is the **retention hook.** Creators return weekly to plan the next batch. Businesses live in it.

---

## Series Mode

Series is the **subscription driver.** Once users build a series workflow, they don't leave.

### Series Lite (move earlier)

Series Lite should ship before the full calendar/publishing version:

- Series name
- Niche
- Default voice
- Default visual style
- Default caption style/font
- Default music mood/category
- Default aspect ratio
- "Create next episode"

This makes repeat creation valuable even before auto-publishing is ready.

### Model
Use `family_id` already on `Project`, plus a `Series` table:
- Series name, niche, cover image
- Default voice profile, visual style, caption preset, music mood, aspect ratio
- Episode list ordered by publish date / episode number

### Flow
1. Create Series → set template (voice, style, music, caption, aspect ratio)
2. "New Episode" → wizard pre-fills all series defaults, user just provides content (script, URL, audio, etc.)
3. Generate → edit → schedule → done
4. Series dashboard shows all episodes with status and publish date

### Batch Series
"Generate 5 horror story episodes this week from these 5 scripts" — one trigger, 5 jobs queued, all inherit series defaults.

---

## Business / Agency Angle

| Feature | Why businesses pay for it |
|---|---|
| Multi-channel workspace | One agency, multiple client brands |
| Client approval link | Preview link → client approves → auto-post |
| Team roles (editor, publisher) | Agency workflow |
| White-label export | No Framecast watermark on client videos |
| Bulk CSV → batch publish | 50 videos a week across 10 clients |
| Analytics pull-back | Show clients post performance inside the tool |

**Agency positioning:** *"Your content production team, not just a video tool."*

---

## Pricing

| Plan | Target | Key limits |
|---|---|---|
| Free | Try it | 3 exports/month, watermark, 1 channel |
| Creator — $29/mo | Solo faceless creator | 30 exports, 3 channels, 1 social account, series mode |
| Studio — $79/mo | Active creator / small team | 100 exports, 10 channels, 5 social accounts, batch export, localization |
| Agency — $199/mo | Agencies and brands | Unlimited exports, team roles, 20+ channels, white-label, client approval, analytics |

**Billing provider: Paddle** (handles VAT, global tax compliance, merchant of record).

---

## Unit Economics

Framecast must know the cost of every heavy action before it can scale safely.

Track estimated cost for:

- Script/hook generation
- Scene breakdown/scoring
- AI image generation
- TTS generation
- Export compute
- Storage and bandwidth
- Failed provider calls

Rules:

- Enforce plan limits before expensive jobs start.
- Record failed provider calls so we can debug, but do not double-count successful retries as extra user usage.
- Warn admins when a workspace approaches its API budget.
- Prefer reusing existing assets when the user did not request regeneration.
- Show cost per completed export in god-mode.
- Keep margin targets per plan visible in admin reporting.

---

## Retention Loops

- Weekly content calendar
- Series episodes
- Saved niche formulas
- Saved voice/caption/visual/music defaults
- Batch-create next week's posts
- Reminders for empty posting days
- Performance analytics feeding the next script
- Reuse winning hooks and formats

---

## Phased Launch Plan

### Phase A — Stabilize (weeks 1–2)
- [ ] Ship the focused MVP defined in `PRODUCT_MVP_CORE.md`
- [ ] Pass the focused MVP QA gate in `PRODUCT_MVP_CORE_QA.md`
- [ ] Prod infra — SSL, domain, prod env, Postmark/SES, supervised queue workers

### Phase B — Publish (weeks 3–5)
- [ ] Social account OAuth connect (YouTube first, then TikTok + Instagram)
- [ ] "Schedule Post" flow after export
- [ ] `PublishVideoJob` with retry on platform failure
- [ ] Basic posting calendar view (list by date, status color coding)
- [ ] Platform approval checklist for each publish provider

### Phase C — Plan (weeks 6–8)
- [ ] Full series dashboard and episode management
- [ ] "New Episode" wizard refinements
- [ ] Posting calendar with series grouping (month/week view)
- [ ] Batch series generation (n scripts → n episodes)

### Phase D — Business (weeks 9–12)
- [ ] Team roles and workspace invite system
- [ ] Client approval link (public preview URL, approve → schedule)
- [ ] White-label export (no watermark on paid tiers)
- [ ] Analytics pull-back (views, likes from platform APIs)
- [ ] Agency workspace (multi-brand under one billing account)

---

## One-Line Pitch Per Audience

- **Solo creator:** "Post 30 faceless videos a month without touching a camera or editor."
- **Business:** "Scale video content across 5 platforms in 3 languages from one dashboard."
- **Agency:** "Run 10 client content calendars from one tool — generate, approve, and auto-post."

---

## MVP Exit Gate

The MVP exit gate lives in `PRODUCT_MVP_CORE_QA.md`.

The broader product should not move into Publish, Plan, or Agency work until the focused MVP gate passes.

---

## Good Launch Niches

- Horror / dark stories
- History mysteries
- Motivational shorts
- Weird facts
- Bible / devotional shorts
- Finance explainers (needs compliance caution)
- Reddit-style stories (watch for copyright on source text)
