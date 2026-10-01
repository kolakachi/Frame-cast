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
| `insufficient_credits` | Workspace 1 is out of local test credits | `app(CreditService::class)->grant(1, 4000, 'local test: ...')` in tinker |
| Run **needs attention** | A paid call's outcome is unknown | Check `composition_attempts`; if every call is settled, `ReconciliationService::closeSettled($runId, true)` closes it. Never update attempt rows by hand without also closing their `api_operation_jobs`, or credits stay reserved |
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
