import api from '../services/api'
import { useAuthStore } from '../stores/auth'

// Resume required first purchases using the signed-in account's billing state.
// Browser hints alone must never force an existing customer to buy again.
export async function resumePendingCheckout() {
  if (['super_admin', 'platform_admin'].includes(useAuthStore().user?.role)) {
    try {
      localStorage.removeItem('wyv_pending_plan')
      localStorage.removeItem('wyv_pending_confirmation')
    } catch { /* optional cache */ }
    return false
  }
  let selected = ''
  try { selected = localStorage.getItem('wyv_pending_plan') ?? '' } catch { /* optional cache */ }
  try {
    const { data } = await api.get('/billing/status')
    const billing = data?.data?.billing
    if (!billing) throw new Error('Billing status unavailable')
    // Browser checkout hints are shared across logins and can outlive payment.
    // Only the current account's server state may require checkout at sign-in.
    if (!billing.checkout_required) {
      try {
        localStorage.removeItem('wyv_pending_plan')
        localStorage.removeItem('wyv_pending_confirmation')
      } catch { /* optional cache */ }
      return false
    }
    let attempt = ''
    try { attempt = localStorage.getItem('wyv_pending_confirmation') || '' } catch { /* optional cache */ }
    if (attempt && /^[0-9a-f-]{36}$/i.test(attempt)) {
      // Confirm ownership before following a hint left by a different login.
      try {
        await api.get(`/billing/confirmation/${encodeURIComponent(attempt)}`)
        window.location.href = `/payment/confirm?attempt=${encodeURIComponent(attempt)}`
        return true
      } catch (error) {
        if (![403, 404].includes(error.response?.status)) throw error
        try { localStorage.removeItem('wyv_pending_confirmation') } catch { /* optional cache */ }
      }
    }
    const plan = billing.checkout_plan || billing.pending_checkout?.plan || selected || ''
    window.location.href = plan ? `/continue?plan=${encodeURIComponent(plan)}` : '/plans'
  } catch {
    // The continuation page displays the retry error if billing is unavailable.
    window.location.href = '/continue'
  }
  return true
}

// Whether the signed-in account must still pay before using the app: a free account that registered for a plan and
// never finished checkout (the server's checkout_required, which exempts paid tiers, staff and grandfathered free
// accounts). Asked on every app page, so a session that outlived an abandoned checkout is sent back to pay instead of
// opening the dashboard (owner, 2026-10-13). Cached for 30 s; on any error the app opens as usual.
let gateCache = null
export function forgetCheckoutGate() { gateCache = null }
export async function checkoutGate() {
  if (gateCache && Date.now() - gateCache.at < 30000) return gateCache.value
  try {
    const { data } = await api.get('/billing/status')
    const billing = data?.data?.billing
    const value = billing ? { required: Boolean(billing.checkout_required), plan: billing.checkout_plan || billing.pending_checkout?.plan || '',
      // A free account: its free ad, upgrading, settings and its past videos only (owner, 2026-10-17).
      freeOnly: Boolean(billing.free_only), freeAd: billing.free_ad_conversation_id || null, hasPastVideos: Boolean(billing.has_past_videos) } : null
    gateCache = { at: Date.now(), value }
    return value
  } catch {
    return null
  }
}

// A link that names one template (?template=<id>, TPL-LINK): its id when it looks like one, else null.
export function templateFromLink(value) {
  return typeof value === 'string' && /^[a-z0-9_-]{2,40}$/i.test(value) ? value : null
}

// The pages a free account can open (FreePlanScope on the server refuses the rest): Templates, its own free ad in
// Create, upgrading, settings, onboarding and its past videos (to watch and download).
export function freeAllows(to, gate) {
  if (['templates', 'template', 'plans', 'continue-checkout', 'payment-confirmation', 'settings', 'onboarding'].includes(to.name)) return true
  if (to.name === 'videos') return gate.hasPastVideos
  // Create opens only the free ad; a template's "Make my free ad" opens it with the new conversation's id before the
  // gate has been asked again, so a conversation id that is not yet known is let through (the server keeps the rules).
  if (to.name === 'create') return !!to.params.conversationId && (!gate.freeAd || String(to.params.conversationId) === String(gate.freeAd))
  return false
}
