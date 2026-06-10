# WyvStudio load tests (k6)

Stress tests that answer "what happens at 1000 users?". Each script targets one
failure mode found in the capacity review.

## Install k6 (Mac)

```bash
brew install k6
```

## Run

Set the base URL and a real bearer token (grab one from the browser devtools →
Network → any `/api` request → Authorization header), then run a scenario:

```bash
export BASE_URL="https://app.wyvstudio.com"
export TOKEN="<paste JWT here>"

k6 run 01-browse.js          # API concurrency — read paths
k6 run 02-plan-concurrency.js # THE artisan-serve freeze test
k6 run 03-generation-burst.js # queue drain (COSTS REAL MONEY — see header)
k6 run 04-websocket.js        # Reverb under load
k6 run 05-webhook-flood.js    # hostile-input path (no auth needed)
```

## Cautions

1. **Run from your Mac, never from the prod box** (the box is the target).
2. **`03-generation-burst.js` spends real OpenAI/Replicate credits.** It is
   capped low by default (`GEN_COUNT=5`). Read its header before running.
3. **Your own per-IP rate limiters will 429 a single-source flood.** For the
   browse/plan tests, either run with a valid token (authenticated paths are
   rate-limited per-user, still single-IP) or temporarily allowlist your IP.
   Expect 429s — the tests assert the app stays *up*, not that every request
   200s.
4. Run read-only scenarios against prod **off-peak**. Run generation bursts
   against a staging clone if you want volume.

## What "pass" looks like

- **01-browse**: p95 < 500ms up to ~50 VUs. If p95 climbs to multiple seconds
  at low VUs, that's the single-threaded `artisan serve` bottleneck.
- **02-plan-concurrency**: with 2+ concurrent planners, a *separate* cheap
  request (`/api/health`) must stay fast. If health latency spikes to 10s+
  while plans run, the API is serializing all requests (the bug to fix).
- **03-generation-burst**: no 5xx; jobs eventually complete; note the drain time.
- **04-websocket**: connections hold, no mass drops.
- **05-webhook-flood**: every unsigned request gets a clean 401/400, CPU stable.
