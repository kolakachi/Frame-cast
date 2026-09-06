/**
 * Turn an API error into something a person can act on.
 *
 * Validation failures come back as
 *   { error: { code: 'validation_error', message: 'The given data was invalid.', details: { field: [msg] } } }
 * and the UI was showing only the message — which names nothing, tells the
 * user nothing they can fix, and tells support nothing either. The details
 * were there the whole time and simply thrown away.
 */

/** Field names the API uses -> what the user sees them called on screen. */
const FIELD_LABELS = {
  prompt: 'Your prompt',
  aspect_ratio: 'Aspect ratio',
  visual_source: 'Visual source',
  animation_tier: 'Animation',
  scenes_count: 'Number of scenes',
  channel_id: 'Channel',
  source_image_asset_ids: 'Reference images',
  character_ids: 'Characters',
  consent: 'Likeness consent',
  scheduled_at: 'Scheduled time',
  social_account_id: 'Social account',
  export_job_id: 'Export',
  caption: 'Caption',
  title: 'Title',
}

function label(field) {
  const base = String(field).split('.')[0]
  if (FIELD_LABELS[base]) return FIELD_LABELS[base]
  return base.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

/**
 * @param {unknown} err        the caught axios error
 * @param {string}  fallback   message when the response carries nothing useful
 * @returns {string}
 */
export function apiErrorMessage(err, fallback = 'Something went wrong. Please try again.') {
  const payload = err?.response?.data?.error
  if (!payload) return fallback

  const details = payload.details
  if (details && typeof details === 'object') {
    const parts = []
    for (const [field, messages] of Object.entries(details)) {
      const first = Array.isArray(messages) ? messages[0] : messages
      if (!first) continue
      // Laravel writes "The prompt field is required." — the field is already
      // named in our label, so lead with that and keep the rule text.
      parts.push(`${label(field)}: ${String(first).replace(/^The [\w. ]+ field /, '').replace(/^The [\w. ]+ /, '')}`)
    }
    if (parts.length) return parts.join(' · ')
  }

  return payload.message || fallback
}
