# Create customer-readiness progress

Started 2026-10-06. Scope and release checks: [create-go-live.md](create-go-live.md).
This tracker separates implementation from deployment and customer verification. No checked item implies a paid test.

| Work | Status | Evidence / next step |
|---|---|---|
| Readiness audit and release gates | Done | Repository review at `304200b9`; L1–L16 recorded in the go-live checklist. |
| L11 durable planning | First implementation complete; rollout pending | Database request journal, atomic admission, dedicated queue job, cache-independent status, queued-request recovery, interrupted-request reporting and browser reconnection implemented. Flag stays off until worker/storage/deployment checks pass. See validation and limitations below. |
| L4 private B2 storage and migration | Implemented locally; rollout pending | Dedicated private disk, per-file storage catalog, verified copy migration, API/worker readers and writers connected. Real bucket, migration/restore drill and cleanup remain release gates. |
| L5/L14 disk capacity and cleanup | First implementation complete; host validation / worker retention pending | API/worker capacity checks, dry-run maintenance, active-use locks, aged-cache cleanup and verified local-copy reclamation implemented. Actual mounts, quotas, worker journals, tombstone/orphan cleanup and alert delivery remain open. |
| L12 build recovery and queue isolation | Ownership and operator recovery implemented locally; automated fencing/host drill pending | Assignment-bound stop records, lease revocation, guarded receipt recovery and atomic saved-output recovery added. Unconfirmed workers still block globally; restart collection, hardware fencing and deployed verification remain open. |
| L13 drain and shutdown | First implementation complete; rollout/host drill pending | Shared database pause, admission checks, active callback continuity, worker SIGTERM drain and operator runbook implemented. Legacy HTTP activity and service-manager stop policy require separate verification. |
| L2/L15 billing policy and alerts | Pending; one historical hold reconciled | Owner-approved recovery of project 227's stale reservation verified live on 2026-10-07; no other holds remained. Planning allowance, full billing-path verification and actionable alerts remain open. No overall cap is added without changing the owner's decision. |
| L3 stale-browser recovery | Implemented locally; deployed browser/CDN drill pending | Explicit reload notice, guarded Create composer recovery, no automatic reload or POST replay. Offline browser smoke passed, including selected files and denied storage. Other forms still require saving/copying edits. |
| L6/L10 repeatable deployment | Web build repaired; worker deployment still pending | Clean lockfile web build passes; Docker contexts exclude host dependencies, and web CI checks added. Worker/API/image revision compatibility, drain-safe rollout/rollback and bounded yt-dlp patch deployment remain open. |
| L16 privacy and restore | Pending | Cross-workspace tests, private B2, isolated database/object restore, secret handling. |
| L1 production canary and acceptance | Pending | Separate paid approval/budget; verify full workflow, audio, motion, revisions and final charges. |
| L7 measured capacity | Pending | Start with one heavy sandbox slot; load-test before raising concurrency. |
| L8 two-customer pilot | Blocked by release gates | Named workspaces/emails only, then expand from observed results. |

## Working rules

- Use offline fixtures and mocked providers for development tests.
- Do not release old holds based only on age, change credits, deploy, or start a paid canary as part of this tracker update.
- Record test results and remaining limitations with each implementation slice.
- Production verification stays pending until tested on the deployed revision.

## Slice 1 — durable planning (2026-10-06)

Implemented locally:

- `create_planning_jobs` persists each accepted request and its terminal result. Admission locks the conversation;
  a duplicate key reuses the request, changed inputs under that key are rejected, and a second active request is blocked.
- `PlanCreateVideo` runs on Redis queue `create-planning`, outside PHP-FPM. Its 1,200-second timeout is below the
  supplied worker's 2,400-second retry interval. Execution uses a database compare-and-set, so duplicate delivery
  does not repeat paid planning. User/workspace access and conversation version are rechecked before planning.
- `create:recover-planning` runs each minute when enabled. It republishes queued requests after a failed/lost
  dispatch, records overdue execution as `needs_attention`, and recovers a committed plan without regenerating it.
  It never automatically replays work that started and has an uncertain result.
- Status survives cache loss. The UI watches the request key, reconnects after reopening a conversation, handles
  queued and recovery states, and does not treat an older plan as proof that a newer request succeeded.
- The local Compose profile `create-planning` includes a worker with the API's existing `api_storage` mount.
  No production worker or configuration was changed.

Validation:

- Ten focused tests passed (51 assertions): acceptance without inline execution, idempotency/conflict handling,
  lost dispatch, interruption without replay, committed-plan recovery, version/access recheck, disabled workers,
  cross-workspace isolation, stable request ordering, and compatibility with the existing after-response path.
- Full `CreateIntegrationTest`: 176 tests, 1,229 assertions passed with `php -d memory_limit=512M vendor/bin/phpunit
  --filter CreateIntegrationTest`. The first run exhausted the local CLI's default 128 MB; it was rerun at 512 MB.
- Changed PHP files passed syntax checks. The Vue script and template compile with `@vue/compiler-sfc`.
  Both existing conversation UI tests pass, and the local Compose planning profile passes `config --quiet`.
- Full web bundle remains unverified: Vite/Rolldown reports `TypeError: Cannot convert undefined or null to object`.
  The default loader fails during config bundling; `--configLoader native` transforms 613 modules then fails in
  Rolldown. The same failure reproduces when the build uses the unchanged HEAD version of `CreateView.vue` via an
  in-memory loader; a minimal Vite build succeeds. This failure is not specific to the planning UI edits, but its
  cause is not resolved. No dependency versions were changed to bypass it.

Rollout prerequisites / remaining L11 work:

1. Resolve L4 storage access first. Production currently has no shared Create storage mount in its checked-in
   Compose file. A separate planning process must see existing uploads, studies and previews. Do not mount an empty
   volume over existing API files; migrate and verify them first. B2 access must use the new storage abstraction.
2. Apply `2026_10_06_220000_create_create_planning_jobs.php` before enabling the flag. Provision one planning worker
   listening on `create-planning`, with the API's configuration/storage and a Redis retry interval longer than its
   timeout. Local opt-in: `docker compose --profile create-planning up -d worker-create-planning` after migration.
3. Verify the scheduler runs `create:recover-planning`, then enable `CREATE_DURABLE_PLANNING=true` on the API,
   scheduler and planning worker together. Restart only after draining existing planning. Enabling the flag makes
   planning requests asynchronous even when a caller omits `async`; clients must handle 202 plus polling.
4. Complete a real-process restart drill and PostgreSQL contention test. Current tests use isolated SQLite and
   fake queue/provider delivery; they do not establish production concurrency or Redis/FPM behavior.
5. Add operator reconciliation and per-provider receipt coverage for interrupted planning. The first slice safely
   blocks uncertain work; it does not yet resolve every partial reference study/question/model call automatically.
   Per-workspace attempt budgets, fairness and alert delivery remain under L2/L7/L15.
6. Verify the web bundle and browser refresh/navigation behavior before rollout. Do not disable the rollout flag
   with active journal jobs: drain and inspect their state first, or the old path could admit competing work.

Next implementation: repeatable worker deployment and revision/rollback checks (L6/L10), then production recovery/restore drills and remaining retention controls. L3 has a tested local browser recovery path; the deployed CDN/rollback drill remains pending. L12 records ownership and operator stop evidence; automatic host fencing and restart collection remain pending. L13 has a local drain implementation; process/host validation remains pending. L4/L11 rollout and production verification remain pending. No new paid canary or production deploy ran. The separate owner-authorized historical hold release is recorded below; account balances and earlier charges were not changed.


## Slice 2 — private Create storage (2026-10-06)

Implemented locally:

- Added a dedicated `create_private` disk, configured only through `CREATE_B2_*`. It does not use the existing
  `b2` alias (which can point to MinIO) or the public media bucket. New writes remain local by default:
  `CREATE_STORAGE_DISK=local`.
- `create_stored_files` records each logical path's authoritative disk, object key, byte count and SHA-256 hash.
  Logical `create-upload://` and `create-private://` URLs and revision paths remain unchanged. Existing uncatalogued
  local files remain readable. A missing or deleted remote object never silently falls back to an old local copy.
- Before remote access, the guard verifies the configured B2 bucket's ACL permits only its owner. Remote writes
  are private and are read back in full for checksum verification before the catalog points at them. Remote readers
  materialize checksum-verified files in a private local cache for ffmpeg and HTTP range delivery.
- Routed Create uploads, frozen inputs, reference sheets/studies, reusable generated images, worker outputs,
  previews, library registration and retention through this storage layer. Scratch inspection/media-attempt
  directories and provider-schema snapshots stay local. Input quota capture uses a workspace database lock.
- Added `create:migrate-storage`: inventory by default; `--apply` copies and verifies without deleting originals.
  Batches can resume with `--after`. Repeated migration verifies an already-copied object. Deleted records are skipped.
- Removed an obsolete local/testing-only guard from signed Create asset delivery. The signature/expiry,
  enabled flag, allowed workspace, active workspace and unarchived-asset checks still apply in production.

Validation:

- The first storage + Create integration run passed 188 tests (1,280 assertions). Added integration checks then
  passed for remote uploads, worker lease protection, range playback with no local original, production-environment
  signed delivery, invalid/expired signatures and suspended workspaces.
- The broader Create/reference selection exercised 257 tests: 256 passed; one reference-study check exposed
  Linux-only font discovery on this Mac. Added the installed macOS font fallback in both sheet builders, then all
  five reference-study tests passed (71 assertions). The entire broad selection was not repeated after that last
  font change; its affected reference-study suite was rerun.
- Fourteen storage tests cover verified writes, fresh-host reads, corruption, migration/resume, dry-run batches,
  deletion tombstones, absent remote objects, invalid paths, public ACLs, unsuitable disks/endpoints and missing
  catalog migration. Tests use fake disks and mocked bucket ACL responses, not a live B2 bucket.
- PHP syntax checks passed for 37 changed/new PHP files; `git diff --check` passed. Syntax checks also found and
  fixed a pre-existing unescaped apostrophe that prevented `FinalLook.php` from loading.
- Existing reference/inspection test fixtures now use valid Create path prefixes and an isolated storage catalog.
  No live B2 upload, paid generation, production migration or deployment ran. The earlier web-bundle failure
  remains unresolved; this storage slice did not change the web UI.

Rollout prerequisites / remaining L4 work:

1. Back up the database and inventory **every host/container with existing Create files**. Verify the catalog migration
   `2026_10_06_230000_create_create_stored_files.php` is applied. Preserve the original API storage before any new mounts.
2. Provision a dedicated **private** B2 bucket and its scoped credentials; configure `CREATE_B2_KEY_ID`,
   `CREATE_B2_APP_KEY`, `CREATE_B2_REGION`, `CREATE_B2_BUCKET`, and `CREATE_B2_ENDPOINT` (B2's HTTPS S3 endpoint).
   The key must permit bucket ACL inspection and required object reads/writes/deletes. Verify this on the actual
   bucket; the fake tests do not prove credentials, permissions, networking or live B2 behavior.
3. Drain Create writes on all hosts before migration. Until L13 exists, use a quiet maintenance window with no
   active planning/builds or outstanding callbacks. Do not migrate during writes, deletes, or retention sweeps.
4. Run `php artisan create:migrate-storage` to inventory the first 100 files; `--prefix=uploads` narrows it.
   Run with `--apply` to copy that batch. Use the printed `--after` path for the next batch. A failed batch stops
   without deleting originals; fix the cause and rerun it. Re-inventory all prefixes/hosts before declaring completion.
5. Set `CREATE_STORAGE_DISK=create_private` consistently on API, planning and other Create PHP workers only after
   copies verify. Workers rendering videos still receive files through the API; they do not receive bucket keys.
   Retain the catalog and private-disk configuration even if new writes are temporarily switched back to local.
   Do not roll back to code that ignores the catalog: it would miss remote-only files or read stale local copies.
6. On a fresh host, restore a database backup plus the catalog and verify uploads, reference analysis, range playback,
   downloads, revision restoration and a new edit from existing inputs. Record checksums and counts. This restore
   drill, live privacy check and API/worker integration remain pending.
7. L5/L14 must bound cache/scratch growth, reject work without disk headroom, reclaim only verified local originals,
   and retry physical deletion failures. Uncatalogued remote objects after a database/upload failure require safe
   orphan reconciliation. Current storage code deliberately retains originals/cache and does not implement that cleanup.
8. Measure copy/download overhead and PostgreSQL contention. Every remote write is read back for verification;
   cache misses require a full download before delivery. Local disk is still needed for staging and active renders.


## Slice 3 — disk admission and safe local reclamation (2026-10-06)

Implemented locally:

- API admission checks cover uploads, reference/planning work, frozen inputs and build claims. The free-space reserve
  is `max(2 GiB, 5% of filesystem size)`, plus 1 GiB for admitting work by default. Existing idempotent planning
  responses remain available. An accepted plan stays queued if its worker has no capacity before execution begins;
  the existing recovery scheduler republishes unstarted requests. No model call or running lease is started by that check.
- Private-storage writes check scratch space before staging and during stream copies, then check local destination
  space before copying. Cache misses check download size against local headroom. Already-cached reads continue when
  space is low. These are point-in-time checks; they do not reserve capacity across concurrent requests.
- The Node worker checks artifact/scratch space before claiming and before sandbox commands, using a default
  `max(8 GiB, 10%)` reserve on each checked filesystem. Low or unknown capacity leaves unclaimed work queued.
  It polls after 30 seconds and emits structured logs when the waiting/available state changes. Mid-run capacity
  failures retain drafts and use existing stop/settlement handling; they do not promise a refund or automatic retry.
- `create:maintain-storage` is dry-run by default. `--apply` deletes only recognized cache/download-temp files that
  have been unused for at least 24 hours. `--originals` additionally reclaims local copies whose catalog points to
  private B2, after checking both the local digest and a fresh full read of the remote object. Unknown, changed,
  missing, corrupt or uncatalogued originals are retained. Remote objects and catalog records are preserved.
- The command limits candidate actions (100 by default). A host-local exclusive lock skips cleanup while any cooperating
  request/job is using a storage path; readers/writers retain a shared lock for their scope. Symlinks are not followed.
  This does not fence unrelated host processes or replace the distributed run-recovery work in L12.
- Hourly local maintenance is opt-in with `CREATE_LOCAL_MAINTENANCE_ENABLED=false` by default. It must run on **each**
  API/planning host that owns a cache; a central scheduler cannot reclaim another host's local files. Existing logical
  orphan retention remains separate. No cache/original cleanup was enabled on production.

Validation: the broad Create/reference PHP suite passed **266 tests / 1,785 assertions**. The worker disk-capacity,
sandbox execution/recovery, input staging and resume suites passed **29 tests**. PHP syntax checks for 16 touched
files, the worker entry-point syntax check and `git diff --check` passed. The real local maintenance dry run found
zero candidates and removed zero files; both storage and scratch capacity checks passed on the local Mac.
All deletions in tests use isolated fake storage. No production inspection, cleanup, resize, deployment, paid
generation, credit change or hold release was performed. Oracle capacity and recovery remain unverified.

Remaining L5/L14 release checks:

1. Verify actual Oracle mount sizes/free space (including the reported 30 GB filesystem versus selected 50 GB volume),
   API scratch and Docker data root. Set `CREATE_WORKER_EXTRA_DISK_PATHS` to a JSON array of existing mount paths.
   The host checker cannot inspect Docker Desktop's VM disk. Keep one heavy render slot until measured otherwise.
2. Record dry-run inventory and complete the B2/database restore drill before enabling local-original reclamation.
   Example: `php artisan create:maintain-storage --originals --limit=100`; add `--apply` only for the verified rollout.
   Backups must include catalog records; local-only files are never candidates for verified-copy reclamation.
3. Add durable recovery/backup coverage before pruning worker run directories. `started.json`, `completion.json`,
   failed drafts, provider receipts, budget ledgers, project media and inputs currently support recovery and stay intact.
   API media-attempt/inspection scratch and uncatalogued remote objects also need explicit lifecycle ownership.
4. Retry failed physical deletions/tombstones, reclaim unreferenced remote generations safely, and enforce concurrent
   workspace/host byte reservations or filesystem quotas. A flood of concurrent uploads or long render can exceed
   point-in-time admission estimates. This slice does not solve those limits.
5. Wire disk warnings/maintenance failures to an operator alert destination with deduplication (L15); current signals
   are structured logs plus command exit status. Verify low-disk behavior and recovery on both actual hosts.
6. Measure cache effectiveness, integrity-check overhead, cleanup scan time, and lock contention. Candidate actions
   are bounded, but filesystem inventory itself is not yet paginated. A continuously busy host may keep cleanup
   deferred; alert on that condition and provide a drained maintenance window.


## Slice 4 — shared drain control and lease monitoring (2026-10-07)

Implemented locally:

- `create_runtime_controls` stores a shared admission pause. `create:drain pause|status|resume` changes or reads it.
  The rollout switch `CREATE_RUNTIME_CONTROLS_ENABLED` defaults to false; no live configuration was changed.
  An enabled process with a missing table/control row fails closed for new admission.
- New planning/build admission and queued execution check the pause. Durable planning CAS and build claims hold
  the control row only during admission, never during provider work. Previously accepted request keys remain
  readable. Queued work is preserved, and active journaled planning proves its execution token before continuing.
- Quotes, new uploads/reference intake and free edits also check the pause. Briefs, saved versions and cancellation
  remain available. The drain does not disable worker authentication, heartbeat, approved provider calls, result
  delivery, stop acknowledgement or settlement. `CREATE_ENABLED=false` remains a separate emergency shutdown.
- Worker `SIGTERM` stops future claims and waits for a claimed build to finish. A claim accepted while the signal
  was in flight is still executed/reported. `SIGINT` retains immediate-stop behavior. Idle disk waits wake on either
  signal. Repeated SIGTERM does not silently change into cancellation.
- `create:check-leases` checks up to 100 expired builds each minute independently of claim traffic and during drain.
  It moves them to `needs_attention` and emits a structured event. It never requeues work, frees uncertain holds,
  marks a worker stopped, or removes the global unknown-worker safety block.
- Added [drain runbook](create-drain-runbook.md) covering rollout, legacy/API activity, systemd signal/grace settings,
  rollback, uncertain work and the checks required before resume. No host or database migration was applied.

Validation:

- Broad Create/reference PHP suite: **272 tests, 1,842 assertions passed**. Six new integration tests cover
  pause/resume, idempotent approvals, preserved queue entries, planning in flight, blocked intake, authenticated
  worker callbacks/settlement, repeated results, expiry detection and retained uncertain holds.
- Worker lifecycle, disk-capacity, sandbox execution/recovery and resume suites: **29 tests passed**. Five new
  lifecycle tests cover signal handling during claims/execution, idle wake-up and one-shot exit codes.
- PHP syntax checks for 17 files, worker/lifecycle syntax and `git diff --check` passed. No paid calls, production changes, credit changes or
  manual hold releases ran. Tests use SQLite and mocked/offline providers; they do not prove PostgreSQL locking,
  live provider outcomes or Oracle/systemd process behavior.

Remaining L12/L13 release work:

1. Prove PostgreSQL contention behavior and consistent control visibility across deployed PHP processes. Complete
   durable planning rollout; legacy after-response execution and intake/quote requests are not in the journal.
2. Verify actual service signal delivery, child-process cleanup and stop grace periods. A supervisor that kills
   the process group or times out can still interrupt a build despite the Node drain behavior.
3. Add host/slot identity and fencing evidence, durable restart recovery and operator receipt reconciliation.
   Unconfirmed lost work still blocks claims globally; no unsafe bypass or automatic paid retry was added.
4. Wire structured expiry/shutdown events to actionable alerts (L15), with an owner and response procedure.
5. Run the real restart/drain/duplicate-callback drills on the deployed revision before customer access. The CLI
   inventory never claims that a host or the whole app is safe to stop solely from database counts.


## Slice 5 — worker assignments and guarded recovery (2026-10-07)

Implemented locally:

- Added a per-run worker assignment: stable configured host label, per-process instance UUID, slot, claim time,
  last heartbeat and internal lease fingerprint. Identified workers check the returned assignment before executing.
  Their local start journal includes assignment and PID, without the lease token.
- `CREATE_WORKER_OWNERSHIP_REQUIRED=false` supports staged rollout; enabling it requires identity on every claim.
  Supplying identity always requires the new table. An existing assignment's recovery requirements apply even if
  the rollout switch is subsequently disabled. Existing legacy runs retain their old explicit recovery path.
- `create:worker-recovery RUN_ID` inspects without mutation. Recording a stop requires the exact assignment ID,
  an explicit operator stop confirmation and an evidence reference. It rejects active/stale/mismatched execution,
  revokes the old lease, frees only execution capacity and preserves attempts and all credit fields.
- Authenticated stop acknowledgements also create durable stop records. Repeating one after a lost response is
  safe for assigned runs. A repeat can acknowledge the saved outcome, never execute work or spend again.
- Receipt reconciliation, unstarted-allowance release, settled-run closure and saved-output recovery require the
  stop record for assigned runs. An uncorroborated `--worker-stopped` boolean no longer bypasses that requirement.
  `--close-settled` is an explicit separate action, allowed only when settlement evidence is complete.
- Saved-output recovery now rechecks state under conversation/run locks, rejects incomplete settlement receipts
  and wraps its temporary lease/delivery in one transaction. Failure restores the prior failed state. No new
  provider request or render is needed to save the existing output.
- Admin trajectory data/timeline includes assignment and stop events without lease secrets or raw evidence notes.
  Added [operator recovery runbook](create-worker-recovery-runbook.md) and linked it from the drain procedure.

Validation:

- Broad Create/reference PHP suite: **280 tests, 1,917 assertions passed**. Eight new integration tests cover
  required identity, heartbeat ownership, instance mismatch, assignment-specific stop evidence, unchanged holds,
  revoked late callbacks, repeated stop acknowledgements, guarded reconciliation, read-only inspection, admin-only
  trajectory visibility, fully settled closure and rollback after an injected saved-output delivery failure.
- Worker identity/lifecycle, disk-capacity, sandbox execution/recovery and resume suites: **32 tests passed**.
  New identity tests cover stable host labels, fresh process IDs, invalid settings and mismatched claim responses.
- PHP syntax checks for 11 touched/new files, worker/identity syntax and `git diff --check` passed.
- All provider behavior is mocked/offline; only isolated local test fixtures are rendered. No production migration,
  host stop, deployment, paid generation, real credit adjustment or manual release of a real hold ran.

Remaining L12 limitations / release gates:

1. Stop records are attestations from the authenticated coordinator or an operator, not independently verified
   hardware fences. The operator must inspect the actual original process and containers or prove infrastructure
   fencing. A worker label, PID, restart or timeout alone is insufficient.
2. Identity labels use the existing shared worker authentication. Separate host registration/credentials remain
   future work if workers become separate trust boundaries. Slot metadata does not increase concurrency.
3. PostgreSQL locking/races, live Oracle shutdown/restart and provider-receipt drills remain unverified.
4. Automatic host fencing, interrupted-journal collection on restart and actionable alerts are still pending.
   The global unconfirmed-worker block remains. There is no automatic paid retry or age-based hold release.
5. Worker journal retention/restore remains open. Existing worker directories and recovery evidence are preserved.

## Slice 6 — browser recovery and clean web builds (2026-10-07)

Implemented locally:

- Missing route chunks/Vite preloads now show a persistent, accessible reload notice. Recovery is explicit:
  there is no automatic reload, failed-navigation replay or repeated API write. Repeated failures share one notice.
- Create checks its current state before that reload: in-flight local requests/planning, selected local files,
  changed plan selections and open editors block it. Composer text is saved synchronously and checked by reading
  it back from user/workspace/conversation-scoped session storage. Storage refusal keeps the page/text in place.
- The notice reloads the current URL. Saved server-side work remains available through the existing request
  status/reconnection paths. Other forms require saving/copying edits; this does not serialize every form or file.
- Both nginx configurations mark entry HTML `no-store`; the standalone web server now returns 404 for absent
  `/assets/` files instead of returning HTML. Hashed assets remain cacheable. CDN/header behavior is not yet verified.
- Diagnosed the earlier web build failure: the mixed local install loaded Rolldown 1.2.6 against native binding
  1.0.0-rc.12. Enforcing native version checking exposed the mismatch. Removed the direct Darwin-only binding
  dependency; its matching version remains a transitive optional dependency selected by Rolldown.
- Web Docker installs use the frozen Yarn lockfile with platform selection enabled. Docker ignore files prevent
  local dependencies and local env files from overwriting/entering the clean build. The explicit prebuilt `static`
  target retains access to `dist`. Existing local `node_modules` and unrelated work were preserved.
- Added a web release-check CI workflow and [web release runbook](create-web-release-runbook.md). The CI workflow
  is not deployed/run here and is not yet a prerequisite of the existing production deploy workflow.

Validation:

- **17 web unit tests passed**, including four recovery tests for browser error classification, duplicate notices,
  explicit/single-use reload, offline state, blocked drafts and guard cleanup/failure.
- A fresh temporary Yarn 1.22.22 installation from the committed lockfile passed the **full production web build**
  (Vite 8.0.3 / Rolldown 1.0.0-rc.12), including the modified Create view. A large-chunk warning remains.
  This resolves the clean-build blocker recorded in slice 1; the existing mixed development install still needs
  a lockfile reinstall when its dev server can be stopped.
- An isolated headless Chrome smoke passed against that built app: actual missing Create route chunk, notice,
  explicit reload, unsent composer restoration, selected-file protection and storage-write refusal. It intercepted
  all API requests, blocked external traffic and asserted **zero API writes**. Initial fixture failures (API base
  URL and absent owner role) were corrected before the passing run.
- `git diff --check` passed. No production deployment, live API/B2 request, generation, credit adjustment or hold
  release was performed. No PHP code changed in this slice, so the prior 280-test PHP result was not rerun.

Remaining release checks:

1. Run the Linux ARM image build, origin/CDN cache checks, old-tab deploy and rollback drill on the actual target.
   Main-entry cold-start failures cannot use a notice bundled inside that same entry file.
2. Form fields outside the composer and local file selections are not restored. Save/copy edits before closing
   editing panels; other app pages only display the reminder. Manual refresh, tab closure and storage eviction
   remain outside this recovery path. Extend draft coverage if the pilot requires those guarantees.
3. Make CI validation a production deployment prerequisite, then add coordinated API/worker/sandbox/art-pack
   release manifests, bounded drain waits, atomic worker activation and compatible rollback. The existing deploy
   workflow still restarts the stack without the new drain procedure; this slice does not make it customer-safe.
4. Deploy and validate the existing yt-dlp patch, database migrations, private storage and durable planning in the
   documented order. Maintain the audience restriction until the live acceptance and recovery gates pass.

## Owner-authorized historical reservation recovery (2026-10-07)

The owner explicitly approved cancellation of `op_01m3e7p2744aknq2e815pgrwq7` after read-only inspection.
This is a production data repair, separate from the undeployed readiness implementation above.

Evidence and action:

- Workspace/pool 1, quote `q_01m3e7p18kmvp7etsh1qtz6c4n`, project 227, “Smoke: AI images 15s.” The operation
  retained 368 reserved credits, zero attributed spend and three nonterminal job rows from September 26.
- Project 227 was `ready_for_review`, with completed generation stages and a successful recorded render.
  Its six separate ledger debits totaled 138 credits (three images at 43 and three speech calls at 3), all with
  null operation attribution. There was no debit attributed to the stale operation and no active advisory fence
  observed in the initial inspection. Zero operation spend did **not** mean the project incurred no cost.
- The existing incident record in `api-mcp-gap-backlog.md`, A6, identifies this exact project/quote: a nested
  synchronous dispatch falsely marked its first queued job as a repeat. Accounting was temporarily disabled and
  the video restarted through the app. Fix `91387e95` followed on September 26; the old reservation survived.
- Acquired the deployed exclusive operation fence, locked the pool/operation in a transaction and rechecked
  identity, state, completed project and expected ledger entries. Called the existing
  `OperationAccounting::cancel` method. Postconditions verified cancelled operation/jobs, zero remaining hold,
  unchanged total balance, unchanged project ledger and preserved project state. A structured operator log records
  the reason and outcome. No grant, refund, regeneration, provider call, job restart or deployment was performed.
- Verified result: **788 total, 0 reserved, 788 available**. The prior 138 credits remain charged. A subsequent
  production-wide aggregate found no positive reserved balances: 18 operations completed, one failed, one cancelled.
- Accounting configuration is enabled in the API and all three queue containers. Live API file hashes for
  `AccountedJob.php` and `OperationAccounting.php` match the locally inspected corrected implementation. This is
  limited configuration/source evidence, not proof of every live billing scenario or every worker's loaded code.

Remaining L2/L15 work: detect aged/nonterminal reservations and charge-attribution inconsistencies, alert an
operator with project/job/ledger evidence, and provide an auditable reconciliation path. Do not automatically
release holds by age or retroactively infer operation attribution from project ID alone. Complete real-queue
normal/failure/retry/cancellation billing checks before opening customer access. The Create Standard canary
remains pending even though its earlier credit blocker is removed.

## Production canary media-loss incident (2026-10-07)

- Conversation `f44e3fc6-c315-43b3-a141-71f82678eb70`, latest run
  `e9f236dd-e82e-4a6f-a7ba-8680ce8a5474`, failed at 00:29:02 UTC with
  `Plan media download failed`, before any composition attempts. Its operation spent zero credits and
  retains zero reserved credits. This failure is separate from account credit availability.
- Both succeeded plan-media rows pointed to missing local API files: narration asset 5031 and music
  asset 5032. The API container was created after the earlier run and has no persistent storage mounts.
  This is consistent with media loss when the container was replaced; the database records survived.
- Recovered both original files from the earlier worker run's `inputs/source` directory. Checked byte
  sizes (485,804 and 2,822,478) and SHA-256 against the stored records before restoring the exact storage
  paths. Verified again as the API user using the deployed `InputSnapshotService::verify` method.
  Private recovery copies also remain outside the container at
  `/home/ubuntu/create-recovery/f44e3fc6` on the production host. Structured operator log:
  `operator.create_media_restored`. No ledger changes, paid calls, run retries or deployments occurred.
- Local worker diagnostics now identify the asset and HTTP status on download failure without logging
  response bodies or credentials. Seven plan-media tests pass, including a missing-media regression that
  confirms failure stops later purchases. This diagnostic change is not yet deployed.
- **Still blocked for customer launch:** deploy and verify durable private storage (L4/L11), including
  container-replacement/recovery drills. These two repaired files alone are not a durable storage rollout.
  The prior run `242e9ace-3762-419d-bd65-640fe5c2e023` failed separately with `Model budget exhausted`
  after 89 credits of recorded spend. Media recovery does not resolve that limit or establish a successful
  canary. Check the next approved build budget before another paid attempt.
