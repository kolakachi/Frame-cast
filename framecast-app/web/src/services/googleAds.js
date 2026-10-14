// Google Ads conversions (owner, 2026-10-11). The tag itself loads from public/google-ads.js with Consent Mode, so a
// conversion is counted with cookies only for visitors who allowed them; others are modelled by Google. Each kind needs
// its label from the Google Ads conversion action ("AW-18506583015/<label>"); until a label is filled in, nothing is
// sent. Setup guide for the Ads side: docs/product/google-ads-conversions.md.
const ID = 'AW-18506583015'
const LABELS = {
  signup: 'z_DsCOG_lJkdEOeX0PhE', // "Sign-up", primary while purchases are few (Google's snippet sends a $1 value)
  purchase: 'LtsvCN-Jn5kdEOeX0PhE', // "Purchase", 2026-10-11
  begin_checkout: '', // secondary; label to follow from the Ads side
  first_video: '', // secondary; label to follow (needs a server signal, not built yet)
}
// What each purchase is worth, for the conversion's value: the list price in USD (the amount actually paid replaces it
// once purchases record their amounts).
export const PLAN_VALUE_USD = {
  ugc_pass: 9, lifetime_entry: 69, lifetime_starter: 47, lifetime_creator: 157, lifetime_agency: 299,
  // Top-ups and the monthly plans shown in the app to a buyer with no plan yet.
  small: 8, medium: 18, large: 36, xl: 70, starter: 29, creator: 59, pro: 99, agency: 199,
}

/**
 * Send one conversion. Resolves once Google has it, or after a short wait, so a caller about to leave the page (the
 * checkout redirect) can wait for it. With an email, enhanced conversions get it: the tag hashes it before sending,
 * and only when the visitor allowed ad data.
 */
export function conversion(kind, { value = null, transactionId = null, email = null } = {}) {
  const label = LABELS[kind]
  if (!label || typeof window === 'undefined' || typeof window.gtag !== 'function') return Promise.resolve()
  return new Promise((resolve) => {
    const done = () => resolve()
    setTimeout(done, 800)
    try {
      if (email) window.gtag('set', 'user_data', { email: String(email).trim().toLowerCase() })
      window.gtag('event', 'conversion', {
        send_to: `${ID}/${label}`,
        ...(value != null ? { value, currency: 'USD' } : {}),
        ...(transactionId ? { transaction_id: String(transactionId) } : {}),
        event_callback: done,
      })
    } catch { done() /* never let tracking break the app */ }
  })
}
