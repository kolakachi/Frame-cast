# Create on production: what is done, what is left

Status on 2026-10-06, 21:30 UTC. The plan and its reasoning are in `create-production-plan.md`; this page tracks the
rollout itself. Every pending Create item is in `create-pending.md`.

**Today:**
- **Where it is:** Create is live on production for the team only: `@wyvstudio.com` accounts and kolakachi@gmail.com.
- **Planning:** works.
- **Builds:** the first paid test build failed. The fix is deployed. The re-run remains unverified.
  Update 2026-10-07: the owner authorized release of the stale 368-credit reservation; workspace 1 now has
  788 total / 0 reserved / 788 available. No new paid canary was started (see L1).

**Customer readiness review, 2026-10-06:** keep access restricted to the team. A deployed fix and a successful
claim are not evidence of a completed customer workflow. The review below checked repository code at `304200b9`;
it did not recheck the remote servers, change credits, release holds, or start paid calls. Server measurements and
deployment results above and below are the rollout record, not fresh measurements from this review.

Implementation status is tracked in [create-go-live-progress.md](create-go-live-progress.md). L11 now has a
locally implemented request journal and dedicated queue job behind `CREATE_DURABLE_PLANNING=false`; it is not
deployed or enabled. The existing after-response path remains active until that rollout is completed.

## Where things are

### Servers

| What | How to reach it | Notes |
|---|---|---|
| Production (API, web, database) | `ssh framecast-prod` (`ubuntu@132.145.195.157`, key `~/.ssh/framecast.key`) | Oracle A1, 2 cores, 12 GB, ARM. App at `/opt/framecast/app/framecast-app`; compose file `docker-compose.prod.yml`. Private IP `10.0.0.140`. |
| Build worker `framecast-create` | `ssh framecast-create` (`opc@150.136.90.107`, key `~/.ssh/create-feature-primary.key`) | Oracle A1, 4 cores, 6 GB, 30 GB disk, Oracle Linux 9. Private IP `10.0.0.18`, same subnet as production. |
| SSH host entries | `~/.ssh/config` (backup made before the `framecast-create` entry was added) | |

### Keys and settings (paths only; values are never copied into docs)

| File | Holds |
|---|---|
| Production `/opt/framecast/app/framecast-app/api/.env` | `ANTHROPIC_API_KEY` (same key as local) and `CREATE_WORKER_TOKEN` (must match the worker's). Also the Create settings below, the B2 keys (`B2_KEY_ID`, `B2_APP_KEY`, `B2_BUCKET_NAME`, `B2_BUCKET_ID`, `B2_ENDPOINT`, `B2_REGION`; `FILESYSTEM_DISK=b2`), `REPLICATE_API_TOKEN` and the mail settings for alerts. |
| Production `api/.env.bak-20261006204838` | The `.env` from before the Create settings were added. Restore it to undo them. |
| Worker `/etc/wyv-create-worker.env` (root only, mode 600) | `CREATE_WORKER_TOKEN`, `CREATE_API_URL=http://10.0.0.140`, `CREATE_AGENT_LIVE=1`, `CREATE_TOOL_MODE=1`, `DOCKER_BIN=/usr/bin/docker` |
| Worker `/etc/systemd/system/wyv-create-worker.service` | The coordinator service: runs as `opc`, restarts always. |
| Local `/Users/user/gigs/frame-cast/.env.create-b2` (git-ignored) | The private bucket's key (`CREATE_B2_KEY_ID`, `CREATE_B2_APP_KEY`), scoped to `wyv-create-private`. Production holds the same in its `api/.env` (added 2026-10-07; backup `api/.env.bak-20261007070628`), with `CREATE_B2_BUCKET=wyv-create-private`, `CREATE_B2_ENDPOINT=https://s3.us-east-005.backblazeb2.com`, `CREATE_STORAGE_DISK=create_private`. |
| Local `framecast-app/api/.env` | The local copies: `ANTHROPIC_API_KEY`, the local `CREATE_WORKER_TOKEN`, the B2 keys and the vendor keys. Never committed. |

**Create settings on production** (appended to its `.env` on 2026-10-06):

| Setting | Value | Meaning |
|---|---|---|
| `CREATE_ENABLED` | `true` | The off switch. |
| `CREATE_MODE` | `agent` | |
| `CREATE_PAID_EXECUTION_ENABLED` | `true` | Real model calls are allowed. |
| `CREATE_AGENT_PROVIDER` / `CREATE_PLANNER` | `anthropic` | |
| `CREATE_PLANNER_MODEL` | `claude-sonnet-5` | |
| `CREATE_RUN_DAILY_LIMIT` | `50` | Builds per workspace per day. |
| `CREATE_PILOT_BUDGET_ID` | `production` | |
| `CREATE_PILOT_BUDGET_MICROUSD` | `0` | No total spend cap (owner decision). Users' credits limit each build. |
| `CREATE_WORKER_TOKEN` | set | |
| `ANTHROPIC_API_KEY` | set | |

**Defaults kept on production:**
- `CREATE_WORKSPACES`: empty, meaning any workspace.
- `CREATE_ALLOWED_DOMAINS`: `wyvstudio.com`.
- `CREATE_ALLOWED_EMAILS`: `kolakachi@gmail.com`.
- `ADMIN_ALERT_EMAILS`: `kolakachi@gmail.com`, plus super admins.
- `CREATE_UNLIMITED`: never set. It works only on local.

**`.env` is read when a container is created, not on restart.** Apply a change by recreating the services:

```
docker compose -f docker-compose.prod.yml up -d --no-build --no-deps api worker-default worker-exports worker-generation scheduler
```

## Done

### Code (all on `master`, deployed 2026-10-06; API container started 21:02:55 UTC)

| Commit | What |
|---|---|
| `09d5e910` | **Production mode:** Create follows `CREATE_ENABLED` instead of running only locally. About 14 local-only checks were removed. Unlimited mode and the test-fixture command stay local-only. |
| `2cc4d2f2` | **People gate** (with vendor alerts, the caps and room changes, and the study fixes): Create shows only for `@wyvstudio.com` and kolakachi@gmail.com (`CREATE_ALLOWED_DOMAINS`, `CREATE_ALLOWED_EMAILS`). |
| `2ad928f4` | **Async planning:** planning answers at once and finishes after the response, because Cloudflare cuts requests at 100 s. The worker's routes (`/api/internal/create/`) get 1,000 s timeouts and 200 MB uploads in nginx. |
| `5dfd925a` | **Private worker connection:** the worker may reach the API over the private network (or HTTPS), not only localhost. |
| `304200b9` | **Per-call ceiling fits the room:** a build on a short balance gets a per-call ceiling that fits it, never below 150 credits ($0.60). Starting needs the likely cost plus 150. This fixes the first test build stopping after one call. |

Before these, the same day:
- vendor errors and alerts;
- the credit calibration;
- the caps and room decisions;
- the reviewer for Thorough;
- the reference study fixes;
- 3dicons.

See `create-pending.md`.

### Production

- **Create settings** added to `.env`, with a backup first (see above).
- **Database migrations** ran with the deploy, including `vendor_incidents`.
- **Checked on the running containers:**
  - Create is on, paid calls are on, and unlimited mode is off;
  - the people gate holds;
  - the nginx rule for worker routes is live;
  - the worker's claim call returns 200.
- **First production plan:** made in 47 s and charged 28 credits (conversation
  `f44e3fc6-c315-43b3-a141-71f82678eb70`, workspace 1).

### Build worker (`framecast-create`)

- **Installed:** Docker 29.8, Compose 5.6, Node 22.23, git, ffmpeg 7.0.2.
- **Code:** `/opt/wyv-create/hyperframes-worker`, from `git archive` of commit `5dfd925a` (see the `REVISION` file
  there). No worker code has changed since.
- **Art packs:** 10,923 items, 3dicons included. SELinux labels set on `artifacts/`.
- **Sandbox:** image `wyv-hyperframes-proof-smoke` (2.55 GB), built on the server. Its smoke test passed.
- **Service:** `wyv-create-worker` is active and reconnected by itself after the deploy.
- **Disk:** 16 GB used of 30 (53%).

### First paid test build (canary)

- **Run:** `242e9ace-3762-419d-bd65-640fe5c2e023`. It failed with "Model budget exhausted" after one call.
  - The workspace had 303 credits of room for the builder.
  - Each call held the most a call could cost (300 credits) while it ran. After one $0.21 call, the next no longer fit.
- **What the calls really cost:** median $0.07, 95% under $0.26, highest $0.595 (1,488 local calls). The fix is
  `304200b9`, deployed.
- **Charged:** 89 credits. The voiceover and music it bought are reused by the next build.

## Left

| # | Item | Waiting on | Notes |
|---|---|---|---|
| L1 | **Paid test build (Standard)** | Passed 2026-10-07, one flag | Run `c615454e` (retry of `e9f236dd`): preview in ~9.5 min, 18 calls, 143 credits, dearest call $0.24. The final listening check did not hear "Approve the plan" (~7.6 s; 83% script coverage): a missed phrase in transcription or in the bought voiceover. Owner to listen. |
| L2 | **Planning is paid** | Done 2026-10-07 (`cdf844e1`) | Subsidized at half cost, never free: planning starts only with at least `CREATE_PLANNING_MIN_CREDITS` (60) available, otherwise a top-up message; the charge is never waived (the whole charge, or what is left). |
| L3 | **A tab left open across a deploy** | Deployed; owner browser check pending | Deployment notice and guarded composer recovery are live with `c335dcd7`; `index.html` is no longer cached. Needs one real-browser check after a deploy. |
| L4 | **Private Create files on B2, plus local cleanup** | Switched on 2026-10-07; local cleanup pending | Private bucket `wyv-create-private` (us-east-005, private, encrypted). The 6 existing files were copied and verified (bucket, catalog and app reads). `CREATE_STORAGE_DISK=create_private` on the API, queue workers and scheduler. Local originals stay on the `api_private` volume; `create:maintain-storage` (dry-run by default) reclaims them later. |
| L5 | **Production disk cleanup** | Done 2026-10-07 | Unused images and build cache older than 2 days cleared: 57 GB freed, disk 91% → 33% (66 GB free). Repeat when free space falls under ~20 GB (each deploy adds ~2.5 GB). |
| L6 | **Worker updates** | Done 2026-10-07 | `framecast-app/ops/deploy_create_worker.sh`: packages committed worker code, drains all slots (bounded wait), replaces code keeping art packs and artifacts, rebuilds only when inputs changed, restarts. Tested (fa27e988 → 7bbff81c). |
| L7 | **Builds at once** | Limit 3 on 2026-10-07; full 3-way test pending | `CREATE_MAX_RUNNING=3`, `CREATE_MAX_RUNNING_PER_WORKSPACE=1` (approval already allows one active build per workspace). Worker runs 3 slots (`wyv-create-worker`, `@2`, `@3`) sharing one sandbox; renders take turns (10-min queue limit). Single-build baseline on the worker: CPU never below 35% idle, at least 3.4 GB of 5.6 GB free, sandbox busy ~3.3 of 9 min. A 3-way test needs three funded workspaces (credit pools are shared within an agency; a Standard build holds ~1,724 credits; planning rechecks the user's saved workspace). The planning worker runs one plan at a time (~1 min each). |
| L8 | **Widen the audience** | Owner: go, after customer gates below | Add named customer emails and workspace restrictions for ws 28 and ws 27 only after the private pilot gates pass. Do not move directly from a successful canary to everyone. |
| L9 | **Spend cap** | Owner (decided: none for now) | `CREATE_PILOT_BUDGET_MICROUSD=0`. Watch the first week's model spend against credits charged (`create:failures`, the vendor digest at 09:05). |
| L10 | **Uncommitted work by the other agent** | Done 2026-10-07 | Committed and deployed in `c335dcd7` with every rollout switch off; the deploy preflight step was left out of `deploy.yml` (script kept in `ops/`). |
| L11 | **Durable planning** | Done 2026-10-07 | `CREATE_DURABLE_PLANNING=true`; `worker-create-planning` runs plans outside PHP-FPM; recovery runs each minute. Verified: plans in 56 s to 2.5 min; a plan for a workspace the user is not in is refused (403). |
| L12 | **Worker ownership** | Done 2026-10-07 | `CREATE_WORKER_OWNERSHIP_REQUIRED=true`; slots identify as `framecast-create` / `render-N`; assignments and heartbeats verified. A build whose worker has not confirmed it stopped holds one slot instead of blocking everyone. |
| L13 | **Pause and drain** | Done 2026-10-07 | `CREATE_RUNTIME_CONTROLS_ENABLED=true`; `php artisan create:drain pause/resume/status`; drill passed (503 while paused). Worker units stop by drain (SIGTERM to the coordinator, `KillMode=mixed`, `TimeoutStopSec=infinity`). |
| L14 | **Worker disk** | Fine for now | 46.6 GB disk: 30 GB root (14 GB free) plus 15 GB `/var/oled` (Oracle Linux layout). The worker refuses claims below 8 GB free. |
| L15 | **Alerts** | Done 2026-10-07 (`e162d8e1`) | `create:health` every 5 min emails super admins + `ADMIN_ALERT_EMAILS`: stuck planning, builds queued > 20 min, no worker checking in (5 min), builds that lost their worker, holds open > 3 h, low disk; IDs included; hourly per problem with a recovery note. Vendor billing/key failures alert immediately (VendorAlerts). |
| L16 | **Privacy and restore** | Tests done; restore drill pending | A test proves another workspace cannot reach conversations, plan activity, planning, videos or files, and a worker fetches only files on its own run. **Found and fixed 2026-10-07:** nightly database dumps were uploaded to the public `frame-cast` bucket (`backups/`, 30 days, guessable names, publicly downloadable). All 31 moved to private `wyv-create-private/db-backups/` (checksums verified), public copies deleted (404), `/opt/framecast/backup.sh` now uploads there (config `~/.s3cfg-private`, mode 600; script backup `backup.sh.bak-*`). Next: restore drill from the private dumps; review plaintext secrets in the database. Customer media in `frame-cast` relies on unguessable names (no anonymous listing). |

## Customer release gates

All boxes below are pending verification. A code test or image smoke test alone does not complete a production gate.
Attach the tested revision/image digest, conversation/run IDs, results and date when checking a box.

### Before the two-customer private pilot

- [ ] **L1: a complete Standard canary**, from plan through the applicable approvals to playable/downloadable video.
  Verify expected audio/motion, refresh/reopen, one targeted edit, saved versions, and final credit settlement.
  Investigate the old non-Create hold using its provider/accounting evidence before releasing it; age alone is not proof.
- [ ] **L4/L5/L14: storage is safe.** Private B2 delivery and migration work, local copies are safely reclaimed,
  both hosts have measured disk headroom, and a low-disk test queues/rejects new work without damaging saved work.
- [ ] **L11/L12/L13: interruption recovery works.** Test API restart during planning, worker termination during a
  build, provider timeout with uncertain acceptance, duplicate approval/callback, cancel while queued/running,
  and drain during deployment. No duplicate charge or paid retry; uncertain work stays held until reconciled.
- [ ] **L2/L15: customer cost behavior is explicit.** Verify insufficient funds, credit reservations, partial failures,
  unused-credit release and the chosen planning allowance. An operator receives and can act on a simulated alert.
- [ ] **L3/L6: deployment is reproducible.** Record API, web, worker, sandbox and art-pack revisions; deploy and
  roll back without interrupting active jobs. An old browser tab recovers without a reload loop or lost draft.
- [ ] **L16: privacy and restore checks pass.** Include a second workspace in the checks.
- [ ] **Customer acceptance:** review representative outputs for the formats offered in this pilot, including a
  motion-graphics brief, a generated/UGC brief and a supplied-footage edit. Record reference fidelity, character
  consistency, readable text, audio, revision count, elapsed time and total cost per accepted output.

### Before broad customer access

- [ ] **L7: load test on the actual Oracle worker.** Separate model wait, sandbox wait, render time and queue time;
  record p50/p95 completion time, peak RAM/disk, failures and API responsiveness. Use replayed/mocked provider waits
  for initial load tests; paid generation is a separately budgeted test. Set limits from the measured results.
- [ ] Test several workspaces together: one busy workspace cannot monopolize the queue; cancellations release queue
  capacity; users can see their saved work and a useful waiting status.
- [ ] The two-customer pilot produces publishable outputs and repeat use at an acceptable cost and support burden.
  Increase the allowlist in stages while watching the same measures; registered-user count is not a capacity target.

**Recommended order:** confirm disk/headroom and the canary prerequisites; implement private storage/cleanup and
durable planning; prove recovery, billing and drain; make deployment repeatable; run the full canary and acceptance
checks; open the two-customer pilot; then tune concurrency from measurements. L7 optimization need not delay a small,
explicitly capacity-limited pilot if recovery, fair admission and the other gates pass.

## Turning it off

- **Emergency:** set `CREATE_ENABLED=false` in the production `.env`, then restart the services as above. Create
  disappears, new claims stop, and worker callbacks are rejected too. Already submitted provider jobs may continue
  and incur cost. Record active run/provider IDs and reconcile them after recovery; this is not a refund or cancellation.
- **Planned maintenance:** until the L13 rollout and host drill pass, schedule a quiet window and verify no active
  planning/builds or uncertain callbacks before shutdown. After rollout, follow [the drain runbook](create-drain-runbook.md).
  Do not restart the worker during a build merely to apply a deploy.
- **Configuration rollback:** restore only the intended Create settings after comparing with the backup. Restoring
  the entire old `.env` can revert unrelated configuration or keys changed since that backup. Stop the worker only
  after draining, or as part of a deliberate emergency stop with reconciliation to follow.
