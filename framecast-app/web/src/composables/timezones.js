/**
 * Timezone helpers shared by the schedule picker and account settings.
 *
 * Both need the same list and the same labels; the schedule picker also needs
 * to read a typed wall-clock time as if it were in a chosen zone, which is the
 * only genuinely fiddly part.
 */

/** The browser's own zone. Used as a default, never as the only option. */
export const detectedTimezone = (() => {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
})()

/**
 * A spread of common zones, used only where the browser doesn't expose the
 * full IANA database. Never the primary source — a short list is exactly the
 * problem this replaces.
 */
const FALLBACK = [
  'UTC',
  'Africa/Lagos', 'Africa/Accra', 'Africa/Johannesburg', 'Africa/Nairobi', 'Africa/Cairo',
  'Europe/London', 'Europe/Dublin', 'Europe/Lisbon', 'Europe/Paris', 'Europe/Berlin',
  'Europe/Madrid', 'Europe/Rome', 'Europe/Amsterdam', 'Europe/Warsaw', 'Europe/Istanbul',
  'Europe/Moscow',
  'America/New_York', 'America/Toronto', 'America/Chicago', 'America/Denver',
  'America/Phoenix', 'America/Los_Angeles', 'America/Vancouver', 'America/Mexico_City',
  'America/Bogota', 'America/Lima', 'America/Sao_Paulo', 'America/Argentina/Buenos_Aires',
  'Asia/Jerusalem', 'Asia/Riyadh', 'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata',
  'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Jakarta', 'Asia/Singapore', 'Asia/Manila',
  'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Seoul', 'Asia/Tokyo',
  'Australia/Perth', 'Australia/Brisbane', 'Australia/Sydney', 'Australia/Melbourne',
  'Pacific/Auckland', 'Pacific/Honolulu',
]

/** Every zone the browser knows, with the detected one guaranteed present. */
export function allTimezones() {
  let list = FALLBACK
  try {
    if (typeof Intl.supportedValuesOf === 'function') {
      const supported = Intl.supportedValuesOf('timeZone')
      if (Array.isArray(supported) && supported.length) list = supported
    }
  } catch {
    // keep the fallback
  }
  return list.includes(detectedTimezone) ? list : [detectedTimezone, ...list].sort()
}

/** Current UTC offset of a zone, in milliseconds, at a given instant. */
export function zoneOffsetMs(instant, timeZone) {
  try {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone, hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).formatToParts(instant).reduce((acc, p) => { acc[p.type] = p.value; return acc }, {})
    const asUTC = Date.UTC(
      Number(parts.year), Number(parts.month) - 1, Number(parts.day),
      Number(parts.hour) % 24, Number(parts.minute), Number(parts.second)
    )
    return asUTC - instant.getTime()
  } catch {
    return 0
  }
}

/**
 * "Africa/Lagos (GMT+1)" — the offset matters when picking from several
 * hundred entries, since most people know their offset but not their IANA
 * city. Computed live, so it reflects daylight saving as it stands today.
 */
export function zoneLabel(timeZone, at = new Date()) {
  const name = timeZone.replace(/_/g, ' ')
  const mins = Math.round(zoneOffsetMs(at, timeZone) / 60000)
  if (mins === 0) return `${name} (GMT)`
  const sign = mins > 0 ? '+' : '−'
  const abs = Math.abs(mins)
  const h = Math.floor(abs / 60)
  const m = abs % 60
  return `${name} (GMT${sign}${h}${m ? ':' + String(m).padStart(2, '0') : ''})`
}

/**
 * Read a typed wall-clock date + time AS IF it were in `timeZone`, and return
 * the real instant.
 *
 * Two passes: the offset is first looked up at an approximate instant, then
 * confirmed at the result. That second pass is what puts a time sitting on a
 * daylight-saving boundary on the correct side of the change.
 */
export function wallClockToDate(dateStr, timeStr, timeZone) {
  if (!dateStr || !timeStr) return null
  const naive = new Date(`${dateStr}T${timeStr}:00Z`)
  if (Number.isNaN(naive.getTime())) return null
  const first = zoneOffsetMs(naive, timeZone)
  let result = new Date(naive.getTime() - first)
  const settled = zoneOffsetMs(result, timeZone)
  if (settled !== first) result = new Date(naive.getTime() - settled)
  return result
}

/** True when the identifier is one the browser actually understands. */
export function isValidTimezone(tz) {
  if (!tz) return false
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: tz })
    return true
  } catch {
    return false
  }
}
