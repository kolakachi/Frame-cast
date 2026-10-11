// Where a visitor first came from (owner, 2026-10-11, priorities #6): the Google click id, UTM tags, referring site and
// landing page of their first visit, sent with the sign-up so each account (and each sale) has a channel. Kept for the
// visit in sessionStorage; also in a cookie shared with wyvstudio.com, for 90 days, only once they pressed "Allow" in the
// cookie notice. Without a cookie, wyvstudio.com hands its first visit over in the link (?src=…). The first visit wins;
// a later Google ad click is still added, since that is the campaign being judged.
const KEYS = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']
const CLICKS = ['gclid', 'gbraid', 'wbraid']
const NAME = 'wyv_src'
// Hosts a visitor passes through that say nothing about where they came from.
const PASSING = /(^|\.)(wyvstudio\.com|kelviq\.com|stripe\.com|paypal\.com|appsumo\.com)$|^accounts\.google\.com$|^localhost$/

function consented(win) {
  try { return /(?:^|;\s*)wyv_consent=all/.test(win.document.cookie) } catch { return false }
}

function readStored(win) {
  try {
    const m = win.document.cookie.match(/(?:^|;\s*)wyv_src=([^;]+)/)
    if (m) { const v = JSON.parse(decodeURIComponent(m[1])); if (v && typeof v === 'object') return v }
  } catch {}
  try { const v = JSON.parse(win.sessionStorage.getItem(NAME) || 'null'); if (v && typeof v === 'object') return v } catch {}
  return null
}

function store(win, touch) {
  const value = JSON.stringify(touch)
  try { win.sessionStorage.setItem(NAME, value) } catch {}
  if (!consented(win)) return
  const host = win.location.hostname
  const domain = /(^|\.)wyvstudio\.com$/.test(host) ? '; domain=.wyvstudio.com' : ''
  try {
    win.document.cookie = `${NAME}=${encodeURIComponent(value)}; max-age=${90 * 86400}; path=/; samesite=lax${domain}${win.location.protocol === 'https:' ? '; secure' : ''}`
  } catch {}
}

function clean(t) {
  if (!t || typeof t !== 'object') return null
  const out = {}
  for (const k of [...KEYS, 'ref_host', 'landing', 'at']) {
    if (typeof t[k] === 'string' && t[k].trim()) out[k] = t[k].trim().slice(0, 200)
  }
  return out
}

export function captureAttribution(win = window) {
  const url = new URL(win.location.href)
  const fresh = {}
  for (const k of KEYS) { const v = url.searchParams.get(k); if (v) fresh[k] = v.slice(0, 200) }
  try {
    const ref = win.document.referrer ? new URL(win.document.referrer).hostname.replace(/^www\./, '').toLowerCase() : ''
    if (ref && !PASSING.test(ref)) fresh.ref_host = ref
  } catch {}
  let carried = null
  try { carried = clean(JSON.parse(url.searchParams.get('src') || 'null')) } catch {}

  let touch = readStored(win)
  if (!touch) touch = carried && Object.keys(carried).length ? carried : { ...fresh, landing: url.pathname.slice(0, 200), at: new Date().toISOString() }
  else if (!CLICKS.some(k => touch[k])) {
    for (const k of CLICKS) { const v = fresh[k] || carried?.[k]; if (v) touch[k] = v }
  }
  store(win, touch)
  if (url.searchParams.has('src')) {
    url.searchParams.delete('src')
    try { win.history.replaceState(win.history.state, '', url.toString()) } catch {}
  }
  return touch
}

/** The first visit to send with a sign-up; null when none was captured. */
export function attribution(win = window) {
  return clean(readStored(win))
}
