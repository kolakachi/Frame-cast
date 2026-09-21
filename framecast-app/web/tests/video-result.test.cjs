const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')

function resultPage(api, router = { push: async () => {} }, projectId = '216') {
  let intervals = 0, clears = 0
  const context = vm.createContext({
    ref: value => ({ value }), computed: callback => ({ get value() { return callback() } }),
    onMounted: () => {}, onBeforeUnmount: () => {},
    useRoute: () => ({ params: { projectId }, query: {} }), useRouter: () => router,
    getEcho: () => null, api,
    window: { setInterval: () => ++intervals, clearInterval: () => clears++ },
  })
  const source = fs.readFileSync(path.join(__dirname, '../src/views/GenerationProgressView.vue'), 'utf8')
    .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
  vm.runInContext(source + '\nglobalThis.result = { finishVideo, startPolling, loadProjectStatus, downloadUrl, openEditor, actionMessage, applyPipelineState, pipelineFailure };', context)
  return { ...context.result, intervals: () => intervals, clears: () => clears }
}

test('a completed result stops polling and keeps its signed playback URL stable', async () => {
  let requests = 0
  const page = resultPage({ get: async () => ({ data: { data: { export_jobs: [{
    id: 1, status: 'completed', output_asset: { storage_url: `https://media.test/video?signature=${++requests}` },
  }] } } }) })
  page.startPolling()
  await page.finishVideo()
  assert.equal(page.clears(), 1)
  const original = page.downloadUrl.value
  await page.finishVideo()
  await page.loadProjectStatus()
  page.startPolling()
  assert.equal(requests, 1)
  assert.equal(page.intervals(), 1)
  assert.equal(page.downloadUrl.value, original)
})

test('Edit video persists the choice before navigating, and stays put if persistence fails', async () => {
  const calls = []
  let fail = false
  const page = resultPage({ post: async url => { calls.push(url); if (fail) throw Error('offline') } },
    { push: async route => calls.push(route.name) })
  await page.openEditor()
  assert.deepEqual(calls, ['/projects/216/editor-opened', 'project-editor'])
  fail = true
  await page.openEditor()
  assert.equal(calls.filter(value => value === 'project-editor').length, 1)
  assert.match(page.actionMessage.value, /try again/)
})

 test('project 215 returns to the editor without requesting an automatic export', async () => {
  let exports = 0, destination
  const page = resultPage({ get: async () => { exports++ } }, { replace: route => { destination = route.name } }, '215')
  page.applyPipelineState({ id: 215, status: 'ready_for_review', source_type: 'script' })
  await page.finishVideo()
  assert.equal(destination, 'project-editor')
  assert.equal(exports, 0)
})


test('paused generation restores its explanation without requesting an export', () => {
  let requests = 0
  const page = resultPage({ get: async () => { requests++ } })
  page.applyPipelineState({ id: 216, source_type: 'script', status: 'failed', generation_status_json: {
    last_message: 'We could not verify the script. Production is paused.',
    stages: { script: { status: 'failed' } },
  } })
  assert.match(page.pipelineFailure.value, /Production is paused/)
  assert.equal(requests, 0)
})
