import test from 'node:test';
import assert from 'node:assert/strict';
import { canGenerateOneShot } from '../src/services/ugcGenerationMode.js';
const presenter = { kind: 'on_camera', seconds: 5, script_text: 'Watch this.' };
const clip = { kind: 'b_roll', source: 'upload', asset_id: 42, seconds: 5, script_text: 'Our real product.' };
test('mixed presenter and uploaded footage preserve the composed lane', () => {
  assert.equal(canGenerateOneShot({ format: 'demo', segments: [presenter, clip] }), false);
});
test('clip-only and stock plans preserve their selected files', () => {
  for (const source of ['upload', 'stock']) {
    assert.equal(canGenerateOneShot({ format: 'demo', segments: [{ ...clip, source }] }), false);
  }
});
test('missing selected footage cannot escape through one-shot', () => {
  assert.equal(canGenerateOneShot({ format: 'demo', segments: [presenter, { ...clip, asset_id: null }] }), false);
});
test('fully generated spoken plans still use one-shot within its duration limit', () => {
  assert.equal(canGenerateOneShot({ format: 'demo', segments: [presenter, { ...clip, source: 'generate', asset_id: null }] }), true);
  assert.equal(canGenerateOneShot({ format: 'story', segments: [{ ...presenter, seconds: 31 }] }), false);
  assert.equal(canGenerateOneShot({ format: 'text_led', segments: [presenter] }), false);
});
