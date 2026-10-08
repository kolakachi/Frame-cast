import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readBriefHash, saveBrief, peekBrief, takeBrief, clearBrief, BRIEF_MAX } from '../src/services/pendingBrief.js'

function store() {
  const values = new Map()
  return { values, getItem: k => values.get(k) ?? null, setItem: (k, v) => values.set(k, String(v)), removeItem: k => values.delete(k) }
}

test('a brief after #brief= is read, decoded, trimmed and capped; anything else is not a brief', () => {
  assert.equal(readBriefHash('#brief=' + encodeURIComponent('  A 20-second ad for Hearthline.\r\nWarm and tactile.  ')), 'A 20-second ad for Hearthline.\nWarm and tactile.')
  assert.equal(readBriefHash('#brief=A+couch+ad'), 'A couch ad')
  assert.equal(readBriefHash('#brief=' + 'x'.repeat(BRIEF_MAX + 50)).length, BRIEF_MAX)
  for (const h of ['', '#pricing', '#brief=', '#brief=%E0%A4%A', null, undefined]) assert.equal(readBriefHash(h), null)
})

test('the saved brief is shown once, then gone', () => {
  const s = store()
  assert.equal(saveBrief('A couch ad', s, 1000), true)
  assert.equal(peekBrief(s, 2000), 'A couch ad', 'peeking keeps it (the register page shows it, Create uses it later)')
  assert.equal(takeBrief(s, 3000), 'A couch ad')
  assert.equal(peekBrief(s, 4000), null)
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
