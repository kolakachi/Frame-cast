import test from 'node:test'
import assert from 'node:assert/strict'
import { editorReadiness } from '../src/lib/editorReadiness.js'
const silent = { scene_order: 1, script_text: '', visual_asset_id: 10, visual_type: 'ai_image' }
const narrated = { ...silent, script_text: 'Hello', voice_settings: { audio_asset_id: 11 } }
test('silent visual and headline scenes do not require narration', () => {
  assert.equal(editorReadiness([silent]), null)
  assert.equal(editorReadiness([{ ...silent, visual_asset_id: null, visual_type: 'text_card', caption_settings: { ugc_headline: { text: 'Hello' } } }]), null)
})
test('narrated scenes require current voice and current talking video', () => {
  assert.equal(editorReadiness([narrated]), null)
  assert.match(editorReadiness([{ ...narrated, voice_settings: { audio_asset_id: 11, is_outdated: true } }]), /outdated narration/)
  assert.match(editorReadiness([{ ...narrated, image_generation_settings: { animation_outdated: true } }]), /talking video/)
  assert.match(editorReadiness([{ ...narrated, voice_settings: {} }]), /generate narration/)
})
test('in flight and failed generation are blocked', () => {
  assert.match(editorReadiness([{ ...narrated, image_generation_settings: { animation_in_progress: true } }]), /progress/)
  assert.match(editorReadiness([{ ...narrated, voice_settings: { audio_asset_id: 11, last_error: 'failed' } }]), /repair/)
})
test('whole-video original delivery has separate readiness', () => {
  assert.equal(editorReadiness([silent], true), null)
  assert.match(editorReadiness([silent, silent], true), /not ready/)
  assert.match(editorReadiness([], true), /Add at least/)
})
