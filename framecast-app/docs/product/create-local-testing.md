# Running Create locally with Opus 5.5

How to use the Create chat in the local app (kolakachi@gmail.com, workspace 1) with real Opus 5.5 builds, and what limits apply.

Last updated: 2026-10-01.

## Current state

- Local Create is in **paid mode**: `api/.env` has `CREATE_MODE=agent` and `CREATE_PAID_EXECUTION_ENABLED=true`.
- The local Opus test ledger (`hyperframes-worker/artifacts/live/e3-opus-budget.json`) is **uncapped** (owner, 2026-10-01). The app-side ceiling still applies.
- The local API runs a built image, not the mounted source. After a code change: `docker compose build api && docker compose up -d --no-deps api`, then `docker compose exec -T api php artisan migrate --force` if there are migrations.
- The sandbox image (`wyv-hyperframes-proof-smoke`) holds the worker's agent code, runtime fonts and the motion kit. After a change under `hyperframes-worker/`: `docker compose -f hyperframes-worker/compose.local.yml build smoke`, then restart the worker.

## Limits that apply

| Limit | Value | Where it's set |
|---|---|---|
| Opus calls per build | 16 | `PilotPolicy::execution` |
| Output tokens per call | 16,384 (thinking counts) | `PilotPolicy::execution` |
| Cost per call | $0.45 maximum | `PilotPolicy::execution` |
| Context per call | 96 KB | `PilotPolicy::execution` |
| Time per build | 15 minutes | `composition-agent.mjs` |
| Worst case per build | $7.20 plus plan media | 16 × $0.45 |
| App-side pilot ceiling | `CREATE_PILOT_BUDGET_MICROUSD` in `api/.env` ($40 as of 2026-10-01) | restart the API after changing it |
| Runs per day | `CREATE_RUN_DAILY_LIMIT` (owner set 50) | `api/.env` |

A build costs **$0.40 to $1.90** of Opus, typically 6 to 15 calls, plus its plan media at catalogue prices (poses 210 credits, talking shot 140, sound effects 50, music about 34, a voice line 3). The app admits a run only if the amount already used plus the run's worst case fits under the ceiling. Effort is recorded per run (`CREATE_AGENT_EFFORT`, default medium; the planner uses `CREATE_PLANNER_EFFORT`, default high).

When any limit is reached (calls, time, context, output, repairs, model unavailable), the last draft that passed every check is delivered with a note, so a build never ends empty if a checked draft existed.

## Run a build from the chat

1. Open http://localhost:5173/create. Pick a style in the composer (WyvStudio styles are built-in packs; your styles are saved ones) or let WyvStudio choose.
2. Send the brief. The plan shows the beats, the script and voice, the style and the quote; approve it. Lines whose words are not in your brief, facts or page are marked "new wording".
3. Start the worker from `framecast-app/`:

   ```
   CREATE_WORKER_TOKEN="$(grep -E '^CREATE_WORKER_TOKEN=' api/.env | cut -d= -f2- | tr -d "\"'")" \
   CREATE_API_URL=http://localhost:8000 CREATE_AGENT_LIVE=1 \
   caffeinate -i node hyperframes-worker/agent/app-worker.mjs
   ```

   The token is read from `api/.env` and never printed. Without `--once` the worker keeps polling. Stop it with Ctrl+C. Restart it after any worker code change.

4. A build takes 3 to 10 minutes. The version, its summary with the review scores, "Before you post" (safe area, edges, contrast, reading time, blank frames, loudness) and the cost appear in the chat.

Settings (the panel beside the composer): length, aspect ratio, approved facts, and **motion blur** (the final render takes about twice as long).

## Scripted builds

`scratchpad/local/pack.php` with `runpack.sh <brief file>` creates a conversation pinned to a style pack, studies the page and an optional X link, approves the first claims, plans, quotes and approves a run, all as user 1. `sequence.sh` runs several briefs one after another, waiting for each run to finish.

## Cheaper changes

- **Text or colour only** ("change the button text to Order now"): the plan shows **This is a free change**; click **Apply for free**. One render, no Opus.
- **Other corrections** (size, position, timing, motion) make Opus patch the existing version. Say "redesign" or "start over" for a full rebuild.

## If a run stops

| What you see | Meaning | What to do |
|---|---|---|
| Run stays **queued** | No worker is running | Start the worker |
| "The approved local test budget cannot cover this run" | The ceiling can't fit the run's worst case | Raise `CREATE_PILOT_BUDGET_MICROUSD`, restart the API, send again |
| `insufficient_credits` | The quote exceeds available credits (total minus all pool reservations) | Read the total/reserved/available breakdown. Inspect holds before adding test credits; a positive balance does not mean the entire balance is spendable. |
| Run **needs attention** | A paid call's outcome is unknown | Check `composition_attempts`; after confirming the original host/container stopped, `ReconciliationService::closeSettled($runId, true)` closes only receipt-backed terminal attempts and repairs their stranded job rows. A manually changed terminal label is not proof of settlement. |
| Old stopped run holds its entire quote | Unstarted work may still be reserved alongside an uncertain call | After verifying the original host/container stopped, use `php artisan create:quarantine-run <run> --worker-stopped --release-unstarted`. This retains the full remaining credit ceiling of uncertain calls, refuses unmapped jobs, revokes the lease and releases only unstarted allowance. It neither grants credits nor retries generation. |
| "Local render stopped without a usable result" | Generic failure | The reason is in `hyperframes-worker/artifacts/live/app-<run id>/failure.json` and `agent-state.json` (`failureDetail`) |

Only one run is processed at a time; a run that needs attention blocks the queue until it is resolved. Never restart the API while a build is mid-call: the call is paid for and lost.

## Checking spend

- **Per run:** `composition_attempts`, one row per Opus call and per plan item.
- **Pilot budget used:** sum `cost_microusd` (or the cap for unsettled calls) over runs with the current `pilot_budget_id`.

## Turning paid mode off

1. In `api/.env`, set `CREATE_MODE=fixture` (optionally `CREATE_PAID_EXECUTION_ENABLED=false`).
2. `docker compose up -d --no-deps api`.
3. Stop the worker.

In fixture mode, Create renders a fixed offline sample and makes no paid calls.


## Offline Barty adapter acceptance (2026-10-02)

This invokes the production composition runner and live render tools with a scripted
provider, **not** a live model. It does not consume the application queue or make
provider calls. The report labels supplied transcript timings as fixture metadata.
Run from `framecast-app/hyperframes-worker` on macOS (Samantha voice installed):

```sh
mkdir -p /tmp/wyv-barty-agent-proof
say -v Samantha -r 175 -o /tmp/wyv-barty-agent-proof/one.aiff 'One idea.'
say -v Samantha -r 175 -o /tmp/wyv-barty-agent-proof/two.aiff 'A new perspective.'
say -v Samantha -r 175 -o /tmp/wyv-barty-agent-proof/three.aiff 'Ready to create.'
docker compose -f compose.local.yml build smoke
docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --shm-size 512m \
  --tmpfs /tmp:rw,size=4294967296,mode=1777 \
  -v /tmp/wyv-barty-agent-proof:/output \
  wyv-hyperframes-proof-smoke:latest node scripts/barty-agent-fixture.mjs
```

The fixture replaces only `/output/live/barty-agent`, a dedicated test folder.
Outputs: `barty-agent-report.json`, `barty-agent-original.mp4`,
`barty-agent-edited.mp4`, sampled screenshots and detailed render logs.
It verifies adapter discovery/read access, both speech-timed builds, colour
preservation, unchanged source hashes and decoded export audio correlation.
A pass does not assert live model creativity, ASR accuracy or UI acceptance.


## Offline Remotion integration acceptance

From `framecast-app/hyperframes-worker`:

```sh
mkdir -p /tmp/wyv-remotion-proof
docker compose -f compose.local.yml build smoke
docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --shm-size 512m \
  --tmpfs /tmp:rw,size=4294967296,mode=1777 \
  -v /tmp/wyv-remotion-proof:/output \
  wyv-hyperframes-proof-smoke:latest node scripts/remotion-fixture.mjs
```

This replaces only `/output/live/remotion-proof` and uses a scripted provider.
It renders native React stills/clips, exports through Hyperframes, edits and checks
source preservation plus rejected-import behavior. Outputs include
`remotion-report.json`, `remotion-original.mp4` and `remotion-edited.mp4`.
No app queue, provider credential, network connection or paid call is used.
See [Remotion integration](remotion-integration.md) for supported imports and release gaps.
