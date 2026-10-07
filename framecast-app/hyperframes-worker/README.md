# Hyperframes local proof

Local-only renderer experiment; not an agent, production worker or security-certified tenant sandbox. No provider credentials or paid API calls are used. Runtime network access is disabled; Hyperframes can reach its own local browser server only.

From this directory:

```sh
mkdir -p artifacts
docker compose -f compose.local.yml build
docker compose -f compose.local.yml run --rm smoke
```

The standalone Compose project does not start, stop or rebuild the application's services. Dependencies are installed at image build, with exact Hyperframes/GSAP versions and an npm lockfile. The Node base is digest-pinned. Debian browser/system packages are recorded at execution but are not yet snapshot-pinned; the built image digest is needed for reproduction.

Artifacts (git-ignored): product.mp4, cta-edit.mp4, footage.mp4, poster frames, check/render logs, ffprobe metadata and report.json. All fixtures are synthetic. The moving footage contains a test tone, not speech. A completed smoke is necessary but insufficient for E0 acceptance: see the E0 acceptance evidence for the additional real-media, playback, parity and failure checks.

The container is non-root, read-only except temporary/output storage, drops capabilities, has no network, no Docker socket, 2 CPUs, 2 GB RAM and a PID limit. This is a trusted-fixture proof. Do not feed customer-generated HTML to it until the production isolation review is complete.

## User-supplied media proof

Place the supplied input files under `artifacts/real-inputs/` as `presenter.mp4` and `product.png` (the provided product URL serves AVIF and needs decoding first). The script intentionally does not fetch URLs or expose a generic downloader.

```sh
docker compose -f compose.local.yml build
docker compose -f compose.local.yml run --rm smoke node scripts/real-media.mjs
python3 scripts/verify-real-media.py artifacts/real-media
```

This fixture is specific to the supplied 5.04s presenter clip, followed by the separate product photo, for a 10s composition. It preserves source audio via a decoded WAV and performs no transcription or AI editing. The presenter holds a different bottle, so the output labels the supplied assets separately. The original media is never overwritten or committed. Do not use this as an endorsement of the pictured product.

The Python check measures aligned decoded-audio correlation, verifies identical audio between CTA variants, and compares presenter frames at 1s and 4s. These are sampled technical checks, not a full listening or semantic quality review.

## Local failure/recovery checks

```sh
docker compose -f compose.local.yml build
docker compose -f compose.local.yml run --rm smoke node scripts/reliability.mjs
```

The new local adapter writes a unique run directory and atomic `state.json`. Only
`status: ready` with `artifact: video.mp4` is deliverable. It runs the upstream
check, renders to `pending.mp4`, probes the approved dimensions/duration/audio,
and decodes the complete output before promotion. Cancellation and deadline
expiry kill the active process group and remove partial output. Previous run
artifacts are never replaced. Reports are under `artifacts/reliability/`.

This adapter is for trusted local fixtures only. It is not yet wired to the app,
does not validate arbitrary customer paths/HTML, and does not implement durable
leases or automatic crash reconciliation. The offline recovery tool below handles stopped local workers. A hard container kill can leave `running` state;
consumers must not interpret an existing MP4 alone as completion. Timely worker
crash recovery and tenant security remain open gates.

## E0 acceptance and recovery

```sh
docker compose -f compose.local.yml run --rm smoke node scripts/acceptance.mjs
```

This tests snapshot seeks in both orders, missing-font and overflow diagnostics,
scoped paths (including symlinks), and network denial. The exact npm-bundled skill
files are recorded in `runtime/skills-manifest.json` and verified at image build.
The current image queues sandbox commands with `flock` on the Compose-managed
`sandbox-lock` volume (native Linux storage). Do not place this lock on the macOS
artifact bind mount: cancellation of a waiter did not preserve exclusion in the
local regression test. One command executes at a time. Waiting is bounded at
600 seconds, can be cancelled, and makes no provider calls. Lock timeout exits
with code 75 before executing the command. The app shows "Waiting for render
capacity", records waiting/acquisition in its trajectory, and allows the queue
wait in addition to the tool timeout. The execution clock starts at acquisition;
startup/acquisition has a separate 630-second watchdog (600-second queue plus
30 seconds to start/report). A watchdog expiry without an acquisition marker is
uncertain, unlike a confirmed lock timeout. The overall agent deadline still applies.
Confirmed queue expiry settles a render at zero cost once the container is stopped;
a missing settlement acknowledgement still requires reconciliation. Failure codes
and redacted exit diagnostics survive the agent-to-host boundary. If container
termination cannot be confirmed, delivery/repair stops; the host also waits for
cleanup still in progress when an agent deadline fires.
This is bounded slot admission, not a durable FIFO scheduler or production scaling.

Rebuild the image and restart the idle host worker after updating this code.
Use current Compose for all local tools so they share the same lock volume;
historical pinned images use the old lock and must not run alongside this worker.
Direct `docker run` calls must also mount that shared volume at `/sandbox-lock`.

Queue checks (offline, no AI calls): run `node --test agent/tests/sandbox-exec.test.mjs agent/tests/sandbox-recovery.test.mjs agent/tests/accounted-call.test.mjs`
on the host, then run `scripts/sandbox-queue-test.mjs` inside Linux with the
entrypoint bypassed (`--entrypoint node`), a **disposable** `/output` directory,
and the native lock volume. The suite covers exclusion, cancelled waiters,
queue expiry, command failure and killed-holder release.

For an interrupted local run, first stop its worker container. Then run:

```sh
docker compose -f compose.local.yml run --rm smoke node scripts/recover.mjs /output/crash-proof --worker-stopped
```

Use the affected run root instead of `crash-proof` for another local test. Recovery
marks only unfinished runs failed, deletes their partial media and preserves ready
runs. Start a fresh run to retry. This is deliberately offline local recovery;
distributed leases and automatic application recovery belong to E2.

`photo-fixture.mjs` renders the supplied product photo and local logo in a 15s
composition; it requires the `real-inputs/product.png` input described above.

### Reuse the exact tested image

After building, the recorded `compose.pinned.yml` refers to the tested local image
by immutable ID. The override clears the build configuration and forbids pulls:

```sh
docker compose -f compose.local.yml -f compose.pinned.yml run --rm smoke
```

That image must exist in the local Docker store. Keep it or export it before
pruning images. The package list is in `runtime/debian-packages.txt`. Rebuilding
from the Dockerfile can resolve newer Debian packages; a rebuilt image must be
reverified and receive a new runtime lock. The pinned image, rather than an
unverified future rebuild, is the accepted E0 runtime.

## E1 agent loop (in progress)

Run the deterministic tests with Node 22+:

```sh
node --test agent/tests/agent.test.mjs
```

Exercise a scripted provider with real offline Hyperframes tools:

```sh
docker compose -f compose.local.yml build
docker compose -f compose.local.yml run --rm smoke node agent/fake-smoke.mjs
```

The E0 pinned-image override intentionally keeps the old E0 image; omit it when
building/testing the new E1 code. Fake smoke outputs have unique run directories.
No model keys or network are provided to this container. The future online
provider coordinator must remain outside it. `ReplicateProvider` is disabled by
default; importing it never reads credentials or starts a prediction.

See `../docs/product/archive/create/hyperframes-e1-verification.md` for verified schemas,
implemented constraints and the outstanding real-model acceptance gates.


E1 now includes combined `preview`, `timeline`, installed `primitives`, compact
model history, source locks, per-call metrics and a locally locked test budget.
The benchmark driver is paid tooling, not part of the ordinary test suite. It
shares `artifacts/live/budget.json`, never resets it, skips existing runs and
avoids follow-up edits when creation has not passed. Do not raise or reset its
cap without explicit approval. The current $5 test budget has paused new calls.

`python3 agent/verify-benchmark.py` checks existing benchmark renders offline
using ffmpeg/ffprobe. It verifies only artifacts present and explicitly reports
that scope; it does not mean the benchmark matrix or creative acceptance passed.
See the E1 verification document for failed cases and the user's creative review.


### Disk admission (Create customer-readiness L5/L14)

The app worker checks free space **before claiming a job** and again before each sandbox command. It checks
`artifacts` and the OS temporary directory, plus explicitly configured mounts. When blocked it leaves work queued,
logs a `create.worker_disk_capacity` state change, and checks again after 30 seconds. `--once` exits 75 when blocked.
Existing receipts and drafts are retained. These are point-in-time checks, not disk reservations or filesystem quotas.

Host environment defaults:

```sh
CREATE_WORKER_MIN_FREE_BYTES=8589934592
CREATE_WORKER_MIN_FREE_RATIO=0.10
CREATE_WORKER_EXTRA_DISK_PATHS='["/var/lib/docker"]'
```

The required headroom is the larger of the byte floor and percentage of each filesystem. The extra-path list is
empty by default: set it to the actual Docker data filesystem and any separate media volume before production use.
Configured extra paths must exist; a missing path or failed disk probe blocks claiming. On Docker Desktop the daemon's
filesystem is inside its VM: a host path is not proof of free space there. Verify that separately for local testing.
On Oracle, inspect Docker's actual data root and filesystem mounts; do not assume the volume and filesystem sizes match.

Start with one heavy render slot. The 8 GiB/10% defaults are conservative starting values, not a measured capacity
promise. Input downloads, generated intermediates, Docker images/logs, and tmpfs memory still need monitoring and
limits. No worker run directory, interrupted draft, provider receipt or budget journal is deleted by this gate.
Worker retention needs authoritative recovery/backup proof before it can remove those files.


## Graceful shutdown (Create customer-readiness L13)

`SIGTERM` drains the coordinator: it accepts no further claims and finishes an already claimed run, including its
heartbeats and settlement. A claim accepted during the signal race is still owned and reported. `SIGINT` requests
the existing immediate-stop path; uncertain provider work remains subject to reconciliation. Repeated SIGTERM
never escalates into cancellation. Both signals wake an idle poll promptly.

Use the app's shared `create:drain pause` before a coordinated deployment. The rollout switch and migration must
be enabled consistently across API/planning processes; worker authentication and `CREATE_ENABLED` remain active
while work drains. A systemd service that signals all children or imposes a short kill timeout defeats this
behavior. See [the drain runbook](../docs/product/create-drain-runbook.md) for the required service policy and host
checks. These changes have not been deployed or tested against the Oracle service manager.


## Worker identity and interrupted runs (L12)

After the API's worker-assignment migration is deployed, set `CREATE_WORKER_ID` to a unique stable host label and
optionally `CREATE_WORKER_SLOT=render-1`. The coordinator generates a fresh instance UUID per process and includes
it in claims. It refuses a mismatched/missing assignment response when identity is configured. The local
`started.json` saves the assignment and coordinator PID, never the lease token.

An unset worker ID retains legacy claims during staged rollout. After every worker is updated, the API can set
`CREATE_WORKER_OWNERSHIP_REQUIRED=true`. That switch defaults off. These labels support diagnosis; they are not
hardware attestation or extra execution slots. Do not restart with a new label to bypass an interrupted run.

Stop evidence and recovery actions are documented in [the worker recovery runbook](../docs/product/create-worker-recovery-runbook.md).
No journal is automatically replayed or pruned on restart. Unconfirmed lost work still blocks claims until its
original execution is independently verified stopped and the stop is recorded.
