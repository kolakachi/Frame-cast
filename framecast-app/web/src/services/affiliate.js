// Exported independently so network failure and retry behavior can be tested.
export function captureAffiliate(api, win = window) {
  const key = 'wyv_aff_pending'
  let pending
  const url = new URL(win.location.href)
  const code = url.searchParams.get('aff')
  try { pending = JSON.parse(win.localStorage.getItem(key) || 'null') } catch {}
  if (pending && (!pending.at || Date.now() - pending.at > 90 * 864e5)) pending = null
  if (code && /^[A-Za-z0-9_-]{1,32}$/.test(code)) {
    const incomingId = url.searchParams.get('aff_visit')
    const validId = incomingId && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(incomingId)
    pending = { code, event_id: validId ? incomingId : win.crypto.randomUUID(),
      landing_path: url.pathname.slice(0, 255), at: Date.now() }
    try { win.localStorage.setItem(key, JSON.stringify(pending)) } catch {}
  }
  if (!pending) return
  let attempts = 0
  let running = false
  async function send() {
    if (!pending || running || attempts >= 3) return
    running = true
    attempts++
    try {
      await api.post('/affiliate/click', pending)
      const completed = pending
      pending = null
      try {
        const stored = JSON.parse(win.localStorage.getItem(key) || 'null')
        if (stored?.event_id === completed.event_id) win.localStorage.removeItem(key)
      } catch {}
      const current = new URL(win.location.href)
      if (current.searchParams.get('aff') === completed.code) {
        current.searchParams.delete('aff')
        current.searchParams.delete('aff_visit')
        win.history.replaceState(win.history.state, '', current.toString())
      }
    } catch {
      win.setTimeout(send, attempts * 1500)
    } finally { running = false }
  }
  win.addEventListener('online', () => { attempts = 0; send() })
  return send()
}
