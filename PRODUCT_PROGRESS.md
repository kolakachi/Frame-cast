# Framecast Product Progress

## How to use this file

Execution tracker for the phases defined in `PRODUCT_PLAN.md`. Every task gets marked as it ships.

**Status key:**
- `[ ]` — not started
- `[~]` — in progress
- `[x]` — complete
- `[!]` — blocked (add a note)

**Rules:**
- Do not mark complete if partially done — use `[~]` and add a note
- Do not move to the next phase until the current phase QA gate passes (see `PRODUCT_QA.md`)
- Record decisions and deviations in the Decisions table at the bottom

---

## Current State

**Active phase:** Phase A — Stabilize  
**Last updated:** 2026-04-25  
**Last updated by:** —

Focused MVP source of truth:

- Scope: `PRODUCT_MVP_CORE.md`
- QA gate: `PRODUCT_MVP_CORE_QA.md`

---

## Phase A — Stabilize

Exit gate: Product is stable enough to charge real users. Billing enforces limits. A new user can complete their first export in under 10 minutes. Prod infra is live.

See QA gate: `PRODUCT_MVP_CORE_QA.md`

### Export Stability
- [x] Split `ProcessExportJob` into per-scene sub-jobs so large projects don't hit the monolithic timeout — Bus::batch() fan-out, RenderSceneSegmentJob per scene → B2 → ConcatenateExportJob assembles final MP4; Batchable trait fix applied in prod
- [x] Add idempotency guard on asset creation in export — retry must not create duplicate Asset records (deterministic storage path + lockForUpdate transaction)
- [x] Clean up temp directories on job failure ($tempDir as instance property, cleaned in failed() and finally)
- [x] Increase export worker timeout — job $timeout=2700s, worker --timeout=2700, REDIS_QUEUE_RETRY_AFTER=3000, 15-min MinIO socket timeout added
- [x] Add `failed()` handler to `ProcessExportJob` — sets export status to `failed` with reason, dispatches Reverb event
- [x] Verify export Reverb events fire correctly for queued → processing → completed → failed transitions
- [x] Export blocked (not silently failed) when required voice or visual is missing per scene
- [x] Bundle editor caption fonts into the export worker image so selected fonts render in MP4 exports
- [~] Caption/music/export preview parity — selected font works; remaining caption preset and audio parity still needs QA

### Paddle Billing
- [x] Install and configure Paddle SDK in Laravel — `config/billing.php` + `PaddleService`; uses Http facade (no SDK needed)
- [x] Define Free / Studio / Scale / Enterprise plan IDs in config — `billing.paddle.price_ids` maps tier → Paddle price
- [x] Workspace model: add `plan_status`, `paddle_customer_id`, `paddle_subscription_id`, `plan_renews_at` — migration 2026_04_26_000001
- [x] Paddle webhook handler — `PaddleWebhookController` (HMAC-SHA256 verified); handles subscription.created/updated/cancelled/past_due/paused
- [x] Upgrade flow — `GET /billing/status` returns price IDs + client_token; frontend opens Paddle.js checkout overlay
- [x] Downgrade flow — cancellation webhook sets `plan_tier = free` on next billing cycle event
- [x] Failed payment state — `subscription.past_due` sets `plan_status = past_due`; SettingsView shows status badge
- [x] Manage Billing — `POST /billing/portal` returns Paddle customer portal URL; opens in new tab
- [x] Admin can manually override plan tier for a workspace — already live via PATCH /admin/workspaces/{id}/plan
- [x] Plan gating middleware — budget checked before exports, AI image, TTS, and now variants (VariantController); workspace suspend blocked at JWT middleware layer
- [x] Over-limit response returns plan name, current usage, and limit to the frontend — global axios interceptor in api.js catches `limit_context`, opens LimitModal via Pinia limitStore; "View Plans" deep-links to Settings billing section

### Onboarding Wizard
- [x] First-login detection — router guard checks `preferences.onboarded`; redirects to /onboarding if false; auth response now includes preferences
- [x] Wizard step 1: pick niche (or custom) — grid of niche cards from /niches; "Other/General" option
- [x] Wizard step 2: pick source type and enter content — 4 source types (prompt/script/url/product); textarea with contextual placeholder
- [x] Wizard step 3: configure voice, style, aspect ratio — aspect ratio picker, voice dropdown, visual style chips
- [x] Wizard step 4: launch generation with progress view — POST /projects → redirect to generation-progress route
- [x] Wizard step 5: land in Editor with scenes populated — handled by existing GenerationProgressView → EditorView flow
- [x] Skip option available at each step — global skip button + per-step skip; PATCH /me sets preferences.onboarded=true
- [x] Wizard state persisted (refresh-safe) — localStorage fc_wizard key; restored on mount

### God-Mode Admin
- [x] Workspace list with plan tier, usage, created date, status
- [x] User list with workspace, last login, role
- [x] Per-workspace: API usage events, monthly spend estimate, failed provider calls
- [x] Recent generations list (last 50 across all workspaces) — videos() endpoint + frontend
- [x] Suspend workspace action (blocks all API calls for that workspace)
- [x] Manually adjust plan tier and limits for a workspace
- [x] Audit trail for admin actions — AdminAuditLog model, auditLog() endpoint, frontend section
- [x] Admin routes restricted to admin + admin.ip middleware

### Content Quality and Repeatability
- [x] Niche-specific script templates for launch niches — {{niche}} injected into all script_from_* prompt templates; GenerateScriptJob loads Niche.name + default_voice_tone as tone fallback
- [x] Hook alternatives before full generation — GenerateHooksJob + ScoreHooksJob; ProjectHookOption stores 3-10 scored hook options; frontend shows scored hooks for selection
- [x] Scene pacing controls — duration_seconds editable per scene in Editor script panel with autosave; motion_settings_json (effect/intensity) controls Ken Burns motion
- [x] One-click rewrite actions — 10 modes live (shorten, expand, stronger_hook, more_punchy, more_educational, more_salesy, simplify, scarier, more_dramatic, more_documentary)
- [x] Series Lite: series name, niche, defaults, and "Create next episode" — Series CRUD, episode linkage, SummarizeEpisodeJob, SeriesDetailView "Create next episode" flow, series memory window
- [x] Saved caption/voice presets — caption presets: full CRUD (GET/POST/DELETE /caption-presets) + editor save/apply/delete chips; voice profiles: POST /voice-profiles saves named profile + appears in voice selector; visual/music preset UI deferred (project-level settings already reusable via Series defaults)
- [x] Regenerate only the requested scene/media without touching voice or unrelated assets — per-scene voice regen, per-scene AI image gen, per-scene visual swap all live in SceneController
- [x] Friendly rewrite suggestions when AI image/script prompts hit policy limits — GenerateAIImageJob auto-rewrites policy-rejected prompts via GPT-4o-mini and retries

### Cost Tracking and Unit Economics
- [x] Record OpenAI text usage and estimated cost — ApiUsageService + ApiUsageEvent
- [x] Record AI image usage and estimated cost
- [x] Record TTS usage/failures and estimated cost
- [x] Record export compute/storage cost estimate — ProcessExportJob records ApiUsageEvent on completion (render_seconds × $0.0001 + MB × $0.00001)
- [x] Show cost per completed export in god-mode jobs view — render_seconds + render_cost_usd columns added
- [x] Enforce per-workspace API budget before expensive jobs start — ProjectController + SceneController
- [x] Admin alert when workspace spend approaches plan budget — CheckBudgetAlertJob at 80% and 100%

### Production Infrastructure
- [x] Production `.env` — all secrets populated (OpenAI, B2, Reverb, Paddle, DB, Redis, mail) on server at /opt/framecast/app/framecast-app/api/.env
- [x] SMTP mail configured — magic link email delivers via server344.web-hosting.com (hello@lumiodocs.com); tested and working
- [x] SSL certificate provisioned and auto-renewing — Cloudflare terminates TLS; no cert needed on origin; AppServiceProvider forces https:// URL generation
- [x] Domain DNS pointed correctly — framecast.lumiodocs.com live on Oracle Cloud (132.145.195.157) behind Cloudflare proxy
- [x] CORS configured for production domain — CORS_ALLOWED_ORIGINS=https://framecast.lumiodocs.com
- [x] Queue workers supervised — Docker restart: unless-stopped on all workers; Docker daemon starts on boot
- [x] All 4 queues running — worker-generation (generation/tts/visual/translation), worker-exports (exports/rendering), worker-default (default), scheduler all live
- [x] Reverb server supervised and reachable from frontend — reverb container with restart policy; /app/ proxied through Nginx; polling fallback in EditorView for WebSocket gaps
- [x] PostgreSQL backups scheduled — daily cron at 02:00 UTC; pg_dump → gzip → B2 backups/ folder; 30-day rolling window; log at /var/log/framecast-backup.log
- [x] Redis persistence decision made and configured — AOF persistence (appendonly yes, appendfsync everysec) in docker-compose.prod.yml
- [x] Docker production build pipeline working — docker-compose.prod.yml + nginx/Dockerfile + git push prod master deploys via post-receive hook
- [x] All seeders and migrations clean — NicheSeeder, MusicTrackSeeder, VoiceProfileSeeder, CaptionPresetSeeder run in prod; all 27 migrations applied; hook runs seeders on every deploy
- [x] No local URLs or dev credentials in production code paths — AppServiceProvider trustProxies + forceScheme('https'); MinIO disk aliased to B2 in prod .env; MINIO env removed from code paths
- [x] B2 production bucket configured — frame-cast bucket in use; MINIO disk credentials point to B2 in prod; signed URL streaming working

**Phase A exit gate:** `PRODUCT_QA.md § Phase A` must pass before Phase B starts.

---

## Phase B — Publish

Exit gate: A user can connect a social account, export a video, schedule a post, and have it auto-publish at the scheduled time. Posting calendar shows accurate status.

See QA gate: `PRODUCT_QA.md § Phase B`

### Social Account OAuth
- [ ] `social_accounts` table — platform, workspace_id, tokens, expires_at, status
- [ ] OAuth connect flow for YouTube (Settings → Connect Account)
- [ ] OAuth connect flow for TikTok
- [ ] OAuth connect flow for Instagram (via Meta Graph API)
- [ ] OAuth connect flow for Facebook Reels (same Meta app as Instagram)
- [ ] Token refresh — auto-refresh before expiry, mark as `expired` if refresh fails
- [ ] Disconnect account action
- [ ] Connected accounts list in Settings with platform avatar and display name

### Schedule Post Flow
- [ ] `scheduled_posts` table — project_id, export_job_id, social_account_id, scheduled_at, status, caption, hashtags, platform_post_id, failure_reason
- [ ] "Schedule Post" button appears on completed exports (Editor + Variants screen)
- [ ] Schedule modal: pick connected account, write caption, add hashtags, pick date/time
- [ ] Caption character limit enforcement per platform (TikTok 2200, YouTube 5000, Instagram 2200)
- [ ] Save as draft option (no scheduled_at set)
- [ ] Edit/reschedule a pending post
- [ ] Cancel a scheduled post

### Publish Job
- [ ] `PublishVideoJob` dispatched at `scheduled_at` via Laravel scheduler or queue delay
- [ ] Downloads export MP4 from B2 and posts to platform API
- [ ] Sets `platform_post_id` and status `published` on success
- [ ] Sets `failure_reason` and status `failed` on error — retries up to 3×
- [ ] Reverb event fires on publish success and failure (toast in UI)
- [ ] Platform-specific metadata: YouTube title/description/category, TikTok privacy, Instagram caption

### Posting Calendar
- [ ] Calendar view route and navigation entry
- [ ] Month view — each day shows scheduled post count badge
- [ ] Week view — each slot shows post card (platform icon + video title + status)
- [ ] Color coding: draft (grey), scheduled (blue), published (green), failed (red)
- [ ] Click post card → quick modal: view details, edit caption, reschedule, retry
- [ ] Filter by platform, status, or channel
- [ ] "Add to calendar" shortcut from any completed export

**Phase B exit gate:** `PRODUCT_QA.md § Phase B` must pass before Phase C starts.

---

## Phase C — Plan (Series + Calendar)

Exit gate: A user can create a named series, generate multiple episodes that inherit series defaults, and view all episodes in the calendar grouped by series.

See QA gate: `PRODUCT_QA.md § Phase C`

### Series Model
- [ ] `series` table — workspace_id, name, niche_id, cover_image_asset_id, default_voice_profile_id, default_visual_style, default_caption_preset_id, default_music_mood, default_aspect_ratio, episode_count
- [ ] Use `Project.family_id` to link episodes to a series
- [ ] Series CRUD API endpoints
- [ ] Series list on dashboard (card grid or sidebar section)
- [ ] Series detail page: name, cover, defaults, episode list ordered by episode number

### New Episode Flow
- [ ] "New Episode" button on Series detail page
- [ ] Episode creation wizard pre-fills all series defaults (voice, style, caption, music, aspect ratio, channel, brand kit)
- [ ] User only needs to provide content (script, URL, audio, etc.)
- [ ] Episode numbered automatically (episode_number on Project or in series metadata)
- [ ] After generation, episode appears in series list

### Batch Series Generation
- [ ] Batch input: paste N scripts or upload CSV → N episodes created
- [ ] All batch episodes inherit series defaults
- [ ] One BatchJob parent tracks all episode jobs
- [ ] Progress visible per episode on series detail page

### Calendar — Series View
- [ ] Calendar groups episodes by series (color per series)
- [ ] Series filter chip on calendar
- [ ] Series episode cards in calendar link back to project editor

**Phase C exit gate:** `PRODUCT_QA.md § Phase C` must pass before Phase D starts.

---

## Phase D — Agency

Exit gate: An agency account can invite team members, manage multiple client brands, send a client approval link, export without watermark, and see platform analytics inside the tool.

See QA gate: `PRODUCT_QA.md § Phase D`

### Team Roles and Invites
- [ ] Workspace roles: `owner`, `editor`, `publisher`, `viewer`
- [ ] Invite by email — sends magic link joining the workspace
- [ ] Role permissions enforced: editor cannot publish, viewer cannot edit
- [ ] Member list in Settings with role management
- [ ] Remove member action

### Client Approval Link
- [ ] `approval_requests` table — export_job_id, token, status (pending/approved/rejected), reviewer_email, reviewed_at, note
- [ ] "Send for Approval" button on completed export
- [ ] Public approval page (no login required) — plays video, approve / reject with note
- [ ] On approval → auto-schedule post if platform was pre-selected
- [ ] On rejection → notification to workspace with reviewer note
- [ ] Approval link expires after 7 days

### White-Label Export
- [ ] Watermark toggled off for Studio and Agency plans
- [ ] `watermark_enabled` on ExportJob defaults to plan-based value
- [ ] White-label flag enforced in `ProcessExportJob` FFmpeg command

### Analytics Pull-Back
- [ ] `post_analytics` table — scheduled_post_id, fetched_at, views, likes, comments, shares, watch_time_seconds
- [ ] Scheduled job fetches analytics daily for published posts (per platform API)
- [ ] Analytics panel on Calendar post detail modal
- [ ] Per-series analytics summary on Series detail page (total views, avg watch time)

### Agency Workspace
- [ ] Agency plan allows creating sub-workspaces (client brands) under one billing account
- [ ] Switch between client workspaces from top nav
- [ ] Billing consolidated to agency workspace owner

**Phase D exit gate:** `PRODUCT_QA.md § Phase D` must pass — product considered full launch-ready.

---

## Decisions Made During Build

| Date | Decision | Reason | Phase |
|---|---|---|---|
| 2026-04-18 | Billing via Paddle, not Stripe | Paddle handles VAT and is merchant of record — simpler global compliance | A |
| 2026-04-18 | Series Lite should move earlier than full calendar | Repeatable content workflows are the subscription hook even before publishing is live | A |
| 2026-04-18 | Treat platform publishing as approval-risk work | YouTube/TikTok direct public posting can require audits and platform policy checks | B |

---

## Blockers

| Blocker | Phase | Raised by | Status |
|---|---|---|---|
| — | — | — | — |
