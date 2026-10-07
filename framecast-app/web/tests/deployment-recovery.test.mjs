import test from 'node:test'
import assert from 'node:assert/strict'
import { createDeploymentRecovery, isChunkLoadError, registerReloadGuard, reloadBlockReason } from '../src/lib/deploymentRecovery.js'

test('recognizes browser module-load failures without swallowing application errors', () => {
  for (const message of ['Failed to fetch dynamically imported module: /assets/CreateView-old.js', 'error loading dynamically imported module', 'Importing a module script failed.', 'Loading chunk 17 failed.', 'Unable to preload CSS for /assets/view.css']) assert.equal(isChunkLoadError(new Error(message)), true)
  for (const message of ['Network Error', 'Failed to fetch', 'Invalid end tag', 'Not enough credits']) assert.equal(isChunkLoadError(new Error(message)), false)
})

test('duplicate preload/router errors only notify; refresh is explicit and single use', () => {
  const notices = []; let reloads = 0
  const recovery = createDeploymentRecovery({ notify: reason => notices.push(reason), reload: () => reloads++ })
  assert.equal(recovery.refresh(), false)
  assert.equal(recovery.report(new Error('bad state')), false)
  recovery.report(new Error('offline'), true)
  recovery.report(new Error('Failed to fetch dynamically imported module'))
  assert.deepEqual(notices, [''])
  assert.equal(reloads, 0)
  assert.equal(recovery.refresh(), true)
  assert.equal(recovery.refresh(), false)
  assert.equal(reloads, 1)
})

test('offline and unsaved work prevent refresh; retry after resolving blockers is allowed', () => {
  let online = false; let reason = 'Wait for the upload.'; let reloads = 0; let notice
  const recovery = createDeploymentRecovery({ online: () => online, beforeReload: () => reason, notify: value => { notice = value }, reload: () => reloads++ })
  recovery.report(null, true)
  assert.equal(recovery.refresh(), false)
  assert.match(notice, /offline/)
  online = true
  assert.equal(recovery.refresh(), false)
  assert.equal(notice, reason)
  reason = ''
  assert.equal(recovery.refresh(), true)
  assert.equal(reloads, 1)
})

test('draft guards fail closed and are removed when their view unmounts', () => {
  const dispose = registerReloadGuard(() => 'Copy your draft.')
  assert.equal(reloadBlockReason(), 'Copy your draft.')
  dispose()
  const broken = registerReloadGuard(() => { throw Error('Storage denied') })
  assert.match(reloadBlockReason(), /could not be checked/)
  broken()
  assert.equal(reloadBlockReason(), '')
})
