// Provider-free type menu and required-input UI regression.
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const types = JSON.parse(execFileSync('php', ['-r', 'require "vendor/autoload.php"; echo json_encode(App\\Services\\Ugc\\UgcTypes::menu());'], { cwd: fileURLToPath(new URL('../../api', import.meta.url)), encoding: 'utf8' }))
const base = process.env.UGC_TEST_URL || 'http://localhost:5173'
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) })
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const user = { id: 1, workspace_id: 1, name: 'UGC Test', role: 'owner', is_internal: true, preferences: { onboarded: true } }
  await context.addInitScript(user => localStorage.setItem('framecast.auth', JSON.stringify({ accessToken: 'mock-only', user })), user)
  await context.route('**/*', async route => {
    const url = new URL(route.request().url())
    if (!url.pathname.includes('/api/v1/')) return url.origin === new URL(base).origin ? route.continue() : route.abort()
    const path = url.pathname.split('/api/v1')[1]
    let data = {}
    if (path === '/me') data = { user, credits: { balance: 10000 } }
    if (path === '/workspaces/1') data = { workspace: { id: 1, name: 'Test', capabilities: { ugc_ads: true, ugc_chat: true, custom_characters: true } } }
    if (path === '/ugc/settings') data = { settings: [], types }
    if (path === '/ugc/chats') data = { chats: [] }
    if (path === '/workspace-access') data = []
    if (path === '/characters') data = { characters: [] }
    if (path === '/assets') data = { asset: { id: 22 } }
    await route.fulfill({ json: { data, meta: {} } })
  })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  await page.goto(base + '/ugc-ads/new')
  await page.getByRole('button', { name: 'Essential only', exact: true }).click()
  await page.getByRole('button', { name: /Type: Auto/ }).click()
  await page.locator('.uc-type').last().waitFor()
  assert.equal(await page.locator('.uc-type').count(), 14)
  assert.equal(await page.locator('.uc-type:disabled').count(), 0)
  await page.locator('.uc-type').filter({ hasText: 'Green screen' }).click()
  await page.getByText('Upload a screenshot or video and choose Background.').waitFor()
  await page.locator('input[type=file]').first().setInputFiles({ name: 'page.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=', 'base64') })
  await page.getByRole('combobox', { name: 'Use page.png as' }).selectOption('background')
  assert.equal(await page.getByRole('combobox', { name: 'Use page.png as' }).inputValue(), 'background')
  await page.getByRole('button', { name: /Type: Green screen/ }).click()
  await page.locator('.uc-type').filter({ hasText: 'Skit' }).click()
  await page.getByText('Choose two presenters in speaking order:').waitFor()
  await page.screenshot({ path: '/tmp/ugc-types-ui.png', fullPage: true })
  assert.deepEqual(errors, [])
  console.log('PASS: all 14 types, background role, skit requirements; no runtime errors')
} finally {
  await browser.close()
}
