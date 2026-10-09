// A brief typed on wyvstudio.com, carried into Weave. The landing page puts it after `#brief=` on its links (the part
// after # never reaches a server: not ours, not Kelviq, not analytics); the router saves it here before any sign-in
// redirect, and Create shows it once. It lives only in this browser, like a cart before sign-in: shown, never run,
// deleted once shown, and gone after a week if never used.
const KEY = 'wyv_pending_brief'
export const BRIEF_MAX = 1500
const DAYS = 7

function storage(store) {
  if (store) return store
  try { return globalThis.localStorage || null } catch { return null }
}

function clean(text) {
  const t = String(text ?? '').replace(/\r\n?/g, '\n').trim().slice(0, BRIEF_MAX).trim()
  return t || null
}

/**
 * The brief in a location hash (`#brief=…`, with `&attach=1` when the visitor means to upload a reference clip,
 * which cannot travel in a link), or null when there is none.
 */
export function readBriefHash(hash) {
  if (typeof hash !== 'string' || !hash.startsWith('#brief=')) return null
  const [raw, ...rest] = hash.slice('#brief='.length).split('&')
  let text
  try { text = clean(decodeURIComponent(raw.replace(/\+/g, ' '))) } catch { return null }
  // Where it came from: the website (default) or a dashboard card (from=card:<type>).
  const from = (rest.find(p => p.startsWith('from=')) || '').slice(5).replace(/[^a-z0-9:_-]/gi, '').slice(0, 40) || null
  return text ? { text, attach: rest.includes('attach=1'), from } : null
}

export function saveBrief(brief, store, now = Date.now()) {
  const s = storage(store), t = clean(typeof brief === 'string' ? brief : brief?.text)
  if (!s || !t) return false
  try { s.setItem(KEY, JSON.stringify({ text: t, attach: !!brief?.attach, from: brief?.from || null, at: now })); return true } catch { return false }
}

function saved(store, now) {
  const s = storage(store)
  if (!s) return null
  try {
    const saved = JSON.parse(s.getItem(KEY) || 'null')
    if (!saved?.text || !(now - saved.at <= DAYS * 864e5)) { s.removeItem(KEY); return null }
    return { text: saved.text, attach: !!saved.attach, from: saved.from || null }
  } catch {
    try { s.removeItem(KEY) } catch { /* nothing to clear */ }
    return null
  }
}

/** The saved brief's text, left in place (the register page shows it; Create uses it later). */
export function peekBrief(store, now = Date.now()) { return saved(store, now)?.text ?? null }

export function clearBrief(store) {
  const s = storage(store)
  try { s?.removeItem(KEY) } catch { /* nothing to clear */ }
}

/** The brief ({ text, attach }), removed as it is read: Create shows it once. */
export function takeBrief(store, now = Date.now()) {
  const brief = saved(store, now)
  clearBrief(store)
  return brief
}
