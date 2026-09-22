export function editorReadiness(scenes, wholeVideo = false) {
  if (!scenes.length) return 'Add at least one scene before exporting.'
  if (wholeVideo) return scenes.length === 1 && scenes[0].visual_asset_id ? null : 'This whole-video take is not ready for original-file delivery.'
  for (const scene of scenes) {
    const label = scene.label || `Scene ${scene.scene_order ?? ''}`
    const voice = scene.voice_settings || scene.voice_settings_json || {}
    const image = scene.image_generation_settings || scene.image_generation_settings_json || {}
    const captions = scene.caption_settings || scene.caption_settings_json || {}
    const script = String(scene.script_text || '').trim()
    if (script && voice.is_outdated) return `${label}: re-record the outdated narration before exporting.`
    if (image.animation_outdated) return `${label}: update the outdated talking video before exporting.`
    if (image.in_progress || image.animation_in_progress || voice.in_progress) return `${label}: generation is still in progress.`
    if (image.last_error || image.animation_last_error || voice.last_error) return `${label}: repair the generation error before exporting.`
    if (!script && !scene.visual_asset_id && !captions.ugc_headline?.text) return `${label}: add a visual, headline or narration.`
    if (!scene.visual_asset_id && !['text_card', 'waveform'].includes(scene.visual_type)) return `${label}: choose a visual before exporting.`
    if (script && !voice.audio_asset_id) return `${label}: generate narration before exporting.`
  }
  return null
}
