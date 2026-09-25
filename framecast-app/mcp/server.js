// WyvStudio MCP server.
//
// POST /mcp   Streamable HTTP, stateless: a fresh McpServer per request.
// GET  /healthz
//
// Five tools, each a one-to-one wrapper over /api/developer/v1. This process
// holds no credentials of its own. The client's bearer token (a WyvStudio API
// key, wyv_live_…) is verified by asking the API and then forwarded on every
// call, so authorization, entitlement, quotas and spend limits all live in
// Laravel and are enforced once. Nothing here can do what the key cannot.
//
// Spending is quote-bound: estimate_video returns a quote_id, create_video
// requires it. The API refuses an expired, foreign or reused quote regardless
// of what the assistant claims the user approved.

import { createHash } from 'node:crypto'
import { createMcpExpressApp, requireBearerAuth } from '@modelcontextprotocol/express'
import { toNodeHandler } from '@modelcontextprotocol/node'
import { createMcpHandler, McpServer, OAuthError, OAuthErrorCode } from '@modelcontextprotocol/server'
import * as z from 'zod/v4'

const PORT = Number(process.env.PORT || 3000)
const API_BASE_URL = (process.env.WYV_API_BASE_URL || 'http://api:8000').replace(/\/$/, '')
const API_HOST_HEADER = process.env.WYV_API_HOST_HEADER || ''
const ALLOWED_HOSTS = (process.env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1').split(',').map(s => s.trim()).filter(Boolean)
const VERSION = process.env.MCP_VERSION || '1.0.0'
const TOKEN_CACHE_SECONDS = 60
const API_TIMEOUT_MS = 30_000

// ── Developer API client ───────────────────────────────────────────────────

async function api(token, method, path, body) {
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    'X-Wyv-Client': `mcp/${VERSION}`,
  }
  if (API_HOST_HEADER) headers.Host = API_HOST_HEADER
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const res = await fetch(`${API_BASE_URL}/api/developer/v1${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(API_TIMEOUT_MS),
  })
  let json = null
  try { json = await res.json() } catch { json = null }
  return { status: res.status, json }
}

// ── Bearer verification ────────────────────────────────────────────────────
//
// The API is the authority; this only asks it. Verified tokens are remembered
// for a minute (by hash, never plaintext) so tools/list and a burst of tool
// calls do not each spend a read against the key's rate limit.

const verified = new Map() // sha256(token) -> { plan, until }

const verifier = {
  async verifyAccessToken(token) {
    if (typeof token !== 'string' || !token.startsWith('wyv_live_')) {
      throw new OAuthError(OAuthErrorCode.InvalidToken, 'Expected a WyvStudio API key (wyv_live_…).')
    }
    const key = createHash('sha256').update(token).digest('hex')
    const cached = verified.get(key)
    const now = Date.now()
    if (cached && cached.until > now) {
      return authInfo(token, cached.plan)
    }

    const { status, json } = await api(token, 'GET', '/capabilities')
    if (status === 200 && json?.data) {
      verified.set(key, { plan: json.data.plan, until: now + TOKEN_CACHE_SECONDS * 1000 })
      return authInfo(token, json.data.plan)
    }
    verified.delete(key)
    const message = json?.error?.message || `The WyvStudio API answered ${status}.`
    if (status === 401) throw new OAuthError(OAuthErrorCode.InvalidToken, message)
    if (status === 403) throw new OAuthError(OAuthErrorCode.InvalidToken, message)
    throw new OAuthError(OAuthErrorCode.ServerError, message)
  },
}

function authInfo(token, plan) {
  // The bearer helper requires an expiry. Keys do not expire on their own,
  // so this is the verification's lifetime: after it the API is asked again.
  return {
    token,
    clientId: 'wyvstudio-api-key',
    scopes: ['videos'],
    expiresAt: Math.floor(Date.now() / 1000) + TOKEN_CACHE_SECONDS,
    extra: { plan },
  }
}

// ── Tool results ───────────────────────────────────────────────────────────

function ok(data, extraContent = []) {
  return {
    content: [{ type: 'text', text: JSON.stringify(data, null, 2) }, ...extraContent],
    structuredContent: data,
  }
}

function fail(status, json) {
  const error = json?.error || { code: `http_${status}`, message: `The WyvStudio API answered ${status}.` }
  const lines = [`${error.code}: ${error.message}`]
  if (error.context) lines.push(JSON.stringify(error.context))
  if (status === 429) lines.push('Wait, then retry. Do not retry in a tight loop.')
  if (error.code === 'quote_expired' || error.code === 'quote_consumed') lines.push('Call estimate_video again for a fresh quote and show it to the user before creating.')
  if (error.code === 'insufficient_credits') lines.push('The user needs to add credits in the WyvStudio dashboard before this video can be made.')
  return { isError: true, content: [{ type: 'text', text: lines.join('\n') }], structuredContent: { error, status } }
}

async function call(token, method, path, body) {
  const { status, json } = await api(token, method, path, body)
  return status >= 200 && status < 300 ? ok(json.data) : fail(status, json)
}

// ── Server per request ─────────────────────────────────────────────────────

const SOURCE_TYPES = ['prompt', 'script']
const VISUAL_MODES = ['stock', 'ai_images', 'ai_video']
const ANIMATE_TIERS = ['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'veo_fast', 'seedance_25']
const ASPECT_RATIOS = ['9:16', '1:1', '16:9']

function buildServer(token) {
  const server = new McpServer({ name: 'wyvstudio', version: VERSION })

  server.registerTool(
    'get_capabilities',
    {
      title: 'WyvStudio capabilities',
      description: 'What this workspace can make and what it costs: plan, credit balance, limits, supported inputs, visual modes and per-scene prices. Call this first, and again if a create is refused for credits or limits.',
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async () => call(token, 'GET', '/capabilities'),
  )

  server.registerTool(
    'estimate_video',
    {
      title: 'Estimate a video (free)',
      description: 'Price a short video before making it. Free. Returns a quote_id with the credit range (min/max), scene count, current balance and whether the workspace can afford it. Show the quote to the user; create_video needs the quote_id and the quote expires in 10 minutes. Nothing is built or charged by estimating.',
      inputSchema: z.object({
        source_type: z.enum(SOURCE_TYPES).describe('"prompt": a brief that WyvStudio writes a script from. "script": narration text to use as-is.'),
        content: z.string().min(10).max(10000).describe('The prompt or the script text.'),
        visual_mode: z.enum(VISUAL_MODES).describe('"stock": licensed stock footage (cheapest). "ai_images": AI stills per scene. "ai_video": AI stills animated into motion clips (most expensive; needs animate_tier).'),
        duration_seconds: z.number().int().min(5).max(600).optional().describe('Target length. Default 60. Capped by the plan (see get_capabilities).'),
        animate_tier: z.enum(ANIMATE_TIERS).optional().describe('ai_video only: the animation model tier. Prices in get_capabilities.'),
        animation_pacing: z.enum(['short', 'long']).optional().describe('ai_video only: shorter or longer motion clips per scene.'),
        aspect_ratio: z.enum(ASPECT_RATIOS).optional().describe('Default 9:16 (vertical).'),
        tone: z.string().max(64).optional().describe('Narration tone, e.g. "friendly", "authoritative".'),
        title: z.string().max(255).optional().describe('A working title for the project in the dashboard.'),
        content_goal: z.string().max(255).optional().describe('What the video is for, e.g. "drive sign-ups for the free trial".'),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async (args) => call(token, 'POST', '/quotes', args),
  )

  server.registerTool(
    'create_video',
    {
      title: 'Create a video from a quote',
      description: 'Starts making the video that estimate_video quoted. SPENDS CREDITS, up to the quote\'s max. Only call after the user has seen the quote and agreed. Returns immediately with a video id and status "generating"; poll get_video_status every 15–30 seconds. Rendering takes a few minutes. The same quote_id cannot start a second video.',
      inputSchema: z.object({
        quote_id: z.string().describe('From estimate_video.'),
        idempotency_key: z.string().max(128).optional().describe('Optional. A retry with the same key returns the same video instead of making another. Defaults to the quote_id, which is already single-use.'),
      }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ quote_id, idempotency_key }) => call(token, 'POST', '/videos', { quote_id, idempotency_key: idempotency_key || quote_id }),
  )

  server.registerTool(
    'get_video_status',
    {
      title: 'Check a video',
      description: 'Progress of a video: status is generating, exporting, completed or failed, with the current stage, credits spent so far and a project_url the user can open in WyvStudio. When completed, call get_video_result for the file. When failed, read failure.message; retryable means the user can retry from the dashboard.',
      inputSchema: z.object({ video_id: z.number().int().describe('From create_video.') }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'GET', `/videos/${video_id}`),
  )

  server.registerTool(
    'get_video_result',
    {
      title: 'Get the finished video',
      description: 'The finished MP4 as a private, time-limited download link (download_expires_at), plus duration, aspect ratio, credits spent and the project_url. Returns not_ready until get_video_status says completed. The link is for the workspace only; nothing is made public.',
      inputSchema: z.object({ video_id: z.number().int().describe('From create_video.') }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => {
      const { status, json } = await api(token, 'GET', `/videos/${video_id}/result`)
      if (status !== 200) return fail(status, json)
      const v = json.data.video
      return ok(json.data, [{
        type: 'resource_link',
        uri: v.download_url,
        name: v.file_name || 'video.mp4',
        mimeType: 'video/mp4',
        description: `Finished video (${v.aspect_ratio}, ${v.duration_seconds ?? '?'}s). Link expires ${v.download_expires_at}.`,
      }])
    },
  )

  return server
}

const handler = createMcpHandler((ctx) => {
  const token = ctx.authInfo?.token
  if (!token) throw new OAuthError(OAuthErrorCode.InvalidToken, 'Missing bearer token.')
  return buildServer(token)
})

// ── HTTP ───────────────────────────────────────────────────────────────────

// createMcpExpressApp already parses JSON bodies and validates Host/Origin.
const app = createMcpExpressApp({ host: '0.0.0.0', allowedHosts: ALLOWED_HOSTS })
const node = toNodeHandler(handler)
const auth = requireBearerAuth({ verifier })

app.get('/healthz', (_req, res) => res.json({ ok: true, api: API_BASE_URL }))
app.all('/mcp', auth, (req, res) => void node(req, res, req.body))

app.listen(PORT, '0.0.0.0', () => {
  console.log(`wyvstudio mcp ${VERSION} listening on :${PORT}, api ${API_BASE_URL}, hosts ${ALLOWED_HOSTS.join(',')}`)
})
