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
