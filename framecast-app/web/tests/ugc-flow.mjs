// Provider-free browser regression. Run against Vite with PLAYWRIGHT_MODULE pointing to
// an installed playwright package (or install it in your test environment).
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdtemp } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const base = process.env.UGC_TEST_URL || 'http://127.0.0.1:5179'
const output = await mkdtemp(join(tmpdir(), 'ugc-browser-'))
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) })
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
const user = { id: 1, workspace_id: 1, name: 'UGC Test', is_internal: true, role: 'owner', preferences: { onboarded: true } }
await context.addInitScript(user => localStorage.setItem('framecast.auth', JSON.stringify({ accessToken: 'mock-only', user })), user)
let generated = null
let takes = []
let planningRequest = null
let holdPlan = false
let releasePlan
const fixture = format => ({
  format, reasoning: 'One intentional performance.', credits_per_character: 150, warnings: [],
  script: format === 'reaction' ? '' : 'Here is one useful idea.',
  segments: [{ kind: format === 'reaction' ? 'reaction' : 'on_camera',
    script_text: format === 'reaction' ? '' : 'Here is one useful idea.', seconds: 5,
    visual_brief: 'Casual kitchen selfie, window light.', motion_prompt: format === 'reaction' ? 'Look concerned, then smile.' : '',
    voice_direction: 'Warm and curious.', headline: 'POV: you almost gave up', source: null, asset_id: null }],
})
await context.route('**/*', async route => {
  const url = new URL(route.request().url())
  if (!url.pathname.includes('/api/v1/')) {
    if (url.origin === new URL(base).origin) return route.continue()
    return route.abort() // Never contact analytics, real APIs or generation providers.
  }
  const path = url.pathname.split('/api/v1')[1]
  let data = {}
  if (path === '/me') data = { user, credits: { balance: 10000 } }
  if (path === '/characters') data = { characters: [{ id: 1, name: 'Test creator', is_stock: true, situations: ['kitchen'] }] }
  if (path === '/voice-profiles') data = { voice_profiles: [{ id: 3, provider: 'google', provider_voice_key: 'Kore', name: 'Kore', is_cloned: false }] }
  if (path === '/voice-profiles/preview') data = { preview_url: 'data:audio/wav;base64,UklGRg==' }
  if (path === '/ugc/takes') data = { takes }
  if (path === '/ugc/plan') {
    planningRequest = route.request().postDataJSON()
    data = fixture(planningRequest.format === 'reaction' ? 'reaction' : 'direct_camera')
    if (holdPlan) await new Promise(resolve => { releasePlan = resolve })
  }
  if (path === '/ugc/quote') {
    const body = route.request().postDataJSON()
    data = { ...body, credits_per_character: 150, warnings: [], script: body.segments.map(s => s.script_text).filter(Boolean).join(' ') }
  }
  if (path === '/ugc/generate') {
    generated = route.request().postDataJSON()
    takes = [{ id: 101, character: 'Test creator', scenes: 1, credits: 150, status: 'generating' }]
    data = { takes }
  }
  return route.fulfill({ status: path === '/ugc/generate' ? 201 : 200, contentType: 'application/json', body: JSON.stringify({ data, meta: {} }) })
})
const page = await context.newPage()
const errors = []
page.on('pageerror', error => errors.push(error.message))
try {
  await page.goto(`${base}/ugc-ads`)
  await page.getByLabel('Format', { exact: true }).selectOption('reaction')
  await page.getByLabel('Audience, idea and desired reaction').fill('Concern then relief about launching an app')
  await page.getByLabel('Product / app').fill('My app')
  await page.getByRole('button', { name: 'Plan the shots' }).click()
  await page.getByText('One intentional performance.').waitFor()
  assert.equal(planningRequest.product, 'My app')
  assert.equal(planningRequest.context, 'Concern then relief about launching an app')
  await page.getByRole('button', { name: 'Add characters' }).click()
  await page.getByRole('button', { name: /Test creator/ }).click()
  await page.getByRole('button', { name: 'Done', exact: true }).click()
  const generate = page.getByRole('button', { name: 'Generate takes' })
  assert.equal(await generate.isEnabled(), false)
  await page.getByLabel(/I reviewed the shots/).check()
  await page.getByLabel(/I have permission/).check()
  assert.equal(await generate.isEnabled(), true)
  await page.getByLabel('Headline (not spoken, stays for this shot)').fill('POV: the launch finally clicks')
  assert.equal(await generate.isEnabled(), false)
  assert.equal(await page.getByLabel(/I reviewed the shots/).isChecked(), false)
  await page.getByRole('button', { name: 'Validate & update estimate' }).click()
  await page.getByLabel(/I reviewed the shots/).check()
  await page.screenshot({ path: join(output, 'ugc-reviewed.png'), fullPage: true })
  await generate.click()
  await page.getByRole('button', { name: 'Open in editor →' }).waitFor()
  assert.equal(generated.format, 'reaction')
  assert.equal(generated.script, '')
  assert.equal(generated.segments[0].motion_prompt, 'Look concerned, then smile.')
  assert.equal(generated.segments[0].headline, 'POV: the launch finally clicks')
  assert.equal(generated.reviewed, true)
  await page.reload()
  await page.getByRole('button', { name: 'Open in editor →' }).waitFor()

  // Editing the brief while an old plan is in flight must discard that response.
  holdPlan = true
  await page.getByLabel('Audience, idea and desired reaction').fill('Old brief')
  await page.getByRole('button', { name: 'Plan the shots' }).click()
  await page.waitForFunction(() => document.querySelector('.ugc-script') !== null)
  for (let i = 0; !releasePlan && i < 100; i++) await new Promise(r => setTimeout(r, 20))
  assert.ok(releasePlan)
  await page.getByLabel('Audience, idea and desired reaction').fill('New brief')
  releasePlan()
  await page.getByRole('button', { name: 'Plan the shots' }).waitFor()
  assert.equal(await page.getByText('One intentional performance.').count(), 0)
  holdPlan = false
  await page.getByRole('button', { name: 'Plan the shots' }).click()
  await page.getByText('One intentional performance.').waitFor()
  await page.getByLabel('Voice', { exact: true }).selectOption('Kore')
  await page.getByRole('button', { name: 'Hear voice sample' }).click()
  await page.locator('audio[controls]').waitFor()
  assert.match(await page.locator('audio[controls]').getAttribute('src'), /^data:audio/)
  assert.equal(errors.length, 0, errors.join('\n'))
  console.log(`UGC browser flow passed. Screenshot: ${join(output, 'ugc-reviewed.png')}`)
} catch (error) {
  await page.screenshot({ path: join(output, 'failed.png'), fullPage: true })
  console.error({ url: page.url(), errors, text: (await page.locator('body').innerText()).slice(0, 1800), output })
  throw error
} finally {
  await browser.close()
}
