import test from 'node:test'
import assert from 'node:assert/strict'
import { tune, DEFAULT_ORDER, ORDER, EXAMPLES } from '../src/components/dashboard/industries.js'

const cards = DEFAULT_ORDER.map(key => ({ key }))

test('an unknown industry keeps the default order and the cards own wording', () => {
  for (const industry of [null, undefined, 'other', 'agency']) {
    const out = tune(cards, industry)
    assert.deepEqual(out.map(c => c.key), DEFAULT_ORDER)
    assert.ok(out.every(c => c.example === null))
  }
})

test('a known industry reorders the cards and gives each an example', () => {
  const out = tune(cards, 'tech')
  assert.deepEqual(out.map(c => c.key), ORDER.tech)
  assert.equal(out[0].example, EXAMPLES.tech.launch_promo)
})

test('every industry orders all six cards and words every one', () => {
  for (const [industry, order] of Object.entries(ORDER)) {
    assert.deepEqual([...order].sort(), [...DEFAULT_ORDER].sort(), industry)
    assert.deepEqual(Object.keys(EXAMPLES[industry]).sort(), [...DEFAULT_ORDER].sort(), industry)
  }
})

test('the onboarding goal leads the cards; the rest keep the industry order', () => {
  const cards = DEFAULT_ORDER.map(key => ({ key }))
  assert.deepEqual(tune(cards, 'beauty', 'explain').map(c => c.key), ['explainer', 'testimonial', 'offer_ad', 'listicle', 'reference', 'launch_promo'])
  assert.deepEqual(tune(cards, null, 'ugc').map(c => c.key)[0], 'testimonial')
  assert.deepEqual(tune(cards, 'beauty', 'explore').map(c => c.key), tune(cards, 'beauty').map(c => c.key), 'just exploring changes nothing')
})
