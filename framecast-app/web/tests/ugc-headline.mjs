// Real FFmpeg export vs actual Vue component, without generation providers or a database.
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { mkdtemp, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright')
const { PNG } = require(process.env.PNGJS_MODULE || 'pngjs')
const base = process.env.UGC_TEST_URL || 'http://127.0.0.1:5179'
const dir = await mkdtemp(join(tmpdir(), 'ugc-headline-'))
const fixturePath = fileURLToPath(new URL('../../api/scripts/ugc-render-smoke.php', import.meta.url))
const fixture = JSON.parse(execFileSync('php', [fixturePath, dir], { cwd: resolve(fileURLToPath(new URL('../../api', import.meta.url))), encoding: 'utf8' }))
execFileSync('ffmpeg', ['-v', 'error', '-i', fixture.video, '-frames:v', '1', join(dir, 'export.png')])
const probe = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_streams', '-of', 'json', fixture.video], { encoding: 'utf8' }))
assert.ok(probe.streams.some(s => s.codec_type === 'video' && s.width === 1080 && s.height === 1920))
assert.ok(probe.streams.some(s => s.codec_type === 'audio'), 'Silent reactions must still export a valid audio stream.')
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) })
try {
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } })
  await page.route('**/*', route => new URL(route.request().url()).origin === new URL(base).origin ? route.continue() : route.abort())
  await page.goto(base)
  await page.evaluate(async layout => {
    const { createApp, h } = await import('/node_modules/.vite/deps/vue.js')
    const { default: Headline } = await import('/src/components/UgcHeadline.vue')
    document.body.innerHTML = '<div id="ugc-headline-test" style="position:fixed;inset:0;width:1080px;height:1920px;background:black"></div>'
    document.body.style.margin = '0'
    createApp({ render: () => h(Headline, { layout, aspectRatio: '9:16' }) }).mount('#ugc-headline-test')
    await document.fonts.ready
  }, fixture.layout)
  await page.locator('#ugc-headline-test svg text').first().waitFor()
  await page.locator('#ugc-headline-test').screenshot({ path: join(dir, 'preview.png') })
} finally { await browser.close() }

function bounds(bytes) {
  const { width, height, data } = PNG.sync.read(bytes)
  let left = width, right = 0, top = height, bottom = 0, count = 0
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const i = (y * width + x) * 4
    if (data[i] > 190 && data[i + 1] > 190 && data[i + 2] > 190) {
      left = Math.min(left, x); right = Math.max(right, x)
      top = Math.min(top, y); bottom = Math.max(bottom, y); count++
    }
  }
  assert.ok(count > 1000, 'Headline must be visibly rendered, even with speech captions disabled.')
  return { left, right, top, bottom }
}
const preview = bounds(await readFile(join(dir, 'preview.png')))
const exported = bounds(await readFile(join(dir, 'export.png')))
console.log({ dir, preview, exported })
for (const edge of ['left', 'right', 'top', 'bottom']) {
  assert.ok(Math.abs(preview[edge] - exported[edge]) <= 4, `Headline ${edge} differs: preview ${preview[edge]}, export ${exported[edge]}`)
}
console.log('UGC headline export/preview geometry passed (≤4px tolerance at 1080×1920).')
