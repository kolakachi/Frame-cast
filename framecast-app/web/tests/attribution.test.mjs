import { test } from 'node:test'
import assert from 'node:assert/strict'
import { attribution, captureAttribution } from '../src/services/attribution.js'

// A browser where document.cookie behaves like one: writes set or clear a name, reads join them.
function browser(href, { referrer = '', cookies = {} } = {}) {
  const jar = new Map(Object.entries(cookies))
  const session = new Map()
  const u = new URL(href)
  const win = {
    location: { href, hostname: u.hostname, protocol: u.protocol },
    sessionStorage: { getItem: k => session.get(k) ?? null, setItem: (k, v) => session.set(k, v) },
    history: { state: null, replaceState(_, __, url) { win.location.href = url } },
    document: {
      referrer,
      get cookie() { return [...jar].map(([k, v]) => `${k}=${v}`).join('; ') },
      set cookie(line) { const [pair, ...attrs] = line.split('; '); const [k, v] = pair.split('='); if (attrs.includes('max-age=0')) jar.delete(k); else { jar.set(k, v); win.lastCookie = line } },
    },
  }
  return { win, jar, session }
}

test('a Google ad visit is kept for the tab, and in the shared cookie only after Allow', () => {
  const plain = browser('https://app.wyvstudio.com/register?gclid=Cj0KCQjw_abcdefghij&utm_campaign=brand', { referrer: 'https://www.google.com/' })
  const t = captureAttribution(plain.win)
  assert.equal(t.gclid, 'Cj0KCQjw_abcdefghij')
  assert.equal(t.ref_host, 'google.com')
  assert.equal(t.landing, '/register')
  assert.equal(plain.jar.has('wyv_src'), false, 'no cookie without consent')
  assert.equal(attribution(plain.win).utm_campaign, 'brand')

  const allowed = browser('https://app.wyvstudio.com/?utm_source=chatgpt.com', { cookies: { wyv_consent: 'all' } })
  captureAttribution(allowed.win)
  assert.match(allowed.win.lastCookie, /domain=\.wyvstudio\.com/)
  assert.match(allowed.win.lastCookie, /max-age=7776000/)
})

test('the first visit wins, a later ad click is added, and our own hosts are not a source', () => {
  const b = browser('https://app.wyvstudio.com/', { referrer: 'https://chatgpt.com/' })
  captureAttribution(b.win)
  b.win.location.href = 'https://app.wyvstudio.com/pricing?gclid=Cj0KCQjw_later12345&utm_source=newsletter'
  b.win.document.referrer = 'https://checkout.kelviq.com/'
  const t = captureAttribution(b.win)
  assert.equal(t.ref_host, 'chatgpt.com')
  assert.equal(t.utm_source, undefined, 'first visit wins')
  assert.equal(t.gclid, 'Cj0KCQjw_later12345', 'a later ad click is still recorded')
})

test('a visit handed over from wyvstudio.com is taken and the link cleaned', () => {
  const src = encodeURIComponent(JSON.stringify({ utm_source: 'facebook', utm_medium: 'paid_social', landing: '/agencies', at: '2026-10-12T09:00:00Z', evil: 'x' }))
  const b = browser(`https://app.wyvstudio.com/register?plan=starter&src=${src}`)
  const t = captureAttribution(b.win)
  assert.deepEqual(t, { utm_source: 'facebook', utm_medium: 'paid_social', landing: '/agencies', at: '2026-10-12T09:00:00Z' })
  assert.equal(b.win.location.href, 'https://app.wyvstudio.com/register?plan=starter')
})
