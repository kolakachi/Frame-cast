// Generation burst — queue drain time under concurrent one-shots.
//
// ⚠️  THIS SPENDS REAL MONEY. Each submitted one-shot generates images (and
//     optionally animation) via OpenAI/Replicate. Default is capped at
//     GEN_COUNT=5 image-only 1-scene projects (~$0.20-1.00 total). Raise only
//     against a staging clone or with eyes open.
//
// Measures: how long the LAST submitted project waits with one generation
// worker. The submit calls return fast (jobs are queued); the wait is the
// queue itself. There is no clean API assertion for drain time here — read
// the server-side worker logs / dashboard after running, or watch the
// progress endpoint. This script just enqueues the burst safely and reports
// submit latency + that nothing 5xx'd.
import http from 'k6/http'
import { check } from 'k6'

const BASE = __ENV.BASE_URL || 'https://app.wyvstudio.com'
const TOKEN = __ENV.TOKEN || ''
const GEN_COUNT = parseInt(__ENV.GEN_COUNT || '5', 10)
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' }

export const options = {
  scenarios: {
    burst: { executor: 'shared-iterations', vus: GEN_COUNT, iterations: GEN_COUNT, maxDuration: '2m' },
  },
  thresholds: { http_req_failed: ['rate<0.10'] },
}

export default function () {
  // 1-scene, image-only (animate:false), no references — cheapest real job.
  const payload = JSON.stringify({
    prompt: `Load test scene ${__VU}: a single calm product shot on a clean background.`,
    aspect_ratio: '9:16',
    scenes_count: 1,
    visual_source: 'ai_images',
    animate: false,
  })
  const res = http.post(`${BASE}/api/v1/projects/one-shot`, payload, { headers, timeout: '60s' })
  check(res, {
    'submit accepted': (r) => r.status === 200 || r.status === 201 || r.status === 202,
    'no server error': (r) => r.status < 500,
  })
  console.log(`VU ${__VU}: submit status ${res.status} in ${Math.round(res.timings.duration)}ms`)
}
