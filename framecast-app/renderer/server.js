// WyvStudio renderer — internal-only headless-browser fetcher.
//
// GET /render?url=https://example.com
//   -> { url, title, description, text }  (text = rendered page innerText, capped)
// GET /healthz -> { ok: true }
//
// Exists so the one-shot prompt's URL grounding works on client-rendered
// (SPA) product pages, where a plain HTTP fetch only sees an empty JS shell.
// Called by OneShotPromptParser::fetchRendered over the compose network.
// NO public port — never expose this: it fetches arbitrary URLs on request.

const express = require('express')
const puppeteer = require('puppeteer-core')
const dns = require('dns').promises
const net = require('net')

const PORT = process.env.PORT || 3000
const NAV_TIMEOUT_MS = 15000
const MAX_TEXT = 8000
const MAX_CONCURRENT = 2

const app = express()

// ── SSRF guard ─────────────────────────────────────────────────────────────
// The PHP caller validates too, but this service must defend itself: it can
// be asked to fetch anything reachable from inside the compose network.
function isPrivateIp(ip) {
  if (net.isIPv6(ip)) {
    const low = ip.toLowerCase()
    return low === '::1' || low.startsWith('fc') || low.startsWith('fd') || low.startsWith('fe80')
  }
  const o = ip.split('.').map(Number)
  return (
    o[0] === 10 || o[0] === 127 || o[0] === 0 ||
    (o[0] === 172 && o[1] >= 16 && o[1] <= 31) ||
    (o[0] === 192 && o[1] === 168) ||
    (o[0] === 169 && o[1] === 254) // link-local / cloud metadata
  )
}

async function assertPublicHttpUrl(raw) {
  const u = new URL(raw)
  if (!['http:', 'https:'].includes(u.protocol)) throw new Error('bad_scheme')
  const { address } = await dns.lookup(u.hostname)
  if (isPrivateIp(address)) throw new Error('blocked_private')
  return u
}

// ── Shared browser + tiny concurrency gate ────────────────────────────────
let browserPromise = null
function getBrowser() {
  if (!browserPromise) {
    browserPromise = puppeteer.launch({
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
      args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--no-zygote'],
    }).catch((e) => { browserPromise = null; throw e })
  }
  return browserPromise
}

let active = 0
const waiters = []
async function acquire() {
  if (active < MAX_CONCURRENT) { active++; return }
  await new Promise((resolve) => waiters.push(resolve))
  active++
}
function release() {
  active--
  const next = waiters.shift()
  if (next) next()
}

// ── Routes ─────────────────────────────────────────────────────────────────
app.get('/healthz', (_req, res) => res.json({ ok: true }))

app.get('/render', async (req, res) => {
  const raw = String(req.query.url || '')
  let target
  try {
    target = await assertPublicHttpUrl(raw)
  } catch (e) {
    return res.status(400).json({ error: String(e.message || e) })
  }

  await acquire()
  let page
  try {
    const browser = await getBrowser()
    page = await browser.newPage()
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 WyvStudioRenderer/1.0')
    await page.setViewport({ width: 1280, height: 1024 })
    // networkidle2 lets SPA hydration finish; goto timeout fails soft below.
    await page.goto(target.href, { waitUntil: 'networkidle2', timeout: NAV_TIMEOUT_MS })

    // Redirect guard: if the final document landed somewhere private
    // (http->internal redirect), refuse to leak its content.
    const finalUrl = page.url()
    try { await assertPublicHttpUrl(finalUrl) } catch { return res.status(400).json({ error: 'blocked_redirect' }) }

    const data = await page.evaluate(() => ({
      title: document.title || '',
      description:
        document.querySelector('meta[name="description"]')?.content ||
        document.querySelector('meta[property="og:description"]')?.content || '',
      text: document.body ? document.body.innerText : '',
    }))
    data.text = (data.text || '').replace(/\s+/g, ' ').trim().slice(0, MAX_TEXT)
    res.json({ url: finalUrl, ...data })
  } catch (e) {
    res.status(502).json({ error: 'render_failed', message: String(e.message || e) })
  } finally {
    if (page) await page.close().catch(() => {})
    release()
  }
})

app.listen(PORT, () => console.log(`renderer listening on :${PORT}`))
