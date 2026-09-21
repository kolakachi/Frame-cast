const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
function harness({ stale = false, pending = false, fail = false } = {}) {
  const calls = []
  let teardown
  const api = { get: async url => {
    if (url.endsWith('/exports')) return { data: { data: { export_jobs: [{ id: 2, status: fail ? 'failed' : 'completed' }] } } }
    return { data: { data: { is_stale: url.includes('/2/') ? false : stale } } }
  } }
  const context = { ref: value => ({ value }), onBeforeUnmount: fn => { teardown = fn }, api, setTimeout }
  vm.createContext(context)
  const source = fs.readFileSync(require.resolve('../src/composables/useExportActionGuard.js'), 'utf8').replace(/^import .*\n/gm, '').replace('export function', 'function')
  vm.runInContext(source, context)
  const guard = context.useExportActionGuard({ projectId: () => 216, getExport: () => ({ id: 1 }), hasPendingChanges: () => pending, perform: async (action, job) => calls.push([action, job.id]), update: async () => { pending = false; return { id: 2 } } })
  return { guard, calls, api, teardown }
}
test('unchanged export performs requested action', async () => {
  const { guard, calls } = harness()
  await guard.request('download')
  assert.deepEqual(calls, [['download', 1]])
  assert.equal(guard.warning.value, null)
})
for (const action of ['open', 'download', 'schedule', 'approval', 'share']) {
  test(`${action}: stale export needs explicit choice`, async () => {
    const { guard, calls } = harness({ stale: true })
    await guard.request(action)
    assert.equal(calls.length, 0)
    assert.ok(guard.warning.value)
    await guard.continuePrevious()
    assert.deepEqual(calls, [[action, 1]])
  })
}
test('unsaved local edits also warn; update resumes with the new export', async () => {
  const { guard, calls } = harness({ pending: true })
  await guard.request('schedule')
  assert.ok(guard.warning.value)
  await guard.updateFirst()
  assert.deepEqual(calls, [['schedule', 2]])
})
test('failed render does not perform delivery', async () => {
  const { guard, calls } = harness({ stale: true, fail: true })
  await guard.request('approval'); await guard.updateFirst()
  assert.equal(calls.length, 0)
  assert.equal(guard.warning.value.busy, false)
})
test('unavailable export cannot be explicitly continued', async () => {
  const { guard, calls, api } = harness()
  api.get = async () => { throw { response: { status: 422 } } }
  await guard.request('open'); await guard.continuePrevious()
  assert.equal(calls.length, 0)
  assert.equal(guard.warning.value.unavailable, true)
})
test('closing while freshness is loading suppresses action', async () => {
  const { guard, calls, api } = harness()
  let resolve
  api.get = () => new Promise(done => { resolve = done })
  const request = guard.request('share')
  guard.cancel(); resolve({ data: { data: { is_stale: false } } }); await request
  assert.equal(calls.length, 0)
})
test('edits made during rendering prevent automatic delivery', async () => {
  const { guard, calls, api } = harness({ stale: true })
  const get = api.get
  api.get = async url => url.includes('/2/freshness') ? { data: { data: { is_stale: true } } } : get(url)
  await guard.request('schedule'); await guard.updateFirst()
  assert.equal(calls.length, 0)
  assert.match(guard.warning.value.message, /More edits/)
})
