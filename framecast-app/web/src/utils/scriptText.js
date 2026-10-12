// Is this a finished script someone pasted, rather than an idea for one? Mirrors App\Support\ScriptText::looksLikeScript
// (2026-10-12: a coach pasted their 232-word session intro into "Write a Prompt" at 180 seconds, and it failed).
export function wordCount(text) {
  return (String(text || '').trim().match(/[A-Za-z0-9'’-]+/g) || []).length
}

export function looksLikeScript(text) {
  const t = String(text || '').trim()
  const words = wordCount(t)
  if (words < 60) return false
  if (/^\W*(please\s+)?(make|create|write|generate|produce|turn|i want|i need|can you|a video|video about)\b/i.test(t)) return false
  const sentences = (t.match(/[.!?](\s|$)/g) || []).length
  const spoken = (t.match(/\b(i|i'm|i've|i'll|my|me|you|you're|you've|your|we|we're|let's|our|us)\b/gi) || []).length
  return sentences >= 4 && spoken / Math.max(1, words) >= 0.04
}

// About how long a text takes to say aloud, in seconds (a natural 2.6 words a second).
export function spokenSeconds(text) {
  return Math.round(wordCount(text) / 2.6)
}
