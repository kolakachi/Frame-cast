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

