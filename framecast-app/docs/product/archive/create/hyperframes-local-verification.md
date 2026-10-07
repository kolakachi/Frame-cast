# Hyperframes local verification

Date: 2026-09-28. Status: **initial renderer proof; E0 remains open**.

## Implemented

Standalone `hyperframes-worker/compose.local.yml` project, pinned Hyperframes 0.8.82 and GSAP 3.14.2, npm dependency lock, digest-pinned Node 22 base. Runtime has network disabled, non-root user, read-only root, capabilities dropped, 2 CPU / 2 GiB / 256 PID limits and a 4 GiB tmpfs ceiling (actual memory remains constrained by the container).

No application or production service was restarted. No API credentials or paid model calls were used. Artifacts are ignored by Git.

Commands from `framecast-app/hyperframes-worker`:

```sh
docker compose -f compose.local.yml build
docker compose -f compose.local.yml run --rm smoke
```

## Observations

- The CLI requires `HYPERFRAMES_BROWSER_PATH` for explicit browser selection. Generic Puppeteer environment variables alone are not the verified integration contract.
- The initial 1 GiB temporary filesystem fails the renderer's disk preflight. The proof uses a 4 GiB ceiling.
- `data-has-audio="true"` is required to include original video audio. The first footage check correctly rejected a video missing this declaration.
- Product checks passed layout, runtime, motion and contrast checks. One nonblocking structure warning remains: the flat timed section should become a sub-composition when the editable production structure is implemented.
- Chromium uses software capture in this environment. Performance is local proof evidence, not a production latency promise.
- An observed container sample was 413.9 MiB and 65.71% CPU during the earlier run. This is **not peak memory** or a load test.

## Limitations / next work

The fixtures are synthetic: a locally authored product SVG and moving test-pattern video with a test tone. No real talking-head clip has been tested. A decoded poster can confirm layout, but does not replace full video playback/listening or preview-to-export comparisons. No skill snapshots or agent runner are integrated yet. No proof of hostile-code isolation, worker-kill recovery, cancellation, seek determinism, cross-workspace authorization or production accounting is claimed.

Debian browser/FFmpeg packages are captured in runtime metadata but apt repositories are not snapshot-pinned. Preserve the built image for exact reuse. Finish E0's real-media, reproducibility, resource and recovery/security gates before E1 model evaluation.

Machine results: `hyperframes-worker/artifacts/report.json`, individual CLI logs, ffprobe reports, MP4s and decoded posters. Final run results are recorded below after completion.

## Successful smoke run

All three outputs passed automated dimensions/duration checks: 1080×1920, 24 fps, 15 seconds. Footage output has an audio stream; product outputs are intentionally silent. Poster frames were visually inspected. Full playback/listening remains pending.

| Output | Render wall time | SHA-256 |
| --- | --- | --- |
| product | 15.40s | `a9a07dd0c9f40c42f1e1a1770b6b1be10ac35d5ec75f383de5bbb479f310625d` |
| cta-edit | 12.94s | `ffc4726f1b509aeebe15d292ce713eeae37956680e252816eb2745c33a022215` |
| footage | 39.28s | `aba2521ced797b023b95823eddb760e05b355e52932863375d5b2ff33001e1f7` |

Runtime identity is recorded in `hyperframes-worker/hyperframes-runtime.lock.json`. Existing local container uptime remained unchanged.

## Supplied-media test

User-supplied presenter clip (506×900, 24 fps, ~5.05s with AAC audio) plus a 500×500 product photo served as AVIF. Decoded image to PNG; preserved original downloads. Composed a 10s 1080×1920/24fps video: full presenter clip, then product photo. The bottles differ, so they are labeled separate assets; no product claims or synthetic endorsement added.

- original: 32.61s render, SHA-256 `52fee0d9fcc66d3ab8ef82b8c76b9a8423deca3993f3af99913a59c3df941a08`.
- cta-edit: 28.40s render, SHA-256 `357b3c32524996d0b4ef44e4082663e34e940e7c665a4d30d7b92df65312c70e`.

Aligned decoded source/output audio correlation: 0.999710. CTA variants have identical decoded audio and identical presenter frames sampled at 1s and 4s. Source file hashes unchanged. Inspected presenter, product and shot-boundary frames. Validation: no runtime/layout/contrast errors, one structure warning. JSON reports motion checks disabled, so no automated motion-audit claim is made.

This is hand-authored Hyperframes, not agent-created output. Full listening, transcription, semantic review, preview parity and recovery/isolation gates remain pending. Artifacts: `hyperframes-worker/artifacts/real-media/`.

## Local lifecycle adapter (2026-09-28)

Added `scripts/lib/render-run.mjs` and `scripts/reliability.mjs`. Separate run
folders preserve earlier revisions. The completion manifest is written atomically
only after upstream checks, rendering, ffprobe assertions and full FFmpeg decode.
Abort and timeout kill the active process group. This is a trusted-fixture local
adapter, not production crash reconciliation or arbitrary-HTML validation.

Reproduce with the standalone Compose `node scripts/reliability.mjs` command in
the worker README. Machine evidence is ignored under `artifacts/reliability/`.
Full playback/listening, preview parity, hostile input checks, hard container
interruption/recovery and skill/system package pinning are still outstanding.

Observed local test results: cancellation → cancelled/no artifact; 100ms deadline
→ failed/no artifact; subsequent fresh run → ready after full decode; missing
product image → failed at check/no artifact. The earlier ready video's SHA-256
remained unchanged after the missing-media failure. Test process exited 0.
Built image: `sha256:483409a1229bc5f5362be8e076152014198dedb34a9858f870ed966e979a9bcd`.
These checks did not call an AI provider or change the application stack.

Repeated the suite with cancellation explicitly triggered two seconds after the render command starts (rather than during validation). All four scenarios passed again; the cancelled run has a render log and no deliverable.


## E0 acceptance results — 2026-09-28

The earlier progress notes above are historical. The local renderer gate is now
verified for trusted fixtures on this machine. This is not authorization to run
arbitrary generated HTML in production or a claim that E1–E6 are complete.

| Check | Observed evidence |
| --- | --- |
| Human playback | User confirmed “Both play and sound correct” for the synthetic product and original presenter/product samples. Product is intentionally silent. |
| Reverse seeks | Snapshot PNG hashes at 1s, 6s, 12s are identical in forward and reverse seek order. |
| Snapshot vs encoded output | SSIM 0.998358, 0.996144, 0.991947 at those timestamps; chosen threshold >0.98. This is sampled parity, not every-frame equality. |
| Missing font | Upstream check exits nonzero; no promotion. |
| Text overflow | Check reports `text_box_overflow` with affected selector, extent and suggested remedy. |
| Missing media | Lifecycle suite rejects it and preserves the previous ready artifact checksum. |
| Cancellation/deadline | Cooperative cancellation during render and deadline expiry leave no deliverable. A new run succeeds. |
| Hard crash | Named proof container killed during render (exit 137). State remains running, artifact null. Offline recovery marks it failed; previous ready run is preserved. |
| Paths | Absolute paths, `..` and symlink escapes rejected by scoped path helper. |
| Network | No non-loopback route; public 1.1.1.1, metadata 169.254.169.254 and private 10.0.0.1 fetches fail. No-network namespace applies to Chromium too. |
| Concurrency | Second worker sharing the output root exits 1 under `flock`; its command never runs. |
| Skills/license | 223 npm-bundled skill files hashed and checked at build. Package declares Apache-2.0. No skill upgrade or network installation during runs. |
| Runtime resources | ARM64 Docker, 10 host CPUs, 12,792,295,424 bytes VM memory. Worker cap 2 CPUs, 2 GiB RAM, 256 PIDs; scratch tmpfs ceiling 4 GiB (also subject to memory limit). |
| Measured memory | Inspection suite peak 517,410,816 bytes; successful lifecycle render peak 672,534,528 bytes; photo fixture first run peak 682,090,496 bytes. These are cgroup high-water values, not point samples. |
| Timing | Synthetic initial render 15.403s, subsequent CTA render 12.941s (renderer time); fresh lifecycle validation/render/full-decode 21.443s. Photo first run 23.552s including checks/decode. No claim of cold-machine benchmark. |
| Existing services | Application API/MCP and Redis/Postgres remain up; databases/cache healthy. No application container restarted. |

Photo fixture: supplied photo + local SVG logo + neutral source-supported text,
15s portrait, 24fps, with image aspect ratio preserved. Encoded poster visually
inspected. Additional machine reports and source hashes stay in ignored artifacts.

### Scope of closure

E0 proves local rendering, representative fidelity and recoverability. It does
not certify a multi-tenant hostile-code sandbox. Browser confinement, workspace
asset staging, untrusted HTML policy, storage quotas/retention and distributed
leases must still be enforced before admitting real agent/customer workloads.
The renderer's scratch size is capped; the retained artifact volume is a local
host folder and needs production quota/retention in E2/E6. Runtime reproduction
uses the recorded immutable local image; Dockerfile rebuilds are not bit-for-bit
because Debian mirrors are not snapshot-pinned.

No AI provider calls, production changes, commits or pushes were made for E0.

Final accepted image: `sha256:2151b4b6739e9e0336bcd498e36edde1eac98552e815cb3a38745ad7df698a75`. The final aspect-preserving photo render took
101.180s including checks/decode and peaked at 690,282,496 bytes. This substantial
latency variation on the shared local machine must be retained in capacity estimates.
Output: `artifacts/photo-proof/7a9ed83c-56ad-4fc5-89d1-241ac5a79326/video.mp4`.

Retained local evidence uses 40,170,924 bytes across all test runs; final photo MP4 is 411,460 bytes.
