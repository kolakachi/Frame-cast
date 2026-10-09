import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readBriefHash, saveBrief, peekBrief, takeBrief, clearBrief, BRIEF_MAX } from '../src/services/pendingBrief.js'

function store() {
  const values = new Map()
  return { values, getItem: k => values.get(k) ?? null, setItem: (k, v) => values.set(k, String(v)), removeItem: k => values.delete(k) }
}

test('a brief after #brief= is read, decoded, trimmed and capped; anything else is not a brief', () => {
  assert.deepEqual(readBriefHash('#brief=' + encodeURIComponent('  A 20-second ad for Hearthline.\r\nWarm & tactile.  ')), { text: 'A 20-second ad for Hearthline.\nWarm & tactile.', attach: false, from: null })
  assert.deepEqual(readBriefHash('#brief=A+couch+ad'), { text: 'A couch ad', attach: false, from: null })
  assert.equal(readBriefHash('#brief=' + 'x'.repeat(BRIEF_MAX + 50)).text.length, BRIEF_MAX)
  assert.deepEqual(readBriefHash('#brief=' + encodeURIComponent('Same pacing, for my shop') + '&attach=1'), { text: 'Same pacing, for my shop', attach: true, from: null }, 'a clip to upload is remembered')
  assert.deepEqual(readBriefHash('#brief=' + encodeURIComponent('An ad for [your product]') + '&from=card:offer_ad'), { text: 'An ad for [your product]', attach: false, from: 'card:offer_ad' }, 'a dashboard card says where it came from')
  for (const h of ['', '#pricing', '#brief=', '#brief=%E0%A4%A', null, undefined]) assert.equal(readBriefHash(h), null)
})

test('the saved brief is shown once, then gone', () => {
  const s = store()
  assert.equal(saveBrief('A couch ad', s, 1000), true)
  assert.equal(peekBrief(s, 2000), 'A couch ad', 'peeking keeps it (the register page shows it, Create uses it later)')
  assert.deepEqual(takeBrief(s, 3000), { text: 'A couch ad', attach: false, from: null })
  assert.equal(peekBrief(s, 4000), null)
})

test('a brief saved with a clip to upload keeps that through to Create', () => {
  const s = store()
  saveBrief({ text: 'Same pacing, for my shop', attach: true }, s, 0)
  assert.equal(peekBrief(s, 1), 'Same pacing, for my shop')
  assert.deepEqual(takeBrief(s, 2), { text: 'Same pacing, for my shop', attach: true, from: null })
})

test('a brief expires after 7 days, and broken or empty values are dropped', () => {
  const s = store(), day = 864e5
  saveBrief('Old brief', s, 0)
  assert.equal(peekBrief(s, 7 * day), 'Old brief')
  assert.equal(peekBrief(s, 7 * day + 1), null)
  assert.equal(s.values.size, 0, 'an expired brief is removed')
  s.setItem('wyv_pending_brief', '{not json')
  assert.equal(peekBrief(s, 0), null)
  assert.equal(s.values.size, 0)
  assert.equal(saveBrief('   ', s, 0), false)
  saveBrief('Keep me', s, 0); clearBrief(s)
  assert.equal(peekBrief(s, 0), null)
})

test('no storage (private window, blocked site data): nothing is saved and nothing throws', () => {
  const broken = { getItem() { throw Error('blocked') }, setItem() { throw Error('blocked') }, removeItem() { throw Error('blocked') } }
  assert.equal(saveBrief('A couch ad', broken), false)
  assert.equal(peekBrief(broken), null)
  assert.equal(takeBrief(broken), null)
})
