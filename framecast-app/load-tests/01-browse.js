// Browse load — the read paths a logged-in user hits constantly.
// Proves the API's concurrency ceiling. On single-threaded `artisan serve`,
// p95 climbs into seconds at low VU counts; on php-fpm it should stay flat.
import http from 'k6/http'
import { check, sleep } from 'k6'
import { Trend } from 'k6/metrics'

const BASE = __ENV.BASE_URL || 'https://app.wyvstudio.com'
const TOKEN = __ENV.TOKEN || ''

const meLatency = new Trend('me_latency', true)
const listLatency = new Trend('projects_list_latency', true)

export const options = {
  stages: [
    { duration: '30s', target: 10 },
    { duration: '1m', target: 50 },
    { duration: '1m', target: 100 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],          // <5% errors (429s count — tune if rate-limited)
    me_latency: ['p(95)<500'],
    projects_list_latency: ['p(95)<800'],
  },
}

const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' }

export default function () {
  const me = http.get(`${BASE}/api/v1/me`, { headers })
  meLatency.add(me.timings.duration)
  check(me, { 'me ok or 429': (r) => r.status === 200 || r.status === 429 })

  const list = http.get(`${BASE}/api/v1/projects`, { headers })
  listLatency.add(list.timings.duration)
  check(list, { 'projects ok or 429': (r) => r.status === 200 || r.status === 429 })

  // Drill into the first project if we got one (detail path = more queries).
  if (list.status === 200) {
    try {
      const body = JSON.parse(list.body)
      const first = (body.data || body)[0]
      if (first && first.id) {
        const detail = http.get(`${BASE}/api/v1/projects/${first.id}`, { headers })
        check(detail, { 'detail ok or 429': (r) => r.status === 200 || r.status === 429 })
      }
    } catch (_) { /* ignore parse */ }
  }

  sleep(Math.random() * 2 + 1) // 1-3s think time
}
