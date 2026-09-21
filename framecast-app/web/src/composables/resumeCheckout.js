import api from '../services/api'

// Every sign-in uses the same checkout screen. Failure keeps the choice intact
// and offers a retry; it must not silently fall through to the dashboard.
export async function resumePendingCheckout() {
  try {
    const attempt = localStorage.getItem('wyv_pending_confirmation')
    if (attempt && /^[0-9a-f-]{36}$/i.test(attempt)) {
      window.location.href = `/payment/confirm?attempt=${encodeURIComponent(attempt)}`
      return true
    }
  } catch { /* optional cache */ }
  let selected = ''
  try { selected = localStorage.getItem('wyv_pending_plan') ?? '' } catch { /* optional cache */ }
  try {
    const { data } = await api.get('/billing/status')
    const billing = data?.data?.billing
    if (!billing) throw new Error('Billing status unavailable')
    if (!selected && !billing.checkout_required) return false
    const plan = selected || billing.checkout_plan || billing.pending_checkout?.plan || ''
    window.location.href = plan ? `/continue?plan=${encodeURIComponent(plan)}` : '/plans'
  } catch {
    // The continuation page displays the retry error if billing is unavailable.
    window.location.href = selected ? `/continue?plan=${encodeURIComponent(selected)}` : '/continue'
  }
  return true
}
