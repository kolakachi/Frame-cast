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
import { createMcpExpressApp, mcpAuthMetadataRouter, requireBearerAuth } from '@modelcontextprotocol/express'
import { toNodeHandler } from '@modelcontextprotocol/node'
import { createMcpHandler, getOAuthProtectedResourceMetadataUrl, McpServer, OAuthError, OAuthErrorCode } from '@modelcontextprotocol/server'
import * as z from 'zod/v4'

const PORT = Number(process.env.PORT || 3000)
const API_BASE_URL = (process.env.WYV_API_BASE_URL || 'http://api:8000').replace(/\/$/, '')
const API_HOST_HEADER = process.env.WYV_API_HOST_HEADER || ''
const ALLOWED_HOSTS = (process.env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1').split(',').map(s => s.trim()).filter(Boolean)
// Bump whenever the tool set changes: ChatGPT snapshots a plugin's tools per
// reported version and only re-reads them for a new one.
const VERSION = process.env.MCP_VERSION || '1.1.0'
// OAuth discovery. The issuer is the WyvStudio app origin (Laravel serves the
// authorization-server document there); this process serves the
// protected-resource document for the MCP URL. Both unset → bearer keys only.
const OAUTH_ISSUER = (process.env.OAUTH_ISSUER || '').replace(/\/$/, '')
const MCP_PUBLIC_URL = process.env.MCP_PUBLIC_URL || ''
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
    // A WyvStudio API key, or an OAuth access token issued by the app.
    if (typeof token !== 'string' || !(token.startsWith('wyv_live_') || token.startsWith('wyv_oat_'))) {
      throw new OAuthError(OAuthErrorCode.InvalidToken, 'Expected a WyvStudio API key (wyv_live_…) or OAuth access token.')
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
    clientId: token.startsWith('wyv_oat_') ? 'wyvstudio-oauth' : 'wyvstudio-api-key',
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

// One JSON line per tool call: what was asked, how the API answered, how
// long it took. The caller is identified by a hash prefix of its token,
// never the token, so a support question ("what did this connection do at
// 14:02?") can be answered from the log without the log being a secret.
function logCall(token, tool, status, ms, errorCode) {
  const caller = createHash('sha256').update(token).digest('hex').slice(0, 12)
  const kind = token.startsWith('wyv_oat_') ? 'oauth' : 'key'
  console.log(JSON.stringify({ ts: new Date().toISOString(), tool, status, ms, caller, kind, ...(errorCode ? { error: errorCode } : {}) }))
}

async function call(token, method, path, body, tool = path) {
  const started = Date.now()
  const { status, json } = await api(token, method, path, body)
  logCall(token, tool, status, Date.now() - started, status >= 300 ? json?.error?.code : undefined)
  return status >= 200 && status < 300 ? ok(json.data) : fail(status, json)
}

// ── Server per request ─────────────────────────────────────────────────────

const SOURCE_TYPES = ['prompt', 'script', 'url', 'product_description', 'images']
const VISUAL_MODES = ['stock', 'ai_images', 'ai_video', 'waveform']
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
    async () => call(token, 'GET', '/capabilities', undefined, 'get_capabilities'),
  )

  // Lookups: what a quote may point at. All read-only and workspace-scoped.
  const lookups = [
    ['get_options', 'Option keys for estimate_video: visual styles with labels, animate tiers, pacing, aspect ratios, platform targets, languages, tone examples, audiogram settings. Read once per conversation.', '/options'],
    ['list_brand_kits', "The workspace's brand kits (colours, fonts, default caption style and voice). Pass an id as brand_kit_id.", '/brand-kits'],
    ['list_channels', "The workspace's channels (defaults for language, platforms, voice, captions, brand kit). Pass an id as channel_id; its defaults apply.", '/channels'],
    ['list_niches', 'Content niches with default style, tone and music mood. Pass an id as niche_id; its defaults fill anything you leave out.', '/niches'],
    ['list_caption_presets', "The workspace's saved caption presets (font, colours, position, animation). Informational until caption editing ships.", '/caption-presets'],
    ['list_characters', "The workspace's reusable AI characters. Pass an id as character_id to feature one in the video.", '/characters'],
  ]
  for (const [name, description, path] of lookups) {
    server.registerTool(name, { title: name.replace(/_/g, ' '), description, annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false } }, async () => call(token, 'GET', path, undefined, name))
  }

  server.registerTool(
    'list_library',
    {
      title: 'List library assets',
      description: "The workspace's uploaded assets by type: images (for source_type images or as references), music tracks (for music_asset_id), videos (footage and demo clips). 50 per page; use q to search titles.",
      inputSchema: z.object({
        type: z.enum(['image', 'music', 'video']),
        page: z.number().int().min(1).optional(),
        q: z.string().max(120).optional().describe('Title search.'),
      }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ type, page, q }) => call(token, 'GET', `/library?${new URLSearchParams({ type, ...(page ? { page: String(page) } : {}), ...(q ? { q } : {}) })}`, undefined, 'list_library'),
  )

  server.registerTool(
    'list_voices',
    {
      title: 'List narration voices',
      description: 'The voices a video can be narrated in: WyvStudio\'s catalogue plus this workspace\'s own voices and clones, each with language, gender and credits per scene. Pass a voice id to estimate_video as voice_id. Omit it for the default voice.',
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async () => call(token, 'GET', '/voices', undefined, 'list_voices'),
  )

  server.registerTool(
    'estimate_video',
    {
      title: 'Estimate a video (free)',
      description: 'Price a short video before making it. Free. Returns a quote_id with the credit range (min/max), scene count, balance, whether the workspace can afford it, and "chosen": the brand kit, channel, niche, character, music and voice it resolved to, by name. Show the quote and the choices to the user; create_video needs the quote_id and the quote expires in 10 minutes. You may pick styles, kits, channels, niches and voices yourself from the list_* tools when the user has not named one. Nothing is built or charged by estimating.',
      inputSchema: z.object({
        source_type: z.enum(SOURCE_TYPES).describe('"prompt": a brief WyvStudio writes a script from. "script": narration used as-is. "url": a web page or article text. "product_description": copy to sell a product. "images": 1–15 library images become the scenes (needs image_asset_ids and a prompt in content).'),
        content: z.string().min(10).max(10000).describe('The prompt, script, URL/article text, or product description.'),
        image_asset_ids: z.array(z.number().int()).min(1).max(15).optional().describe('source_type images only: ids from list_library type image.'),
        visual_mode: z.enum(VISUAL_MODES).describe('"stock": licensed footage (cheapest). "ai_images": AI stills per scene. "ai_video": AI stills animated (most expensive; needs animate_tier). "waveform": audiogram over a background.'),
        visual_style: z.string().max(64).optional().describe('AI modes only: a style key from get_options (e.g. cinematic, watercolor, anime).'),
        custom_visual_style: z.string().max(500).optional().describe('AI modes only: free-text art direction.'),
        audiogram: z.object({ style: z.string().max(64).optional(), color: z.string().max(16).optional(), bg: z.string().max(32).optional() }).optional().describe('waveform only.'),
        duration_seconds: z.number().int().min(5).max(600).optional().describe('Target length. Default 60. Capped by the plan (see get_capabilities).'),
        animate_tier: z.enum(ANIMATE_TIERS).optional().describe('ai_video only: the animation model tier. Prices in get_capabilities.'),
        animation_pacing: z.enum(['short', 'long']).optional().describe('ai_video only: shorter or longer motion clips per scene.'),
        aspect_ratio: z.enum(ASPECT_RATIOS).optional().describe('Default 9:16 (vertical).'),
        tone: z.string().max(64).optional().describe('Narration tone, e.g. "friendly", "authoritative".'),
        title: z.string().max(255).optional().describe('A working title for the project in the dashboard.'),
        content_goal: z.string().max(255).optional().describe('What the video is for, e.g. "drive sign-ups for the free trial".'),
        voice_id: z.string().max(255).optional().describe('A voice id from list_voices. Omit for the default voice.'),
        brand_kit_id: z.number().int().optional().describe('From list_brand_kits.'),
        channel_id: z.number().int().optional().describe('From list_channels; its defaults apply.'),
        niche_id: z.number().int().optional().describe('From list_niches; fills tone, style and music you leave out.'),
        character_id: z.number().int().optional().describe('From list_characters.'),
        music_asset_id: z.number().int().optional().describe('From list_library type music.'),
        languages: z.array(z.string()).min(1).max(5).optional().describe('Language codes from get_options; first is the narration language. Default en.'),
        platform_target: z.string().optional().describe('From get_options, e.g. youtube_shorts, tiktok, instagram_reels.'),
        allow_script_edit: z.boolean().optional().describe('Let WyvStudio lightly edit a provided script for pacing.'),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async (args) => call(token, 'POST', '/quotes', args, 'estimate_video'),
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
    async ({ quote_id, idempotency_key }) => call(token, 'POST', '/videos', { quote_id, idempotency_key: idempotency_key || quote_id }, 'create_video'),
  )

  // ── Characters: create, update, quote-bound images. Listing is list_characters.
  server.registerTool(
    'create_character',
    {
      title: 'Create a character',
      description: "Create a reusable AI character for videos and UGC ads, from a description and/or 1–8 library reference images (list_library type image). Free. CONSENT: if reference images show a real person, ask the user to confirm they have that person's consent and pass consent: true. Plan limits on the number of characters apply.",
      inputSchema: z.object({
        name: z.string().max(120),
        description: z.string().max(2000).optional().describe('Appearance, age, vibe, setting.'),
        reference_asset_ids: z.array(z.number().int()).max(8).optional(),
        consistency_method: z.enum(['quick', 'lora']).optional(),
        identity_strength: z.enum(['subtle', 'balanced', 'strong', 'locked']).optional(),
        consent: z.boolean().optional().describe('Required with reference images.'),
      }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    },
    async (args) => call(token, 'POST', '/characters', args, 'create_character'),
  )
  server.registerTool(
    'update_character',
    {
      title: 'Update a character',
      description: 'Change a character\'s name, description, references, consistency method or identity strength. Free.',
      inputSchema: z.object({
        character_id: z.number().int(),
        name: z.string().max(120).optional(),
        description: z.string().max(2000).optional(),
        reference_asset_ids: z.array(z.number().int()).max(8).optional(),
        consistency_method: z.enum(['quick', 'lora']).optional(),
        identity_strength: z.enum(['subtle', 'balanced', 'strong', 'locked']).optional(),
      }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ character_id, ...rest }) => call(token, 'PATCH', `/characters/${character_id}`, rest, 'update_character'),
  )
  server.registerTool(
    'estimate_character_image',
    {
      title: 'Estimate a character image (free)',
      description: 'Price one generated image of a character (a new look, or a new reference photo). Returns a quote_id (10 minutes). Cheaper without a reference photo; with one, the image is generated to match it.',
      inputSchema: z.object({
        character_id: z.number().int(),
        prompt: z.string().max(2000).describe('What the image should show.'),
        style: z.string().max(64).optional().describe('A style key from get_options; default photorealistic.'),
        model_key: z.enum(['nano-banana-pro', 'nano-banana', 'gpt-image-2', 'gpt-image-1']).optional(),
        aspect_ratio: z.enum(['9:16', '1:1', '16:9']).optional(),
        quality: z.enum(['low', 'medium', 'high']).optional(),
        set_as_reference: z.boolean().optional().describe('Make the result the character\'s reference photo.'),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async ({ character_id, ...rest }) => call(token, 'POST', `/characters/${character_id}/images/quotes`, rest, 'estimate_character_image'),
  )
  server.registerTool(
    'create_character_image',
    {
      title: 'Generate the quoted character image',
      description: 'SPENDS CREDITS (the quote\'s amount). Only after the user agreed. Returns a generation id; poll get_character_image every 10 seconds.',
      inputSchema: z.object({ character_id: z.number().int(), quote_id: z.string(), idempotency_key: z.string().max(128).optional() }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ character_id, quote_id, idempotency_key }) => call(token, 'POST', `/characters/${character_id}/images`, { quote_id, idempotency_key: idempotency_key || quote_id }, 'create_character_image'),
  )
  server.registerTool(
    'get_character_image',
    {
      title: 'Check a character image',
      description: 'Status of a character image generation: generating, completed (with the image asset) or failed.',
      inputSchema: z.object({ character_id: z.number().int(), generation_id: z.number().int() }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ character_id, generation_id }) => call(token, 'GET', `/characters/${character_id}/images/${generation_id}`, undefined, 'get_character_image'),
  )

  // ── UGC ads: plan → estimate → create, priced and gated exactly like the app.
  const segment = z.object({
    kind: z.enum(['on_camera', 'b_roll', 'reaction']),
    script_text: z.string().max(1500).nullable().optional(),
    seconds: z.number().min(1).max(60),
    visual_brief: z.string().max(1000),
    voice_direction: z.string().max(500).nullable().optional(),
    motion_prompt: z.string().max(1000).nullable().optional(),
    headline: z.string().max(180).nullable().optional(),
    source: z.enum(['upload', 'stock', 'generate']).nullable().optional(),
    asset_id: z.number().int().nullable().optional(),
  }).passthrough()

  server.registerTool(
    'plan_ugc',
    {
      title: 'Plan a UGC ad (free)',
      description: 'Turn a script or a brief into a shot plan for a UGC-style ad: shots with kind (on_camera, b_roll, reaction), narration, seconds and visual direction, plus the format it chose. Free. Pass the returned format and segments to estimate_ugc unchanged. Formats: direct_camera (one continuous talking take), demo, story, reaction, text_led; "auto" lets the planner pick. Optionally ask for alternate openings with variants_count.',
      inputSchema: z.object({
        script: z.string().max(1500).optional().describe('The narration, if the user has one.'),
        context: z.string().max(1500).optional().describe('Or a brief: product, audience, angle.'),
        product: z.string().max(200).optional(),
        format: z.enum(['auto', 'direct_camera', 'demo', 'story', 'reaction', 'text_led']).default('auto'),
        duration_seconds: z.number().int().min(5).max(180),
        language: z.string().optional(),
        footage_asset_ids: z.array(z.number().int()).max(40).optional().describe('Library images/videos the plan may cut to (list_library).'),
        variants_count: z.number().int().min(2).max(6).optional(),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async (args) => call(token, 'POST', '/ugc/plans', args, 'plan_ugc'),
  )

  server.registerTool(
    'estimate_ugc',
    {
      title: 'Estimate a UGC ad (free)',
      description: 'Price a plan from plan_ugc and get a quote_id (10 minutes). mode "composed": each shot generated and cut together, with one or more characters from list_characters (needs character_ids unless every shot is b_roll). mode "one_shot": a single continuous AI presenter take from a description or a character. CONSENT: before calling, ask the user to confirm they have the right to use any real person\'s likeness or voice in the ad, and pass consent: true only if they say so. Returns credits, takes used against the monthly allowance, and can_afford.',
      inputSchema: z.object({
        mode: z.enum(['composed', 'one_shot']),
        format: z.enum(['direct_camera', 'demo', 'story', 'reaction', 'text_led']),
        segments: z.array(segment).min(1).max(12).describe('From plan_ugc, unchanged.'),
        variants: z.array(z.object({ label: z.string().max(40).optional(), segments: z.array(segment).min(1).max(12) })).max(5).optional(),
        character_ids: z.array(z.number().int()).max(5).optional().describe('composed: presenters, from list_characters.'),
        character_id: z.number().int().optional().describe('one_shot: the presenter, from list_characters.'),
        cast_style: z.enum(['exact', 'variant']).optional(),
        quality: z.enum(['draft', 'full']).optional().describe('one_shot: draft is 480p and cheaper.'),
        presenter_description: z.string().max(400).optional().describe('one_shot without a character: who presents.'),
        product_asset_id: z.number().int().optional().describe('A library image of the product.'),
        demo_asset_id: z.number().int().optional().describe('one_shot: a ≤30s library video to embed as the demo.'),
        setting: z.string().max(300).optional(),
        product: z.string().max(200).optional(),
        tone: z.string().max(200).optional(),
        aspect_ratio: z.enum(['9:16', '1:1', '16:9']).optional(),
        language: z.string().optional(),
        title: z.string().max(120).optional(),
        consent: z.boolean().describe('True only after the user confirmed likeness/voice rights.'),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async (args) => call(token, 'POST', '/ugc/quotes', args, 'estimate_ugc'),
  )

  server.registerTool(
    'create_ugc',
    {
      title: 'Create the quoted UGC ad',
      description: 'Starts the takes an estimate_ugc quote described. SPENDS CREDITS and uses monthly takes. Only after the user has seen the quote and agreed. Returns one video id per take; poll each with get_video_status and fetch with get_video_result.',
      inputSchema: z.object({
        quote_id: z.string(),
        idempotency_key: z.string().max(128).optional().describe('Defaults to the quote_id.'),
      }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ quote_id, idempotency_key }) => call(token, 'POST', '/ugc/videos', { quote_id, idempotency_key: idempotency_key || quote_id }, 'create_ugc'),
  )

  server.registerTool(
    'get_ugc_allowance',
    {
      title: 'UGC takes allowance',
      description: 'Whether UGC ads are enabled on this plan, takes used and remaining this month, and the per-run and per-take limits.',
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async () => call(token, 'GET', '/ugc/allowance', undefined, 'get_ugc_allowance'),
  )

  // ── Editing: read → propose → apply, with a revision precondition.
  server.registerTool(
    'get_project',
    {
      title: 'Read a video project',
      description: 'The full editable state of a video: revision, project settings, ordered scenes with every setting and a readiness block (script, visual, voice, animation, in-progress, errors, locked fields), hook options, and the latest export with its freshness. Read this before proposing edits; the revision must match when you apply.',
      inputSchema: z.object({ video_id: z.number().int() }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'GET', `/videos/${video_id}/project`, undefined, 'get_project'),
  )
  server.registerTool(
    'get_project_schema',
    {
      title: 'What can be edited',
      description: 'The operations available on this video (and why any is not), the scene settings that update_scene accepts, enums for styles, tiers, rewrite modes and caption/motion settings, and the plan\'s limits.',
      inputSchema: z.object({ video_id: z.number().int() }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'GET', `/videos/${video_id}/project/schema`, undefined, 'get_project_schema'),
  )
  server.registerTool(
    'propose_edits',
    {
      title: 'Propose edits (free)',
      description: 'Validate a list of changes against the project\'s current revision and price the ones that spend credits (regenerate_voice, generate_image, edit_image, animate, regenerate_music). Returns a proposal_id (10 minutes), each change with its max credits, and the total. Nothing is applied. Show the user the changes and the total; apply_edits needs the proposal_id. Ops: update_scene {scene_id, settings}, reorder_scenes {scene_ids}, add_scene {...}, duplicate_scene, rewrite_scene {scene_id, mode}, regenerate_voice, swap_visual {scene_id, visual_asset_id|query}, generate_image {scene_id, model_key?}, edit_image {scene_id, instruction}, animate {scene_id, tier, ...}, cancel_animation, revert_animation, regenerate_music {scene_id, mood}, update_project {...}, generate_hooks.',
      inputSchema: z.object({
        video_id: z.number().int(),
        revision: z.string().describe('From get_project.'),
        changes: z.array(z.object({ op: z.string() }).passthrough()).min(1).max(30),
      }),
      annotations: { readOnlyHint: true, idempotentHint: false, openWorldHint: false },
    },
    async ({ video_id, ...rest }) => call(token, 'POST', `/videos/${video_id}/proposals`, rest, 'propose_edits'),
  )
  server.registerTool(
    'apply_edits',
    {
      title: 'Apply a proposal',
      description: 'Apply the changes in a proposal, in order, through the editor. SPENDS CREDITS up to the proposal\'s total. Refuses with revision_conflict if the project changed since the proposal. Returns each change\'s result (partial failures included) and the new revision. Only after the user agreed.',
      inputSchema: z.object({ video_id: z.number().int(), proposal_id: z.string(), idempotency_key: z.string().max(128).optional() }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id, proposal_id, idempotency_key }) => call(token, 'POST', `/videos/${video_id}/proposals/${proposal_id}/apply`, { idempotency_key: idempotency_key || proposal_id }, 'apply_edits'),
  )
  server.registerTool(
    'export_video',
    {
      title: 'Export a video',
      description: 'Render a new export of the current state, in one or more aspect ratios. Uses the plan\'s export allowance, not credits. Poll get_video_status; get_video_result returns the newest completed export.',
      inputSchema: z.object({ video_id: z.number().int(), aspect_ratios: z.array(z.enum(['9:16', '1:1', '4:5', '16:9'])).max(4).optional(), language: z.string().optional(), watermark_enabled: z.boolean().optional() }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    },
    async ({ video_id, ...rest }) => call(token, 'POST', `/videos/${video_id}/exports`, rest, 'export_video'),
  )
  server.registerTool(
    'list_exports',
    {
      title: 'List exports',
      description: 'Every export of a video, newest first, with status and download links for completed ones. Older exports do not contain newer edits.',
      inputSchema: z.object({ video_id: z.number().int() }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'GET', `/videos/${video_id}/exports`, undefined, 'list_exports'),
  )
  server.registerTool(
    'retry_video',
    {
      title: 'Retry a failed video',
      description: 'Retry generation of a failed video, or resume the failed parts of one. Only useful when get_video_status says failed and retryable.',
      inputSchema: z.object({ video_id: z.number().int() }),
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'POST', `/videos/${video_id}/retry`, {}, 'retry_video'),
  )

  server.registerTool(
    'get_video_status',
    {
      title: 'Check a video',
      description: 'Progress of a video: status is generating, exporting, completed or failed, with the current stage, credits spent so far and a project_url the user can open in WyvStudio. When completed, call get_video_result for the file. When failed, read failure.message; retryable means the user can retry from the dashboard.',
      inputSchema: z.object({ video_id: z.number().int().describe('From create_video.') }),
      annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ video_id }) => call(token, 'GET', `/videos/${video_id}`, undefined, 'get_video_status'),
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
      const started = Date.now()
      const { status, json } = await api(token, 'GET', `/videos/${video_id}/result`)
      logCall(token, 'get_video_result', status, Date.now() - started, status >= 300 ? json?.error?.code : undefined)
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

const oauth = Boolean(OAUTH_ISSUER && MCP_PUBLIC_URL)
if (oauth) {
  // Mirrors Laravel's /.well-known/oauth-authorization-server so a connector
  // that lands here first still finds the same endpoints.
  const oauthMetadata = {
    issuer: OAUTH_ISSUER,
    authorization_endpoint: `${OAUTH_ISSUER}/oauth/authorize`,
    token_endpoint: `${OAUTH_ISSUER}/api/v1/oauth/token`,
    registration_endpoint: `${OAUTH_ISSUER}/api/v1/oauth/register`,
    revocation_endpoint: `${OAUTH_ISSUER}/api/v1/oauth/revoke`,
    response_types_supported: ['code'],
    response_modes_supported: ['query'],
    grant_types_supported: ['authorization_code', 'refresh_token'],
    code_challenge_methods_supported: ['S256'],
    token_endpoint_auth_methods_supported: ['none'],
    scopes_supported: ['videos'],
  }
  app.use(mcpAuthMetadataRouter({
    oauthMetadata,
    resourceServerUrl: new URL(MCP_PUBLIC_URL),
    scopesSupported: ['videos'],
    resourceName: 'WyvStudio',
    dangerouslyAllowInsecureIssuerUrl: OAUTH_ISSUER.startsWith('http://'),
  }))
}
const auth = requireBearerAuth({
  verifier,
  ...(oauth ? { resourceMetadataUrl: getOAuthProtectedResourceMetadataUrl(new URL(MCP_PUBLIC_URL)) } : {}),
})

app.get('/healthz', (_req, res) => res.json({ ok: true, api: API_BASE_URL }))
app.all('/mcp', auth, (req, res) => void node(req, res, req.body))

app.listen(PORT, '0.0.0.0', () => {
  console.log(`wyvstudio mcp ${VERSION} listening on :${PORT}, api ${API_BASE_URL}, hosts ${ALLOWED_HOSTS.join(',')}, oauth ${oauth ? OAUTH_ISSUER : 'off'}`)
})
