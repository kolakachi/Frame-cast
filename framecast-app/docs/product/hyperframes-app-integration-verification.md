# Create — local app integration

Date: 2026-09-28. **Local fixture integration, not production acceptance.**

The user asked to finish integration before model comparisons. E1 creative evaluation is deferred rather than accepted. This batch makes the Laravel app and Vue conversation talk to the isolated Hyperframes renderer. It does not enable new paid tests or increase the existing $5 cap.

## Implemented

- `/create/:conversationId?` in the existing app shell, preserving the sidebar and colors. Create navigation appears only after the API confirms availability.
- Persistent briefs, searchable recent conversation history, rename/archive, asset-library attachments with separate source/reference roles, optional details panel, version selection and restore-as-new.
- Inline custom MP4 player and authenticated private download. A conversation refresh does not recreate the blob URL for an unchanged revision.
- Workspace-scoped APIs, write-role checks, feature allowlist and local/testing environment gate. Developer keys cannot enter the session API namespace.
- Optimistic conversation versions, expiring frozen quotes, explicit approval, idempotency with mismatched-payload rejection, one active run per workspace and ten admissions per day.
- Shared `ApiQuote` / `OperationAccounting` records rather than another credit balance. The fixture is zero credits, so this proves admission/capacity/lifecycle, **not paid debit settlement**.
- A separate private worker credential, hashed per-run lease tokens, monotonic progress, stale callback rejection, content-addressed private files, immutable results and preserved conflicting drafts.
- One local render host at a time. PostgreSQL claim decisions use a transaction advisory lock; SQLite unit tests are not proof of real concurrent PostgreSQL behavior.
- The host coordinator executes fixed Docker argument arrays, runs the existing isolated renderer, validates its result and uploads only a verified MP4. Model credentials are neither read nor mounted. The render container has no network or Docker socket.
- Cancellation before claim closes the operation. Running cancellation waits for the worker. Expiry becomes `needs_attention` and retains capacity; it does not queue a replacement.

## Verification

- New `CreateIntegrationTest`: 19 tests covering isolation, write roles, message replay/order, reference roles, quote expiry/staleness, disabled accounting, paid-mode refusal, capacity, cancellation, lease expiry, progress ordering, immutable completion, conflicting drafts, restore, recovery and HTTP contracts.
- Combined Create and developer API suite: **104 passed, 1 existing skip, 956 assertions** in a disposable network-disabled container using in-memory SQLite.
- Existing E1 agent suite: **41 passed**. No paid calls.
- Vue production build passes. Existing bundle-size/dynamic-import warnings remain.
- HTTP smoke: brief → approval → repeated approval with the same key → worker claim → real 15-second render → private download → restore to a new revision. Output: **247,846 bytes**, decoded and checked by the renderer. **Zero paid calls**.
- Machine-local evidence: `hyperframes-worker/artifacts/app-integration/`. Media, screenshots and fixture IDs are not tracked as source code.

Browser acceptance passed: desktop, mobile, stable playback during brief-save refresh, history selection, dialog Escape, no horizontal overflow and no JavaScript exceptions. Evidence is in `browser.json` from `tests/create-browser.mjs`. That test uses the real Create API and fixtures for unrelated app-shell endpoints; it checks desktop/mobile layout, history selection, dialog dismissal and playback continuity during a real brief-save refresh. It is not an authentication or realtime-service integration test.

## Run locally

Prerequisites: the existing local API image, the E0/E1 Hyperframes image, a supported Node runtime, and Docker. Keep the production stack untouched.

For a normal local app environment:

```dotenv
CREATE_ENABLED=true
CREATE_WORKSPACES=<your local workspace ID>
CREATE_MODE=fixture
CREATE_WORKER_TOKEN=<new random secret of at least 32 characters>
DEVELOPER_OPERATION_ACCOUNTING=true
```

Do not enable shared accounting on a production database just to try this feature. Apply the migration to the intended local database first. Use a separate disposable environment when the local database contains important work.

The new migration is `2026_09_28_120000_create_composition_conversations.php`. No existing project or scene records are changed. Start the host-side coordinator with the same worker token:

```sh
CREATE_API_URL=http://localhost:8000 node hyperframes-worker/agent/app-worker.mjs
```

Supply `CREATE_WORKER_TOKEN` through the process environment. `DOCKER_BIN` may specify the Docker executable. `--once` processes at most one queued run. Only loopback API origins are accepted in this local bridge.

The UI intentionally says **sample**, **preview** and **no paid AI**. It saves arbitrary briefs but renders the fixed 15-second synthetic sample; it does not claim to follow the brief or consume the attached assets.

### Disposable HTTP test harness

`api/tests/Support/create-http-fixture.php` is an explicit PHP built-in-server router, not a public application route. It refuses to start unless `CREATE_HTTP_FIXTURE=1` and `DB_DATABASE=/tmp/create-fixture.sqlite`. It constructs only a disposable SQLite schema and test account. Mount `storage`, `bootstrap/cache` and `/tmp` as writable temporary filesystems; mount source read-only. Bind the server to a loopback-only host port, e.g. `127.0.0.1:8018`.

Start it with `php -S 0.0.0.0:8000 tests/Support/create-http-fixture.php` inside that disposable container. Set `APP_ENV=testing`, a new test `APP_KEY`, and a random `CREATE_WORKER_TOKEN`. The browser session credential is the deliberately fixed **test-only** `local-create-fixture`; it grants no access to the real app.

```sh
CREATE_API_URL=http://localhost:8018 node hyperframes-worker/tests/app-http-smoke.mjs
```

Pass the same worker token via the environment. This test creates its own conversation and invokes the host coordinator. No provider token is needed. It writes a local preview and evidence JSON.

For browser checks, start Vite on port 5188 with `VITE_API_URL=http://localhost:8018`, `VITE_REVERB_APP_KEY=local-fixture` and an unused local realtime port. The existing app shell requires a key even though this test does not use realtime. Install/use Playwright separately and run `tests/create-browser.mjs`; `PLAYWRIGHT_MODULE` can point to an existing Playwright ESM module and `PLAYWRIGHT_CHANNEL=chrome` uses installed Chrome. External browser requests are blocked by the test.

## Recovery

1. Locate `wyv-create-<run UUID>` in Docker and the corresponding local `artifacts/live/app-<UUID>/` journal.
2. Confirm that the container stopped. If Docker is unavailable, the outcome remains unknown; do not release the hold.
3. For an expired **fixture** run only, run:

```sh
php artisan create:reconcile-fixture <run UUID> --worker-stopped
```

This revokes the lease, cancels the shared operation and releases capacity. Old callbacks are rejected. Previously completed revisions remain available. A completion whose callback was lost is retained locally for investigation; this command does not automatically promote that file or repeat a render.

## Still required before the integration is complete

- Wire the existing E1 agent into this durable bridge, stage immutable workspace assets and support source-preserving follow-up edits. Fixed-fixture success does not verify those paths.
- Provider-call receipts, cost attribution, bounded paid reservation/debit/settlement, unknown-cost reconciliation and PostgreSQL concurrency verification. Paid admission currently refuses to run even if `CREATE_MODE` is changed.
- Composition Project discriminator, scene-operation guards, final Asset/ExportJob registration, lifecycle quotas and retention cleanup.
- Direct upload progress/retry, image creation/editing/animation routes, generated-media approvals, final share/schedule/approval and variants.
- Speech-timed editing and source maps, plus remaining desktop/mobile acceptance cases.
- Model/creative comparison, measured customer pricing and explicit production pilot approval.

Do not tick E2 or E3 complete from this fixture slice. Do not publish it as a customer-ready prompt-to-video experience.
