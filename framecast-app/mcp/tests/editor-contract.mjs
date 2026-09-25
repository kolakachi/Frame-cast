// Real HTTP MCP -> mock API recording. Copies server.js beside a caller-provided
// node_modules directory; never contacts the app or a generation provider.
import http from 'node:http'
import { spawn } from 'node:child_process'
import { readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import assert from 'node:assert/strict'
const dir = process.env.MCP_TEST_DIRECTORY
if (!dir) throw new Error('Set MCP_TEST_DIRECTORY to a disposable directory containing installed MCP node_modules.')
await writeFile(path.join(dir, 'package.json'), '{"type":"module"}')
await writeFile(path.join(dir, 'server.js'), await readFile(new URL('../server.js', import.meta.url)))
const captured = []
const backend = http.createServer(async (req, res) => {
  let raw = ''; for await (const chunk of req) raw += chunk
  captured.push({ path: req.url, method: req.method, body: raw ? JSON.parse(raw) : null })
  res.setHeader('Content-Type', 'application/json')
  res.end(JSON.stringify({ data: { plan: 'creator', proposal_id: 'proposal-test', applied: true } }))
})
await new Promise(r => backend.listen(0, '127.0.0.1', r))
const probe = http.createServer(); await new Promise(r => probe.listen(0, '127.0.0.1', r))
const port = probe.address().port; await new Promise(r => probe.close(r))
const child = spawn(process.execPath, [path.join(dir, 'server.js')], { env: { ...process.env, PORT: String(port), WYV_API_BASE_URL: `http://127.0.0.1:${backend.address().port}`, WYV_API_HOST_HEADER: '', OAUTH_ISSUER: '', MCP_PUBLIC_URL: '', MCP_ALLOWED_HOSTS: '127.0.0.1,localhost' }, stdio: ['ignore', 'pipe', 'pipe'] })
let errors = ''; child.stderr.on('data', c => { errors += c }); child.stdout.on('data', () => {})
try {
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(`http://127.0.0.1:${port}/healthz`)).ok) break } catch {}
    if (i === 99) throw new Error(`MCP failed to start: ${errors}`)
    await new Promise(r => setTimeout(r, 50))
  }
  let id = 0
  async function rpc(method, params) {
    const response = await fetch(`http://127.0.0.1:${port}/mcp`, { method: 'POST', headers: { Authorization: 'Bearer wyv_live_disposable_contract', 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-11-25' }, body: JSON.stringify({ jsonrpc: '2.0', id: ++id, method, params }) })
    const raw = await response.text()
    assert.equal(response.status, 200, raw)
    const json = JSON.parse(raw.startsWith('data:') || raw.includes('\ndata:') ? raw.split('\n').find(s => s.startsWith('data:')).slice(5) : raw)
    assert.equal(json.error, undefined, raw)
    assert.notEqual(json.result?.isError, true, raw)
    return json.result
  }
  await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'phase-b-test', version: '1' } })
  const listed = await rpc('tools/list', {})
  assert(listed.tools.some(t => t.name === 'propose_edits'))
  const clear = { video_id: 1, revision: 'replace-with-current-revision', changes: [{ op: 'update_project', music_asset_id: null, channel_id: null, brand_kit_id: null }] }
  const image = { video_id: 1, revision: 'replace-with-current-revision', changes: [{ op: 'generate_image', scene_id: 1, model_key: 'gpt-image-2', style: 'anime', prompt_override: 'A ceramic mug on a clean desk.' }] }
  for (const args of [clear, image]) await rpc('tools/call', { name: 'propose_edits', arguments: args })
  await rpc('tools/call', { name: 'apply_edits', arguments: { video_id: 1, proposal_id: 'proposal-test' } })
  for (const name of ['upload_asset', 'get_asset', 'clone_voice', 'preview_voice', 'save_voice']) assert(listed.tools.some(t => t.name === name))
  const mediaCalls = [
    ['upload_asset', { title: 'Narration', asset_type: 'audio', content_base64: Buffer.alloc(120000).toString('base64') }, '/assets'],
    ['get_asset', { asset_id: 7 }, '/assets/7'],
    ['clone_voice', { name: 'Narrator', source_asset_id: 7, consent: true }, '/voices/clone'],
    ['preview_voice', { voice_id: 'clone-7' }, '/voices/preview'],
    ['save_voice', { name: 'Narrator', voice_id: 'clone-7' }, '/voices'],
    ['update_character', { character_id: 3, reference_asset_ids: [9], consent: true }, '/characters/3'],
    ['list_library', { type: 'sound' }, '/library?type=sound'],
  ]
  for (const [name, args, route] of mediaCalls) {
    await rpc('tools/call', { name, arguments: args })
    assert.equal(captured.at(-1).path, `/api/developer/v1${route}`)
    if (name === 'clone_voice' || name === 'update_character') assert.equal(captured.at(-1).body.consent, true)
  }
  await rpc('tools/call', { name: 'propose_edits', arguments: { video_id: 1, revision: 'v1', changes: [{ op: 'use_narration', scene_id: 1, asset_id: 7, mode: 'audio_and_script' }] } })
  assert.equal(captured.at(-1).body.changes[0].mode, 'audio_and_script')
  const reference = { shape: 'Hook and proof', beats: [{ role: 'hook', does: 'Ask a question' }] }
  await rpc('tools/call', { name: 'analyze_ugc_reference', arguments: { asset_id: 7 } })
  assert.equal(captured.at(-1).path, '/api/developer/v1/ugc/reference')
  await rpc('tools/call', { name: 'plan_ugc', arguments: { script: 'A product demo', format: 'auto', duration_seconds: 10, reference } })
  assert.deepEqual(captured.at(-1).body.reference, reference)
  await rpc('tools/call', { name: 'estimate_presenter_preview', arguments: { character_id: 3, consent: true } })
  assert.equal(captured.at(-1).body.consent, true)
  await rpc('tools/call', { name: 'create_presenter_preview', arguments: { character_id: 3, quote_id: 'preview-quote' } })
  assert.equal(captured.at(-1).body.idempotency_key, 'preview-quote')
  assert(listed.tools.some(t => t.name === 'prepare_delivery'))
  await rpc('tools/call', { name: 'prepare_delivery', arguments: { video_id: 1, action: 'schedule', revision: 'v1', export_id: 8, allow_stale: true } })
  assert.equal(captured.at(-1).path, '/api/developer/v1/videos/1/delivery/handoff')
  assert.deepEqual(captured.at(-1).body, { action: 'schedule', revision: 'v1', export_id: 8, allow_stale: true })
  const records = captured.filter(c => c.path.includes('/proposals'))
  assert.deepEqual(records[0].body.changes, clear.changes)
  assert.deepEqual(records[1].body.changes, image.changes)
  await writeFile(path.join(dir, 'editor-payloads.json'), JSON.stringify(records))
  console.log('PASS real MCP HTTP discovery, editor/media/voice/consent forwarding and >100KB upload body. Recorded editor-payloads.json for PHP persistence tests.')
} finally {
  child.kill(); await new Promise(r => child.once('exit', r)); await new Promise(r => backend.close(r))
}
