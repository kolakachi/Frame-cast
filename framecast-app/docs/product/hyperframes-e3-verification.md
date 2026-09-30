# E3 — conversation UI checkpoint

Date: 2026-09-29. **E3 is in progress, not complete.** Local only; no push or paid calls.

## Implemented

- The approved conversation-first layout uses the existing sidebar, colours and
  shared dropdown/player components. A brief and its outputs stay on one page.
  The composer is pinned above mobile navigation and remains available on desktop.
- Users can add files before writing a brief. PNG/JPEG/WebP, MP4 and MP3/WAV have
  inline previews. Each upload has a source/reference choice, reuse attestation,
  progress, removal and retry. Files are private with expiring access URLs.
- A dedicated local upload endpoint intentionally avoids the existing Assets
  uploader's automatic transcription. It verifies actual MIME, ownership/write
  access, expected version and limits (20 attachments, 100 MiB/file, 200 MiB per
  conversation; 1 GiB stored uploads/workspace, separate from staged input quota).
  Workspace locking serializes uploads; a client key and content hash prevent
  duplicate assets after an uncertain response. Rollbacks remove newly written
  bytes. Replaying an old upload does not undo a later attachment removal.
- Uploaded media is stored as a normal Asset and can enter the existing immutable
  input snapshot flow. No storage path or credential is returned to the browser.
  Like saved outputs, uploaded bytes are retained conservatively; deleting or
  archiving an asset does not currently reclaim this local storage. Reclamation
  and full retention policy remain E6, not a promise of unlimited uploads.
- History searches titles, brief text and attached filenames. It supports state
  filters, rename, archive and restore. An active/unknown run cannot be archived.
  The query returns at most 100 matches, disclosed in the UI.
- Unsent briefs survive refresh and conversation switching in session storage,
  scoped to the signed-in user/workspace/conversation. Closing the browser session
  can remove them; server-saved briefs persist. Version conflicts refresh data
  without discarding the draft. Explicitly expired quotes require a new plan.
- Details is hidden initially, docked on wide screens, and modal on phones. Escape,
  focus restoration and native modal focus containment are supported. Closed
  comparison dialogs do not load hidden players. Earlier versions can be compared
  with the current artifact without changing the head; restoring creates a new
  revision. Downloads always target the displayed version. Shared player volume
  control was added alongside seek, mute and fullscreen.
- Image briefs are stored separately. Image generation is **not** implemented in
  this checkpoint. Both API and UI prevent an image brief from starting the fixed
  video sample. The UI makes this limitation explicit.

## Verification

- **138 API tests passed, 1 existing skip; 1,132 assertions.** Includes 41 Create
  tests (201 assertions): private MIME-checked upload, replay, source consent,
  viewer denial, quota/stale-version rejection, no dispatched jobs, snapshot
  handoff, image intent gating, history search and archive lifecycle.
- **15 web result/export guard tests passed**; production frontend build passed
  with the existing bundle-size warning.
- Real isolated HTTP test: two offline Hyperframes renders, attachment staging,
  export registration/replay, restore and an exact source-preserving follow-up.
  No model credentials are used. This remains a scripted execution contract test,
  not proof of creative prompt-following.
- E3 browser test: upload before brief, injected upload failure/retry, private
  attachment preview, draft refresh, conflict preservation, rename/archive/restore,
  filename search, image-brief gating, mobile layout and Escape. No JS errors.
- Render browser regression: stable playback during refresh, save to Videos,
  history selection, two-version playback comparison, mobile overflow and dialog
  dismissal. No JS errors. External browser requests are blocked in both tests.
- Desktop/mobile screenshots inspected locally under ignored
  `hyperframes-worker/artifacts/e3-ui/`; result evidence lives in `browser.json`.

Re-run against the disposable harness documented in
[app integration verification](hyperframes-app-integration-verification.md):
`tests/create-e3-browser.mjs` checks intake/history; `tests/create-browser.mjs`
checks the real offline outputs after `tests/app-http-smoke.mjs`. Use a Playwright
installation via `PLAYWRIGHT_MODULE`, and installed Chrome. Never point these
fixture tests at the normal local account or production.

## Local availability and remaining work

The normal local API/workers were updated after database and service-storage
backups to ignored `artifacts/local-enable/e3-ui-backup/`. No new migration was
needed. Existing files were restored and the offline coordinator restarted.
`kolakachi@gmail.com` (workspace 1) can use `http://localhost:5173/create`.
The backend remains local/allowlisted, and paid execution is hard-disabled.

Still open in E3: actual image generation/edit/variation/animation routing through
existing consent, entitlement and accounting contracts; per-operation pricing;
conversational output settings; generated image result actions; up to three quoted
variants and failed-only retries; composition-aware sharing/scheduling with explicit
confirmation and export freshness checks. The user deferred model comparison and
creative acceptance; the UI does not claim these have passed.

## Evening checkpoint, 2026-09-29

The previous slice was found uncommitted and committed as `ce4b28a` (backend:
image quotes, variants, retry, delivery, migrations, worker media provider)
and `13702fb` (UI: quote card with variations, image actions, delivery
dialog, share/schedule, safe margins, settings panel, `/creation/:token`).
Added on top:

- Library surfacing (`c0ec026`): All Videos opens a composition project in
  its conversation and hides the scene-only Variants action; the asset drawer
  links a saved Create image back to its conversation.
- Conversational settings: `BriefSettings` reads format, length, language,
  silence and no-captions from the brief, applies supported values before the
  quote with an assistant message saying so, and answers unsupported lengths
  and languages with a question. Each reply advances the conversation version,
  so an older quote is invalidated exactly as a settings edit would.
- Copy audit: no illustrative prices or timing guarantees; fixture and paid
  wording checked.

Verification: CreateIntegrationTest 49 tests, BriefSettingsTest 4 tests
(53 passed, 272 assertions); web unit tests 43 passed (the one failure is
the pre-existing affiliate arrival test); production web build passes.
Not run: the browser tests, because no Playwright install is available
here. The E3 exit remains open on that run.

## Slice 1 — shell parity with the approved mockup (2026-09-29, late)

The built screen was compared with `create-ui/agent-new.html` and
`create-ui/agent.html` rendered side by side at desktop and phone sizes. The
layout now follows the mockup: header with title, version status, credit
balance, New creation, Recent conversations and a Details & versions toggle;
right-aligned brief bubbles carrying the files attached for that brief (REUSE
or REFERENCE); WyvStudio replies with speaker and time; the working card with
spinner and "Stop · keeps what is done so far"; the plan and cost as a warning
card with the cost line in its footer; the result card with meta row and
actions; pending uploads as chips above a rounded composer with + Attach,
From library, a Video/Image switch before the first brief and a round send
button; the empty state and examples; a docked Details/Versions panel (output
summary with editable settings, approved facts, files, conversation name and
archive; versions with current/viewing tags and saved outputs); Recent
conversations grouped by day with an All Videos link. The local-preview
banner and pill are gone; fixture honesty stays in the quote and the result
meta. The sidebar lists Create after UGC Ads with a PILOT tag.

Deliberately not copied because they are not true yet: auto-run under 15
credits and free text/colour/size edits (composer note says every paid
creation is quoted), the brand-kit chip, voiceover and thumbnail examples, the
placement overlays and per-video credit breakdown. Those arrive with the plan
turn and later slices.

API change: attachments now return `attached_at`, duration and dimensions so a
chip can sit in the brief it belongs to. Checks: Create suite 49 passed; web
unit tests 43 passed (1 pre-existing affiliate failure); production build
passes; screenshots at 1440×900 and 390×844 compared with the mockup. The two
browser tests were updated to the new labels but not run, because port 8018 is
held by the paid pilot.

## Slice 2 — the plan turn (2026-09-29)

After every brief WyvStudio now answers with a plan before anything is
priced or built. The plan says what it will make, lists the on-screen copy
as editable lines, offers at most three pick-one decisions (each option tagged
INCLUDED or with its credits), lists what is kept as-is, and under View
details shows reused files, scenes with timings, output and any proposed
WyvStudio media. Edits are saved to the plan; Review cost then quotes the
creation, and the quote carries the approved plan. The worker passes it to
the agent as the user-approved direction: exact on-screen copy (also treated
as approved facts), chosen options, kept items, scene order, and no media the
plan does not list. A newer brief makes a plan stale; planning again keeps the
user's edited copy.

Planning is free to the user and costs WyvStudio one model call, bounded by a
daily limit per workspace (`CREATE_PLAN_DAILY_LIMIT`, 40). Planners:
`offline` (deterministic, always used in fixture mode, and the default),
`replicate` (a Claude model on Replicate, default `anthropic/claude-sonnet-5`)
and `anthropic` (Claude API directly, for Opus 5.5; needs `ANTHROPIC_API_KEY`,
caches the system prompt). Whatever the model returns is normalised: only this
conversation's source files, scenes clamped to the length, unknown tools
dropped, decisions without two options dropped, and every price taken from
`CapabilityCatalogue`, never from the model. A planner failure answers 502,
stores nothing and spends nothing.

The planner knows WyvStudio's own tools with their prices (stock video and
photos, AI image, image animation, catalogue or cloned voiceover, library
music, brand kit) and may propose them. Executing proposed media as child
operations is later work; today they are shown with their price and passed
to the agent as allowed, not run.

Checks: Create 52 tests, BriefSettings 5 (57 passed); developer/OAuth/key
suites pass with `DEVELOPER_OPERATION_ACCOUNTING` now pinned off in
phpunit.xml (the local .env had been leaking it in); worker 59 passed; web 43
passed (1 pre-existing); a real browser run against a disposable fixture API
on port 8019 (brief → plan → edit a line and an option → save → review cost →
new brief → re-plan) with no console errors. Two defects found there and
fixed: quoted copy was being read as settings, and re-planning dropped edited
copy. The paid pilot on 8018 was not touched; its router adds the plans table
additively on its next request, and its planner stays offline unless
`CREATE_PLANNER` is set.

## Slice 3 — free edits and auto-run (2026-09-29)

**Free edits.** Compositions now declare HyperFrames variables for their
on-screen lines and main colours (`data-composition-variables`), and the
agent is instructed to do so and to read text through `getVariables()`. The
result card offers "Edit text and colours · FREE": the fields come from the
version's own declarations; applying bakes the new values into the
declarations and queues a render-only run (no agent, no provider, 0 credits,
`free_edit_daily_limit` 60/day). It always makes a new version on top of the
current one, so an older version can be the starting point without being
overwritten. Unknown fields, malformed colours and no-op edits are refused.
Size is deliberately not a free edit: compositions are laid out in pixels, so
a new ratio needs the agent to re-lay the design and stays a priced request.

**Auto-run (owner decision).** A quote at or under `auto_run_credits` (15)
runs without a separate approval, capped at `auto_run_daily_limit` (20) a
day. In paid mode it additionally requires that the user has approved sending
this conversation's brief and media to the provider once
(`create_conversations.provider_consent_at`, recorded on that first
approval). The server re-checks eligibility on the auto approval. Variation
groups and free edits are not auto-run. The working card says "Ran
automatically · up to N credits".

**Proven end to end** on a disposable fixture API with the real worker and
the pinned HyperFrames 0.8.82 container: brief → plan → auto-run → render
(41 s) → free edit of button text and accent colour → render-only run (26 s)
→ version 2 current, with the frame showing "Get 20% off" on the new green.
Driven again through the browser with no console errors. The sample fixture
now declares four variables and the offline agent edits the variable, which is
what renders.

Checks: API 162 passed (Create 54); worker 59; web 43 (1 pre-existing).
Browser tests for port 8018 still not run while the paid pilot holds it.
Still open in this lane: running plan media (AI image, animation, voiceover,
stock) as child operations within the approved amount; placement overlays;
the states sheet; agent-call repricing (currently up to 75 credits a call,
which keeps agent edits above the auto-run line).

## Slice 4 — sandbox media tools (2026-09-29, E4 foundation)

The agent can now edit supplied footage inside the existing render sandbox
(no network, read-only root, no capabilities, 2 CPUs, 2 GB) with a `media`
action: probe, silences, trim, cut (ranges), remove_silence, clean_audio,
loudness, stabilize, speed (0.25–4, pitch kept), crop (9:16, 1:1, 4:5, 16:9
around a focus point), frame, grade (warm, cool, punchy, muted, mono, film).
Each is a fixed ffmpeg recipe with bounded numbers; the model never supplies a
command, filter or path, only a file already in the project. Cut-type
operations return a source map from output time to source time so overlays
stay on the right moment. Clips are limited to 3 minutes. Derived files are
protected like supplied assets, uploaded before the render, stored as private
library assets with `derived_from_asset_id`, operation and parameters, renamed
to their stored name in the composition, and inherited by later runs and free
edits. The planner lists them as free tools.

Proven: every operation run in the locked container on a generated clip
(silence detection found both gaps; removing them took 8.0 s to 5.81 s;
invalid look, path escape and too-short trim refused). Then end to end with
the real worker on a disposable harness: upload a take → build with a
sandbox trim → derived file saved to the library as "Trim · take.mp4" (from
the original, op trim) → free edit inherited both files and rendered. The
harness's SQLite now uses a busy timeout and WAL; production uses Postgres.

Opus 5.5: `ANTHROPIC_API_KEY` is set locally. Two live planner calls against
`claude-opus-5-5`: 23 s / ~$0.05, then after asking for concise fields 16 s /
~$0.035. The plan proposed the new footage tools on its own and listed the
missing offer code instead of inventing one. Planner stays `offline` locally
until `CREATE_PLANNER=anthropic` and `CREATE_PLANNER_MODEL=claude-opus-5-5`
are set, which was not done while the paid pilot is running. The build agent
still runs on Replicate; an Anthropic provider for the worker is next.

Checks: API 163 passed; worker 61 passed. Not in this slice: timestamped
transcripts from the app's transcription service and overlays bound to
spoken words (E4 items 1 and 3), green-screen keying (needs alpha video
support in the renderer).


## Build agent on Claude Opus 5.5 through the app (2026-09-29)

- The build agent can run on Opus 5.5 through the Claude API. Set `CREATE_AGENT_PROVIDER=anthropic` (default stays `replicate`) and `ANTHROPIC_API_KEY` in the local API env.
- The worker never holds the key. It records the attempt, then posts the exact prompt to `POST /internal/create/runs/{id}/attempts/{attemptId}/anthropic`. The app checks the request hash, makes the call with prompt caching, reads token usage, binds the message id and settles.
- Cost is computed from returned usage at $4 in, $20 out, $5 cache write and $0.20 cache read per million tokens, then charged at the pilot tariff of 1 credit per $0.004, capped at 75 credits a call.
- A refused call is recorded against Anthropic's request id and costs nothing. A timeout or an over-ceiling cost holds the attempt as unknown for reconciliation. Automatic reconciliation of unknown Claude API attempts is not built yet; they need a manual check.
- Local test spend has its own $5 ledger, separate from the Replicate pilot's.
- Tests: API suite 715 passed, 1 skipped. Worker suite 64 passed. Node and PHP request hashes were checked to match on Unicode, slashes and control characters.

### Live Opus 5.5 build runs (2026-09-29)

Four local builds of one brief (Brewline cold brew, 15 s vertical, kinetic type), on a separate $5 test budget. Total Anthropic spend was about $1.45, including planner calls and three small API probes.

- The planner worked each time in 17 to 19 s, at about $0.035 a plan. Prompt caching hit on every build call (5,315 cached tokens).
- Run 1 was stopped before any model call. Laravel trims request strings, so the prompt no longer matched its recorded hash. Fixed: the gateway hashes the raw body. Covered by an HTTP test.
- Run 2 stopped after one successful call. PHP's single-threaded test server queued heartbeats behind the 39 s model call. Fixed for the harness with `PHP_CLI_SERVER_WORKERS=4`; production PHP-FPM is unaffected.
- Run 3 ran out of output. Opus 5.5 always thinks adaptively, and thinking counts as output, so the 4,096-token cap cut the file mid-string. Thinking cannot be disabled on this model. Fixed: `output_config.effort` defaults to medium (`CREATE_AGENT_EFFORT`), and the Opus path allows 8,192 output tokens. The worst-case call is still under the $0.30 ceiling.
- Run 4 wrote a full composition and fixed each checker finding (a GSAP relative-tween conflict, occluded and overlapping text), then hit the 2-repair limit. Paid runs now allow 4 repairs; the call and cost limits are unchanged.
- Run 5 passed all automated checks at revision 3. Its own visual review then correctly found clipped letters at 5 s and asked for one more repair, but the 8-call limit was reached. Cost: $0.32 for 8 calls, charged 85 credits.

Open decision: an Opus build needs about 9 to 10 calls with its review. That means raising `max_calls` on the Opus path or accepting fewer review passes. Raising it also raises the most a build can charge.

## Slice 6: word-timed transcripts (2026-09-29)

- **Agent action.** `{"type":"transcript","input":"<file>"}` returns words as `[text,start,end]` on that file's own timeline. Output is capped at 1,500 words and 300 segments for context.
- **App side.** `POST /internal/create/runs/{id}/transcripts` transcribes the run's immutable input copy with OpenAI Whisper word timestamps. The worker never holds the key. Results are cached on the asset against the file's SHA-256, so versions and free edits reuse them. It is free, limited to 30 a day per workspace and to clips up to 10 minutes. The media service's placeholder transcript, returned on provider failure, is refused with a 503.
- **Timing through edits.** The runner transcribes the original once and carries times through the edits the agent made in the run. Trims, cuts and silence removal use the source map. Speed rescales time. Stabilize, clean audio, loudness, crop and grade keep timing. A word whose midpoint was cut is dropped, and a word straddling a cut is clipped.
- **Planning.** Both planners propose the transcript step for supplied video or audio. The catalogue lists it at 0 credits.
- **Tests.** API 716 passed with 1 skipped. Worker 70 passed, including mapping, the runner action and the protocol shape.
- **Live check.** A 4-second spoken clip went through the harness, the container's audio extraction and real Whisper. The first call took 4.3 s and the repeat was cached in 47 ms. Whisper rendered "Save twenty percent" as "Save 20", dropping a word. On-screen copy must come from approved text, not from the transcript.

### First finished Opus 5.5 build (2026-09-30)

With the 12-call limit, the same Brewline brief finished at `preview_ready`: 6 calls, $0.333, 86 credits, 159 s end to end. The 15 s 1080×1920 video used the exact approved copy ("Cold brew, zero wait", "Order today", Brewline) in the requested orange and cream palette. Opus's own review of sampled frames passed. The only font in the sandbox is DejaVu Sans, which limits typographic range; bundling a small licensed font set is the next quality lever.

## Slice 5: public links as style references (2026-09-30)

Owner approved using public X, YouTube and TikTok posts as style references.

- **Intake.** `POST /v1/create/conversations/{id}/references` takes a public https link from an allowlisted host. X edit-history links point at the post. YouTube tracking parameters are dropped. A pinned yt-dlp (`2026.08.19`, installed in the API image per architecture) runs with no config, cookies or playlists. Posts over 5 minutes, live streams and failures are refused with a plain message. It is limited to 20 a day per workspace. The file is stored privately through the normal upload path and attached with purpose `reference`, so it is never renderable. Replays with the same key return the same reference without fetching again.
- **Study.** Cuts come from ffmpeg scene detection. Speech is transcribed with Whisper when present. Opus reads a 4×2 contact sheet at low effort and returns a summary, look, palette, type, motion, structure, techniques to borrow and specific things not to copy. This is best-effort: a failed study still leaves the reference attached.
- **Use.** The planner and the build agent receive the notes with a rule to borrow approach, never content. The Create page has a "From a link" button that shows progress and the one-line summary.
- **Live run, with the owner's link** (DreW, "Made with @claudeai Opus 5.5", 32 s). Fetched and studied in 29 s. The notes were accurate, and they named the orange box character and specific compositions as not to copy. The Opus plan borrowed the calm, escalating, calm structure. The build finished in 4 calls, $0.52, 132 credits and 245 s, on paper texture in the reference's purple and peach palette, with nothing from the original copied.
- **Timeout fix found on the way.** One build call took over 120 s and hit the gateway timeout, which left an unknown attempt of up to $0.30 that cannot be reconciled. The gateway now waits up to 280 s and the worker 300 s. Production PHP-FPM has a 120 s fastcgi timeout; the worker's internal routes need a longer one before this runs there.
- **Tests.** API 717 passed with 1 skipped; worker 70 passed; web 43 passed with the known affiliate failure.

## Slice 7: buying plan media under one approval (2026-10-01)

- **Quote.** Paid video quotes list each purchasable plan item (stock video or photo, AI image, animation, narration, cloned narration, library music, brand kit), up to 6, at its catalogue price recomputed at quote time. The plan's own number is ignored. Sandbox edits such as stabilise or trim are not purchases. The quote's maximum is the build ceiling plus the exact item total, and the one approval covers both. The quote card lists the items.
- **Buying.** Before the build, the worker asks the app for each item in turn (`POST /internal/create/runs/{id}/plan-media/{index}`). The app makes it with the existing adapters, stores it privately with the run's derived files, and settles an accounted attempt (kind `plan_media`) at the listed price. The charge happens only on success; a failed item costs nothing and the agent is told to work around it without inventing a substitute. Narration only speaks approved on-screen lines. Animation needs a supplied photo.
- **Retry.** Items are recorded per plan (`create_plan_media`). A later run of the same plan reuses finished items free and retries only failed ones. A replay within a run is also free.
- **Live run.** One approval (943 credits maximum) covered the Opus build and three items: Pexels stock video (0 credits), an AI image (43 credits) and library music, which failed because the harness workspace has no music (0 credits). The build used both files. Total charged: 128 credits.
- **Follow-ups.** The stock adapter searches with the whole item description and picked a beer-like pour; Opus flagged it honestly. Short search terms per stock item would help. The workspace music library is SoundHelix placeholder tracks, not licensed music, so `library_music` should not be sold as licensed until real tracks exist.
- **Tests.** API 718 passed with 1 skipped; worker 79 passed.
