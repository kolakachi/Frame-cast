// Webhook flood — hostile, unauthenticated input on the Kelviq webhook path.
//
// No auth needed (that's the point). Fires a stream of UNSIGNED / bad-signature
// webhook posts and asserts every one is cleanly rejected (401/400) — never a
// 5xx, never a 200 (which would mean signature verification can be bypassed).
// Also a cheap DoS probe: signature verification should be fast and not spike
// CPU. Watch `docker stats` on the box while this runs.
import http from 'k6/http'
import { check } from 'k6'

const BASE = __ENV.BASE_URL || 'https://app.wyvstudio.com'
// Adjust if your webhook route differs.
const WEBHOOK_PATH = __ENV.WEBHOOK_PATH || '/api/webhooks/kelviq'

export const options = {
  stages: [
    { duration: '20s', target: 20 },
    { duration: '40s', target: 100 },
    { duration: '20s', target: 0 },
  ],
  thresholds: {
    // Must NEVER 200 (bypass) and NEVER 5xx (crash on hostile input).
    'checks{check:rejected_cleanly}': ['rate>0.99'],
  },
}

export default function () {
  const body = JSON.stringify({
    id: `evt_load_${__VU}_${__ITER}`,
    type: 'subscription.created',
    data: { customer: { customer_id: 'attacker' }, planIdentifier: 'wyvstudio-agency' },
  })
  // Deliberately bogus Svix headers.
  const headers = {
    'Content-Type': 'application/json',
    'webhook-id': `msg_${__VU}_${__ITER}`,
    'webhook-timestamp': String(Math.floor(Date.now() / 1000)),
    'webhook-signature': 'v1,ZmFrZXNpZ25hdHVyZQ==', // base64("fakesignature")
  }
  const res = http.post(`${BASE}${WEBHOOK_PATH}`, body, { headers })
  check(res, {
    rejected_cleanly: (r) => r.status === 401 || r.status === 400 || r.status === 403,
  })
  check(res, {
    'not a bypass (never 200)': (r) => r.status !== 200,
    'no server crash (never 5xx)': (r) => r.status < 500,
  })
}
