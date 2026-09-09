import api from '../services/api'

/**
 * Send someone straight back to Kelviq when they have a checkout they never
 * finished.
 *
 * While the free tier is closed a signup arrives having chosen a plan, gets
 * handed to Kelviq, and may simply close the tab. Without this they could log
 * in afterwards to an account with no credits and no obvious way forward. The
 * intent is recorded server-side (workspaces.pending_checkout_*, cleared by
 * the webhook the moment a purchase lands), so it survives a new device, a
 * cleared cache and any amount of time.
 *
 * Only fires for someone who has not actually paid — a workspace on any real
 * tier is left alone, so a paid customer whose row was never cleared can still
 * reach the app.
 *
 * @returns {Promise<boolean>} true when the browser is being sent to Kelviq
 */
export async function resumePendingCheckout() {
  let plan = ''
  let required = false

  try {
    const { data } = await api.get('/billing/status')
    const billing = data?.data?.billing
    plan = billing?.pending_checkout?.plan ?? ''
    // The server decides who can be held at the door: an unfinished checkout,
    // no paid tier, and an account created after the gate went up. Anyone who
    // signed up while the free tier was genuinely on offer is exempt — they
    // keep it, and are never bounced to checkout at sign-in.
    required = Boolean(billing?.checkout_required)
  } catch {
    return false // never block a sign-in on this
  }

  if (!plan || !required) return false

  const LIFETIME = ['lifetime_starter', 'lifetime_creator', 'lifetime_agency']
  const MONTHLY = ['starter', 'creator', 'pro', 'agency']
  const TOPUP = ['small', 'medium', 'large', 'xl']

  const body = LIFETIME.includes(plan)
    ? { lifetime: plan }
    : MONTHLY.includes(plan)
      ? { plan }
      // A top-up is an add-on, not the thing standing between them and the
      // product — never hold someone at the door over one.
      : TOPUP.includes(plan) ? null : null

  if (!body) return false

  try {
    const { data } = await api.post('/billing/kelviq/checkout', body)
    if (data?.data?.url) {
      window.location.href = data.data.url
      return true
    }
  } catch {
    // Checkout unavailable — fall through to the app rather than stranding them.
  }

  return false
}
