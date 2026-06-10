// THE artisan-serve freeze test.
//
// `planOneShot` holds a request for 10-30s (URL fetch + LLM call). On a
// single-threaded server, ONE plan in flight blocks EVERY other request.
// This test fires a few concurrent plans while hammering a cheap /api/health
// in parallel — and asserts health STAYS FAST. If health latency tracks the
// plan duration, the API is serializing all traffic (the bug to fix).
//
// Uses a URL-less prompt so it does NOT fetch external pages or burn image
// credits — the plan call itself (LLM parse) is what we're measuring. It does
// make one cheap gpt-4o-mini call per iteration (~$0.001).
import http from 'k6/http'
import { check } from 'k6'
import { Trend } from 'k6/metrics'

const BASE = __ENV.BASE_URL || 'https://app.wyvstudio.com'
const TOKEN = __ENV.TOKEN || ''
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' }

const healthDuringPlan = new Trend('health_latency_while_planning', true)

export const options = {
  scenarios: {
    // A handful of users requesting plans at the same time.
    planners: {
      executor: 'constant-vus', vus: 3, duration: '1m', exec: 'planner',
    },
    // A cheap endpoint polled throughout — the canary.
    canary: {
      executor: 'constant-arrival-rate', rate: 2, timeUnit: '1s',
      duration: '1m', preAllocatedVUs: 5, exec: 'canary',
    },
  },
  thresholds: {
    // The whole point: the canary must stay fast even while plans run.
    health_latency_while_planning: ['p(95)<1000'],
  },
}

export function planner() {
  const payload = JSON.stringify({
    prompt: 'A 3-scene ad for a productivity app. Scene 1 a stressed worker, scene 2 they find the app, scene 3 relief and focus.',
    aspect_ratio: '9:16',
    scenes_count: 3,
  })
  const res = http.post(`${BASE}/api/v1/projects/one-shot/plan`, payload, { headers, timeout: '60s' })
  check(res, { 'plan ok or 429': (r) => r.status === 200 || r.status === 429 })
}

export function canary() {
  const res = http.get(`${BASE}/api/health`, { timeout: '30s' })
  healthDuringPlan.add(res.timings.duration)
  check(res, { 'health 200': (r) => r.status === 200 })
}
