# Running Create locally with Opus 5.5

How to use the Create chat in the local app (kolakachi@gmail.com, workspace 1) with real Opus 5.5 builds, and what limits apply.

Last updated: 2026-10-01.

## Current state

- Local Create is in **paid mode**. `api/.env` has `CREATE_MODE=agent` and `CREATE_PAID_EXECUTION_ENABLED=true`.
- The local Opus test ledger is **uncapped**, by owner decision on 2026-10-01. The ledger is `hyperframes-worker/artifacts/live/e3-opus-budget.json`. It stood at $6.82 spent when the cap was removed.
- The local API runs a built image, not the mounted source. It was rebuilt on 2026-10-01 with the latest Create code, and the Create migrations were applied.

## Limits that still apply

| Limit | Value | Where it's set |
|---|---|---|
| Opus calls per build | 12 | `PilotPolicy::execution` |
| Cost per call | $0.30 maximum | `PilotPolicy::execution` |
| Worst case per build | $3.60 | 12 × $0.30 |
| App-side pilot ceiling | $4.50 | `CREATE_PILOT_BUDGET_MICROUSD=4500000` in `api/.env` |

A typical build costs **$0.30 to $0.50**. On real footage with contrast fixes, expect 9 to 12 calls.

The app admits a new run only if the amount already used, plus that run's $3.60 worst case, fits under the ceiling. About $0.43 was used at the last check. That leaves room for roughly one more build. To keep going, raise the ceiling (for example `CREATE_PILOT_BUDGET_MICROUSD=20000000` for $20) and restart the API:

```
docker compose up -d --no-deps api
```

Docker reads `api/.env` only when a container starts, so every `.env` change needs this restart.

## Run a build from the chat

1. Open the Create page at http://localhost:5173/create. The test conversation is `/create/19266222-df34-4e5b-8414-6585c7d82bd2`.
2. Send your request. The plan and quote appear; approve it. The run is now **queued**.
3. Start the worker from `framecast-app/`. It picks up queued runs:

   ```
   CREATE_WORKER_TOKEN="$(grep -E '^CREATE_WORKER_TOKEN=' api/.env | cut -d= -f2- | tr -d "\"'")" \
   CREATE_API_URL=http://localhost:8000 CREATE_AGENT_LIVE=1 \
   caffeinate -i node hyperframes-worker/agent/app-worker.mjs
   ```

   - The token is read straight from `api/.env`, so it never appears on screen. It must match `CREATE_WORKER_TOKEN` exactly.
   - Without `--once`, the worker keeps polling, so you can keep chatting. Add `--once` to process one run and exit.
   - `caffeinate -i` stops the Mac sleeping mid-build.
   - Stop the worker with Ctrl+C.

4. Watch the chat. A build takes about 2 to 5 minutes. The version, its summary, the delivery checks ("Before you post") and the cost appear there when it finishes.

## Cheaper changes

- **Text or colour only.** For example: "change the button text to Order now". The plan shows **This is a free change**; click **Apply for free**. There's no Opus build, just one render. The worker must be running for the render.
- **Other corrections.** Size, position, timing or motion changes make Opus patch the existing file rather than rebuild it. Say "redesign" or "start over" only if you want a full rebuild.

## If a run stops

| What you see | Meaning | What to do |
|---|---|---|
| Run stays **queued** | No worker is running | Start the worker (step 3) |
| "budget exhausted" in the worker journal | The app-side ceiling or a ledger cap was reached | Raise `CREATE_PILOT_BUDGET_MICROUSD`, restart the API, and send again |
| "The approved local test budget cannot cover this run" | The app-side ceiling can't fit the $3.60 worst case | Raise `CREATE_PILOT_BUDGET_MICROUSD` and restart the API |
| Run **needs attention** | The worker stopped mid-run | Ask for a check. If every call was settled, it can be closed safely with `ReconciliationService::closeSettled` |
| "Local render stopped without a usable result" | Generic failure message | The real reason is in `hyperframes-worker/artifacts/live/app-<run id>/failure.json` and `agent-state.json` |

Only one run is processed at a time. A run that needs attention blocks all queued runs until it's resolved.

## Checking spend

- **Per run.** Calls, cost and credits are in the `composition_attempts` table, one row per Opus call.
- **Local ledger.** Every Opus call's reservation and actual cost is in `hyperframes-worker/artifacts/live/e3-opus-budget.json`.

## Turning paid mode off

When you're done testing:

1. In `api/.env`, set `CREATE_MODE=fixture`. Optionally set `CREATE_PAID_EXECUTION_ENABLED=false`.
2. Restart the API:

   ```
   docker compose up -d --no-deps api
   ```

3. Stop the worker with Ctrl+C.

In fixture mode, Create renders a fixed offline sample and makes no paid calls.
