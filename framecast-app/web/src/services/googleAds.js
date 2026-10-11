// Google Ads conversions (owner, 2026-10-11). The tag itself loads from public/google-ads.js with Consent Mode, so a
// conversion is counted with cookies only for visitors who allowed them; others are modelled by Google. Each kind needs
// its label from the Google Ads conversion action ("AW-18506583015/<label>"); until a label is filled in, nothing is sent.
const ID = 'AW-18506583015'
const LABELS = {
  signup: 'z_DsCOG_lJkdEOeX0PhE', // "Sign-up" action, 2026-10-11 (Google's snippet sends a $1 value)
  purchase: '',
}
// What each one-time purchase is worth, for the purchase conversion's value (the public prices).
export const PLAN_VALUE_USD = {
  ugc_pass: 9, lifetime_starter: 59, lifetime_creator: 199, lifetime_agency: 399,
  // Top-ups and the monthly plans shown in the app to a buyer with no plan yet.
  small: 8, medium: 18, large: 36, xl: 70, starter: 29, creator: 59, pro: 99, agency: 199,
}

export function conversion(kind, { value = null, transactionId = null } = {}) {
  const label = LABELS[kind]
  if (!label || typeof window === 'undefined' || typeof window.gtag !== 'function') return
  try {
    window.gtag('event', 'conversion', {
      send_to: `${ID}/${label}`,
      ...(value != null ? { value, currency: 'USD' } : {}),
      ...(transactionId ? { transaction_id: String(transactionId) } : {}),
    })
  } catch { /* never let tracking break the app */ }
}
