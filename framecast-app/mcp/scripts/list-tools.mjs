// Lists the tools exactly as a client's tools/list sees them (OpenAI's "Scan Tools"): starts server.js against a
// stand-in API that accepts any key, and prints [{name, annotations}] as JSON. Nothing real is contacted.
// Run where the MCP node_modules are installed: node scripts/list-tools.mjs <path to server.js> [/mcp | /mcp/app]
import http from 'node:http'
import { spawn } from 'node:child_process'
const serverPath = process.argv[2]
const endpoint = process.argv[3] || '/mcp'
if (!serverPath) throw new Error('Usage: node scripts/list-tools.mjs <server.js>')
const backend = http.createServer((req, res) => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ data: { plan: 'creator' } })) })
await new Promise(r => backend.listen(0, '127.0.0.1', r))
const probe = http.createServer(); await new Promise(r => probe.listen(0, '127.0.0.1', r))
const port = probe.address().port; await new Promise(r => probe.close(r))
const child = spawn(process.execPath, [serverPath], { env: { ...process.env, PORT: String(port), WYV_API_BASE_URL: `http://127.0.0.1:${backend.address().port}`, MCP_ALLOWED_HOSTS: '127.0.0.1,localhost', OAUTH_ISSUER: '', MCP_PUBLIC_URL: '' } })
let errors = ''; child.stderr.on('data', c => { errors += c })
try {
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(`http://127.0.0.1:${port}/healthz`)).ok) break } catch {}
    if (i === 99) throw new Error(`MCP failed to start: ${errors}`)
    await new Promise(r => setTimeout(r, 50))
  }
  let id = 0
  const rpc = async (method, params) => {
    const r = await fetch(`http://127.0.0.1:${port}${endpoint}`, { method: 'POST', headers: { Authorization: 'Bearer wyv_live_scan', 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
      body: JSON.stringify({ jsonrpc: '2.0', id: ++id, method, params }) })
    const raw = await r.text()
    const json = JSON.parse(raw.startsWith('data:') || raw.includes('\ndata:') ? raw.split('\n').find(s => s.startsWith('data:')).slice(5) : raw)
    if (json.error) throw new Error(method + ': ' + JSON.stringify(json.error))
    return json.result
  }
  const init = await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'scan', version: '1' } })
  if (process.env.SHOW_INSTRUCTIONS) process.stderr.write(init.instructions + '\n')
  const tools = []; let cursor
  do { const page = await rpc('tools/list', cursor ? { cursor } : {}); tools.push(...page.tools); cursor = page.nextCursor } while (cursor)
  process.stdout.write(JSON.stringify(tools.map(t => ({ name: t.name, annotations: t.annotations ?? null })), null, 1))
} finally { child.kill(); backend.close() }
