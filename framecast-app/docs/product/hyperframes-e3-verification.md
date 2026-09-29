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
