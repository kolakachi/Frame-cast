/**
 * The languages WyvStudio can write and speak.
 *
 * One list, because there were three and they had drifted: Channels offered
 * ten, Variants eight, and the new-video wizard only three — so German was
 * selectable when creating a Channel but not when creating a video, which is
 * what a customer ran into asking whether German was supported at all.
 *
 * These drive script generation (projects.primary_language reaches
 * GenerateScriptJob) and voice synthesis (the same value reaches
 * GenerateTTSJob), so a code added here must be one the TTS provider can
 * actually speak.
 */
export const LANGUAGES = [
  { value: 'en', label: 'English' },
  { value: 'es', label: 'Spanish' },
  { value: 'fr', label: 'French' },
  { value: 'de', label: 'German' },
  { value: 'pt', label: 'Portuguese' },
  { value: 'it', label: 'Italian' },
  { value: 'hi', label: 'Hindi' },
  { value: 'ja', label: 'Japanese' },
  { value: 'ar', label: 'Arabic' },
  { value: 'zh', label: 'Chinese' },
]

/** "German" for 'de'; falls back to the raw code so nothing renders blank. */
export function languageLabel(code) {
  return LANGUAGES.find((l) => l.value === code)?.label ?? code
}

/**
 * Everything except the language a project is already in — the list for
 * localisation, where offering the source language back is meaningless.
 */
export function localizationTargets(sourceLanguage = 'en') {
  return LANGUAGES.filter((l) => l.value !== sourceLanguage)
}
