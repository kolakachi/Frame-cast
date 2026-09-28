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
The image uses `flock` on the shared output volume to reject overlapping runs.

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
