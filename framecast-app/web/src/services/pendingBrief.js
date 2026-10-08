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

/** The brief in a location hash (`#brief=…`), or null when there is none. */
export function readBriefHash(hash) {
  if (typeof hash !== 'string' || !hash.startsWith('#brief=')) return null
  try { return clean(decodeURIComponent(hash.slice('#brief='.length).replace(/\+/g, ' '))) } catch { return null }
}

export function saveBrief(text, store, now = Date.now()) {
  const s = storage(store), t = clean(text)
  if (!s || !t) return false
  try { s.setItem(KEY, JSON.stringify({ text: t, at: now })); return true } catch { return false }
}

export function peekBrief(store, now = Date.now()) {
  const s = storage(store)
  if (!s) return null
  try {
    const saved = JSON.parse(s.getItem(KEY) || 'null')
    if (!saved?.text || !(now - saved.at <= DAYS * 864e5)) { s.removeItem(KEY); return null }
    return saved.text
  } catch {
    try { s.removeItem(KEY) } catch { /* nothing to clear */ }
    return null
  }
}

export function clearBrief(store) {
  const s = storage(store)
  try { s?.removeItem(KEY) } catch { /* nothing to clear */ }
}

/** The brief, removed as it is read: Create shows it once. */
export function takeBrief(store, now = Date.now()) {
  const text = peekBrief(store, now)
  clearBrief(store)
  return text
}
