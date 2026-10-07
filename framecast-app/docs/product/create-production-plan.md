# Create on production: plan

Drafted 2026-10-06. The initial state below predates the team deployment. See `create-go-live.md` for the rollout
record and customer readiness gates; local build diagnostics remain recorded below.
**Decide** marks what the owner chooses before work starts.

## Where we are

- **Production** runs commit `f40af79` (2026-09-30). It has no Create settings, no build worker and no sandbox. Its
  server has 2 vCPU, 11 GB RAM and a 96 GB disk at 86% (14 GB free), shared by the API, three queue workers, the
  renderer, Reverb, Postgres, Redis, the docs and the marketing site.
- **Create is local-only by design.** About 14 checks refuse anything but a local or test environment: the
  conversation service, paid execution, the worker endpoints, delivery, artifact retention, reconciliation and the
  recovery commands.
- **Locally a build needs:**
  - the API;
  - a coordinator process on the Mac (`app-worker.mjs`);
  - sandbox containers started from `compose.local.yml` (marked "never merges into the production stack"), 2 CPUs and
    4 GB each, one at a time;
  - the art packs (188 MB) on the host;
  - run artifacts and uploads on local disk (uploads capped at 1 GB a workspace).
- **The API image build passed locally on 2026-10-06.** A hang was reported at the yt-dlp download. The version was already
  pinned, but the old retry policy allowed roughly 25 minutes with no progress output. The 2026-10-06 patch adds a
  180-second total download deadline, progress, pinned release checksums for both architectures, and a bounded version
  check. The exact ARM64 release downloaded on the host in about seven seconds and matched its official checksum.
  An isolated, uncached BuildKit install also passed in 7.6 seconds, including checksum and executable version checks.
  Seven offline shell cases passed (both architectures, download failure/stall, corrupt bytes, wrong version and
  unsupported architecture). The first full build reproduced a GitHub HTTPS connect timeout and failed clearly after
  34 seconds. One retry passed (yt-dlp: 6.2 seconds), producing ARM64 image
  `wyv-api-build-check:local`, ID `fc134a4fdcf937891e998c166c555d36210e9ca563a38eef73b4d3c5522fea70`.
  GitHub connectivity is intermittent; the new limits bound failure, not eliminate network dependency. The rebuilt
  image has not replaced running services, and this patch still needs verification in the production ARM64 build.
- **Local Docker failure found during diagnosis:** listing build history (`docker buildx history ls`) triggered a
  BuildKit `filterHistoryEvents` nil-pointer panic and stopped Docker Desktop. Avoid that command on this installation;
  recover with a normal Docker restart, not a factory reset. Docker and the local containers recovered normally.
  The stack matches [upstream issue 52257](https://github.com/moby/moby/issues/52257). This crash is separate from the
  reported download stall.
- **Measured local cost:**
  - a Standard 15 s build: median 238 credits (about $0.95);
  - renders: 1.5 to 4 minutes;
  - whole builds: up to 38 minutes;
  - plans: 3.5 to 6.5 minutes.

## Decide

Decided 2026-10-06: builds run on `framecast-create` (Oracle A1, us-ashburn-1, 4 cores, 6 GB, 30 GB disk, Oracle
Linux 9.8, user `opc`); files live on B2. Open: a private bucket for customer uploads (recommended) or the existing
public-read `frame-cast` bucket; the first audience; the daily brake.


| Decision | Recommendation |
|---|---|
| Where builds run | **A separate worker server** (8 vCPU, 16 to 32 GB, about $40 to $80 a month) running the coordinator and the sandbox, talking to the production API over HTTPS with the worker token. Not the production server: a render takes 2 CPUs and 4 GB, which would starve the API, and its disk is nearly full. |
| Who gets it first | **An allowlist** (`CREATE_WORKSPACES`): your workspace, then the two real customers (ws 28, ws 27), then everyone. `CREATE_ENABLED` is the off switch. |
| Emergency spend brake | Keep today's pilot budget as a **daily dollar cap across all builds** (e.g. $50 a day), with an alert when it is reached. Users' own credits still limit each build. |
| Where files live | **Create uploads and run artifacts on B2** (the bucket the app already uses), not the production disk. |

## Work, in order

1. **Unblock deploys:** local ARM64 image build verified; verify the patched production ARM64 image before deployment. If
   release downloads remain unreliable, stage checksum-verified release artifacts in the build context or a controlled
   artifact store. Pinning alone is not a fix: the version was already pinned. Roll out rebuilt service images separately;
   do not recreate services carrying copied-in changes prematurely.
2. **Production mode in code:**
   - Replace the local-only checks with `CREATE_ENABLED` plus the allowlist.
   - Reconciliation and recovery become super-admin commands.
   - The pilot budget becomes the daily brake.
   - Worker endpoints stay token-only and accept the worker server's address.
   - Tests: everything above, in a production-like environment.
3. **Storage:**
   - Uploads, studies, run artifacts and deliveries go to B2.
   - Old run artifacts are cleaned up (the existing retention service, made to run on production).
4. **Worker server:**
   - Provision it with Docker.
   - Build the sandbox image there, with the art packs fetched by `scripts/fetch-art-packs.mjs` (they are not in git).
   - Run the coordinator as a service (restart on failure, logs kept).
   - A production compose file for the sandbox, keeping today's isolation: no network, read-only, all capabilities
     dropped, 4 GB, one render at a time (queue).
5. **Production settings and secrets:**
   - `CREATE_ENABLED`, `CREATE_MODE=agent`, `CREATE_PAID_EXECUTION_ENABLED`, `CREATE_WORKSPACES`;
   - the daily brake;
   - the Anthropic key, the worker token, `ADMIN_ALERT_EMAILS`.
   - `CREATE_UNLIMITED` is never set. It is local-only in code too.
6. **Database:** run the Create and composition migrations, including `vendor_incidents`. Back up first.
7. **Deploy:**
   - A master push after your go.
   - Verify the running containers, not git HEAD.
   - The paid canary (approved): one plan and one Standard build on your workspace.
8. **Rollout:**
   - A week on the allowlist, watching the failure ledger (`create:failures`), the vendor digest and spend.
   - Then widen.

## Risks

- **Model-written code runs in the sandbox.** Its isolation (no network, read-only, no capabilities) must carry over
  unchanged. It is the main security boundary.
- **Spend:** builds now have generous room. The per-build budget, the user's balance, the per-call limit, the
  no-progress guard and the daily brake bound it. Watch the first week's costs against credits charged.
- **First impressions:** AppSumo refunds came from weak first sessions. Real customers should see Create only after
  the canary and a few internal builds pass.
