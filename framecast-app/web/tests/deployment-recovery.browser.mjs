// Offline smoke against a built dist directory. All API/provider traffic is
// intercepted; this must never use a real login or real creation.
// NODE_PATH=/path/to/playwright/node_modules node tests/deployment-recovery.browser.mjs /path/to/dist
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { resolve, extname, sep } from 'node:path'
const { chromium } = createRequire(import.meta.url)('playwright')
const root = resolve(process.argv[2] || 'dist')
const server = createServer(async (req, res) => {
  const pathname = new URL(req.url, 'http://localhost').pathname
  const file = pathname.startsWith('/assets/') ? resolve(root, '.' + pathname) : resolve(root, 'index.html')
  if (!file.startsWith(root + sep)) { res.writeHead(400).end(); return }
  try {
    const body = await readFile(file)
    res.writeHead(200, { 'Content-Type': ({ '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html' })[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' }).end(body)
  } catch { res.writeHead(404).end() }
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
let browser
try {
  browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) })
  const page = await browser.newPage()
  page.on('pageerror', error => console.error('Browser error:', error.message))
  const origin = `http://127.0.0.1:${server.address().port}`
  const user = { id: 11, workspace_id: 22, role: 'owner', name: 'Offline tester', email: 'test@example.invalid', preferences: { onboarded: true } }
  const writes = []
  await page.addInitScript(user => localStorage.setItem('framecast.auth', JSON.stringify({ accessToken: 'offline-fixture', user })), user)
  let missingChunk = true
  await page.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url())
    if (url.origin !== origin) return route.abort()
    if (missingChunk && /\/assets\/CreateView-.*\.js$/.test(url.pathname)) return route.fulfill({ status: 404, body: '' })
    if (!url.pathname.startsWith('/api/')) return route.continue()
    if (req.method() !== 'GET') writes.push(`${req.method()} ${url.pathname}`)
    let data = []
    if (url.pathname.endsWith('/me')) data = { user }
    if (url.pathname.endsWith('/create/capabilities')) data = { mode: 'agent', paid_generation: false }
    if (url.pathname.endsWith('/create/conversations/offline-test')) data = { conversation: { id: 'offline-test', version: 0, title: 'Offline fixture', status: 'draft', settings_json: '{}' }, plans: [], messages: [], revisions: [], runs: [], attachments: [] }
    await route.fulfill({ json: { data } })
  })
  await page.goto(`${origin}/create/offline-test`)
  const notice = page.getByRole('alert', { name: 'Page recovery' })
  await notice.waitFor()
  assert.equal(new URL(page.url()).pathname, '/create/offline-test')
  missingChunk = false
  await notice.getByRole('button', { name: 'Reload page' }).click()
  const composer = page.getByPlaceholder('Describe what you want to create or change…')
  await composer.waitFor().catch(async error => { console.error('Page:', await page.locator('body').innerText()); throw error })
  await composer.fill('Keep this unsent draft, including its timing.')
  await page.evaluate(() => window.dispatchEvent(new Event('vite:preloadError')))
  await notice.waitFor()
  await notice.getByRole('button', { name: 'Reload page' }).click()
  await composer.waitFor()
  assert.equal(await composer.inputValue(), 'Keep this unsent draft, including its timing.')
  assert.equal(await notice.count(), 0)
  await page.locator('input[type="file"][multiple]').setInputFiles({ name: 'unsent.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>') })
  await page.evaluate(() => window.dispatchEvent(new Event('vite:preloadError')))
  await notice.getByRole('button', { name: 'Reload page' }).click()
  await page.getByText('Upload or remove the selected files before reloading. Local file selections cannot be restored.').waitFor()
  await page.getByRole('button', { name: 'Remove unsent.svg', exact: true }).click()
  // A storage failure must leave the current text/page intact.
  await page.evaluate(() => {
    Storage.prototype.setItem = () => { throw Error('Storage unavailable') }
    window.dispatchEvent(new Event('vite:preloadError'))
  })
  await notice.getByRole('button', { name: 'Reload page' }).click()
  await page.getByText('This browser could not save your message draft. Copy it before refreshing manually.').waitFor()
  assert.equal(await composer.inputValue(), 'Keep this unsent draft, including its timing.')
  assert.deepEqual(writes, [])
  console.log('PASS: missing route chunk, explicit recovery, draft restore, selected-file guard, storage refusal; zero API writes.')
} finally {
  await browser?.close()
  await new Promise(resolve => server.close(resolve))
}
