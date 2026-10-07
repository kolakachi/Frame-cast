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

**Production does not cache its config**, so a `.env` change takes effect after a restart:

```
docker compose -f docker-compose.prod.yml restart api worker-default worker-exports worker-generation scheduler
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
| L1 | **Re-run the paid test build (Standard)** | Paid acceptance pending | Credit blocker resolved 2026-10-07: owner-authorized fenced cancellation released stale hold `op_01m3e7p2744aknq2e815pgrwq7`. Workspace 1 now has 788 available. Project 227's separate 138-credit charges and output were preserved. The earlier Standard quote was 439; obtain a current quote. No new canary ran. |
| L2 | **Planning on an empty balance is free** | Owner: decision, then build | If the balance is below the planning charge, the plan is still made and the charge is waived (`PlanService.php`). Choose a bounded introductory allowance or require sufficient funds before paid planning. Do not silently change the current policy. Count attempted provider work, not only saved plans, when enforcing the allowance. |
| L3 | **A tab left open across a deploy shows a broken Create page** | Build (small) | The old page asks for files the new build replaced (404 on `CreateView-*.js`), seen 2026-10-06 21:04. A hard refresh fixes it. Proposed: reload the page by itself when one of its files is gone. The web app has no handler for this today. |
| L4 | **Private Create files on B2, plus local cleanup** | Local implementation; rollout/cleanup pending | The dedicated private disk, storage catalog, Create reader/writer integration and copy-and-verify migration command are implemented locally; see [progress tracker](create-go-live-progress.md). `FILESYSTEM_DISK=b2` does not move Create files. Use private objects and authenticated delivery or short-lived signed URLs. Migrate existing files with hash checks and a recoverable disk/key mapping. The current retention service only removes orphaned `create/inputs` and `create/previews`; it does not bound uploads, studies, saved revisions or worker run directories. Verify B2 copies and recovery before deleting local files. |
| L5 | **Production disk cleanup** | Owner: go | Old Docker images pile up with each deploy. The first deploy filled the disk to 100% for a moment. |
| L6 | **Worker updates are manual** | Build (small) | Pushing to `master` does not update `framecast-create`. Today: `git archive` the commit to `/opt/wyv-create/hyperframes-worker`, keep `runtime/art-packs` and `artifacts`, write `REVISION`, rebuild the sandbox image if its files changed, then `sudo systemctl restart wyv-create-worker`. Never restart it during a build. Proposed: a deploy step or script. |
| L7 | **Bounded concurrency and measured capacity** | Build and load test before widening | Three gates currently serialize work: the API globally admits one running build, the worker awaits each build, and `flock` serializes all sandbox commands. Overlapping model waits may help, but three concurrent builds on 6 GB is unverified. Begin with one heavy sandbox slot; measure CPU, peak memory, scratch disk, queue age and API latency before raising limits. Daily-user estimates are not acceptance evidence. Add per-workspace fairness and queue admission limits. |
| L8 | **Widen the audience** | Owner: go, after customer gates below | Add named customer emails and workspace restrictions for ws 28 and ws 27 only after the private pilot gates pass. Do not move directly from a successful canary to everyone. |
| L9 | **Spend cap** | Owner (decided: none for now) | `CREATE_PILOT_BUDGET_MICROUSD=0`. Watch the first week's model spend against credits charged (`create:failures`, the vendor digest at 09:05). |
| L10 | **Uncommitted work by the other agent** | That agent | The `api/Dockerfile` yt-dlp download patch and its notes in `create-production-plan.md` are not committed. The deploy built without them. Production is ARM, so that plan's "production AMD64 build" line does not apply. |
| L11 | **Durable planning jobs** | Build; customer gate | `CreateController::plan` uses `app()->terminating`, not a durable queue. Planning still occupies a PHP-FPM child; a killed process loses the work while the cache can report `running` for 30 minutes. The cache get/put is not an atomic claim. Use a persisted job with atomic admission, explicit terminal states, restart recovery and provider receipt handling before any retry. |
| L12 | **A lost worker must not strand the service** | Local ownership/operator recovery; automated fencing and host drill pending | `RunService::claim` blocks every workspace if any `needs_attention` run lacks `worker_stopped_at`. Preserve this protection until the old execution is fenced/stopped; do not simply remove the check. Add monitored recovery and host/slot ownership so unrelated healthy capacity can proceed when available. Prove restart, lease expiry and delayed callback handling without buying media twice. |
| L13 | **Drain and emergency-stop behavior** | Local implementation/runbook; rollout and host drill pending | `CREATE_ENABLED=false` rejects every worker endpoint, including heartbeat, result, settlement and stopped callbacks. It is an emergency shutdown, not a graceful admission pause. A separate database drain control is implemented locally behind `CREATE_RUNTIME_CONTROLS_ENABLED=false`; it stops new admission while active work reports and settles. See [drain runbook](create-drain-runbook.md) and the progress tracker; real host verification remains pending. |
| L14 | **Disk admission and actual worker capacity** | Measure, then build | The owner selected 50 GB in the console, but this record reports a 30 GB worker filesystem. Check provisioned volume, partition and filesystem sizes before assuming 50 GB is usable. Add minimum-free-space admission, bounded scratch usage, log rotation and cleanup after verified persistence. Alert on both production and worker disks; do not delete active/recoverable jobs to make room. |
| L15 | **Launch monitoring and support** | Operations; customer gate | Alert promptly on stalled planning, old queued jobs, missing worker heartbeats, unresolved holds, low disk and provider billing failures. The daily vendor digest alone is too late for an outage. Give each failure a support-visible conversation/run ID and document who reconciles it, expected response time, and how partial work is charged. Keep the owner's no-total-cap decision; measure actual provider spend against credits collected, including waived planning and rejected outputs. |
| L16 | **Privacy and restore proof** | Verification; customer gate | Test cross-workspace denial on uploads, previews, source bundles, generated assets and worker downloads. Confirm the B2 bucket is private rather than assuming the existing bucket is suitable. Restore database records plus their referenced objects into an isolated environment and open/revise a saved video. Verify worker API network access, token rotation and secret-free logs. |

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
