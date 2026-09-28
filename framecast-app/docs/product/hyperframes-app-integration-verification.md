# Create — local app integration

Date: 2026-09-29. **Offline agent integration verified; full E2 and production acceptance remain open.**

Current evidence is in the final “E2 agent bridge and lifecycle” section. Earlier dated sections describe checkpoints, not the current implementation.

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

- The E1 runner, immutable input staging, follow-up edit bridge, storage quota/cleanup and PostgreSQL admission races are now verified offline (see latest section). A real creative provider and verified cost adapter are still gated.
- Provider-call receipts, cost attribution, bounded paid reservation/debit/settlement, unknown-cost reconciliation. PostgreSQL admission/claim/attempt races now pass; paid settlement races remain unverified. Paid admission currently refuses to run even if `CREATE_MODE` is changed.
- Composition Project discriminator, scene-operation guards, final Asset/ExportJob registration and final-output lifecycle adapters. Local input quotas/orphan cleanup are implemented.
- Direct upload progress/retry, image creation/editing/animation routes, generated-media approvals, final share/schedule/approval and variants.
- Speech-timed editing and source maps, plus remaining desktop/mobile acceptance cases.
- Model/creative comparison, measured customer pricing and explicit production pilot approval.

Do not tick E2 or E3 complete from this fixture slice. Do not publish it as a customer-ready prompt-to-video experience.


## E2 input handoff — 2026-09-28

Implemented as a separate backend checkpoint; **E2 is not complete**.

- Quote preparation copies supported workspace-owned managed objects into private
  local snapshots, before taking conversation/credit-pool locks. SHA-256, actual
  MIME, byte size, source/reference purpose and bounded transcript text accompany
  each file. The base revision's bundle and hash are frozen into the quote.
- PNG, JPEG, WebP, MP4, MP3 and WAV are accepted after inspecting bytes. Arbitrary
  HTTP URLs, SVG/HTML and unrecognized formats are refused. Limits are 100 MiB per
  file and 200 MiB per quote. Failed captures remove their completed snapshots.
- Approval rechecks asset availability. Changing the original stored object after
  quoting does not change approved snapshot bytes. Archiving/deleting an asset
  blocks approval or subsequent worker download.
- Worker input downloads require the private worker credential plus the current
  run lease, active allowlisted workspace and matching run asset. Expired,
  cancelled, wrong-asset and changed-snapshot requests are rejected. Storage paths
  stay server-side; the model gets neither storage credentials nor arbitrary URLs.
- Host staging validates filenames, byte bounds and hashes, separates references
  from reusable sources, and saves the base bundle without overwriting an earlier
  attempt. The fixture renderer deliberately does not consume those attachments.

Verification: **50 Node tests pass** (45 E1 + 5 input-staging cases). **110 API
regression tests pass, one existing skip, 983 assertions.** Tests cover private
HTTP downloads, expired/cancelled/wrong leases, archived assets, changed snapshots,
source/reference preservation, size limits, traversal, cleanup and base revisions.
The legacy developer tests require their explicit accounting-off baseline when
running alongside a local `.env` that enables accounting; Create tests enable it
in their own setup. Use `-e DEVELOPER_OPERATION_ACCOUNTING=false` in the disposable
PHP test container command.

Real disposable HTTP smoke passed: attachment → quote → approval/replay → leased
input download → hash-verified staging → offline render → authenticated MP4
retrieval → restore-as-new. Run `e28cfee9-b00e-4891-a713-a106b4714e1f` produced a
247,846-byte MP4. Evidence is in the ignored `artifacts/app-integration/evidence.json`.
The test used a synthetic image in temporary local storage and a disposable SQLite
account, not customer media. **Zero paid calls.**

Still pending: connect the model adapter to durable execution/receipts, account
for known and unknown provider costs, inherit exact asset sets on follow-up/restore,
clean expired unused snapshots with storage quotas, and register final app artifacts.
No production rollout, paid enablement, or full E2 completion is claimed. The
running normal local app has not been rebuilt for this checkpoint; verification
used the current source mounted into the disposable API harness.


## E2 attempt accounting — 2026-09-29

This is a verified accounting foundation, **not paid-agent enablement**.

- New additive migration: `2026_09_29_000000_create_composition_attempts.php`.
  Attempts are unique per run/key, bind a request hash, and carry the immutable
  quoted operation kind/provider/model/credit and cost ceilings. No prompts,
  source files, provider keys or model output are stored in receipt rows.
- A start is recorded before execution. Its replay returns `may_execute=false`;
  it cannot authorize a second provider call. Per-kind attempt ceilings and pending
  credit ceilings are checked while locking the run and shared operation.
- Confirmed worker receipts settle once through `CreditService` under the shared
  operation context. Ledger metadata names the exact run and attempt. Conflicting
  receipts, oversize costs and stale leases are rejected. Provider receipt IDs
  are unique per provider. Identical settled receipt replay makes no new debit.
- Unknown outcomes preserve the reservation and capacity and mark the run and
  operation `needs_attention`. Pending attempts also register in the existing
  operation dependency table. Finishing/cancelling a run cannot bypass them.
  Late/unknown paid receipts still require a future provider-verification and
  reconciliation workflow; there is no automatic retry or release.
- The offline render worker now uses this start/settle lifecycle. Uncertain
  receipt acknowledgements pause work instead of re-running it. The fixture
  recovery command only reconciles zero-cost offline attempts after explicit
  operator confirmation that the renderer stopped; it cannot release paid ones.

The public quote path still authorizes only one zero-credit offline render.
`create.paid_execution_enabled` is hard-disabled with no environment switch.
Synthetic tests exercise a hypothetical seven-credit attempt to prove atomic
ledger integration; this is **not a selected customer price**. The generic
settlement rule charges the quoted attempt amount on success or a known billable
failure, and zero on a confirmed zero-cost failure. A verified provider adapter
must supply actual usage/cost evidence before this rule can serve customers;
worker claims alone are not proof of an invoice. No model call was made here.

Verification: **55 Node tests; 115 passing API tests, one existing skip, 1,012
assertions**. Tests include replayed starts, changed request hashes, attempt and
credit ceilings, unknown outcomes, cancellation, receipt replay after completion,
per-attempt ledger attribution and lost acknowledgements. SQLite tests do not
certify concurrent PostgreSQL admission; that remains a release gate.

Disposable HTTP smoke passed on run
`230d2bb0-e85c-4e6b-93b1-3e606d0e02c3`: frozen input → approval → recorded render
attempt → real offline render → settled zero-cost receipt → download → restore.
Database inspection confirmed the attempt and dependency completed, the parent
operation completed with zero reserved/spent credits, and a 247,846-byte MP4.
The normal local app was not migrated/rebuilt for this checkpoint, and production
was untouched. Deploy the new migration before running the updated coordinator.

Remaining E2 work includes the real model execution bridge and verified cost
adapter, operator reconciliation for paid/late results, final Project/Asset/
ExportJob registration, inherited asset/retention handling and PostgreSQL race
verification. E2 stays open; creative benchmarking stays separate.


## E2 agent bridge and lifecycle — 2026-09-29

**Current state: significant backend gates verified offline; E2 remains open.**

- `composition-agent.mjs` connects the existing E1 runner to the exact frozen
  conversation, base bundle, source/reference metadata and approved attempt policy.
  Provider, tools, execution arguments and credentials remain host-owned. Every
  agent call is durably admitted and settled before its output reaches a tool.
- Sources are copied into the protected render workspace. Reference-only files
  remain outside it. The local provider is an explicit scripted contract probe,
  not an AI model: read → text patch → check → snapshots → finish. It does not
  interpret arbitrary prompts. Paid execution and provider credentials stay off.
- Follow-up quotes and restored versions inherit original input bytes, even after
  a library URL changes. A role change on an inherited asset is rejected rather
  than silently turning reference media into footage. Old snapshots are not
  deleted if preparation of a new quote fails.
- Local input storage is capped at 1 GiB per workspace, serialized with a host
  filesystem lock. The existing 100 MiB/file, 200 MiB/quote and 20-file caps remain.
  `create:cleanup` removes orphaned inputs/previews older than 24 hours. It keeps
  all admitted-run inputs, unexpired quote inputs, saved revision artifacts and
  files for active/unknown runs. Local enabled schedulers run it hourly. This is
  deliberately conservative, not a full conversation-deletion/export-retention
  policy. No cleanup or migrations were run on the normal app database.

Verification:

- **57 Node tests pass.** New cases verify context forwarding, reference exclusion,
  protected source bytes, exact follow-up diff, and lost settlement stopping tools.
- **117 API tests pass, 1 existing skip, 1,021 assertions.** Includes inherited
  bytes after restore/library change, role changes, quota refusal and retention.
- Real disposable HTTP flow performed **10 accounted offline agent calls** and
  two real Hyperframes renders: initial creation → private MP4 → restore → follow-up
  quote → exact source-preserving edit → second saved revision. Initial MP4:
  **241,021 bytes**. No paid calls. Run IDs:
  `4c73f39f-70a6-44de-b782-15813698751d`,
  `57a976c7-b718-451c-9e30-a1fcf418b2d4`.
- Real **PostgreSQL 16** concurrency proof passed with three separate PHP processes
  racing each of approval, claim and attempt admission: one operation, one lease,
  one authorized execution. Running cancellation followed by lease expiry retained
  unknown capacity and did not requeue work. Harness:
  `api/tests/Support/create-pg-concurrency.php`. It refuses any database other than
  `create_e2_proof` on `create-e2-pg` and requires `CREATE_PG_PROOF=1`; use an empty
  disposable database in an isolated Docker network, never the local app database.

Still required for full E2: verified real-provider receipts/unknown reconciliation;
final Project/Asset/ExportJob relationships and scene-editor guards; final-output
access/lifecycle adapters and broader recovery/settlement concurrency tests. Model
creativity remains deferred separately. Current changes were tested from mounted
source in disposable containers. The normal local API and long-running coordinator
must be rebuilt/migrated/restarted together before they use this version. No push,
production deployment or additional paid usage was performed.
