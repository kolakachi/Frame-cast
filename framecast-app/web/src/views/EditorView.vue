<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../services/api";
import { getEcho } from "../services/echo";
import { useAuthStore } from "../stores/auth";
import { useSidebarStore } from "../stores/sidebar";
import AppSidebar from "../components/AppSidebar.vue";
import EditorTimeline from "../components/EditorTimeline.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import SchedulePostModal from "../components/SchedulePostModal.vue";
import NotifBell from "../components/NotifBell.vue";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const sidebarStore = useSidebarStore();

const projectId = computed(() => route.params.projectId);
const loading = ref(true);
const error = ref("");
const project = ref(null);
const scenes = ref([]);

// Cruise Control — Phase 1A scaffolding. Flips the right rail between the
// existing accordion ("Config") and the chat shell ("Assistant"). LLM glue
// lands in Phase 1B. cruiseAssistantPending is the blue dot on the
// Assistant tab — fires when a resolve completes while the user is on
// Config. Keyboard shortcut: Cmd/Ctrl+J toggles.
const cruiseTab = ref('config')
const cruiseAssistantPending = ref(false)
function onCruiseKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'j') {
    e.preventDefault()
    cruiseTab.value = cruiseTab.value === 'config' ? 'assistant' : 'config'
    if (cruiseTab.value === 'assistant') cruiseAssistantPending.value = false
  }
}

// Cruise Control Phase 1B — chat state. Conversation is purely client-side
// in 1B; persistence lands in 1C. Each message has:
//   { id, role: 'user'|'assistant', text, action?, action_status?, action_credits? }
const cruiseMessages = ref([])
const cruiseInputText = ref('')
const cruiseResolving = ref(false)
const cruiseApplying = ref(null) // message id currently being applied
const cruiseScopeSceneId = ref(null) // null = follow activeScene; otherwise explicit
const cruiseChatScrollRef = ref(null)
const cruisePulseSection = ref(null) // 'voice' | 'visual' | 'music' | 'motion' (drives the pulse animation)
const cruiseToast = ref('')
let cruiseToastTimer = null
// Workspace-level pref — when true, confirmation_class='auto' tools
// apply immediately on resolve without showing the Apply button.
const cruiseAutoApply = ref(true)
// Workspace-level Cruise prefs. Hydrated from /me. null = no bias
// (LLM picks per turn). visual_source is special: 'auto' renders as
// "no bias" but is the explicit on-the-wire sentinel.
const cruisePrefs = ref({ image_model: null, animation_tier: null, visual_source: 'auto' })
const cruisePrefsOpen = ref(false)
// Frequently-used prompts shown above the input.
const CRUISE_QUICK_PROMPTS = [
  'change voice on this scene',
  'use upbeat music',
  'swap visual to stock',
  'rewrite this script punchier',
  'animate this scene',
  'add a CTA scene',
]
// Credits live at response.data.data.credits — sibling to user, not nested
// inside it. We mirror them into a dedicated ref so the action card's
// top-up gate reads truth. Set by loadMe() / refreshed after each apply.
const cruiseCreditsPayload = ref(null)
const cruiseUserBalance = computed(() => cruiseCreditsPayload.value?.balance ?? 0)

const cruiseScopeLabel = computed(() => {
  // Default scope: the active scene if any, otherwise whole project.
  // Manually flipped scope (via the ↻ link) overrides activeScene.
  if (cruiseScopeSceneId.value === null) return 'whole project'
  if (cruiseScopeSceneId.value === 'active') return activeScene.value ? `Scene ${activeScene.value.scene_order}` : 'whole project'
  const s = scenes.value.find((x) => x.id === cruiseScopeSceneId.value)
  return s ? `Scene ${s.scene_order}` : 'whole project'
})

function cruiseEffectiveSceneId() {
  // What we actually send to the backend as scope_scene_id.
  if (cruiseScopeSceneId.value && cruiseScopeSceneId.value !== 'active') return cruiseScopeSceneId.value
  return activeScene.value?.id ?? null
}

function cruiseActionIcon(section) {
  return {
    voice: '🎤', visual: '🎨', music: '🎵', motion: '🎬',
    scene: '＋', script: '📝', captions: '💬', sounds: '🔊', brand: '🎨',
  }[section] ?? '✦'
}
function cruiseActionTitle(tool) {
  return {
    rerecord_voice: 'Re-record voice',
    swap_visual_from_library: 'Swap visual',
    change_music: 'Regenerate music',
    regenerate_image: 'Regenerate image',
    animate_scene: 'Animate scene',
    add_scene: 'Add a new scene',
    find_stock_video: 'Find stock video',
    find_stock_image: 'Find stock image',
    pick_library_music: 'Pick library music',
    set_audiogram_visual: 'Set audiogram',
    update_scene_script: 'Edit script',
    update_captions: 'Update captions',
    apply_brand_kit: 'Apply brand kit',
    add_sound_effect: 'Add sound effect',
  }[tool] ?? tool
}

async function cruiseSubmitIntent() {
  const text = cruiseInputText.value.trim()
  if (!text || cruiseResolving.value || !projectId.value) return
  // Optimistic push with a temp id; we replace with backend's id once
  // the response lands so apply() can reference the persisted message.
  const tempUserId = `u-tmp-${Date.now()}`
  cruiseMessages.value.push({ id: tempUserId, role: 'user', text })
  cruiseInputText.value = ''
  cruiseResolving.value = true
  await nextTick(); cruiseScrollChatToBottom()
  try {
    const recent = cruiseMessages.value
      .slice(0, -1)
      .slice(-6)
      .map((m) => ({ role: m.role === 'user' ? 'user' : 'assistant', text: m.text }))
    const res = await api.post('/cruise/resolve', {
      project_id: projectId.value,
      intent: text,
      scope_scene_id: cruiseEffectiveSceneId(),
      history: recent,
    })
    const data = res?.data?.data ?? {}
    // Replace the temp user message with the persisted id from backend.
    const tempIdx = cruiseMessages.value.findIndex((m) => m.id === tempUserId)
    if (tempIdx >= 0 && data.user_message_id) {
      cruiseMessages.value[tempIdx] = { ...cruiseMessages.value[tempIdx], id: data.user_message_id }
    }
    // Normalise to actions[] regardless of the backend response shape.
    // New backend always returns actions[]; older clients may still see
    // the singular 'action' on the persisted side.
    const rawActions = Array.isArray(data.actions)
      ? data.actions
      : (data.action ? [data.action] : [])
    const assistantMsg = {
      id: data.assistant_message_id ?? `a-${Date.now()}`,
      role: 'assistant',
      text: data.reply_to_user ?? 'Okay.',
      actions: rawActions.map((a) => cruiseInitActionState(a, 'proposed')),
      action_status: rawActions.length ? 'proposed' : null,
    }
    cruiseMessages.value.push(assistantMsg)
    if (cruiseTab.value !== 'assistant') cruiseAssistantPending.value = true
    // Fire-and-forget the auto-apply path for any low-risk actions.
    maybeAutoApply(assistantMsg)
  } catch (e) {
    const msg = e?.response?.data?.error?.message ?? 'Assistant is unavailable. Try again.'
    cruiseMessages.value.push({ id: `a-${Date.now()}`, role: 'assistant', text: msg })
  } finally {
    cruiseResolving.value = false
    await nextTick(); cruiseScrollChatToBottom()
  }
}

async function cruiseSkipAction(msg, actionIndex = 0) {
  const card = cruiseGetCard(msg, actionIndex)
  if (!card || card.status === 'skipped') return
  card.status = 'skipped'
  cruiseRecomputeMessageStatus(msg)
  try {
    await api.post('/cruise/skip', {
      project_id: projectId.value,
      message_id: msg.id,
      action_index: actionIndex,
    })
  } catch (_) { /* optimistic — refresh will reconcile */ }
}

// Skip every still-proposed card in this message in one click.
async function cruiseSkipAll(msg) {
  const cards = msg.actions || []
  for (let i = 0; i < cards.length; i++) {
    if (cards[i].status === 'proposed') await cruiseSkipAction(msg, i)
  }
}

// Apply ONE card by index. Same flow as before but writes onto the
// indexed card, not the message.
async function cruiseApplyAction(msg, actionIndex = 0) {
  const card = cruiseGetCard(msg, actionIndex)
  if (!card || cruiseApplying.value) return
  cruiseApplying.value = `${msg.id}:${actionIndex}`
  try {
    const res = await api.post('/cruise/apply', {
      project_id: projectId.value,
      tool: card.tool,
      params: card.params,
      message_id: msg.id,
      action_index: actionIndex,
    })
    const out = res?.data?.data
    card.credits = out?.credits_spent ?? 0
    card.progress_text = cruiseInitialProgressText(card.tool)
    card.expected_stages = cruiseExpectedStages(card.tool, card.params)
    card.completed_stages = []
    card.affected_scene_id = out?.affected_scene_id ?? card.params?.scene_id ?? null
    card.status = cruiseToolHasAsyncWork(card.tool) ? 'running' : 'applied'
    cruiseRecomputeMessageStatus(msg)
    cruisePulseSection.value = out?.affected_section ?? null
    window.setTimeout(() => { cruisePulseSection.value = null }, 1800)
    cruiseShowToast(`${out?.summary} · spent ${out?.credits_spent ?? 0} cr`)
    loadMe?.()
    // One-shot scene fetch so the editor picks up the new scene
    // (add_scene) or the in_progress flag (regenerate_image / animate).
    if (card.affected_scene_id) {
      try {
        const r = await api.get(`/scenes/${card.affected_scene_id}/preview`)
        const fresh = r?.data?.data?.scene
        if (fresh) replaceSceneInCollection(normalizeScenePayload(fresh))
      } catch (_) {}
    }
    if (card.status === 'running') {
      cruiseStartProgressLoop(card)
      cruiseStartPollingFallback(card)
    }
  } catch (e) {
    card.status = 'failed'
    card.error = e?.response?.data?.error?.message ?? 'Apply failed.'
    cruiseRecomputeMessageStatus(msg)
  } finally {
    cruiseApplying.value = null
  }
}

// Run every proposed card in this message back-to-back. Stops on the
// first failure so the user can decide whether to retry / skip the rest.
async function cruiseApplyAll(msg) {
  const cards = msg.actions || []
  for (let i = 0; i < cards.length; i++) {
    if (cards[i].status !== 'proposed') continue
    await cruiseApplyAction(msg, i)
    // Wait for the action to terminate (applied / failed / skipped).
    // Running actions complete async via Reverb / polling — we don't
    // block the queue waiting for the generation to finish, but we DO
    // wait for the apply call itself before firing the next one.
    if (cards[i].status === 'failed') break
  }
}

function cruiseGetCard(msg, idx) {
  return (msg.actions && msg.actions[idx]) || null
}

function cruiseProposedCount(msg) {
  return (msg.actions || []).filter((c) => c.status === 'proposed').length
}

function cruiseTotalCost(msg) {
  return (msg.actions || []).reduce((sum, c) => sum + (c.estimated_cost ?? 0), 0)
}

// Disable "Apply all" when the user can't cover the combined cost.
function cruiseCanApplyAll(msg) {
  const need = (msg.actions || [])
    .filter((c) => c.status === 'proposed')
    .reduce((sum, c) => sum + (c.estimated_cost ?? 0), 0)
  return cruiseUserBalance.value >= need
}

// Aggregate per-card statuses into the legacy message-level
// action_status so older code paths (the Apply-tab pending dot,
// rehydrate, etc.) keep working.
function cruiseRecomputeMessageStatus(msg) {
  const statuses = (msg.actions || []).map((a) => a.status)
  if (statuses.includes('running')) { msg.action_status = 'running'; return }
  if (statuses.length && statuses.every((s) => s === 'applied' || s === 'skipped')) {
    msg.action_status = statuses.includes('failed') ? 'failed' : 'applied'
    return
  }
  if (statuses.includes('failed')) { msg.action_status = 'failed'; return }
  msg.action_status = statuses.includes('proposed') ? 'proposed' : null
}

// Rotating "Claude Code-style" progress text. Cycles every 2.5s while
// the action is 'running' so the chat looks alive even between Reverb
// events. Phrases are per-tool so the user sees something specific.
const CRUISE_PROGRESS_PHRASES = {
  regenerate_image: [
    'Generating image…',
    'Composing the prompt…',
    'Painting pixels…',
    'Almost there…',
  ],
  animate_scene: [
    'Animating scene…',
    'Setting up motion…',
    'Rendering frames…',
    'Polishing the clip…',
  ],
  rerecord_voice: [
    'Generating voice…',
    'Warming up the model…',
    'Synthesising audio…',
    'Almost there…',
  ],
  change_music: [
    'Composing music…',
    'Picking a vibe…',
    'Mixing tracks…',
    'Almost there…',
  ],
  add_scene: [
    'Adding scene…',
    'Writing the script…',
    'Generating image…',
    'Recording voice…',
  ],
}

// All progress + polling now operates on a CARD (one entry from
// msg.actions[]), not the message. Callers pass card; the card holds
// its own status, progress_text, expected_stages, _progressTimer, etc.
function cruiseStartProgressLoop(card) {
  if (card._progressTimer) return
  const phrases = CRUISE_PROGRESS_PHRASES[card.tool] || ['Working…']
  let i = 0
  card.progress_text = phrases[0]
  card._progressTimer = window.setInterval(() => {
    if (card.status !== 'running') { cruiseStopProgressLoop(card); return }
    // Skip rotating once Reverb has set a multi-stage status like
    // 'Voice ready · generating image…' — let the event truth win.
    if (card.progress_text && card.progress_text.includes('·')) return
    i = (i + 1) % phrases.length
    card.progress_text = phrases[i]
  }, 2500)
}
function cruiseStopProgressLoop(card) {
  if (card._progressTimer) {
    window.clearInterval(card._progressTimer)
    card._progressTimer = null
  }
}

// Polling fallback: every 5s while running, hit /scenes/{id}/preview
// to detect completion. Reverb is primary; this catches misses (lost
// websocket, ad blockers, network blip). Gives up after 5 minutes so a
// truly-stuck job doesn't poll forever.
const CRUISE_POLL_INTERVAL_MS = 5000
const CRUISE_POLL_TIMEOUT_MS = 5 * 60 * 1000

function cruiseStartPollingFallback(card) {
  if (card._pollTimer) return
  const sceneId = Number(card.affected_scene_id ?? card.params?.scene_id ?? 0)
  if (!sceneId) return
  const startedAt = Date.now()
  let attempts = 0
  const MAX_ATTEMPTS = Math.floor(CRUISE_POLL_TIMEOUT_MS / CRUISE_POLL_INTERVAL_MS) + 5
  // Snapshot the scene's pre-apply state so we can detect a real change.
  // For add_scene the scene didn't exist yet — baseline is "no asset",
  // any non-null asset id counts as a completion.
  const baselineScene = scenes.value?.find?.((s) => s.id === sceneId) || {}
  // Scene's per-stage asset locations:
  //   image     -> scene.visual_asset_id (top-level column)
  //   animation -> scene.image_generation_settings.animation_video_asset_id
  //   tts       -> scene.voice_settings.audio_asset_id (nested)
  // The frontend used to look at fresh.video_asset_id / fresh.audio_asset_id
  // which DO NOT EXIST on the scene model — TTS polling never finished.
  const baseline = {
    visual_asset_id:           baselineScene.visual_asset_id ?? null,
    animation_video_asset_id:  baselineScene.image_generation_settings?.animation_video_asset_id ?? null,
    audio_asset_id:            baselineScene.voice_settings?.audio_asset_id ?? null,
  }
  card._pollTimer = window.setInterval(async () => {
    if (card.status !== 'running') { cruiseStopPolling(card); return }
    attempts++
    if (attempts > MAX_ATTEMPTS || Date.now() - startedAt > CRUISE_POLL_TIMEOUT_MS) {
      card.status = 'failed'
      card.error = 'Generation timed out — check the Config tab for the latest scene state.'
      cruiseStopPolling(card)
      cruiseStopProgressLoop(card)
      return
    }
    try {
      const res = await api.get(`/scenes/${sceneId}/preview`)
      const freshRaw = res?.data?.data?.scene
      if (!freshRaw) return
      const fresh = normalizeScenePayload(freshRaw)
      const expected = card.expected_stages || []
      const completed = card.completed_stages || []
      const newlyDone = []
      const stageDoneByAsset = {
        ai_image:  fresh.visual_asset_id && fresh.visual_asset_id !== baseline.visual_asset_id,
        animation: fresh.image_generation_settings?.animation_video_asset_id
                   && fresh.image_generation_settings.animation_video_asset_id !== baseline.animation_video_asset_id,
        tts:       fresh.voice_settings?.audio_asset_id
                   && fresh.voice_settings.audio_asset_id !== baseline.audio_asset_id,
      }
      const stageFailedByState = {
        ai_image:  !!fresh.image_generation_settings?.last_error,
        animation: !!fresh.image_generation_settings?.animation_last_error,
        tts:       !!fresh.voice_settings?.last_error,
      }
      const failedStage = expected.find(stage => stageFailedByState[stage])
      if (failedStage) {
        card.status = 'failed'
        card.error = failedStage === 'animation'
          ? (fresh.image_generation_settings?.animation_last_error || 'Animation failed.')
          : failedStage === 'tts'
            ? (fresh.voice_settings?.last_error || 'Voice generation failed.')
            : (fresh.image_generation_settings?.last_error || 'Generation failed.')
        try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
        cruiseStopPolling(card)
        cruiseStopProgressLoop(card)
        return
      }
      for (const stage of expected) {
        if (!completed.includes(stage) && stageDoneByAsset[stage]) newlyDone.push(stage)
      }
      if (!newlyDone.length) return
      card.completed_stages = [...completed, ...newlyDone]
      const allDone = expected.every(s => card.completed_stages.includes(s))
      if (allDone) {
        card.status = 'applied'
        card.progress_text = cruiseSuccessText(card.tool, null)
        try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
        cruiseStopPolling(card)
        cruiseStopProgressLoop(card)
      } else {
        card.progress_text = cruiseStagePendingText(card.tool, expected, card.completed_stages)
        try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
      }
    } catch (_) { /* keep polling */ }
  }, CRUISE_POLL_INTERVAL_MS)
}
function cruiseStopPolling(card) {
  if (card._pollTimer) {
    window.clearInterval(card._pollTimer)
    card._pollTimer = null
  }
}

// Per-action state used by every render path (chat card, polling,
// progress checklist). raw = the {tool, params, diff_lines, ...} blob
// from the backend. We layer per-card status fields on top so the
// frontend can drive each action independently in a multi-action turn.
function cruiseInitActionState(raw, status = 'proposed') {
  return {
    ...raw,
    status,                    // 'proposed' | 'running' | 'applied' | 'failed' | 'skipped'
    credits: 0,                // populated on apply
    progress_text: null,       // single-line spinner phrase
    expected_stages: [],       // filled at apply time
    completed_stages: [],
    affected_scene_id: null,
    error: null,
  }
}

// Tools whose work is synchronous (no GenerationProgressed event will
// fire) — stamp 'applied' immediately. Everything else dispatches a job
// and we wait for the Reverb 'completed' event before stamping ✓.
function cruiseToolHasAsyncWork(tool) {
  return [
    'regenerate_image', 'animate_scene', 'rerecord_voice',
    'update_scene_script', 'change_music', 'add_scene',
  ].includes(tool)
}

// Async stages we expect each tool to fire, so we know not to mark
// 'applied' until ALL of them complete. add_scene dispatches both an
// image and a voice job; chained regenerate_image fires ai_image AND
// then animation. Without this, the chat said "Voice ready" while the
// image was still cooking.
function cruiseExpectedStages(tool, params) {
  const isChained = !!params?.chain_animate_tier
  switch (tool) {
    case 'regenerate_image': return isChained ? ['ai_image', 'animation'] : ['ai_image']
    case 'animate_scene':    return ['animation']
    case 'rerecord_voice':   return ['tts']
    case 'update_scene_script': return ['tts']
    case 'change_music':     return ['ai_music']
    case 'add_scene': {
      // animate_tier is the add_scene-specific param name (different from
      // regenerate_image's chain_animate_tier). When set, the image job
      // dispatches an animation job after the image lands — we need to
      // wait on both stages before stamping the card applied.
      const stages = ['ai_image', 'tts']
      if (params?.animate_tier) stages.push('animation')
      return stages
    }
    default:                 return []
  }
}

// Progress text for a partial-completion (one stage done, others
// pending). "Voice ready · generating image…" reads better than
// flipping to green ✓ before the work is done.
function cruiseStagePendingText(tool, expected, completed) {
  const pending = expected.filter(s => !completed.includes(s))
  if (!pending.length) return cruiseSuccessText(tool, null)
  const doneLabel = completed.map(s => ({
    ai_image: 'Image ready', animation: 'Animation ready',
    tts: 'Voice ready', ai_music: 'Music ready',
  }[s] || s)).join(', ')
  const pendingLabel = pending.map(s => ({
    ai_image: 'generating image', animation: 'animating',
    tts: 'recording voice', ai_music: 'composing music',
  }[s] || s)).join(' + ')
  return `${doneLabel} · ${pendingLabel}…`
}

// First line shown the instant Apply lands, before any event fires.
function cruiseInitialProgressText(tool) {
  switch (tool) {
    case 'regenerate_image':  return 'Generating image…'
    case 'animate_scene':     return 'Animating scene…'
    case 'rerecord_voice':    return 'Generating voice…'
    case 'update_scene_script': return 'Generating voice…'
    case 'change_music':      return 'Composing music…'
    case 'add_scene':         return 'Adding scene…'
    default:                  return 'Working…'
  }
}

// Match an incoming generation.progress event to the most recent cruise
// message whose action covers this work. Stage→tool map:
//   ai_image -> regenerate_image | add_scene
//   animation -> animate_scene (or chained regenerate_image)
//   tts -> rerecord_voice | add_scene
//   ai_music -> change_music
// Scene scoping: when payload.scene_id is set, prefer a message whose
// action.params.scene_id matches. Falls back to the most recent matching
// tool when scene_id is absent from the event (e.g. ai_image 'processing'
// hasn't been dispatched with scene_id).
// Returns {msg, card} when the event belongs to a cruise card that's
// running/proposed; null otherwise. Walks every message's actions[]
// because a single message can carry multiple in-flight cards.
function cruiseFindCardForProgress(payload) {
  const stage = payload.stage
  const sceneId = payload.scene_id ? Number(payload.scene_id) : null
  const stageTools = {
    ai_image:  ['regenerate_image', 'add_scene'],
    // add_scene with animate_tier chains into AnimateSceneJob too, so an
    // 'animation' event can legitimately belong to an add_scene card.
    animation: ['animate_scene', 'regenerate_image', 'add_scene'],
    tts:       ['rerecord_voice', 'update_scene_script', 'add_scene'],
    ai_music:  ['change_music'],
  }[stage] || []
  if (!stageTools.length) return null
  for (let i = cruiseMessages.value.length - 1; i >= 0; i--) {
    const m = cruiseMessages.value[i]
    if (!Array.isArray(m.actions) || !m.actions.length) continue
    // Newest action first inside a message so the most-recently-Applied
    // card wins on ambiguous matches (e.g. two regenerate_image cards
    // in one turn, only one in flight).
    for (let j = m.actions.length - 1; j >= 0; j--) {
      const card = m.actions[j]
      if (!stageTools.includes(card.tool)) continue
      if (card.status !== 'running') continue
      if (sceneId) {
        const cardSceneId = Number(card.affected_scene_id ?? card.params?.scene_id ?? 0)
        if (cardSceneId && cardSceneId !== sceneId) continue
      }
      return { msg: m, card }
    }
  }
  return null
}

// Drive a running CARD's progress text + checklist from the Reverb
// event. Resolves to {msg, card} via cruiseFindCardForProgress so each
// in-flight card in a multi-action turn updates independently.
function cruiseHandleProgressEvent(payload) {
  const found = cruiseFindCardForProgress(payload)
  if (!found) return
  const { msg, card } = found
  const status = String(payload.status || '')
  if (status === 'processing') {
    card.progress_text = card.completed_stages?.length
      ? cruiseStagePendingText(card.tool, card.expected_stages || [], card.completed_stages)
      : cruiseInitialProgressText(card.tool)
    return
  }
  if (status === 'completed') {
    const expected = card.expected_stages || []
    const completed = card.completed_stages || []
    if (!completed.includes(payload.stage)) completed.push(payload.stage)
    card.completed_stages = completed
    const allDone = expected.length > 0 && expected.every(s => completed.includes(s))
    if (allDone) {
      card.status = 'applied'
      card.progress_text = cruiseSuccessText(card.tool, payload.stage)
      cruiseStopProgressLoop(card)
      cruiseStopPolling(card)
      cruiseRecomputeMessageStatus(msg)
    } else {
      card.progress_text = cruiseStagePendingText(card.tool, expected, completed)
    }
    return
  }
  if (status === 'failed') {
    card.status = 'failed'
    card.error = payload.message || 'Generation failed.'
    cruiseStopProgressLoop(card)
    cruiseStopPolling(card)
    cruiseRecomputeMessageStatus(msg)
  }
}

// Stage label for the bullet checklist. "done" form strikes through
// past-tense; "pending" form is present participle so the user sees
// what's happening right now.
function cruiseStageLabel(stage, done) {
  const labels = {
    ai_image:  done ? 'Image generated'    : 'Generating image',
    animation: done ? 'Animation rendered' : 'Animating scene',
    tts:       done ? 'Voice recorded'     : 'Recording voice',
    ai_music:  done ? 'Music composed'     : 'Composing music',
  }
  return labels[stage] ?? stage
}

function cruiseSuccessText(tool, stage) {
  if (stage === 'ai_image')  return 'Image ready'
  if (stage === 'animation') return 'Animation ready'
  if (stage === 'tts')       return 'Voice ready'
  if (stage === 'ai_music')  return 'Music ready'
  // Fallback for polling path (no stage)
  switch (tool) {
    case 'regenerate_image': return 'Image ready'
    case 'animate_scene':    return 'Animation ready'
    case 'rerecord_voice':   return 'Voice ready'
    case 'update_scene_script': return 'Voice ready'
    case 'change_music':     return 'Music ready'
    case 'add_scene':        return 'Scene added'
    default:                 return 'Done'
  }
}

function cruiseShowToast(text) {
  cruiseToast.value = text
  if (cruiseToastTimer) window.clearTimeout(cruiseToastTimer)
  cruiseToastTimer = window.setTimeout(() => { cruiseToast.value = '' }, 4500)
}

function cruiseScrollChatToBottom() {
  const el = cruiseChatScrollRef.value
  if (el) el.scrollTop = el.scrollHeight
}

// Load the persisted conversation for this project. Fires after the
// editor knows its project_id (loadProject succeeds).
async function loadCruiseConversation() {
  if (!projectId.value) return
  try {
    const res = await api.get(`/cruise/conversation/${projectId.value}`)
    const msgs = res?.data?.data?.messages
    if (Array.isArray(msgs) && msgs.length) {
      cruiseMessages.value = msgs.map((m) => {
        // Backwards compat: older messages stored a single action; new
        // ones store actions[]. Promote singular to array on hydrate.
        const rawActions = Array.isArray(m.actions) && m.actions.length
          ? m.actions
          : (m.action ? [{ ...m.action, status: m.action_status ?? 'proposed', credits: m.action_credits ?? 0, affected_scene_id: m.affected_scene_id ?? null }] : [])
        const out = {
          id: m.id,
          role: m.role,
          text: m.text,
          actions: rawActions.map((a) => {
            const card = cruiseInitActionState(a, a.status ?? 'proposed')
            // Restore the bits that drive hydrate verification.
            card.credits = a.credits ?? 0
            card.affected_scene_id = a.affected_scene_id ?? null
            card.expected_stages = Array.isArray(a.expected_stages) ? a.expected_stages : []
            card.completed_stages = Array.isArray(a.completed_stages) ? a.completed_stages : []
            card.error = a.error ?? null
            return card
          }),
        }
        cruiseRecomputeMessageStatus(out)
        return out
      })
      await nextTick(); cruiseScrollChatToBottom()
      // Resume polling for anything that's still cooking on the backend.
      // Runs after the messages array is committed so the message refs
      // we hand to cruiseStartPollingFallback are reactive.
      await cruiseResumeInflightAfterHydrate()
    }
  } catch { /* silent — empty conversation is fine */ }
}

// On hydrate, walk every applied card with an affected_scene_id and
// verify the scene actually has the assets the action was supposed to
// produce. If anything's still pending, flip the card back to 'running'
// and resume the polling fallback + spinner. This is what makes a page
// refresh mid-generation pick up where the user left off.
async function cruiseResumeInflightAfterHydrate() {
  // Dedup: one scene fetch per affected_scene_id even if multiple
  // cards share it.
  const toCheck = new Map()
  for (const m of cruiseMessages.value) {
    for (const card of (m.actions || [])) {
      if (!['running', 'applied'].includes(card.status)) continue
      if (!card.affected_scene_id) continue
      const expected = cruiseExpectedStages(card.tool, card.params)
      if (!expected.length) continue
      // Cache scene fetch by id; pin (msg, card, expected) for processing.
      const bucket = toCheck.get(card.affected_scene_id) || []
      bucket.push({ msg: m, card, expected })
      toCheck.set(card.affected_scene_id, bucket)
    }
  }
  if (!toCheck.size) return
  await Promise.all([...toCheck.entries()].map(async ([sceneId, items]) => {
    try {
      const res = await api.get(`/scenes/${sceneId}/preview`)
      const sceneRaw = res?.data?.data?.scene
      if (!sceneRaw) return
      const scene = normalizeScenePayload(sceneRaw)
      const stageDone = {
        ai_image:  !!scene.visual_asset_id && !scene.image_generation_settings?.in_progress,
        animation: !!scene.image_generation_settings?.animation_video_asset_id
                   && !scene.image_generation_settings?.animation_in_progress,
        tts:       !!scene.voice_settings?.audio_asset_id
                   && !scene.voice_settings?.is_outdated,
        ai_music:  true, // project-level, can't verify from scene
      }
      const stageFailed = {
        ai_image:  !!scene.image_generation_settings?.last_error,
        animation: !!scene.image_generation_settings?.animation_last_error,
        tts:       !!scene.voice_settings?.last_error,
        ai_music:  false,
      }
      for (const { msg, card, expected } of items) {
        const failedStage = expected.find(s => stageFailed[s])
        if (failedStage) {
          card.status = 'failed'
          card.error = failedStage === 'animation'
            ? (scene.image_generation_settings?.animation_last_error || 'Animation failed.')
            : failedStage === 'tts'
              ? (scene.voice_settings?.last_error || 'Voice generation failed.')
            : (scene.image_generation_settings?.last_error || 'Generation failed.')
          cruiseStopProgressLoop(card)
          cruiseStopPolling(card)
          cruiseRecomputeMessageStatus(msg)
          continue
        }

        const allDone = expected.every(s => stageDone[s])
        if (allDone) {
          card.status = 'applied'
          card.completed_stages = [...expected]
          card.progress_text = cruiseSuccessText(card.tool, expected[expected.length - 1] || null)
          cruiseStopProgressLoop(card)
          cruiseStopPolling(card)
          cruiseRecomputeMessageStatus(msg)
          continue
        }
        // Still cooking. Flip back to running, record what's already
        // done, kick off progress loop + polling so the user sees it
        // resume even though Reverb missed the last event.
        card.status = 'running'
        card.expected_stages = expected
        card.completed_stages = expected.filter(s => stageDone[s])
        card.progress_text = card.completed_stages.length
          ? cruiseStagePendingText(card.tool, expected, card.completed_stages)
          : cruiseInitialProgressText(card.tool)
        cruiseStartProgressLoop(card)
        cruiseStartPollingFallback(card)
        cruiseRecomputeMessageStatus(msg)
      }
    } catch (_) { /* silent — leave card as 'applied' */ }
  }))
}

// Pulse a Config panel-section after an apply. The CSS class
// .panel-section.cruise-pulse drives the animation.
const cruisePulseClass = (sectionKey) => cruisePulseSection.value === sectionKey ? 'cruise-pulse' : ''
const hookOptions = ref([]);
const mePayload = ref(null);
const isAdmin = computed(() => ["super_admin", "platform_admin"].includes(mePayload.value?.role ?? authStore.user?.role));
const activeSceneId = ref(null);
const notificationDrawerOpen = ref(false);
const notifications = ref([]);
const notificationToasts = ref([]);
const exportJobs      = ref([]);
const scheduleModalOpen = ref(false);

// Approval link state
const approvalModalOpen = ref(false);
const approvalForm = ref({ email: '', name: '', message: '', expires_in_days: 7 });
const approvalSubmitting = ref(false);
const approvalError = ref('');
const approvalCreated = ref(null); // { public_url, expires_at, reviewer_email }
async function submitApproval() {
  if (approvalSubmitting.value) return;
  if (!approvalForm.value.email) { approvalError.value = 'Reviewer email is required.'; return; }
  approvalSubmitting.value = true;
  approvalError.value = '';
  try {
    const res = await api.post('/approvals', {
      project_id:      Number(route.params.projectId),
      export_job_id:   latestExportJob.value?.id ?? null,
      reviewer_email:  approvalForm.value.email.trim(),
      reviewer_name:   approvalForm.value.name.trim() || null,
      comment:         approvalForm.value.message.trim() || null,
      expires_in_days: approvalForm.value.expires_in_days,
    });
    approvalCreated.value = {
      public_url:     res.data?.data?.public_url,
      approval:       res.data?.data?.approval,
      warning:        res.data?.warning ?? null,
    };
  } catch (err) {
    approvalError.value = err?.response?.data?.error?.message || 'Could not send approval link.';
  } finally {
    approvalSubmitting.value = false;
  }
}
function closeApprovalModal() {
  approvalModalOpen.value = false;
  approvalForm.value = { email: '', name: '', message: '', expires_in_days: 7 };
  approvalError.value = '';
  approvalCreated.value = null;
}
function copyApprovalUrl() {
  if (!approvalCreated.value?.public_url) return;
  navigator.clipboard?.writeText(approvalCreated.value.public_url);
}
let workspaceChannelName = null;

const audioRef = ref(null);
const musicAudioRef = ref(null);
const soundAudioRef = ref(null);
const musicAuditionRef = ref(null);
const isAudioPlaying = ref(false);
const isAudioLoading = ref(false);
const auditionMusicTrackId = ref(null);
const currentVisualUrl = ref(null);
const visualLoadFailed = ref(false);
const mediaCache = ref({
  visual: {},
  audio: {},
});
const mediaPreloaders = new Map();
const MEDIA_ELEMENT_CROSS_ORIGIN = "anonymous";
const API_ORIGIN = new URL(api.defaults.baseURL, window.location.origin).origin;

const addScenePanelPosition = ref("");
const selectedSceneType = ref("Narration");
const selectedAddSceneVisualSource = ref("Stock Clip");
// '' = no source picked yet (UI shows tabs without one pre-selected, scene
// gets created with visual_type='placeholder' so nothing is auto-attached).
// User has to actively click a tab — Video / Image / AI / Assets — before
// the scene picks up that source.
const addSceneVisualMode = ref("");
const addSceneStockSubType = ref("stock_clip");
const addSceneVisualStyle = ref(null);
const addSceneCustomVisualStyle = ref("");
const addSceneVisualQuery = ref("");
// Asset picked via the MediaPickerModal while the Assets tab is active.
// Cleared on cancel/reset; consumed when createScene() builds the POST.
const addScenePickedAsset = ref(null);
// Optional per-scene character (used in AI mode for an ad-hoc reference image).
// Null = no character bound; visual brief generates without a reference.
const addSceneCharacterId = ref(null);
// Drives the rich character picker (thumbnail grid) inside the add-scene panel —
// mirrors `characterPopoverOpen` on the right-hand scene panel.
const addSceneCharPopoverOpen = ref(false);
const selectedSwapVisualSource = ref("Stock Clip");
const newSceneScript = ref("");
const rewriteToolsVisible = ref(true);
const rewritePreviewVisible = ref(false);
const rewritePreviewCopy = ref("");
const rewriteCustomInstruction = ref("");
const rewriteMode = ref("");
const rewritePending = ref(false);
const rewriteApplyPending = ref(false);
const rewriteError = ref("");
const sceneDurationDraft = ref("");
const sceneDurationSaving = ref(false);
const captionPresets = ref([]);
const captionPresetSaveOpen = ref(false);
const captionPresetSaveName = ref("");
const captionPresetSaving = ref(false);
const captionPresetDeleting = ref(null);
const voiceProfileSaveOpen = ref(false);
const voiceProfileSaveName = ref("");
const voiceProfileSaving = ref(false);
const voiceRegeneratePending = ref(false);
const voiceRegenerateError = ref("");
const addSceneGeneratePending = ref(false);
const addSceneGenerateError = ref("");
const sceneReorderPendingId = ref(null);
const deleteScenePending = ref(false);
const deleteSceneTarget = ref(null);
const voiceProfiles = ref([]);
const channels = ref([]);
const brandKits = ref([]);
const projectChannelId = ref("");
const projectBrandKitId = ref("");
const projectDefaultsSaveState = ref("idle");
const projectDefaultsSaveError = ref("");
const musicTracks = ref([]);
const selectedMusicTrackId = ref(null);
// My Asset image browser
const myImageAssets = ref([]);
const myImageSearch = ref('');
const myImageLoading = ref(false);
// Custom audio picker
const customAudioAssets = ref([]);
const customAudioSearch = ref('');
const customAudioLoading = ref(false);
// Voice tab + upload / recording state
const voiceTab = ref('default'); // 'default' | 'custom'
const voiceUploadStatus = ref('idle'); // idle | previewing | uploading | transcribing | ready | error
const voicePreviewBlob = ref(null);
const voicePreviewUrl = ref(null);
const voicePreviewName = ref('');
const voicePreviewRef = ref(null);
const voicePreviewPlaying = ref(false);
const voicePreviewCurrent = ref(0);
const voicePreviewDuration = ref(0);
const voiceUploadAsset = ref(null);
const voiceUploadError = ref('');
const voiceRecording = ref(false);
const voiceRecorder = ref(null);
const voiceRecordedChunks = ref([]);
const voiceRecordSeconds = ref(0);
let voiceRecordTimer = null;
let voiceTranscribePoller = null;
const mediaPickerVisible = ref(false);
const mediaPickerMode = ref("visual"); // 'visual' | 'music' | 'sound'
// When the add-scene panel opened the picker, route selections into the
// add-scene buffer instead of assigning to the currently-active scene.
const addSceneAssetPickerActive = ref(false);
const musicPanelTab = ref("library"); // 'library' | 'uploads' | 'ai'

// AI music regen — calls /scenes/{id}/regenerate-music which fires
// GenerateAIMusicJob and swaps the scene's sound_asset_id when complete.
const aiMusicMood = ref("");
const aiMusicPending = ref(false);
const aiMusicError = ref("");
const AI_MUSIC_MOODS = [
  "calm acoustic",
  "upbeat indie pop",
  "cinematic ambient",
  "lo-fi chill",
  "tense electronic",
  "inspiring orchestral",
  "warm folk",
  "energetic synth",
  "hopeful piano",
];

async function regenerateAIMusic() {
  if (!activeScene.value?.id || aiMusicPending.value) return;
  const mood = aiMusicMood.value.trim();
  if (!mood) return;
  aiMusicPending.value = true;
  aiMusicError.value = "";
  try {
    await api.post(`/scenes/${activeScene.value.id}/regenerate-music`, {
      mood,
      duration_seconds: Math.max(3, Math.min(30, Math.round(activeScene.value.duration_seconds || 8))),
    });
    // pollSceneUntilVisual watches the scene's image_generation state
    // (in_progress / animation_in_progress / visual_asset) — not music.
    // Music lands on project.music_asset_id, so spawn a parallel poller
    // that refreshes the project + music-tracks list until the id flips.
    const beforeId = project.value?.music_asset_id ?? null;
    pollProjectMusicUntilNew(beforeId);
    pushToast({ id: `ai-music-queued-${activeScene.value.id}-${Date.now()}`,
                title: '✦ Generating music', message: `Mood: "${mood}". Ready in ~30s.` });
  } catch (e) {
    aiMusicError.value = e.response?.data?.error?.message ?? 'Music generation failed.';
    aiMusicPending.value = false;
  }
  // NOTE: don't clear aiMusicPending here — the music job is async.
  // pollProjectMusicUntilNew clears it when the new music_asset_id lands
  // (or after the timeout). Clearing here would re-enable the Generate
  // button while the job is still running.
}

// Poll the project until music_asset_id changes from its pre-regen value
// (or we hit the ceiling). When it changes: refresh musicTracks so the
// new "AI Music — …" track appears in the picker, point the active
// selection at it, and toast the user. ~30s ceiling matches the
// MusicGen typical-runtime; loud-toast on timeout so the user knows.
async function pollProjectMusicUntilNew(beforeId, attempt = 0) {
  const MAX = 30;
  if (attempt >= MAX) {
    aiMusicPending.value = false;
    pushToast({ id: `ai-music-timeout-${Date.now()}`,
                title: 'Music taking longer than expected',
                message: 'Refresh in a moment — it should arrive shortly.' });
    return;
  }
  window.setTimeout(async () => {
    try {
      const res = await api.get(`/projects/${project.value.id}`);
      const proj = res.data?.data?.project;
      const newId = proj?.music_asset_id ?? null;
      if (proj && newId && newId !== beforeId) {
        // New music asset is live. Refresh the picker + bind to it so the
        // editor shows the new track immediately.
        project.value = { ...project.value, music_asset_id: newId, music_settings_json: proj.music_settings_json };
        await loadMusicTracks();
        selectedMusicTrackId.value = newId;
        aiMusicPending.value = false;
        pushToast({ id: `ai-music-done-${newId}`,
                    title: '✦ Music ready',
                    message: 'New AI track loaded — preview to hear it.' });
        return;
      }
    } catch {
      // Transient — keep polling.
    }
    pollProjectMusicUntilNew(beforeId, attempt + 1);
  }, attempt < 4 ? 2500 : 5000);
}
const musicVolume = ref(30);
const musicDuckVolume = ref(8);
const musicFadeInMs = ref(500);
const musicLoop = ref(true);
const musicDuckDuringVoice = ref(true);
let musicSaveTimer = null;
const musicSaveState = ref("idle");
const musicSaveError = ref("");
// Per-scene voice + sound volumes (0-100, default 100)
const sceneVoiceVolume = ref(100);
const sceneSoundVolume = ref(100);
let sceneVoiceVolumeSaveTimer = null;
let sceneSoundVolumeSaveTimer = null;
// Audiogram settings
const audiogramStyle = ref("bars");
const audiogramColor = ref("#ff6b35");
const audiogramBg = ref("dark");
let audiogramSaveTimer = null;
const audiogramSaveState = ref("idle");

const AUDIOGRAM_STYLES = [
  { key: "bars",    label: "Classic" },
  { key: "mirror",  label: "Mirror"  },
  { key: "circle",  label: "Radial"  },
  { key: "minimal", label: "Minimal" },
];
const AUDIOGRAM_COLORS = [
  "#ff6b35", "#60a5fa", "#34d399", "#a78bfa", "#f472b6", "#fbbf24", "#ffffff",
];
const AUDIOGRAM_BACKGROUNDS = [
  { key: "dark",   label: "Dark",   css: "linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)" },
  { key: "black",  label: "Black",  css: "#000" },
  { key: "purple", label: "Purple", css: "linear-gradient(135deg,#1a0a2e 0%,#0d0d2b 50%,#14102a 100%)" },
  { key: "ocean",  label: "Ocean",  css: "linear-gradient(135deg,#0a1628 0%,#0d1f3c 50%,#0a0e1a 100%)" },
];
const voiceProfileKey = ref("alloy");
const voiceSpeedDraft = ref("1.0");
const voiceStabilityDraft = ref("medium");
const voiceSaveState = ref("idle");
const voiceSaveError = ref("");
const visualQueryDraft = ref("");
const visualSwapPending = ref(false);
const visualSwapError = ref("");
// AI Image generation
const aiImagePromptOverride = ref("");
const aiImagePending = ref(false);
// Image model picker for the editor's regen modal. Fetched from
// /api/v1/image-models on mount; static fallback so the dropdown
// still renders if the API call fails.
const aiImageModelKey = ref('gpt-image-1');
const availableImageModelsEditor = ref([
  { key: 'gpt-image-1',    label: 'GPT Image 1',    sub: 'OpenAI photoreal',   cost: 15, render: '~20s', requires_reference: false },
  { key: 'gpt-image-2',    label: 'GPT Image 2',    sub: 'OpenAI · newer',      cost: 35, render: '~30s', requires_reference: false },
  { key: 'nano-banana',    label: 'Nano Banana',    sub: 'Google · cheap',      cost:  8, render: '~10s', requires_reference: false },
  { key: 'flux-schnell',   label: 'Flux Schnell',   sub: 'cheapest',            cost:  1, render: '~5s',  requires_reference: false },
  { key: 'sdxl-lightning', label: 'SDXL Lightning', sub: 'stylish',             cost:  1, render: '~5s',  requires_reference: false },
]);
const activeImageModelMeta = computed(
  () => availableImageModelsEditor.value.find((m) => m.key === aiImageModelKey.value) ?? null,
);
const activeSceneHasCharacter = computed(() => Boolean(activeScene.value?.character_id));
const aiImageError = ref("");

// Animate (i2v rung 4) state.
const animateModalOpen = ref(false);
const animateTier = ref("quick");          // quick | balanced | premium | seedance_lite | seedance_pro
const animateDuration = ref(5);            // value depends on tier — see ANIMATE_TIER_DURATIONS
const animateMotionPrompt = ref("");
const animateSubmitting = ref(false);
const animateError = ref("");
// Credits per short clip; long clip doubles. Mirror the backend cost calc exactly.
const ANIMATE_TIER_COSTS_5S = { quick: 60, balanced: 120, premium: 240, seedance_lite: 100, seedance_pro: 200 };
// Valid durations per tier — each upstream model accepts only specific values.
//   Wan 2.5 (quick)         → 5 or 10
//   Hailuo 2.3-fast (balanced) → 6 or 10 (NOT 5; sending 5 returns Replicate 422)
//   Kling 2.1 (premium)     → 5 or 10
const ANIMATE_TIER_DURATIONS = { quick: [5, 10], balanced: [6, 10], premium: [5, 10], seedance_lite: [5, 10], seedance_pro: [5, 10] };
const animateDurations = computed(() => ANIMATE_TIER_DURATIONS[animateTier.value] || [5, 10]);
const animateShortDuration = computed(() => animateDurations.value[0]);
const animateCost = computed(() =>
  // "Long" clip = the larger of the two valid durations (always 10 today).
  ANIMATE_TIER_COSTS_5S[animateTier.value] * (animateDuration.value === animateDurations.value[1] ? 2 : 1)
);
// Animation models. We expose actual model names (Wan/Hailuo/Kling/Seedance)
// instead of generic tier labels so power users know what they're picking.
// `key` still maps to the backend tier so the API doesn't need to change.
const ANIMATE_TIER_META = {
  quick:         { name: "Wan 2.5",       sub: "Fast · cheap",      quality: "Good",       render: "~30s" },
  seedance_lite: { name: "Seedance Lite", sub: "ByteDance · cheap", quality: "Strong",     render: "~45s" },
  balanced:      { name: "Hailuo 2.3",    sub: "Best for most",     quality: "Strong",     render: "~90s" },
  seedance_pro:  { name: "Seedance Pro",  sub: "ByteDance · sharp", quality: "Very high",  render: "~2 min" },
  premium:       { name: "Kling 2.1",     sub: "Cinematic",         quality: "Top",        render: "~3 min" },
};

// When the user switches tier, snap the chosen duration to one the new tier
// actually supports. Prevents the 422 ("duration must be one of: 6, 10") that
// fired when balanced inherited the 5s default from quick.
watch(animateTier, () => {
  if (!animateDurations.value.includes(animateDuration.value)) {
    animateDuration.value = animateDurations.value[0];
  }
});
// Quick-pick prompts for the animate modal — covers the motion patterns most
// faceless creators reach for. Click sets the prompt; user can still edit.
const ANIMATE_PROMPT_SUGGESTIONS = [
  { label: "Subtle motion",   text: "subtle natural motion, gentle breathing, hair drifting" },
  { label: "Slow push-in",    text: "slow camera push-in, cinematic depth, dramatic lighting" },
  { label: "Dolly back",      text: "slow dolly back revealing the scene, smooth camera move" },
  { label: "Pan left",        text: "smooth camera pan from right to left, parallax background" },
  { label: "Wind & weather",  text: "wind blowing through hair and fabric, drifting fog or dust" },
  { label: "Dramatic zoom",   text: "fast dramatic zoom-in on the subject, intense lighting" },
];

// Workspace-level characters: name + description + optional reference image.
// Selecting one binds it to the active scene; the AI image prompt picks up the description.
// Reference image: shown as the chip/grid thumbnail today; used by IP-Adapter when wired later.
const characters = ref([]);
const characterPopoverOpen = ref(false);
const createCharacterOpen = ref(false);
const createCharacterName = ref("");
const createCharacterDescription = ref("");
const createCharacterFile = ref(null);
const createCharacterPreviewUrl = ref("");
const createCharacterSaving = ref(false);
const createCharacterError = ref("");
const AI_IMAGE_STYLES = [
  { key: "cinematic",     label: "Cinematic",     icon: "🎬" },
  { key: "dark",          label: "Dark",           icon: "🌑" },
  { key: "documentary",   label: "Documentary",    icon: "📽️" },
  { key: "anime",         label: "Anime",          icon: "🌸" },
  { key: "minimalist",    label: "Minimalist",     icon: "◽" },
  { key: "realistic",     label: "Realistic",      icon: "📸" },
  { key: "vintage",       label: "Vintage",        icon: "🌅" },
  { key: "neon",          label: "Neon",           icon: "⚡" },
  { key: "photorealistic",label: "Photorealistic", icon: "📷" },
  { key: "cyberpunk_80s", label: "Cyberpunk 80s",  icon: "🕹️" },
  { key: "anime_80s",     label: "Anime 80s",      icon: "🌟" },
  { key: "anime_90s",     label: "Anime 90s",      icon: "💫" },
  { key: "dark_fantasy",  label: "Dark Fantasy",   icon: "🐉" },
  { key: "fantasy_retro", label: "Fantasy Retro",  icon: "🧙" },
  { key: "comic",         label: "Comic",          icon: "💥" },
  { key: "film_noir",     label: "Film Noir",      icon: "🎩" },
  { key: "line_drawing",  label: "Line Drawing",   icon: "✏️" },
  { key: "watercolor",    label: "Watercolor",     icon: "🎨" },
  { key: "paper_cutout",  label: "Paper Cutout",   icon: "✂️" },
  { key: "cartoon",       label: "Cartoon",        icon: "🎭" },
  { key: "3d_animated",  label: "3D Animated",    icon: "🎬" },
  // Custom — last so users see the preset library first. Picking this
  // reveals a textarea where they type the descriptor that replaces the
  // preset string in every scene's image prompt for this scene.
  { key: "custom",       label: "Custom",         icon: "✦" },
];

// Style catalog from /api/v1/image-styles — same keys + labels + a
// sample_url thumbnail rendered by `php artisan generate:style-samples`
// and stored in B2 at style-samples/<key>.jpg. Static AI_IMAGE_STYLES
// stays as a fallback when the API call fails or hasn't returned yet.
const richImageStyles = ref([]);
const imageStylesByKey = computed(() => {
  const map = new Map();
  for (const s of richImageStyles.value) map.set(s.key, s);
  // Layer the static list on top so 'custom' (not in META) still renders.
  for (const s of AI_IMAGE_STYLES) {
    if (!map.has(s.key)) map.set(s.key, { ...s, sample_url: null, description: '' });
  }
  return map;
});
const stylePickerRows = computed(() => Array.from(imageStylesByKey.value.values()));

async function loadImageStyleCatalog() {
  try {
    const res = await api.get('/image-styles');
    const styles = res?.data?.data?.styles;
    if (Array.isArray(styles)) richImageStyles.value = styles;
  } catch { /* keep static fallback */ }
}

// Open-state refs for the three style-picker dropdowns:
//   stylePickerOpen           – main editor's AI image panel
//   topAddStylePickerOpen     – "Add scene" panel at the top of the editor
//   perSceneAddStyleOpenId    – inline "Add scene" rendered under each scene
//                                (per-scene because there are N of them)
const stylePickerOpen = ref(false);
const topAddStylePickerOpen = ref(false);
const perSceneAddStyleOpenId = ref(null);

const exportPending = ref(false);

// Public share state for the export header's "🔗 Share publicly" button.
// project.is_shared + project.share_url come from the project serializer.
// First click enables share + copies; subsequent clicks just re-copy.
const shareTogglePending = ref(false);
const shareCopiedToast = ref('');
let shareCopiedTimer = null;
async function toggleShareLink() {
  if (!project.value?.id || shareTogglePending.value) return;
  shareTogglePending.value = true;
  try {
    // Idempotent: if already shared, this is a no-op server-side and
    // returns the existing share_url for us to copy.
    const res = await api.post(`/projects/${project.value.id}/share`, { enabled: true });
    const url = res.data?.data?.share_url;
    if (url) {
      // Mutate the local project state so the button label flips
      // immediately, then copy.
      project.value = { ...project.value, is_shared: true, share_token: res.data.data.share_token, share_url: url };
      try {
        await navigator.clipboard.writeText(url);
        shareCopiedToast.value = 'Copied!';
      } catch {
        // Some browsers gate clipboard behind permissions; show URL inline
        // so the user can copy manually.
        shareCopiedToast.value = url;
      }
      if (shareCopiedTimer) window.clearTimeout(shareCopiedTimer);
      shareCopiedTimer = window.setTimeout(() => { shareCopiedToast.value = ''; }, 3000);
    }
  } catch (e) {
    pushToast({ id: `share-fail-${Date.now()}`, title: 'Could not generate share link', message: e?.response?.data?.error?.message ?? 'Try again in a moment.' });
  } finally {
    shareTogglePending.value = false;
  }
}
const exportState = ref("idle");
const timelineOpen = ref(false);
watch(timelineOpen, (open) => {
  if (open) sidebarStore.collapse();
  else sidebarStore.restore();
});
const previewMode = ref("scene");
const stockVideoSubType = ref("stock_clip");
const playProgress = ref(0);
const isPreviewPlaying = ref(false);
let previewPlayTimer = null;
let pendingPreviewAudioOffset = 0;
const musicMoodFilter = ref("all");
const queuedExportJobId = ref(null);
let exportPollTimer = null;
const scriptSaveState = ref("idle");
const scriptSaveError = ref("");
const captionEnabledDraft = ref(true);
const captionStyleDraft = ref("impact");
const captionHighlightDraft = ref("keywords");
const captionPositionDraft = ref("bottom_third");
const DEFAULT_CAPTION_FONT = "Bebas Neue";
const DEFAULT_CAPTION_SETTINGS = Object.freeze({
  enabled: true,
  style_key: "impact",
  highlight_mode: "keywords",
  position: "bottom_third",
  font: DEFAULT_CAPTION_FONT,
  highlight_color: "#ff6b35",
  color: "#ffffff",
  size: "medium",
  preset_id: null,
});
const captionFontDraft = ref(DEFAULT_CAPTION_FONT);
const captionColorDraft = ref("#ffffff");
const captionSizeDraft = ref("medium");
const captionHighlightColorDraft = ref("#ff6b35");
const CAPTION_COLOR_SWATCHES = ["#ffffff","#ffff00","#ff6b35","#ff4444","#44ff88","#44aaff","#cc88ff","#000000"];
const CAPTION_SIZE_MAP = { small: "13px", medium: "17px", large: "23px", xlarge: "30px" };
const fontDropdownOpen = ref(false);
const captionSaveState = ref("idle");
const captionSaveError = ref("");
const CAPTION_FONT_GROUPS = [
  { label: "Bold Display", fonts: ["Bebas Neue", "Days One", "Passion One", "Fredoka One", "Luckiest Guy", "New Rocker", "Aladin"] },
  { label: "Sans-serif", fonts: ["Montserrat", "Raleway", "Lato", "Nunito", "Quicksand", "Noto Sans", "Liberation Sans", "Nimbus Sans"] },
  { label: "Serif", fonts: ["Playfair Display", "Roboto Slab", "Libre Baskerville", "Liberation Serif", "Nimbus Roman", "Century Schoolbook L"] },
  { label: "Script", fonts: ["Dancing Script", "Sacramento", "Satisfy", "Shadows Into Light"] },
  { label: "Mono", fonts: ["Roboto Mono", "Source Code Pro", "Orbitron", "Liberation Mono", "Nimbus Mono PS"] },
  { label: "Handwritten", fonts: ["Permanent Marker", "Amatic SC", "Indie Flower", "Rock Salt", "Calligraffitti"] },
];
const motionEffectDraft = ref("zoom_in");
const motionIntensityDraft = ref("moderate");
const motionSaveState = ref("idle");
const motionSaveError = ref("");
const visualStyleDraft = ref(null);
let visualStyleSaveTimer = null;
const visualStyleSaveState = ref("idle");
// Free-text descriptor for the "custom" style. Lives on scene.custom_visual_style.
// Saved with a similar debounce pattern as captions / visual_style.
const customVisualStyleDraft = ref("");
let customVisualStyleSaveTimer = null;
const customVisualStyleSaveState = ref("idle");
// All panels collapsed by default — users open only what they need.
// Cuts visual clog; the badges/tags on each header already surface state at a glance.
const panelState = ref({
  script: true,
  visual: true,
  motion: true,
  voice: true,
  sounds: true,
  captions: true,
  music: true,
  brand: true,
  project: true,
});
const sceneScriptDraft = ref("");
let scriptSaveTimer = null;
let voiceSaveTimer = null;
let captionSaveTimer = null;
let motionSaveTimer = null;
let beforeUnloadHandler = null;

const activeScene = computed(
  () => scenes.value.find((scene) => scene.id === activeSceneId.value) ?? null
);
const activeSceneVisualUrl = computed(
  () => activeScene.value?.visual_asset?.storage_url ?? null
);
const activeSceneVisualAsset = computed(() => activeScene.value?.visual_asset ?? null);
const activeSceneVisualType = computed(
  () => String(activeScene.value?.visual_type || "")
);
function sceneHasResolvedAIImage(scene) {
  if (!scene || String(scene.visual_type || "") !== "ai_image") return false;
  return Boolean(scene.visual_asset || scene.visual_asset_id);
}

const activeSceneAIImagePending = computed(() => {
  const scene = activeScene.value;
  if (!scene) return false;
  // Treat the scene as pending ONLY when the backend has explicitly flagged
  // it as in-progress (image_generation_settings_json.in_progress = true).
  //
  // Previous logic returned `!settings.needs_visual` as a fallback, which
  // evaluated to `true` for any scene with visual_type=ai_image and no
  // settings object — meaning a scene whose generation job silently failed
  // to write back would show "AI Generating..." forever, with no recovery.
  // Seen on prod 2026-06-02 (project 20, scene 187): orphaned asset, NULL
  // settings, infinite spinner.
  const settings = scene.image_generation_settings;
  if (!settings) return false;
  return Boolean(settings.in_progress);
});

// True while an i2v animation job is running for the active scene.
const activeSceneAnimationPending = computed(() => {
  const settings = activeScene.value?.image_generation_settings ?? {};
  return Boolean(settings.animation_in_progress);
});
const activeSceneAnimationError = computed(() => {
  return activeScene.value?.image_generation_settings?.animation_last_error || "";
});
const canAnimateActiveScene = computed(() => {
  // Allow animate when scene has any visual asset. When it's already a video
  // (a previous animation), the button labels itself "Re-animate" and re-runs
  // i2v against the latest still — or against the same source if we tracked it.
  return Boolean(activeScene.value?.visual_asset_id);
});
const activeSceneAlreadyAnimated = computed(() => {
  const asset = activeScene.value?.visual_asset;
  if (!asset) return false;
  return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
});

// When switching to a scene that has in_progress=true, auto-start the poll
// so the UI unsticks itself even if Reverb dropped the event
watch(activeSceneAIImagePending, (pending) => {
  if (pending && !aiImagePending.value) {
    aiImagePending.value = true;
    if (activeScene.value?.id) pollSceneUntilVisual(activeScene.value.id);
  }
});
const activeSceneVisualGenerationError = computed(() => {
  const scene = activeScene.value;
  const settings = scene?.image_generation_settings;
  if (sceneHasResolvedAIImage(scene) && !settings?.in_progress) return "";
  if (!settings?.needs_visual) return "";
  return (
    settings.last_error ||
    "Image generation failed. Please revise the prompt and try again."
  );
});
const activeSceneVisualIsVideo = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
});
// True only when the scene visual is a user library asset (not AI broll, not stock/matched)
const activeSceneVisualIsFromLibrary = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  if (activeScene.value?.visual_type === "ai_image") return false;
  const tags = Array.isArray(asset.tags) ? asset.tags : [];
  return !tags.includes("matched_visual");
});
const activeSceneAudioUrl = computed(
  () => activeScene.value?.audio_asset?.storage_url ?? null
);
const activeSceneSoundAsset = computed(() => activeScene.value?.sound_asset ?? null);
const activeSceneSoundUrl = computed(() => activeSceneSoundAsset.value?.storage_url ?? null);
const selectedProjectChannel = computed(() =>
  channels.value.find((channel) => String(channel.id) === String(projectChannelId.value)) || null
);
const activeMusicTrack = computed(() =>
  musicTracks.value.find((t) => t.id === selectedMusicTrackId.value) ?? null
);
const activeMusicTrackUrl = computed(() => activeMusicTrack.value?.storage_url ?? null);
const auditionMusicTrack = computed(() =>
  musicTracks.value.find((t) => t.id === auditionMusicTrackId.value) ?? null
);
const previewMusicVolume = computed(() => {
  const rawVolume =
    musicDuckDuringVoice.value && activeSceneAudioUrl.value && isPreviewPlaying.value && isAudioPlaying.value
      ? musicDuckVolume.value
      : musicVolume.value;

  return Math.max(0, Math.min(1, Number(rawVolume || 0) / 100));
});
const filteredMusicTracks = computed(() => {
  if (musicMoodFilter.value === "all") return musicTracks.value;
  return musicTracks.value.filter((t) =>
    (t.tags ?? []).some((tag) => tag.toLowerCase() === musicMoodFilter.value)
  );
});
const myImageAssetsFiltered = computed(() => {
  const q = myImageSearch.value.toLowerCase();
  if (!q) return myImageAssets.value;
  return myImageAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
});

const customAudioAssetsFiltered = computed(() => {
  const q = customAudioSearch.value.toLowerCase();
  if (!q) return customAudioAssets.value;
  return customAudioAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
});

const musicTrackGroups = computed(() => {
  const groups = {};
  for (const track of filteredMusicTracks.value) {
    const mood = track.tags?.find((tag) => tag !== "music") ?? "other";
    if (!groups[mood]) groups[mood] = [];
    groups[mood].push(track);
  }

  return Object.entries(groups).map(([mood, tracks]) => ({ mood, tracks }));
});
const activeSceneVisualLoaded = computed(() => {
  const sceneId = activeSceneId.value;
  if (!sceneId || !activeSceneVisualUrl.value) return false;
  return Boolean(mediaCache.value.visual[sceneId]?.loaded);
});
const isVisualLoading = computed(() => {
  if (!activeSceneVisualUrl.value) return false;
  return !activeSceneVisualLoaded.value && !visualLoadFailed.value;
});
const showTextCardPreview = computed(
  () => !currentVisualUrl.value && activeSceneVisualType.value === "text_card"
);
const showWaveformPreview = computed(
  () => activeSceneVisualType.value === "waveform"
);
const waveformLive = ref([0.28, 0.52, 0.34, 0.76, 0.42, 0.88, 0.48, 0.66, 0.31, 0.58, 0.40, 0.72, 0.35, 0.65]);
let waveformRafId = null;

// Web Audio API state — read the live mix from narration and music elements
let waveformAudioCtx = null;
let waveformAnalyser = null;
let waveformConnectedCount = 0;
let waveformUserActivated = false;
const waveformConnectedEls = new WeakMap();

// Simulation fallback state (speech-like energy distribution)
let waveformSimBands = new Array(14).fill(0.05);
let waveformSimLastMs = 0;

function waveformEnsureContext() {
  if (waveformAudioCtx && waveformAnalyser) return true;

  try {
    waveformAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    waveformAnalyser = waveformAudioCtx.createAnalyser();
    waveformAnalyser.fftSize = 128;
    waveformAnalyser.smoothingTimeConstant = 0.82;
    waveformAnalyser.connect(waveformAudioCtx.destination);
    return true;
  } catch {
    waveformAudioCtx = null;
    waveformAnalyser = null;
    return false;
  }
}

function mediaCrossOriginMode(url) {
  if (!url) return null;

  try {
    const resolved = new URL(url, window.location.origin);
    return resolved.origin === window.location.origin || resolved.origin === API_ORIGIN
      ? MEDIA_ELEMENT_CROSS_ORIGIN
      : null;
  } catch {
    return null;
  }
}

function unlockWaveformAudio() {
  waveformUserActivated = true;
  if (!waveformEnsureContext()) return false;

  if (waveformAudioCtx?.state === "suspended") {
    waveformAudioCtx.resume().catch(() => {});
  }

  waveformTryConnect();
  return true;
}

function waveformTryConnectElement(el) {
  if (!el || waveformConnectedEls.has(el)) return Boolean(el && waveformConnectedEls.has(el));
  if (!waveformUserActivated || !waveformAudioCtx || !waveformAnalyser) return false;
  if (!mediaCrossOriginMode(el.currentSrc || el.src)) return false;

  try {
    const src = waveformAudioCtx.createMediaElementSource(el);
    src.connect(waveformAnalyser);
    waveformConnectedEls.set(el, src);
    waveformConnectedCount += 1;
    return true;
  } catch {
    // SecurityError (CORS) or already connected elsewhere — fall through to simulation
    return false;
  }
}

function waveformTryConnect() {
  return [audioRef.value, musicAudioRef.value].some((el) => waveformTryConnectElement(el));
}

function waveformHasActiveSource() {
  const narrationPlaying = Boolean(audioRef.value && !audioRef.value.paused && activeSceneAudioUrl.value);
  const musicPlaying = Boolean(musicAudioRef.value && !musicAudioRef.value.paused && activeMusicTrackUrl.value);
  return narrationPlaying || musicPlaying;
}

function tickWaveform(ts) {
  const playing = isPreviewPlaying.value;
  const audioActive = playing && waveformHasActiveSource();

  if (audioActive && waveformConnectedCount > 0 && waveformAnalyser) {
    // Real frequency data from the active audio mix
    waveformTryConnect();
    if (waveformAudioCtx?.state === "suspended") waveformAudioCtx.resume().catch(() => {});
    const data = new Uint8Array(waveformAnalyser.frequencyBinCount);
    waveformAnalyser.getByteFrequencyData(data);
    const count = waveformLive.value.length;
    const usableBins = Math.max(count, Math.floor(data.length * 0.8));
    const binsPerBar = Math.max(1, Math.floor(usableBins / count));

    // Average small frequency ranges so voice and music both produce stable motion.
    waveformLive.value = Array.from({ length: count }, (_, i) => {
      const start = i * binsPerBar;
      const end = i === count - 1 ? usableBins : Math.min(usableBins, start + binsPerBar);
      let total = 0;

      for (let bin = start; bin < end; bin += 1) {
        total += data[bin] ?? 0;
      }

      const avg = total / Math.max(1, end - start);
      return Math.max(0.04, Math.min(1, avg / 180));
    });
  } else if (playing) {
    // Fallback: speech-like pseudo-reactive simulation
    // Refresh target band heights every ~80 ms with a realistic speech energy envelope
    if (!waveformSimLastMs || ts - waveformSimLastMs > 80) {
      waveformSimLastMs = ts;
      const energy = 0.3 + Math.random() * 0.7;
      const n = waveformSimBands.length;
      waveformSimBands = waveformSimBands.map((_, i) => {
        const pos = i / n;
        // Energy peaks in low-mid range (speech formants), tapers at extremes
        const envelope = Math.max(0, 1 - Math.pow(pos * 2.5 - 0.5, 2) * 2.2);
        return Math.max(0.06, Math.min(1, energy * envelope * (0.3 + Math.random() * 0.7)));
      });
    }
    // Lerp smoothly toward target heights
    waveformLive.value = waveformLive.value.map((cur, i) => cur + (waveformSimBands[i] - cur) * 0.28);
  } else {
    // Paused or stopped: decay to near-zero idle dots
    waveformLive.value = waveformLive.value.map(v => Math.max(0.04, v * 0.75));
  }

  waveformRafId = requestAnimationFrame(tickWaveform);
}

function startWaveformAnimation() {
  if (!waveformRafId) {
    waveformRafId = requestAnimationFrame(tickWaveform);
  }
}

function stopWaveformAnimation() {
  if (waveformRafId) { cancelAnimationFrame(waveformRafId); waveformRafId = null; }
}
const audiogramBgStyle = computed(() => {
  const bg = AUDIOGRAM_BACKGROUNDS.find(b => b.key === audiogramBg.value);
  return { background: bg ? bg.css : AUDIOGRAM_BACKGROUNDS[0].css };
});
const activeVoiceName = computed(() => {
  const voiceId = activeScene.value?.voice_settings?.voice_id;
  const match = voiceProfiles.value.find(
    (profile) => profile.provider_voice_key === voiceId
  );
  if (match?.name) return match.name;
  return voiceId ? voiceId.charAt(0).toUpperCase() + voiceId.slice(1) : "Default";
});
const activeVoiceSpeed = computed(
  () => activeScene.value?.voice_settings?.speed ?? 1.0
);
const activeVoiceOutdated = computed(
  () => Boolean(activeScene.value?.voice_settings?.is_outdated)
);
// Hook options sorted by score descending; unscored hooks go last.
const sortedHookOptions = computed(() =>
  [...hookOptions.value].sort((a, b) => {
    const sa = a.hook_score ?? -1;
    const sb = b.hook_score ?? -1;
    return sb - sa;
  })
);
// True when the active scene's visual is a still image (not video, text card, or waveform).
// Ken Burns motion controls only appear in this state.
const activeSceneIsStillImage = computed(() => {
  if (!activeScene.value) return false;
  const vtype = String(activeScene.value.visual_type || "");
  if (vtype === "text_card" || vtype === "waveform") return false;
  if (!activeScene.value.visual_asset) return false;
  return !activeSceneVisualIsVideo.value;
});
const activeMotionClass = computed(() => {
  if (!activeSceneIsStillImage.value) return "";
  const effect = motionEffectDraft.value;
  if (effect === "static") return "";
  return "kb-" + effect.replace(/_/g, "-");
});
const activeSceneIndex = computed(() =>
  scenes.value.findIndex((scene) => scene.id === activeSceneId.value)
);
// Preview box matches the project's real aspect ratio (was locked to 9:16).
// Fit within a 480×480 bounding box so the layout stays stable across ratios.
const previewContainerStyle = computed(() => {
  const ratio = project.value?.aspect_ratio || "9:16";
  const [w, h] = { "9:16": [9, 16], "16:9": [16, 9], "1:1": [1, 1] }[ratio] || [9, 16];
  const box = 480;
  const scale = box / Math.max(w, h);
  return { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` };
});
const projectTitle = computed(
  () => project.value?.title || `Project #${projectId.value}`
);
const editingTitle = ref(false);
const titleDraft = ref('');
const titleSaving = ref(false);

async function startEditTitle() {
  titleDraft.value = project.value?.title || '';
  editingTitle.value = true;
  await nextTick();
  document.getElementById('editor-title-input')?.select();
}

async function commitTitle() {
  editingTitle.value = false;
  const title = titleDraft.value.trim();
  if (!title || title === project.value?.title) return;
  titleSaving.value = true;
  try {
    await api.patch(`/projects/${projectId.value}`, { title });
    if (project.value) project.value.title = title;
  } catch { /* revert silently */ } finally {
    titleSaving.value = false;
  }
}

const unreadCount = computed(() =>
  notifications.value.filter((item) => !item.is_read).length
);
const latestExportJob = computed(() => exportJobs.value[0] ?? null);
const latestExportDownloadUrl = computed(
  () => latestExportJob.value?.output_asset?.storage_url ?? null
);
// Resume-failed: scan scenes for image / animation failures so we can
// surface a banner that re-runs everything in one click. Mirrors the
// backend's needs_visual + animation_last_error classification in
// ProjectController::resumeFailed.
const failedScenes = computed(() => {
  const failed = [];
  for (const s of scenes.value) {
    const cfg = s.image_generation_settings ?? {};
    const imageBroken = !!cfg.needs_visual || (cfg.last_error && !s.visual_asset_id);
    const animBroken  = !!cfg.animation_last_error && !cfg.animation_video_asset_id;
    if (imageBroken) failed.push({ id: s.id, order: s.scene_order, kind: 'image' });
    else if (animBroken) failed.push({ id: s.id, order: s.scene_order, kind: 'animate' });
  }
  return failed;
});
const failedSceneCount = computed(() => failedScenes.value.length);
const resumePending = ref(false);
const resumeError = ref('');
async function resumeFailedScenes() {
  if (!project.value?.id || resumePending.value || failedSceneCount.value === 0) return;
  resumePending.value = true;
  resumeError.value = '';
  try {
    const res = await api.post(`/projects/${project.value.id}/resume-failed`);
    pushToast({
      id: `resume-${Date.now()}`,
      title: `Resuming ${res.data?.data?.resumed ?? failedSceneCount.value} scene${failedSceneCount.value === 1 ? '' : 's'}`,
      message: 'Re-dispatched the failed jobs — they\'ll finish in the background.',
    });
    // Pop active scene poller so the editor picks up the new state as
    // each scene completes.
    for (const f of failedScenes.value) pollSceneUntilVisual(f.id);
  } catch (e) {
    const code = e?.response?.status;
    if (code === 402) {
      resumeError.value = e.response.data?.error?.message ?? 'Not enough credits to resume.';
    } else {
      resumeError.value = e.response?.data?.error?.message ?? 'Resume failed.';
    }
  } finally {
    resumePending.value = false;
  }
}

const exportBlockerMessage = computed(() => {
  const VISUAL_OPTIONAL = ["text_card", "waveform"];
  for (const scene of scenes.value) {
    const label = scene.label || `Scene ${scene.scene_order ?? ""}`;
    if (!String(scene.script_text || "").trim()) {
      return `"${label}" has no script — add copy before exporting.`;
    }
    if (!scene.visual_asset_id && !VISUAL_OPTIONAL.includes(String(scene.visual_type || ""))) {
      return `"${label}" is missing a visual — pick a clip or image first.`;
    }
    if (!scene.voice_settings?.audio_asset_id) {
      return `"${label}" has no generated voice — wait for TTS or regenerate.`;
    }
  }
  return null;
});

// Per-section error indicators for the active scene
const activeSceneVisualError = computed(() => {
  const scene = activeScene.value;
  if (!scene) return null;
  const VISUAL_OPTIONAL = ['text_card', 'waveform'];
  if (!scene.visual_asset_id && !VISUAL_OPTIONAL.includes(String(scene.visual_type || '')))
    return 'No visual assigned — pick a stock clip, image, or generate with AI.'
  return null;
});

const activeSceneVoiceError = computed(() => {
  const scene = activeScene.value;
  if (!scene) return null;
  if (!scene.voice_settings?.audio_asset_id)
    return 'No voice generated — wait for TTS to finish or click Regenerate.'
  return null;
});

const activeSceneScriptError = computed(() => {
  const scene = activeScene.value;
  if (!scene) return null;
  if (!String(scene.script_text || '').trim())
    return 'Scene has no script — add copy before this scene can be exported.'
  return null;
});

const captionPreviewClass = computed(() => {
  if (!captionEnabledDraft.value || !captionsCanRender.value) return "caption-hidden";
  if (captionStyleDraft.value === "editorial") return "caption-style-editorial";
  if (captionStyleDraft.value === "hacker") return "caption-style-hacker";
  return "caption-style-impact";
});
const captionsCanRender = computed(() => {
  if (
    activeSceneAIImagePending.value ||
    activeSceneVisualGenerationError.value ||
    visualLoadFailed.value
  ) return false;
  if (activeSceneVisualUrl.value) return activeSceneVisualLoaded.value;
  return showTextCardPreview.value || showWaveformPreview.value;
});
const captionPositionStyle = computed(() => {
  if (captionPositionDraft.value === "center")
    return { top: "50%", transform: "translateY(-50%)", bottom: "auto" };
  if (captionPositionDraft.value === "top_third")
    return { top: "80px", bottom: "auto" };
  return {};
});
const flattenedCaptionFonts = computed(() =>
  CAPTION_FONT_GROUPS.flatMap((group) =>
    group.fonts.map((font) => ({ font, group: group.label }))
  )
);
const selectedCaptionFont = computed(
  () =>
    flattenedCaptionFonts.value.find((item) => item.font === captionFontDraft.value) || {
      font: captionFontDraft.value || DEFAULT_CAPTION_FONT,
      group: "Custom",
    }
);
const captionFontStyle = computed(() => ({
  fontFamily: fontFamilyValue(captionFontDraft.value || DEFAULT_CAPTION_FONT),
  fontSize: CAPTION_SIZE_MAP[captionSizeDraft.value] || "17px",
}));
const activeCaptionSettings = computed(
  () => activeScene.value?.caption_settings ?? activeScene.value?.caption_settings_json ?? {}
);

const sceneTypeOptions = [
  "Narration",
  "Hook",
  "Transition",
  "Text Card",
  "Quote",
];
const addSceneStockTypeOptions = ["Stock Clip", "BG Loop", "Text Only", "Audiogram"];
const visualSourceTypeMap = {
  "Stock Video": "stock_clip",
  "Stock Image": "image_montage",
  "Stock Clip": "stock_clip",
  "BG Loop": "background_loop",
  "AI Image": "ai_image",
  "Text Only": "text_card",
  Audiogram: "waveform",
};
const visualTypeLabelMap = {
  image_montage: "Stock Clip",
  stock_clip: "Stock Clip",
  background_loop: "BG Loop",
  ai_image: "AI Image",
  text_card: "Text Only",
  waveform: "Audiogram",
};
const rewriteOptions = [
  "Shorten",
  "Expand",
  "Stronger hook",
  "More punchy",
  "More educational",
  "More dramatic",
  "Scarier",
  "More documentary",
  "Simplify",
];
const rewriteModeMap = {
  Shorten: "shorten",
  Expand: "expand",
  "Stronger hook": "stronger_hook",
  "More punchy": "more_punchy",
  "More educational": "more_educational",
  "More dramatic": "more_dramatic",
  Scarier: "scarier",
  "More documentary": "more_documentary",
  Simplify: "simplify",
};

watch(
  activeScene,
  (scene, prevScene) => {
    const sceneChanged = scene?.id !== prevScene?.id;

    if (sceneChanged) {
      // Scene switch — cancel pending saves and reset all draft state
      if (scriptSaveTimer) {
        window.clearTimeout(scriptSaveTimer);
        scriptSaveTimer = null;
      }
      if (voiceSaveTimer) {
        window.clearTimeout(voiceSaveTimer);
        voiceSaveTimer = null;
      }
      if (captionSaveTimer) {
        window.clearTimeout(captionSaveTimer);
        captionSaveTimer = null;
      }
      sceneScriptDraft.value = scene?.script_text || "";
      voiceProfileKey.value = scene?.voice_settings?.voice_id || "alloy";
      voiceSpeedDraft.value = String(scene?.voice_settings?.speed ?? 1.0);
      voiceStabilityDraft.value = String(scene?.voice_settings?.stability ?? "medium");
      visualQueryDraft.value = scene?.visual_prompt || "";
      const rawType = String(scene?.visual_type || "");
      const assetTags = Array.isArray(scene?.visual_asset?.tags) ? scene.visual_asset.tags : [];
      const isLibraryAsset = scene?.visual_asset && !assetTags.includes("matched_visual") && rawType !== "ai_image";
      if (rawType === "ai_image") selectedSwapVisualSource.value = "AI Image";
      else if (rawType === "waveform") selectedSwapVisualSource.value = "Audiogram";
      else if (isLibraryAsset) selectedSwapVisualSource.value = "My Assets";
      else if (rawType === "image_montage") selectedSwapVisualSource.value = "Stock Image";
      else if (rawType === "background_loop") selectedSwapVisualSource.value = "Stock Video";
      else selectedSwapVisualSource.value = "Stock Video";
      // Restore audiogram settings
      const imgSettings = scene?.image_generation_settings ?? scene?.image_generation_settings_json ?? {};
      audiogramStyle.value = imgSettings.audiogram_style ?? "bars";
      audiogramColor.value = imgSettings.audiogram_color ?? "#ff6b35";
      audiogramBg.value = imgSettings.audiogram_bg ?? "dark";
      // Restore per-scene voice + sound volume
      const voiceSettingsForVol = scene?.voice_settings ?? scene?.voice_settings_json ?? {};
      sceneVoiceVolume.value = Math.max(0, Math.min(200, parseInt(voiceSettingsForVol.volume ?? 100, 10) || 100));
      const soundSettings = scene?.sound_settings_json ?? scene?.sound_settings ?? {};
      sceneSoundVolume.value = Math.max(0, Math.min(200, parseInt(soundSettings.volume ?? 100, 10) || 100));
      const captionSettings = normalizeCaptionSettings(
        scene?.caption_settings || scene?.caption_settings_json
      );
      captionEnabledDraft.value = captionSettings.enabled !== false;
      captionStyleDraft.value = String(captionSettings.style_key);
      captionHighlightDraft.value = String(captionSettings.highlight_mode);
      captionPositionDraft.value = String(captionSettings.position);
      captionFontDraft.value = String(captionSettings.font);
      captionColorDraft.value = captionSettings.color || "#ffffff";
      captionSizeDraft.value = captionSettings.size || "medium";
      captionHighlightColorDraft.value = captionSettings.highlight_color || "#ff6b35";
      fontDropdownOpen.value = false;
      const motionSettings = scene?.motion_settings || scene?.motion_settings_json || {};
      motionEffectDraft.value = String(motionSettings.effect || "zoom_in");
      motionIntensityDraft.value = String(motionSettings.intensity || "moderate");
      visualStyleDraft.value = scene?.visual_style ?? scene?.image_generation_settings?.style ?? project.value?.ai_broll_style ?? null;
      visualStyleSaveState.value = "idle";
      customVisualStyleDraft.value = scene?.custom_visual_style ?? project.value?.custom_visual_style ?? "";
      customVisualStyleSaveState.value = "idle";
      aiImagePending.value = false;
      aiImageError.value = "";
      visualSwapPending.value = false;
      visualSwapError.value = "";
      voiceSaveState.value = "idle";
      voiceSaveError.value = "";
      scriptSaveState.value = "idle";
      scriptSaveError.value = "";
      captionSaveState.value = "idle";
      captionSaveError.value = "";
      rewriteToolsVisible.value = true;
      rewritePreviewVisible.value = false;
      rewritePreviewCopy.value = "";
      rewriteCustomInstruction.value = "";
      rewriteMode.value = "";
      rewriteError.value = "";
      sceneDurationDraft.value = String(scene?.duration_seconds ?? "");
      sceneDurationSaving.value = false;
      // Reset and reload both visual and audio
      if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value.load();
        isAudioPlaying.value = false;
      }
      if (soundAudioRef.value) {
        soundAudioRef.value.pause();
        soundAudioRef.value.load();
      }
      isAudioLoading.value = false;
      visualLoadFailed.value = false;
      syncActiveSceneMedia(scene);
      if (isPreviewPlaying.value) {
        nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
      }
      return;
    }

    // Same scene updated — selectively reload only what changed
    const prevVisualUrl = prevScene?.visual_asset?.storage_url ?? null;
    const nextVisualUrl = scene?.visual_asset?.storage_url ?? null;
    const visualChanged =
      scene?.visual_asset_id !== prevScene?.visual_asset_id ||
      nextVisualUrl !== prevVisualUrl;

    const prevAudioUrl = prevScene?.audio_asset?.storage_url ?? null;
    const nextAudioUrl = scene?.audio_asset?.storage_url ?? null;
    const audioChanged = nextAudioUrl !== prevAudioUrl;
    const prevSoundUrl = prevScene?.sound_asset?.storage_url ?? null;
    const nextSoundUrl = scene?.sound_asset?.storage_url ?? null;
    const soundChanged = nextSoundUrl !== prevSoundUrl;

    if (visualChanged) {
      visualLoadFailed.value = false;
      syncActiveSceneMedia(scene);
      if (sceneHasResolvedAIImage(scene)) {
        aiImagePending.value = false;
        aiImageError.value = "";
      }
    }

    if (audioChanged) {
      if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value.load();
        isAudioPlaying.value = false;
      }
      isAudioLoading.value = false;
      if (scene?.audio_asset?.storage_url) {
        preloadSceneAudio(scene);
      }
      if (isPreviewPlaying.value) {
        nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
      }
    }

    if (soundChanged) {
      if (soundAudioRef.value) {
        soundAudioRef.value.pause();
        soundAudioRef.value.load();
      }
      if (isPreviewPlaying.value) {
        nextTick(() => playActiveSceneSound(pendingPreviewAudioOffset));
      }
    }
  },
  { immediate: true }
);

watch(activeMusicTrackUrl, () => {
  if (!musicAudioRef.value) return;
  musicAudioRef.value.pause();
  musicAudioRef.value.load();
  if (isPreviewPlaying.value) {
    nextTick(() => playPreviewMusic());
  }
});

watch(previewMusicVolume, () => {
  syncPreviewMusicVolume();
});

watch([voiceProfileKey, voiceSpeedDraft, voiceStabilityDraft], () => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedVoice = scene.voice_settings || {};
  const nextSettings = {
    voice_id: voiceProfileKey.value,
    speed: Number(voiceSpeedDraft.value || 1),
    stability: voiceStabilityDraft.value,
  };

  if (
    String(savedVoice.voice_id || "alloy") === nextSettings.voice_id &&
    Number(savedVoice.speed ?? 1) === nextSettings.speed &&
    String(savedVoice.stability || "medium") === nextSettings.stability
  ) {
    if (voiceSaveTimer) {
      window.clearTimeout(voiceSaveTimer);
      voiceSaveTimer = null;
    }
    if (voiceSaveState.value !== "saved") {
      voiceSaveState.value = "idle";
    }
    voiceSaveError.value = "";
    return;
  }

  if (voiceSaveTimer) {
    window.clearTimeout(voiceSaveTimer);
  }

  voiceSaveState.value = "pending";
  voiceSaveError.value = "";
  voiceSaveTimer = window.setTimeout(() => {
    persistVoiceSettings(scene.id, nextSettings);
  }, 500);
});

watch(sceneScriptDraft, (draft) => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedScript = scene.script_text || "";

  if (draft === savedScript) {
    if (scriptSaveTimer) {
      window.clearTimeout(scriptSaveTimer);
      scriptSaveTimer = null;
    }
    if (scriptSaveState.value !== "saved") {
      scriptSaveState.value = "idle";
    }
    scriptSaveError.value = "";
    return;
  }

  if (scriptSaveTimer) {
    window.clearTimeout(scriptSaveTimer);
  }

  scriptSaveState.value = "pending";
  scriptSaveError.value = "";

  scriptSaveTimer = window.setTimeout(() => {
    persistSceneScript(scene.id, draft);
  }, 700);
});

watch([captionEnabledDraft, captionStyleDraft, captionHighlightDraft, captionPositionDraft, captionFontDraft, captionColorDraft, captionSizeDraft, captionHighlightColorDraft], () => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedCaptions = activeCaptionSettings.value || {};
  const nextSettings = {
    enabled: captionEnabledDraft.value,
    style_key: captionStyleDraft.value,
    highlight_mode: captionHighlightDraft.value,
    position: captionPositionDraft.value,
    font: captionFontDraft.value,
    highlight_color: captionHighlightColorDraft.value,
    color: captionColorDraft.value,
    size: captionSizeDraft.value,
    preset_id: savedCaptions.preset_id || null,
  };

  if (
    (savedCaptions.enabled !== false) === nextSettings.enabled &&
    String(savedCaptions.style_key || "impact") === nextSettings.style_key &&
    String(savedCaptions.highlight_mode || "keywords") === nextSettings.highlight_mode &&
    String(savedCaptions.position || "bottom_third") === nextSettings.position &&
    String(savedCaptions.font || DEFAULT_CAPTION_FONT) === nextSettings.font &&
    String(savedCaptions.color || "#ffffff") === nextSettings.color &&
    String(savedCaptions.size || "medium") === nextSettings.size &&
    String(savedCaptions.highlight_color || "#ff6b35") === nextSettings.highlight_color
  ) {
    if (captionSaveTimer) {
      window.clearTimeout(captionSaveTimer);
      captionSaveTimer = null;
    }
    if (captionSaveState.value !== "saved") {
      captionSaveState.value = "idle";
    }
    captionSaveError.value = "";
    return;
  }

  if (captionSaveTimer) {
    window.clearTimeout(captionSaveTimer);
  }

  patchSceneCaptionSettings(scene.id, nextSettings);
  captionSaveState.value = "pending";
  captionSaveError.value = "";
  captionSaveTimer = window.setTimeout(() => {
    persistCaptionSettings(scene.id, nextSettings);
  }, 500);
});

watch([motionEffectDraft, motionIntensityDraft], () => {
  const scene = activeScene.value;
  if (!scene) return;

  const savedMotion = scene.motion_settings || scene.motion_settings_json || {};
  const nextSettings = {
    effect: motionEffectDraft.value,
    intensity: motionIntensityDraft.value,
  };

  if (
    String(savedMotion.effect || "zoom_in") === nextSettings.effect &&
    String(savedMotion.intensity || "moderate") === nextSettings.intensity
  ) {
    if (motionSaveTimer) {
      window.clearTimeout(motionSaveTimer);
      motionSaveTimer = null;
    }
    if (motionSaveState.value !== "saved") {
      motionSaveState.value = "idle";
    }
    motionSaveError.value = "";
    return;
  }

  if (motionSaveTimer) {
    window.clearTimeout(motionSaveTimer);
  }

  motionSaveState.value = "pending";
  motionSaveError.value = "";
  motionSaveTimer = window.setTimeout(() => {
    persistMotionSettings(scene.id, nextSettings);
  }, 500);
});

watch(visualStyleDraft, (nextStyle) => {
  const scene = activeScene.value;
  if (!scene) return;
  if ((nextStyle ?? null) === (scene.visual_style ?? null)) return;

  visualStyleSaveState.value = "pending";
  clearTimeout(visualStyleSaveTimer);
  visualStyleSaveTimer = setTimeout(async () => {
    try {
      const response = await api.patch(`/scenes/${scene.id}`, { visual_style: nextStyle });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      visualStyleSaveState.value = "saved";
    } catch {
      visualStyleSaveState.value = "idle";
    }
  }, 500);
});

watch(customVisualStyleDraft, (nextCustom) => {
  const scene = activeScene.value;
  if (!scene) return;
  const next = (nextCustom ?? "").trim();
  const prev = (scene.custom_visual_style ?? "").trim();
  if (next === prev) return;

  customVisualStyleSaveState.value = "pending";
  clearTimeout(customVisualStyleSaveTimer);
  customVisualStyleSaveTimer = setTimeout(async () => {
    try {
      const response = await api.patch(`/scenes/${scene.id}`, { custom_visual_style: next || null });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      customVisualStyleSaveState.value = "saved";
    } catch {
      customVisualStyleSaveState.value = "idle";
    }
  }, 600);
});

watch(
  [exportJobs, queuedExportJobId],
  ([jobs, pendingJobId]) => {
    if (!pendingJobId) return;

    const matchingJob = jobs.find((job) => job.id === pendingJobId);

    if (!matchingJob) return;

    if (
      matchingJob.status === "completed" &&
      matchingJob.output_asset?.storage_url
    ) {
      pushToast({
        id: `export-complete-${matchingJob.id}`,
        title: "Export ready",
        message: matchingJob.file_name || "Your rendered MP4 is ready.",
        created_at: new Date().toISOString(),
      });
      window.open(
        matchingJob.output_asset.storage_url,
        "_blank",
        "noopener,noreferrer"
      );
      queuedExportJobId.value = null;
      exportState.value = "idle";
      return;
    }

    if (matchingJob.status === "failed") {
      pushToast({
        id: `export-failed-${matchingJob.id}`,
        title: "Export failed",
        message: matchingJob.failure_reason || "Export failed.",
        created_at: new Date().toISOString(),
      });
      queuedExportJobId.value = null;
      exportState.value = "error";
    }
  },
  { deep: true }
);

// Poll export status while any job is pending — covers WebSocket gaps and
// server-side retried jobs that weren't initiated from this UI session.
const hasActiveExport = computed(() =>
  exportJobs.value.some((j) => ['queued', 'processing'].includes(j.status))
)

watch(
  () => queuedExportJobId.value || hasActiveExport.value,
  (active) => {
    if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
    if (active) {
      exportPollTimer = setInterval(() => loadExportJobs(), 5000);
    }
  },
  { immediate: true }
);

function humanizeSceneType(sceneType) {
  return String(sceneType || "narration")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function sceneTypeLabel(scene) {
  return humanizeSceneType(scene?.scene_type);
}

function sceneVisualLabel(scene) {
  if (scene?.visual_type === "text_card") return "text card";
  if (scene?.visual_type === "waveform") return "audiogram";
  if (scene?.visual_type === "ai_image") return "ai image";
  if (scene?.visual_type === "background_loop") return "bg loop";
  if (scene?.visual_type === "stock_clip") return "stock clip";
  if (scene?.visual_asset?.asset_type === "video") return "stock clip";
  if (scene?.visual_asset?.asset_type === "image") return "ai image";
  return "bg loop";
}

function selectedVisualType() {
  if (selectedSwapVisualSource.value === "Stock Video") {
    return stockVideoSubType.value || "stock_clip";
  }
  return visualSourceTypeMap[selectedSwapVisualSource.value] || "stock_clip";
}

function sceneVoiceOutdated(scene) {
  return Boolean(scene?.voice_settings?.is_outdated);
}

function normalizeScenePayload(scene) {
  if (!scene) return null;

  const captionSettings = normalizeCaptionSettings(
    scene.caption_settings ?? scene.caption_settings_json
  );

  return {
    ...scene,
    voice_settings: scene.voice_settings ?? scene.voice_settings_json ?? null,
    caption_settings: captionSettings,
    caption_settings_json: captionSettings,
    image_generation_settings: normalizeImageGenerationSettings(scene),
    locked_fields: scene.locked_fields ?? scene.locked_fields_json ?? null,
  };
}

function normalizeImageGenerationSettings(scene) {
  const settings = scene?.image_generation_settings ?? scene?.image_generation_settings_json ?? null;
  if (!settings || typeof settings !== "object") return settings;

  if (
    String(scene?.visual_type || "") === "ai_image" &&
    scene?.visual_asset_id &&
    Number(settings.asset_id || 0) === Number(scene.visual_asset_id) &&
    !settings.in_progress
  ) {
    return {
      ...settings,
      needs_visual: false,
      last_error: null,
    };
  }

  return settings;
}

function normalizeCaptionSettings(settings, fallback = {}) {
  const source = settings && typeof settings === "object" ? settings : {};
  const fallbackSource = fallback && typeof fallback === "object" ? fallback : {};

  return {
    ...DEFAULT_CAPTION_SETTINGS,
    ...fallbackSource,
    ...source,
    enabled: source.enabled ?? fallbackSource.enabled ?? DEFAULT_CAPTION_SETTINGS.enabled,
    style_key: source.style_key || fallbackSource.style_key || DEFAULT_CAPTION_SETTINGS.style_key,
    highlight_mode:
      source.highlight_mode ||
      fallbackSource.highlight_mode ||
      DEFAULT_CAPTION_SETTINGS.highlight_mode,
    position: source.position || fallbackSource.position || DEFAULT_CAPTION_SETTINGS.position,
    font: source.font || fallbackSource.font || DEFAULT_CAPTION_SETTINGS.font,
    highlight_color:
      source.highlight_color ||
      fallbackSource.highlight_color ||
      DEFAULT_CAPTION_SETTINGS.highlight_color,
    color: source.color || fallbackSource.color || DEFAULT_CAPTION_SETTINGS.color,
    size: source.size || fallbackSource.size || DEFAULT_CAPTION_SETTINGS.size,
    preset_id: source.preset_id ?? fallbackSource.preset_id ?? DEFAULT_CAPTION_SETTINGS.preset_id,
  };
}

function sortScenesByOrder(nextScenes) {
  return [...nextScenes].sort(
    (left, right) => Number(left.scene_order || 0) - Number(right.scene_order || 0)
  );
}

function replaceSceneInCollection(updatedScene) {
  if (!updatedScene?.id) return;

  const idx = scenes.value.findIndex((s) => s.id === updatedScene.id);
  if (idx === -1) {
    // New scene we don't have yet — append, then sort.
    scenes.value.push(updatedScene);
    scenes.value = sortScenesByOrder(scenes.value);
    return;
  }

  const existing = scenes.value[idx];
  const captionSettings = normalizeCaptionSettings(
    updatedScene.caption_settings ?? updatedScene.caption_settings_json,
    existing.caption_settings ?? existing.caption_settings_json
  );

  // Mutate the existing scene IN PLACE. Vue 3's fine-grained reactivity
  // only re-renders the bindings whose specific keys changed, instead
  // of remounting every component that touches this scene. Before this,
  // every caption toggle / preview poll / generation event replaced the
  // whole scenes array with new object references and the editor
  // flickered on every API response.
  Object.assign(existing, updatedScene, {
    caption_settings: captionSettings,
    caption_settings_json: captionSettings,
  });

  // Re-sort only when the array is actually out of order (scene_order
  // changed). The common case — same scene updated with same order —
  // skips the sort entirely so the array reference stays stable too.
  let outOfOrder = false;
  for (let i = 0; i < scenes.value.length - 1; i++) {
    if ((scenes.value[i].scene_order ?? 0) > (scenes.value[i + 1].scene_order ?? 0)) {
      outOfOrder = true;
      break;
    }
  }
  if (outOfOrder) scenes.value = sortScenesByOrder(scenes.value);
}

function patchSceneCaptionSettings(sceneId, captionSettings) {
  const scene = scenes.value.find((item) => item.id === sceneId);

  if (!scene) return;

  const normalizedSettings = normalizeCaptionSettings(captionSettings);

  scene.caption_settings = normalizedSettings;
  scene.caption_settings_json = normalizedSettings;
}

function buildSceneLabel(sceneType) {
  const label = humanizeSceneType(sceneType);
  return label === "Narration" ? `Scene ${scenes.value.length + 1}` : label;
}

function resetAddSceneDrafts() {
  selectedSceneType.value = "Narration";
  selectedAddSceneVisualSource.value = "Stock Clip";
  addSceneVisualMode.value = "";
  addSceneStockSubType.value = "stock_clip";
  addSceneVisualStyle.value = null;
  addSceneCustomVisualStyle.value = "";
  addSceneVisualQuery.value = "";
  addScenePickedAsset.value = null;
  addSceneCharacterId.value = null;
  addSceneCharPopoverOpen.value = false;
  newSceneScript.value = "";
}

// Called when the Assets tab is selected; opens the existing MediaPickerModal
// in 'visual' mode but redirects the selection into the add-scene buffer
// instead of assigning to activeScene.
function pickAssetForNewScene() {
  addSceneAssetPickerActive.value = true;
  mediaPickerMode.value = "visual";
  mediaPickerVisible.value = true;
}

async function generateNewSceneDraft() {
  if (!project.value || addSceneGeneratePending.value) return;

  addSceneGeneratePending.value = true;
  addSceneGenerateError.value = "";

  let insertAfterSceneId = null;
  if (addScenePanelPosition.value.startsWith("after-")) {
    insertAfterSceneId = Number(addScenePanelPosition.value.replace("after-", "")) || null;
  }

  try {
    const response = await api.post("/scenes/generate-draft", {
      project_id: project.value.id,
      insert_after_scene_id: insertAfterSceneId,
      scene_type: String(selectedSceneType.value || "Narration").toLowerCase().replace(/\s+/g, "_"),
      current_text: newSceneScript.value.trim(),
    });

    newSceneScript.value = response.data?.data?.draft?.candidate || newSceneScript.value;
  } catch (requestError) {
    addSceneGenerateError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "AI draft failed.";
  } finally {
    addSceneGeneratePending.value = false;
  }
}

function scriptSaveCopy() {
  if (scriptSaveState.value === "pending") return "Unsaved changes";
  if (scriptSaveState.value === "saving") return "Saving...";
  if (scriptSaveState.value === "saved") return "Saved";
  if (scriptSaveState.value === "error") return scriptSaveError.value || "Save failed";
  return "";
}

function voiceSaveCopy() {
  if (voiceSaveState.value === "pending") return "Unsaved voice changes";
  if (voiceSaveState.value === "saving") return "Saving voice...";
  if (voiceSaveState.value === "saved") return "Voice saved";
  if (voiceSaveState.value === "error") return voiceSaveError.value || "Voice save failed";
  return "";
}

function captionSaveCopy() {
  if (captionSaveState.value === "pending") return "Unsaved captions";
  if (captionSaveState.value === "saving") return "Saving captions...";
  if (captionSaveState.value === "saved") return "Captions saved";
  if (captionSaveState.value === "error") return captionSaveError.value || "Caption save failed";
  return "";
}

function exportStatusCopy(job) {
  if (!job) return "";
  if (job.status === "completed") return "Export ready";
  if (job.status === "failed") return "Export failed";
  if (job.status === "processing") return `Exporting ${job.progress_percent || 0}%`;
  if (job.status === "queued") return "Export queued";
  return `Export ${job.status}`;
}

function formatSceneDuration(value) {
  const amount = Number(value || 0);
  if (!amount) return "—";
  return `${amount.toFixed(1)}s`;
}

function previewWords(text, highlightMode, progress) {
  const mode = highlightMode || captionHighlightDraft.value || "keywords";
  const pct = Math.min(1, Math.max(0, (progress ?? playProgress.value) / 100));

  if (mode === "none") return [];

  const timedWords = captionTimingWords(activeScene.value);
  if (timedWords.length > 0 && (mode === "word_by_word" || mode === "line_by_line")) {
    return previewTimedWords(timedWords, mode);
  }

  const words = String(text || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (words.length === 0) return [];

  if (mode === "word_by_word") {
    const idx = Math.min(Math.floor(pct * words.length), words.length - 1);
    return [{ text: words[idx], highlighted: true }];
  }

  if (mode === "line_by_line") {
    const wordsPerLine = 4;
    const lines = [];
    for (let i = 0; i < words.length; i += wordsPerLine) {
      lines.push(words.slice(i, i + wordsPerLine));
    }
    const wordIdx = Math.min(Math.floor(pct * words.length), words.length - 1);
    const lineIdx = Math.min(Math.floor(wordIdx / wordsPerLine), lines.length - 1);
    const lineWords = lines[lineIdx];
    const hStart = Math.min(1, lineWords.length - 1);
    const hEnd = Math.min(lineWords.length, hStart + 2);
    return lineWords.map((word, i) => ({
      text: i === lineWords.length - 1 ? word : word + " ",
      highlighted: i >= hStart && i < hEnd,
    }));
  }

  // keywords: full text with 2nd–3rd words highlighted
  const highlightStart = Math.min(1, words.length - 1);
  const highlightEnd = Math.min(words.length, highlightStart + 2);
  return words.map((word, index) => ({
    text: `${word}${index === words.length - 1 ? "" : " "}`,
    highlighted: index >= highlightStart && index < highlightEnd,
  }));
}

function captionTimingWords(scene) {
  const words = scene?.audio_asset?.metadata_json?.caption_timing?.words;
  if (!Array.isArray(words)) return [];

  return words
    .map((word) => ({
      text: String(word?.text || word?.word || "").trim(),
      start: Number(word?.start),
      end: Number(word?.end),
    }))
    .filter((word) => word.text && Number.isFinite(word.start) && Number.isFinite(word.end))
    .sort((a, b) => a.start - b.start);
}

function previewTimedWords(timedWords, mode) {
  const currentSeconds = currentCaptionSeconds();
  const wordIndex = timedWords.findIndex(
    (word) => currentSeconds >= word.start && currentSeconds < word.end
  );
  const activeIndex =
    wordIndex >= 0
      ? wordIndex
      : Math.max(0, Math.min(timedWords.length - 1, timedWords.findLastIndex((word) => word.start <= currentSeconds)));

  if (mode === "word_by_word") {
    return [{ text: timedWords[activeIndex]?.text || "", highlighted: true }];
  }

  const wordsPerLine = 4;
  const lineStart = Math.floor(activeIndex / wordsPerLine) * wordsPerLine;
  return timedWords.slice(lineStart, lineStart + wordsPerLine).map((word, index, lineWords) => ({
    text: `${word.text}${index === lineWords.length - 1 ? "" : " "}`,
    highlighted: lineStart + index === activeIndex,
  }));
}

function currentCaptionSeconds() {
  const audio = audioRef.value;
  if (audio && Number.isFinite(audio.currentTime)) {
    return Math.max(0, audio.currentTime);
  }

  return currentSceneAudioOffset();
}

function fontFamilyValue(font) {
  return `"${font}", sans-serif`;
}

function selectCaptionFont(font) {
  captionFontDraft.value = font;
  fontDropdownOpen.value = false;
}

function formatPreviewTime(value) {
  const whole = Math.max(0, Math.round(Number(value || 0)));
  const mins = Math.floor(whole / 60);
  const secs = whole % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

const totalVideoDuration = computed(() =>
  scenes.value.reduce((sum, s) => sum + sceneDuration(s), 0)
);

function sceneDuration(scene) {
  const audioDuration = Number(scene?.audio_asset?.duration_seconds || 0);
  if (Number.isFinite(audioDuration) && audioDuration > 0) {
    return Math.max(0.1, audioDuration);
  }

  return Math.max(0.1, Number(scene?.duration_seconds || 12));
}

function sceneAtFullElapsed(elapsedSeconds) {
  if (!scenes.value.length) return { scene: null, index: -1, start: 0, duration: 0 };

  const clamped = Math.max(0, Math.min(elapsedSeconds, totalVideoDuration.value || 0));
  let start = 0;

  for (let index = 0; index < scenes.value.length; index += 1) {
    const scene = scenes.value[index];
    const duration = sceneDuration(scene);
    if (clamped < start + duration || index === scenes.value.length - 1) {
      return { scene, index, start, duration };
    }
    start += duration;
  }

  return { scene: scenes.value[0], index: 0, start: 0, duration: sceneDuration(scenes.value[0]) };
}

// Cumulative scene start percentages for scrubber boundary markers (full video mode)
const sceneBoundaryPcts = computed(() => {
  if (scenes.value.length < 2) return [];
  const total = totalVideoDuration.value || 1;
  const pcts = [];
  let cum = 0;
  for (let i = 0; i < scenes.value.length - 1; i++) {
    cum += sceneDuration(scenes.value[i]);
    pcts.push((cum / total) * 100);
  }
  return pcts;
});

const previewContextDuration = computed(() =>
  previewMode.value === "full"
    ? totalVideoDuration.value
    : sceneDuration(activeScene.value)
);

const previewElapsedSecs = computed(() =>
  (playProgress.value / 100) * previewContextDuration.value
);

const previewTimer = computed(() => ({
  elapsed: formatPreviewTime(previewElapsedSecs.value),
  total: formatPreviewTime(previewContextDuration.value),
}));

function formatNotifTime(value) {
  if (!value) return "now";
  const ts = new Date(value).getTime();
  const delta = Math.floor((Date.now() - ts) / 60000);
  if (delta < 1) return "now";
  if (delta < 60) return `${delta} min ago`;
  const hrs = Math.floor(delta / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function updateVisualCache(sceneId, value) {
  mediaCache.value = {
    ...mediaCache.value,
    visual: {
      ...mediaCache.value.visual,
      [sceneId]: {
        ...(mediaCache.value.visual[sceneId] || {}),
        ...value,
      },
    },
  };
}

function updateAudioCache(sceneId, value) {
  mediaCache.value = {
    ...mediaCache.value,
    audio: {
      ...mediaCache.value.audio,
      [sceneId]: {
        ...(mediaCache.value.audio[sceneId] || {}),
        ...value,
      },
    },
  };
}

function syncActiveSceneMedia(scene) {
  const sceneId = scene?.id;
  if (!sceneId) {
    currentVisualUrl.value = null;
    return;
  }

  const visualUrl = scene.visual_asset?.storage_url ?? null;
  const cachedVisual = mediaCache.value.visual[sceneId];

  if (!visualUrl) {
    currentVisualUrl.value = null;
  } else if (cachedVisual?.loaded) {
    currentVisualUrl.value = visualUrl;
  } else {
    currentVisualUrl.value = null;
  }

  if (visualUrl) {
    preloadSceneVisual(scene);
  }

  if (scene.audio_asset?.storage_url) {
    preloadSceneAudio(scene);
  }
}

function preloadSceneVisual(scene) {
  const sceneId = scene?.id;
  const asset = scene?.visual_asset;
  const url = asset?.storage_url;

  if (!sceneId || !url) return;

  const cached = mediaCache.value.visual[sceneId];
  if (cached?.loaded && cached.url === url) {
    if (activeSceneId.value === sceneId) {
      currentVisualUrl.value = url;
    }
    return;
  }

  const preloadKey = `visual:${sceneId}:${url}`;
  if (mediaPreloaders.has(preloadKey)) return;

  updateVisualCache(sceneId, { url, loaded: false, failed: false });

  const isVideo =
    asset?.asset_type === "video" ||
    String(asset?.mime_type || "").startsWith("video/");

  if (isVideo) {
    const video = document.createElement("video");
    video.preload = "metadata";
    video.muted = true;
    video.playsInline = true;
    const clear = () => mediaPreloaders.delete(preloadKey);

    video.onloadeddata = () => {
      updateVisualCache(sceneId, { url, loaded: true, failed: false });
      if (activeSceneId.value === sceneId) {
        currentVisualUrl.value = url;
      }
      clear();
    };
    video.onerror = () => {
      updateVisualCache(sceneId, { url, loaded: false, failed: true });
      if (activeSceneId.value === sceneId) {
        visualLoadFailed.value = true;
        currentVisualUrl.value = null;
      }
      clear();
    };

    mediaPreloaders.set(preloadKey, video);
    video.src = url;
    video.load();
    return;
  }

  const image = new Image();
  image.onload = () => {
    updateVisualCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      currentVisualUrl.value = url;
    }
    mediaPreloaders.delete(preloadKey);
  };
  image.onerror = () => {
    updateVisualCache(sceneId, { url, loaded: false, failed: true });
    if (activeSceneId.value === sceneId) {
      visualLoadFailed.value = true;
      currentVisualUrl.value = null;
    }
    mediaPreloaders.delete(preloadKey);
  };

  mediaPreloaders.set(preloadKey, image);
  image.src = url;
}

function preloadSceneAudio(scene) {
  const sceneId = scene?.id;
  const url = scene?.audio_asset?.storage_url;

  if (!sceneId || !url) return;

  const cached = mediaCache.value.audio[sceneId];
  if (cached?.loaded && cached.url === url) return;

  const preloadKey = `audio:${sceneId}:${url}`;
  if (mediaPreloaders.has(preloadKey)) return;

  updateAudioCache(sceneId, { url, loaded: false, failed: false });

  const audio = new Audio();
  audio.crossOrigin = MEDIA_ELEMENT_CROSS_ORIGIN;
  audio.preload = "metadata";
  const clear = () => mediaPreloaders.delete(preloadKey);

  audio.onloadeddata = () => {
    updateAudioCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };
  audio.oncanplaythrough = () => {
    updateAudioCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };
  audio.onerror = () => {
    updateAudioCache(sceneId, { url, loaded: false, failed: true });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };

  mediaPreloaders.set(preloadKey, audio);
  audio.src = url;
  audio.load();
}

async function loadImageModelCatalog() {
  try {
    const res = await api.get('/image-models');
    const models = res?.data?.data?.models;
    if (Array.isArray(models) && models.length) availableImageModelsEditor.value = models;
  } catch { /* keep fallback list */ }
}

const modelPickerOpen = ref(false);
const activeImageModelLabel = computed(() => activeImageModelMeta.value?.label ?? 'GPT Image 1');

async function loadProject() {
  loading.value = true;
  error.value = "";

  try {
    const response = await api.get(`/projects/${projectId.value}`);
    applyProjectPayload(response.data?.data, { preserveActiveScene: false });
    await loadExportJobs();
    loadImageModelCatalog();   // fire-and-forget — falls back to static list
    loadImageStyleCatalog();   // fire-and-forget — falls back to icon list
    loadCruiseConversation();  // fire-and-forget — hydrates Assistant chat history
    subscribeProjectChannel();
  } catch (requestError) {
    error.value =
      requestError.response?.data?.error?.message ?? "Project load failed.";
  } finally {
    loading.value = false;
  }
}

function applyProjectPayload(data, { preserveActiveScene = true } = {}) {
  const previousActiveSceneId = activeSceneId.value;

  project.value = data?.project ?? null;
  projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
  projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
  selectedMusicTrackId.value = project.value?.music_asset_id ?? null;
  const ms = project.value?.music_settings_json ?? {};
  musicVolume.value = ms.volume ?? 30;
  musicDuckVolume.value = ms.duck_volume ?? 8;
  musicFadeInMs.value = ms.fade_in_ms ?? 500;
  musicLoop.value = ms.loop ?? true;
  musicDuckDuringVoice.value = ms.duck_during_voice ?? true;
  // Merge each scene in place when we already have it — same-id scenes
  // keep their object identity so Vue only re-evaluates the keys that
  // actually changed (caption settings, asset ids). Without this, a TTS
  // completion event triggered refreshProjectPayload() which rebuilt
  // every scene from scratch and the editor flickered each time.
  const freshScenes = (data?.scenes ?? []).map((scene) => normalizeScenePayload(scene));
  if (!scenes.value?.length) {
    scenes.value = freshScenes;
  } else {
    const byId = new Map(scenes.value.map((s) => [s.id, s]));
    const merged = freshScenes.map((fresh) => {
      const existing = byId.get(fresh.id);
      if (!existing) return fresh;
      Object.assign(existing, fresh);
      return existing;
    });
    // Same length + same id order? Skip array reassignment to keep the
    // ref stable. Otherwise (scene added / removed / reordered) replace.
    const sameOrder = merged.length === scenes.value.length
      && merged.every((s, i) => s.id === scenes.value[i].id);
    if (!sameOrder) scenes.value = merged;
  }
  hookOptions.value = data?.hook_options ?? [];

  if (preserveActiveScene && scenes.value.some((scene) => scene.id === previousActiveSceneId)) {
    activeSceneId.value = previousActiveSceneId;
  } else {
    activeSceneId.value = scenes.value[0]?.id ?? null;
  }

  scenes.value.forEach((scene) => {
    preloadSceneVisual(scene);
    preloadSceneAudio(scene);
  });
}

async function refreshProjectPayload() {
  const response = await api.get(`/projects/${projectId.value}`);
  applyProjectPayload(response.data?.data, { preserveActiveScene: true });
}

async function loadMe() {
  try {
    const response = await api.get("/me");
    mePayload.value = response.data?.data?.user ?? null;
    // Credits live at data.credits — sibling to user, not nested inside.
    cruiseCreditsPayload.value = response.data?.data?.credits ?? null;
    // Hydrate workspace-level Cruise prefs so the toggle reflects truth.
    cruiseAutoApply.value = response.data?.data?.cruise?.auto_apply ?? true;
    cruisePrefs.value = {
      image_model:    response.data?.data?.cruise?.image_model    ?? null,
      animation_tier: response.data?.data?.cruise?.animation_tier ?? null,
      visual_source:  response.data?.data?.cruise?.visual_source  ?? 'auto',
    };
    await Promise.all([loadVoiceProfiles(), loadCaptionPresets(), loadChannels(), loadBrandKits(), loadMusicTracks()]);
    await loadNotifications();
    subscribeWorkspaceNotifications();
  } catch {
    mePayload.value = null;
    voiceProfiles.value = [];
    channels.value = [];
    brandKits.value = [];
  }
}

// Toggle the workspace's Cruise auto-apply pref. Optimistic UI — flip
// the local ref immediately, send PATCH in the background. Revert on
// failure.
async function setCruiseAutoApply(next) {
  const previous = cruiseAutoApply.value
  cruiseAutoApply.value = next
  try {
    await api.patch('/cruise/settings', { auto_apply: next })
  } catch {
    cruiseAutoApply.value = previous
  }
}

// Patch a single pref. Optimistic — flip locally, revert on network
// failure. Keys map 1:1 to backend (image_model, animation_tier,
// visual_source). Passing null clears the bias.
async function setCruisePref(key, value) {
  const previous = cruisePrefs.value[key]
  cruisePrefs.value = { ...cruisePrefs.value, [key]: value }
  try {
    await api.patch('/cruise/settings', { [key]: value })
  } catch {
    cruisePrefs.value = { ...cruisePrefs.value, [key]: previous }
  }
}

// Auto-apply runs the action right after resolve when:
//   - workspace pref is on
//   - the proposed action's confirmation_class is 'auto'
//   - user can afford it
// Skips action cards that need confirmation (prompt / always_prompt) or
// would push the user below zero balance.
async function maybeAutoApply(msg) {
  if (!cruiseAutoApply.value) return
  const cards = msg?.actions || []
  if (!cards.length) return
  // Auto-apply each card flagged 'auto' if balance covers it. Stops at
  // the first 'prompt'/'always_prompt' card so the user still confirms
  // anything risky / expensive.
  let runningTotal = 0
  for (let i = 0; i < cards.length; i++) {
    const c = cards[i]
    if (c.confirmation_class !== 'auto') break
    runningTotal += (c.estimated_cost ?? 0)
    if (cruiseUserBalance.value < runningTotal) break
    await cruiseApplyAction(msg, i)
  }
}

// Quick prompt was clicked — fill the input and submit immediately.
async function cruiseUseQuickPrompt(text) {
  if (cruiseResolving.value) return
  cruiseInputText.value = text
  await cruiseSubmitIntent()
}

async function loadVoiceProfiles() {
  try {
    const response = await api.get("/voice-profiles");
    voiceProfiles.value = response.data?.data?.voice_profiles ?? [];
  } catch {
    voiceProfiles.value = [];
  }
}

async function loadCaptionPresets() {
  try {
    const response = await api.get("/caption-presets");
    captionPresets.value = response.data?.data?.caption_presets ?? [];
  } catch {
    captionPresets.value = [];
  }
}

async function loadChannels() {
  try {
    const response = await api.get("/channels");
    channels.value = response.data?.data?.channels ?? [];
  } catch {
    channels.value = [];
  }
}

async function loadBrandKits() {
  try {
    const response = await api.get("/brand-kits");
    brandKits.value = response.data?.data?.brand_kits ?? [];
  } catch {
    brandKits.value = [];
  }
}

async function loadMusicTracks() {
  try {
    const response = await api.get("/assets", { params: { asset_type: "music", per_page: 50 } });
    musicTracks.value = response.data?.data?.assets ?? [];
  } catch {
    musicTracks.value = [];
  }
}

async function loadMyImageAssets() {
  if (myImageLoading.value) return;
  myImageLoading.value = true;
  try {
    const response = await api.get("/assets", { params: { asset_type: "image", per_page: 60 } });
    myImageAssets.value = response.data?.data?.assets ?? [];
  } catch {
    myImageAssets.value = [];
  } finally {
    myImageLoading.value = false;
  }
}

// ── Custom voice: upload + record ───────────────────────────
function handleVoiceFileUpload(event) {
  const file = event?.target?.files?.[0];
  if (!file) return;
  event.target.value = '';
  enterPreview(file, file.name.replace(/\.[^.]+$/, ''));
}

function enterPreview(blob, name) {
  // Release any prior preview URL
  if (voicePreviewUrl.value) {
    try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
  }
  voicePreviewBlob.value = blob;
  voicePreviewUrl.value = URL.createObjectURL(blob);
  voicePreviewName.value = name || 'Custom voice';
  voiceUploadStatus.value = 'previewing';
  voiceUploadError.value = '';
}

async function confirmUploadFromPreview() {
  if (!voicePreviewBlob.value) return;
  const blob = voicePreviewBlob.value;
  const name = voicePreviewName.value;
  // Release the preview URL before kicking off the upload
  if (voicePreviewUrl.value) {
    try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
    voicePreviewUrl.value = null;
  }
  voicePreviewBlob.value = null;
  await uploadVoiceBlob(blob, name);
}

async function uploadVoiceBlob(blob, title) {
  voiceUploadStatus.value = 'uploading';
  voiceUploadError.value = '';
  voiceUploadAsset.value = null;
  try {
    const fd = new FormData();
    fd.append('asset_type', 'audio');
    fd.append('title', title || 'Custom voice');
    fd.append('asset_file', blob, (title || 'voice') + (blob.type.includes('webm') ? '.webm' : (blob.type.includes('wav') ? '.wav' : '.mp3')));
    const res = await api.post('/assets', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    const asset = res.data?.data?.asset;
    if (!asset) throw new Error('Upload returned no asset');
    voiceUploadAsset.value = asset;
    voiceUploadStatus.value = asset.transcription_status === 'completed' ? 'ready' : 'transcribing';
    if (voiceUploadStatus.value === 'transcribing') startVoiceTranscribePoll(asset.id);
  } catch (e) {
    voiceUploadError.value = e?.response?.data?.error?.message || e?.message || 'Upload failed.';
    voiceUploadStatus.value = 'error';
  }
}

function startVoiceTranscribePoll(assetId) {
  clearInterval(voiceTranscribePoller);
  let attempts = 0;
  voiceTranscribePoller = setInterval(async () => {
    attempts++;
    if (attempts > 60) { clearInterval(voiceTranscribePoller); voiceUploadStatus.value = 'error'; voiceUploadError.value = 'Transcription timed out.'; return; }
    try {
      const res = await api.get(`/assets/${assetId}`);
      const asset = res.data?.data?.asset ?? res.data?.data;
      if (!asset) return;
      voiceUploadAsset.value = asset;
      if (asset.transcription_status === 'completed') {
        clearInterval(voiceTranscribePoller);
        voiceUploadStatus.value = 'ready';
      } else if (asset.transcription_status === 'failed') {
        clearInterval(voiceTranscribePoller);
        voiceUploadStatus.value = 'error';
        voiceUploadError.value = 'Transcription failed. You can still use the audio without word-level captions.';
      }
    } catch { /* keep polling */ }
  }, 2000);
}

async function applyVoiceUpload({ updateScript = true } = {}) {
  if (!voiceUploadAsset.value || !activeScene.value) return;
  const asset = voiceUploadAsset.value;
  const currentVoiceSettings = activeScene.value.voice_settings || activeScene.value.voice_settings_json || {};
  const payload = {
    voice_settings_json: {
      ...currentVoiceSettings,
      audio_asset_id: asset.id,
      is_outdated: false,
      custom_audio: true,
    },
  };
  if (updateScript && asset.transcript_text) {
    payload.script_text = asset.transcript_text;
  }
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, payload);
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) replaceSceneInCollection(updatedScene);
    // Reset upload UI
    voiceUploadStatus.value = 'idle';
    voiceUploadAsset.value = null;
    voiceUploadError.value = '';
  } catch (err) {
    voiceUploadError.value = err?.response?.data?.error?.message || 'Could not apply voice.';
  }
}

function toggleVoicePreviewPlay() {
  const el = voicePreviewRef.value;
  if (!el) return;
  if (voicePreviewPlaying.value) { el.pause(); } else { el.play().catch(() => {}); }
}

function onVoicePreviewTime() {
  const el = voicePreviewRef.value;
  if (!el) return;
  voicePreviewCurrent.value = el.currentTime || 0;
  if (Number.isFinite(el.duration)) voicePreviewDuration.value = el.duration;
}

function onVoicePreviewSeek(e) {
  const el = voicePreviewRef.value;
  if (!el || !voicePreviewDuration.value) return;
  const rect = e.currentTarget.getBoundingClientRect();
  const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
  el.currentTime = pct * voicePreviewDuration.value;
}

function fmtVoicePreviewTime(s) {
  if (!Number.isFinite(s)) return '0:00';
  const sec = Math.floor(s);
  return `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
}

function cancelVoiceUpload() {
  clearInterval(voiceTranscribePoller);
  if (voicePreviewUrl.value) {
    try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
  }
  voicePreviewBlob.value = null;
  voicePreviewUrl.value = null;
  voicePreviewName.value = '';
  voiceUploadStatus.value = 'idle';
  voiceUploadAsset.value = null;
  voiceUploadError.value = '';
}

async function startVoiceRecording() {
  voiceUploadError.value = '';
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
    const rec = new MediaRecorder(stream, { mimeType });
    voiceRecordedChunks.value = [];
    rec.ondataavailable = (e) => { if (e.data?.size) voiceRecordedChunks.value.push(e.data); };
    rec.onstop = () => {
      stream.getTracks().forEach(t => t.stop());
      voiceRecording.value = false;
      clearInterval(voiceRecordTimer);
      const blob = new Blob(voiceRecordedChunks.value, { type: mimeType });
      voiceRecordedChunks.value = [];
      if (blob.size > 0) {
        enterPreview(blob, `Voice recording ${new Date().toLocaleTimeString()}`);
      }
    };
    voiceRecorder.value = rec;
    voiceRecording.value = true;
    voiceRecordSeconds.value = 0;
    voiceRecordTimer = setInterval(() => { voiceRecordSeconds.value += 1; }, 1000);
    rec.start();
  } catch (err) {
    voiceUploadError.value = err?.name === 'NotAllowedError'
      ? 'Microphone permission denied. Allow microphone access and try again.'
      : 'Could not start recording.';
  }
}

function stopVoiceRecording() {
  if (voiceRecorder.value && voiceRecording.value) {
    voiceRecorder.value.stop();
  }
}

async function loadCustomAudioAssets() {
  if (customAudioLoading.value) return;
  customAudioLoading.value = true;
  try {
    const response = await api.get("/assets", { params: { asset_type: "audio", per_page: 60 } });
    customAudioAssets.value = response.data?.data?.assets ?? [];
  } catch {
    customAudioAssets.value = [];
  } finally {
    customAudioLoading.value = false;
  }
}

async function assignAssetImage(asset) {
  if (!activeScene.value || visualSwapPending.value) return;
  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      visual_asset_id: asset.id,
      visual_type: "image_montage",
      visual_prompt: asset.title || "",
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) {
      replaceSceneInCollection(updatedScene);
      preloadSceneVisual(updatedScene);
      pushToast({ id: `visual-done-${updatedScene.id}-${Date.now()}`, title: 'Visual updated', message: `Scene ${updatedScene.scene_order ?? ''} visual swapped.` });
    }
  } catch (err) {
    visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign image.";
  } finally {
    visualSwapPending.value = false;
  }
}

async function assignCustomAudio(asset) {
  if (!activeScene.value) return;
  const currentVoiceSettings = activeScene.value.voice_settings || {};
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      voice_settings_json: {
        ...currentVoiceSettings,
        audio_asset_id: asset.id,
        is_outdated: false,
        custom_audio: true,
      },
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) replaceSceneInCollection(updatedScene);
  } catch (err) {
    console.error("Failed to assign custom audio", err);
  }
}

function openMediaPicker(mode) {
  mediaPickerMode.value = mode;
  mediaPickerVisible.value = true;
}

function syncSceneVoiceVolume() {
  if (audioRef.value) {
    audioRef.value.volume = Math.max(0, Math.min(1, sceneVoiceVolume.value / 100));
  }
}

function scheduleSceneVoiceVolumeSave() {
  syncSceneVoiceVolume();
  clearTimeout(sceneVoiceVolumeSaveTimer);
  sceneVoiceVolumeSaveTimer = setTimeout(async () => {
    if (!activeScene.value) return;
    const currentVoiceSettings = activeScene.value.voice_settings || activeScene.value.voice_settings_json || {};
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        voice_settings_json: { ...currentVoiceSettings, volume: sceneVoiceVolume.value },
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) replaceSceneInCollection(updatedScene);
    } catch (err) { console.error("Failed to save voice volume", err); }
  }, 500);
}

function scheduleSceneSoundVolumeSave() {
  clearTimeout(sceneSoundVolumeSaveTimer);
  sceneSoundVolumeSaveTimer = setTimeout(async () => {
    if (!activeScene.value) return;
    const current = activeScene.value.sound_settings_json || {};
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        sound_settings_json: { ...current, volume: sceneSoundVolume.value },
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) replaceSceneInCollection(updatedScene);
    } catch (err) { console.error("Failed to save sound volume", err); }
  }, 500);
}

async function saveAudiogramSettings({ apply = false } = {}) {
  if (!activeScene.value) return;
  clearTimeout(audiogramSaveTimer);
  audiogramSaveState.value = "saving";
  const existing = activeScene.value.image_generation_settings ?? activeScene.value.image_generation_settings_json ?? {};
  const payload = {
    image_generation_settings_json: {
      ...existing,
      audiogram_style: audiogramStyle.value,
      audiogram_color: audiogramColor.value,
      audiogram_bg: audiogramBg.value,
    },
  };
  if (apply) {
    payload.visual_type = "waveform";
    payload.visual_asset_id = null;
  }
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, payload);
    const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updated) replaceSceneInCollection(updated);
    audiogramSaveState.value = "saved";
    setTimeout(() => { audiogramSaveState.value = "idle"; }, 1400);
  } catch {
    audiogramSaveState.value = "idle";
  }
}

function selectAudiogramStyle(key) {
  audiogramStyle.value = key;
  saveAudiogramSettings();
}
function selectAudiogramColor(color) {
  audiogramColor.value = color;
  saveAudiogramSettings();
}
function queueAudiogramColorSave() {
  clearTimeout(audiogramSaveTimer);
  audiogramSaveTimer = setTimeout(() => saveAudiogramSettings(), 600);
}
function selectAudiogramBg(key) {
  audiogramBg.value = key;
  saveAudiogramSettings();
}

function handleMediaPickerSelect({ mode, item }) {
  if (mode === "visual") {
    if (item._type === "asset") {
      // If the picker was opened from the add-scene panel, route the
      // selection into the add-scene buffer instead of assigning to the
      // currently-active scene.
      if (addSceneAssetPickerActive.value) {
        addScenePickedAsset.value = item;
        addSceneAssetPickerActive.value = false;
        return;
      }
      const isVid = item.asset_type === "video" || String(item.mime_type ?? "").startsWith("video/");
      assignAssetVisual(item, isVid ? "background_loop" : "image_montage");
    }
  } else if (mode === "music") {
    if (item._type === "no-music") {
      selectMusicTrack(null);
    } else if (item._type === "track" || item._type === "asset") {
      selectMusicTrack(item.id);
    }
  } else if (mode === "sound") {
    if (item._type === "asset") assignSceneSound(item);
  }
}

async function assignSceneSound(asset) {
  if (!activeScene.value) return;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      sound_asset_id: asset ? asset.id : null,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) replaceSceneInCollection(updatedScene);
  } catch (err) {
    console.error("Failed to assign sound", err);
  }
}

async function assignAssetVisual(asset, visualType) {
  if (!activeScene.value || visualSwapPending.value) return;
  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      visual_asset_id: asset.id,
      visual_type: visualType,
      visual_prompt: asset.title || "",
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) {
      replaceSceneInCollection(updatedScene);
      preloadSceneVisual(updatedScene);
      pushToast({ id: `visual-done-${updatedScene.id}-${Date.now()}`, title: 'Visual updated', message: `Scene ${updatedScene.scene_order ?? ''} visual swapped.` });
    }
  } catch (err) {
    visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign visual.";
  } finally {
    visualSwapPending.value = false;
  }
}

async function persistMusicSettings() {
  if (!project.value) return;
  musicSaveState.value = "saving";
  musicSaveError.value = "";
  try {
    const response = await api.patch(`/projects/${project.value.id}`, {
      music_asset_id: selectedMusicTrackId.value ?? null,
      music_settings_json: {
        volume: musicVolume.value,
        duck_volume: musicDuckVolume.value,
        fade_in_ms: musicFadeInMs.value,
        loop: musicLoop.value,
        duck_during_voice: musicDuckDuringVoice.value,
      },
    });
    project.value = response.data?.data?.project ?? project.value;
    musicSaveState.value = "saved";
  } catch (err) {
    musicSaveError.value = err?.response?.data?.error?.message ?? "Failed to save music settings.";
    musicSaveState.value = "idle";
  }
}

function scheduleMusicSave() {
  musicSaveState.value = "idle";
  clearTimeout(musicSaveTimer);
  musicSaveTimer = setTimeout(persistMusicSettings, 500);
}

async function saveProjectDefaults() {
  if (!project.value || projectDefaultsSaveState.value === "saving") return;

  projectDefaultsSaveState.value = "saving";
  projectDefaultsSaveError.value = "";

  try {
    const response = await api.patch(`/projects/${project.value.id}`, {
      channel_id: projectChannelId.value ? Number(projectChannelId.value) : null,
      brand_kit_id: projectBrandKitId.value ? Number(projectBrandKitId.value) : null,
    });

    project.value = response.data?.data?.project ?? project.value;
    projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
    projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
    projectDefaultsSaveState.value = "saved";
  } catch (requestError) {
    projectDefaultsSaveError.value =
      requestError.response?.data?.error?.message ?? "Could not save project defaults.";
    projectDefaultsSaveState.value = "error";
  }
}

watch(projectChannelId, (nextChannelId, previousChannelId) => {
  const channel = channels.value.find((item) => String(item.id) === String(nextChannelId));
  const previousChannel = channels.value.find((item) => String(item.id) === String(previousChannelId));

  if (!channel) return;

  if (!projectBrandKitId.value || String(projectBrandKitId.value) === String(previousChannel?.brand_kit_id || "")) {
    projectBrandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : "";
  }
});

async function loadNotifications() {
  try {
    const response = await api.get("/notifications");
    notifications.value = response.data?.data?.notifications ?? [];
  } catch {
    notifications.value = [];
  }
}

async function loadExportJobs() {
  if (!projectId.value) return;

  try {
    const response = await api.get(`/projects/${projectId.value}/exports`);
    exportJobs.value = response.data?.data?.export_jobs ?? [];
  } catch {
    exportJobs.value = [];
  }
}

async function markNotificationRead(notificationId) {
  try {
    await api.post(`/notifications/${notificationId}/read`);
    notifications.value = notifications.value.map((item) =>
      item.id === notificationId ? { ...item, is_read: true } : item
    );
  } catch {
    // no-op
  }
}

async function markAllRead() {
  const unread = notifications.value.filter((item) => !item.is_read);
  await Promise.all(unread.map((item) => markNotificationRead(item.id)));
}

function pushToast(notification) {
  notificationToasts.value = [notification, ...notificationToasts.value].slice(0, 3);
  window.setTimeout(() => {
    notificationToasts.value = notificationToasts.value.filter((toast) => toast.id !== notification.id);
  }, 5000);
}

function onPostScheduled({ mode, posts } = {}) {
  if (mode === 'now') {
    // Keep modal open — it shows live polling. Push a "publishing" toast so
    // the user has feedback even if they close the modal early.
    pushToast({ id: 'publish-now-' + Date.now(), title: 'Publishing…', message: 'Your post is being sent to the platform.' })
    pollPublishStatus(posts ?? [])
  } else {
    scheduleModalOpen.value = false
    const label = mode === 'draft' ? 'Saved as draft' : 'Post scheduled'
    const detail = mode === 'draft' ? 'Your post was saved as a draft.' : 'Your post has been scheduled successfully.'
    pushToast({ id: 'scheduled-' + Date.now(), title: label, message: detail })
  }
}

function pollPublishStatus(posts) {
  if (!posts.length) return
  const ids = posts.map(p => p.id).filter(Boolean)
  if (!ids.length) return
  let attempts = 0
  const timer = setInterval(async () => {
    attempts++
    if (attempts > 20) { clearInterval(timer); return }
    try {
      const res = await api.get('/scheduled-posts', { params: { per_page: 50 } })
      const all = res.data?.data?.posts ?? []
      const watched = all.filter(p => ids.includes(p.id))
      const allDone = watched.every(p => !['pending', 'processing', 'scheduled'].includes(p.status) || p.status === 'scheduled')
      const anyFailed = watched.some(p => p.status === 'failed')
      const anyPublished = watched.some(p => p.status === 'published')
      if (anyPublished || anyFailed) {
        clearInterval(timer)
        scheduleModalOpen.value = false
        if (anyFailed && !anyPublished) {
          pushToast({ id: 'publish-fail-' + Date.now(), title: 'Publish failed', message: watched.find(p => p.status === 'failed')?.failure_reason || 'The post could not be published.' })
        } else {
          pushToast({ id: 'publish-ok-' + Date.now(), title: 'Published!', message: 'Your post was published successfully.' })
        }
      }
    } catch { /* silent */ }
  }, 3000)
}

function subscribeWorkspaceNotifications() {
  const echo = getEcho();
  const workspaceId = mePayload.value?.workspace_id;

  if (!echo || !workspaceId) return;

  if (workspaceChannelName) {
    echo.leave(workspaceChannelName);
  }

  workspaceChannelName = `workspace.${workspaceId}`;

  echo.private(workspaceChannelName).listen(".notification.created", (payload) => {
    const normalized = {
      id: payload.id,
      type: payload.type,
      title: payload.title,
      message: payload.message,
      payload: payload.payload,
      is_read: payload.is_read,
      created_at: payload.created_at,
    };

    notifications.value = [normalized, ...notifications.value].slice(0, 50);
    pushToast(normalized);
  });
}

function unsubscribeWorkspaceNotifications() {
  const echo = getEcho();

  if (echo && workspaceChannelName) {
    echo.leave(workspaceChannelName);
  }
}

async function logout() {
  await authStore.logout();
  router.push({ name: "login" });
}

function selectScene(sceneId) {
  if (sceneId === activeSceneId.value) return;

  stopPreviewPlay();
  playProgress.value = 0;
  flushActiveSceneDrafts().then((flushed) => {
    if (flushed === false) return;
    activeSceneId.value = sceneId;
  });
}

function skipToScene(direction) {
  stopPreviewPlay();
  const idx = activeSceneIndex.value;
  const next = scenes.value[idx + direction];
  if (next) {
    flushActiveSceneDrafts().then((flushed) => {
      if (flushed === false) return;
      activeSceneId.value = next.id;
    });
  }
}

async function setPreviewMode(mode) {
  if (previewMode.value === mode) return;

  stopPreviewPlay();

  if (mode === "full" && scenes.value[0]?.id && activeSceneId.value !== scenes.value[0].id) {
    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;
    activeSceneId.value = scenes.value[0].id;
  }

  previewMode.value = mode;
  playProgress.value = 0;
}

function togglePreviewPlay() {
  unlockWaveformAudio();
  isPreviewPlaying.value ? stopPreviewPlay() : startPreviewPlay();
}

async function startPreviewPlay() {
  if (!scenes.value.length) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  if (previewMode.value === "full") {
    syncFullPreviewScene();
  }

  stopMusicAudition();
  isPreviewPlaying.value = true;
  nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
  nextTick(() => playPreviewMusic());
  const TICK = 50;
  previewPlayTimer = window.setInterval(() => {
    const dur = previewContextDuration.value || 1;

    if (previewMode.value === "scene") {
      // Drive progress from actual audio position so captions stay in sync
      const audio = audioRef.value;
      if (
        audio &&
        !audio.paused &&
        Number.isFinite(audio.duration) &&
        audio.duration > 0
      ) {
        const newProgress = (audio.currentTime / audio.duration) * 100;
        if (audio.ended || newProgress >= 100) {
          playProgress.value = 0;
          playActiveSceneAudio(0);
        } else {
          playProgress.value = newProgress;
        }
      } else {
        // Audio not ready yet — advance timer as fallback
        playProgress.value += (100 / dur) * (TICK / 1000);
        if (playProgress.value >= 100) {
          playProgress.value = 0;
          playActiveSceneAudio(0);
        }
      }
    } else {
      const audioDrivenProgress = fullPreviewAudioProgress();
      playProgress.value =
        audioDrivenProgress ??
        playProgress.value + (100 / dur) * (TICK / 1000);

      if (playProgress.value >= 100) {
        playProgress.value = 100;
        stopPreviewPlay();
      } else {
        syncFullPreviewScene();
      }
    }
  }, TICK);
}

function stopPreviewPlay() {
  isPreviewPlaying.value = false;
  if (previewPlayTimer) {
    window.clearInterval(previewPlayTimer);
    previewPlayTimer = null;
  }
  if (audioRef.value) {
    audioRef.value.pause();
  }
  if (soundAudioRef.value) {
    soundAudioRef.value.pause();
  }
  stopPreviewMusic();
}

async function timelineSeek(pct) {
  if (previewMode.value !== "full") await setPreviewMode("full");
  playProgress.value = Math.max(0, Math.min(100, pct));
  syncFullPreviewScene();
  if (isPreviewPlaying.value) {
    nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
    nextTick(() => playPreviewMusic());
  } else if (audioRef.value) {
    audioRef.value.currentTime = currentSceneAudioOffset();
  }
}

async function timelineReorder(newSceneIds) {
  const previousScenes = [...scenes.value];
  const reordered = newSceneIds.map((id, orderIndex) => {
    const sc = scenes.value.find(s => s.id === id);
    return { ...sc, scene_order: orderIndex + 1 };
  }).filter(Boolean);
  scenes.value = reordered;

  try {
    const response = await api.patch("/scenes/reorder", {
      project_id: project.value.id,
      scene_ids: newSceneIds,
    });
    scenes.value = sortScenesByOrder(
      (response.data?.data?.scenes ?? []).map(s => normalizeScenePayload(s)).filter(Boolean)
    );
  } catch {
    scenes.value = previousScenes;
  }
}

function scrubberSeek(event) {
  const track = event.currentTarget.querySelector(".scrubber-track");
  if (!track) return;
  const rect = track.getBoundingClientRect();
  playProgress.value = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
  if (previewMode.value === "full") {
    syncFullPreviewScene();
  }
  if (isPreviewPlaying.value) {
    nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
    nextTick(() => playPreviewMusic());
  } else if (audioRef.value) {
    audioRef.value.currentTime = currentSceneAudioOffset();
  }
}

function currentSceneAudioOffset() {
  if (previewMode.value === "full") {
    const elapsed = previewElapsedSecs.value;
    const match = sceneAtFullElapsed(elapsed);
    return Math.max(0, elapsed - match.start);
  }

  return previewElapsedSecs.value;
}

function fullPreviewAudioProgress() {
  if (previewMode.value !== "full") return null;

  const audio = audioRef.value;
  if (
    !audio ||
    audio.paused ||
    !Number.isFinite(audio.duration) ||
    audio.duration <= 0 ||
    !activeScene.value
  ) {
    return null;
  }

  const currentSceneIndex = scenes.value.findIndex(
    (scene) => scene.id === activeScene.value.id
  );
  if (currentSceneIndex < 0) return null;

  let sceneStart = 0;
  for (let i = 0; i < currentSceneIndex; i += 1) {
    sceneStart += sceneDuration(scenes.value[i]);
  }

  const activeDuration = sceneDuration(activeScene.value);
  const sceneElapsed = Math.min(audio.currentTime, activeDuration);
  const total = totalVideoDuration.value || 1;

  return Math.max(0, Math.min(100, ((sceneStart + sceneElapsed) / total) * 100));
}

function syncFullPreviewScene() {
  if (previewMode.value !== "full") return;

  const elapsed = previewElapsedSecs.value;
  const match = sceneAtFullElapsed(elapsed);
  if (!match.scene) return;

  pendingPreviewAudioOffset = Math.max(0, elapsed - match.start);
  if (activeSceneId.value !== match.scene.id) {
    activeSceneId.value = match.scene.id;
    scrollSceneIntoView(match.scene.id);
  }
}

function scrollSceneIntoView(sceneId) {
  nextTick(() => {
    const el = document.getElementById(`scene-item-${sceneId}`);
    if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });
}

function playActiveSceneAudio(offsetSeconds = 0) {
  if (!isPreviewPlaying.value) return;

  playActiveSceneSound(offsetSeconds);

  if (!audioRef.value || !activeSceneAudioUrl.value) {
    isAudioPlaying.value = false;
    isAudioLoading.value = false;
    syncPreviewMusicVolume();
    return;
  }

  isAudioLoading.value = true;
  preloadSceneAudio(activeScene.value);

  const duration = Number.isFinite(audioRef.value.duration) ? audioRef.value.duration : null;
  const boundedOffset = duration
    ? Math.max(0, Math.min(offsetSeconds, Math.max(0, duration - 0.05)))
    : Math.max(0, offsetSeconds);

  try {
    audioRef.value.currentTime = boundedOffset;
  } catch {
    // Some browsers reject currentTime before metadata loads; playback can still begin.
  }

  audioRef.value.play().catch(() => {
    isAudioPlaying.value = false;
    isAudioLoading.value = false;
    syncPreviewMusicVolume();
  });
}

function playActiveSceneSound(offsetSeconds = 0) {
  if (!soundAudioRef.value || !activeSceneSoundUrl.value || !isPreviewPlaying.value) return;

  const duration = Number.isFinite(soundAudioRef.value.duration) ? soundAudioRef.value.duration : null;
  const boundedOffset = duration
    ? Math.max(0, Math.min(offsetSeconds, Math.max(0, duration - 0.05)))
    : Math.max(0, offsetSeconds);

  soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));

  try {
    soundAudioRef.value.pause();
    soundAudioRef.value.currentTime = boundedOffset;
  } catch {
    // Some browsers reject currentTime before metadata loads; playback can still begin.
  }

  soundAudioRef.value.play().catch(() => {});
}

function previewMusicOffset() {
  if (previewMode.value === "full") {
    return previewElapsedSecs.value;
  }

  return 0;
}

function syncPreviewMusicVolume() {
  if (!musicAudioRef.value) return;
  musicAudioRef.value.volume = previewMusicVolume.value;
}

function playPreviewMusic() {
  if (!musicAudioRef.value || !activeMusicTrackUrl.value || !isPreviewPlaying.value) return;

  syncPreviewMusicVolume();

  const offset = previewMusicOffset();
  const duration = Number.isFinite(musicAudioRef.value.duration) ? musicAudioRef.value.duration : null;
  if (!musicLoop.value && duration && offset >= duration) {
    musicAudioRef.value.pause();
    return;
  }

  const nextTime = duration && duration > 0
    ? (musicLoop.value ? offset % duration : offset)
    : offset;

  try {
    musicAudioRef.value.currentTime = Math.max(0, nextTime);
  } catch {
    // Metadata may not be available yet; the browser will start from the beginning.
  }

  musicAudioRef.value.play().catch(() => {});
}

function stopPreviewMusic() {
  if (musicAudioRef.value) {
    musicAudioRef.value.pause();
  }
}

function handleSceneAudioPlay() {
  isAudioPlaying.value = true;
  syncPreviewMusicVolume();
}

function handleSceneAudioPause() {
  isAudioPlaying.value = false;
  if (soundAudioRef.value) {
    try { soundAudioRef.value.pause(); soundAudioRef.value.currentTime = 0; } catch {}
  }
  syncPreviewMusicVolume();
}

function moodGradient(mood) {
  const map = {
    dark: "linear-gradient(135deg,#1a0a2e,#0d0d2b)",
    upbeat: "linear-gradient(135deg,#0d2e1a,#1a2e0d)",
    calm: "linear-gradient(135deg,#0d1a2e,#1a1a0d)",
    epic: "linear-gradient(135deg,#2e0d0d,#1a1a2e)",
    corporate: "linear-gradient(135deg,#0d2e2e,#1a2e1a)",
  };
  return map[mood?.toLowerCase()] ?? "linear-gradient(135deg,#1a1a2e,#2e1a1a)";
}

function moodEmoji(mood) {
  const map = { dark: "🌑", upbeat: "🎵", calm: "🌊", epic: "⚡", corporate: "💼" };
  return map[mood?.toLowerCase()] ?? "🎵";
}

function trackMoodLabel(track) {
  const mood = (track.tags ?? []).find((t) => t !== "music") ?? "music";
  return mood.charAt(0).toUpperCase() + mood.slice(1) + " · Royalty-free";
}

function selectMusicTrack(trackId) {
  selectedMusicTrackId.value = trackId;
  scheduleMusicSave();

  if (isPreviewPlaying.value) {
    nextTick(() => playPreviewMusic());
  }
}

function toggleMusicAudition(track) {
  if (!track?.storage_url || !musicAuditionRef.value) return;

  unlockWaveformAudio();
  stopPreviewPlay();

  if (auditionMusicTrackId.value === track.id && !musicAuditionRef.value.paused) {
    musicAuditionRef.value.pause();
    auditionMusicTrackId.value = null;
    return;
  }

  auditionMusicTrackId.value = track.id;
  nextTick(() => {
    if (!musicAuditionRef.value) return;
    musicAuditionRef.value.volume = Math.max(0, Math.min(1, Number(musicVolume.value || 0) / 100));
    musicAuditionRef.value.currentTime = 0;
    musicAuditionRef.value.play().catch(() => {
      auditionMusicTrackId.value = null;
    });
  });
}

function stopMusicAudition() {
  if (musicAuditionRef.value) {
    musicAuditionRef.value.pause();
  }
  auditionMusicTrackId.value = null;
}

function moodLabel(mood) {
  const value = String(mood || "other");
  return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatTrackDuration(secs) {
  if (!secs) return "—";
  const m = Math.floor(Number(secs) / 60);
  const s = Math.round(Number(secs) % 60);
  return `${m}:${String(s).padStart(2, "0")}`;
}

function togglePanel(name) {
  panelState.value[name] = !panelState.value[name];
}

function toggleAddScene(position) {
  addScenePanelPosition.value =
    addScenePanelPosition.value === position ? "" : position;
}

function closeAddScene() {
  addScenePanelPosition.value = "";
  resetAddSceneDrafts();
  addSceneGenerateError.value = "";
}

function selectAddSceneVisualMode(mode) {
  addSceneVisualMode.value = mode;
  // No auto-open of the library picker — the user explicitly clicks
  // "Pick from your library" inside the tab when they're ready.
}

function applyCaptionPreset(preset) {
  if (preset.preset_type) captionStyleDraft.value = preset.preset_type;
  if (preset.font) captionFontDraft.value = preset.font;
  if (preset.font_size_rule) captionSizeDraft.value = preset.font_size_rule;
  if (preset.highlight_mode) captionHighlightDraft.value = preset.highlight_mode;
  if (preset.highlight_color) captionHighlightColorDraft.value = preset.highlight_color;
  if (preset.caption_color) captionColorDraft.value = preset.caption_color;
  if (preset.caption_position) captionPositionDraft.value = preset.caption_position;
  // watcher autosaves
}

async function saveCaptionPreset() {
  const name = captionPresetSaveName.value.trim();
  if (!name) return;
  captionPresetSaving.value = true;
  try {
    const response = await api.post("/caption-presets", {
      name,
      preset_type: captionStyleDraft.value || null,
      font: captionFontDraft.value || null,
      font_size_rule: captionSizeDraft.value || null,
      highlight_mode: captionHighlightDraft.value || null,
      highlight_color: captionHighlightColorDraft.value || null,
      caption_color: captionColorDraft.value || null,
      caption_position: captionPositionDraft.value || null,
    });
    const created = response.data?.data?.caption_preset;
    if (created) captionPresets.value = [...captionPresets.value, created].sort((a, b) => a.name.localeCompare(b.name));
    captionPresetSaveName.value = "";
    captionPresetSaveOpen.value = false;
  } catch (_) {
    // non-critical
  } finally {
    captionPresetSaving.value = false;
  }
}

async function deleteCaptionPreset(presetId) {
  captionPresetDeleting.value = presetId;
  try {
    await api.delete(`/caption-presets/${presetId}`);
    captionPresets.value = captionPresets.value.filter((p) => p.id !== presetId);
  } catch (_) {
    // non-critical
  } finally {
    captionPresetDeleting.value = null;
  }
}

async function saveVoiceProfile() {
  const name = voiceProfileSaveName.value.trim();
  if (!name || !voiceProfileKey.value) return;
  voiceProfileSaving.value = true;
  try {
    const response = await api.post("/voice-profiles", {
      name,
      provider_voice_key: voiceProfileKey.value,
      provider: "openai",
      language: activeScene.value?.voice_settings?.language || "en",
    });
    const created = response.data?.data?.voice_profile;
    if (created) voiceProfiles.value = [...voiceProfiles.value, created].sort((a, b) => a.name.localeCompare(b.name));
    voiceProfileSaveName.value = "";
    voiceProfileSaveOpen.value = false;
  } catch (_) {
    // non-critical
  } finally {
    voiceProfileSaving.value = false;
  }
}

async function saveSceneDuration() {
  if (!activeScene.value) return;
  const val = parseFloat(sceneDurationDraft.value);
  if (isNaN(val) || val < 1 || val > 600) return;
  sceneDurationSaving.value = true;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, { duration_seconds: val });
    const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updated) replaceSceneInCollection(updated);
  } catch (_) {
    // non-critical — leave draft value as-is
  } finally {
    sceneDurationSaving.value = false;
  }
}

function toggleRewriteTools() {
  rewriteToolsVisible.value = !rewriteToolsVisible.value;
  if (!rewriteToolsVisible.value) {
    rewritePreviewVisible.value = false;
    rewritePreviewCopy.value = "";
    rewriteMode.value = "";
    rewriteError.value = "";
  }
}

async function submitRewrite(modeLabel) {
  if (!activeScene.value) return;

  const mode = rewriteModeMap[modeLabel];
  if (!mode) return;

  rewritePending.value = true;
  rewriteError.value = "";
  rewriteMode.value = mode;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
      mode,
      apply: false,
    });

    rewritePreviewCopy.value =
      response.data?.data?.rewrite?.candidate || "";
    rewritePreviewVisible.value = Boolean(rewritePreviewCopy.value);
  } catch (requestError) {
    rewriteError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "Rewrite failed.";
    rewritePreviewVisible.value = false;
  } finally {
    rewritePending.value = false;
  }
}

function submitRewriteCustom() {
  const source =
    sceneScriptDraft.value.trim() || activeScene.value?.script_text || "";
  if (!source) return;

  const suffix = rewriteCustomInstruction.value.trim();
  rewriteMode.value = "custom";
  rewriteError.value = "";
  rewritePreviewCopy.value = suffix ? `${source} ${suffix}.` : source;
  rewritePreviewVisible.value = true;
}

function hideRewritePreview() {
  rewritePreviewVisible.value = false;
  rewriteError.value = "";
}

async function acceptRewrite() {
  if (!activeScene.value) return;

  if (rewriteMode.value && rewriteMode.value !== "custom") {
    rewriteApplyPending.value = true;
    rewriteError.value = "";

    try {
      const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
        mode: rewriteMode.value,
        apply: true,
      });

      const updatedScene = response.data?.data?.scene ?? null;
      if (updatedScene) {
        const normalizedScene = normalizeScenePayload(updatedScene);
        scenes.value = scenes.value.map((scene) =>
          scene.id === normalizedScene.id ? { ...scene, ...normalizedScene } : scene
        );
        activeSceneId.value = normalizedScene.id;
        sceneScriptDraft.value = normalizedScene.script_text || "";
      }

      rewritePreviewVisible.value = false;
      return;
    } catch (requestError) {
      rewriteError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Failed to apply rewrite.";
      return;
    } finally {
      rewriteApplyPending.value = false;
    }
  }

  sceneScriptDraft.value = rewritePreviewCopy.value;
  activeScene.value.voice_settings = {
    ...(activeScene.value.voice_settings || {}),
    is_outdated: true,
  };
  rewritePreviewVisible.value = false;
}

async function regenerateVoiceForScene(sceneId) {
  if (voiceRegeneratePending.value) return
  // If not already active, select it first so the panel shows the right scene
  if (activeSceneId.value !== sceneId) selectScene(sceneId)
  await nextTick()
  regenerateVoice()
}

async function regenerateVoice() {
  if (!activeScene.value || voiceRegeneratePending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  voiceRegeneratePending.value = true;
  voiceRegenerateError.value = "";
  isAudioPlaying.value = false;
  isAudioLoading.value = true;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/regenerate-voice`);
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Voice regeneration returned no scene payload.");
    }

    updatedScene.voice_settings = {
      ...(updatedScene.voice_settings || {}),
      is_outdated: false,
    };
    updatedScene.voice_settings_json = updatedScene.voice_settings;

    replaceSceneInCollection(updatedScene);
    activeSceneId.value = updatedScene.id;
    sceneScriptDraft.value = updatedScene.script_text || "";
    voiceProfileKey.value = updatedScene.voice_settings?.voice_id || "alloy";
    voiceSpeedDraft.value = String(updatedScene.voice_settings?.speed ?? 1.0);
    voiceStabilityDraft.value = String(updatedScene.voice_settings?.stability ?? "medium");
    voiceSaveState.value = "saved";
    scriptSaveState.value = "idle";
    preloadSceneAudio(updatedScene);
    pushToast({ id: `voice-done-${updatedScene.id}-${Date.now()}`, title: 'Voice ready', message: `Scene ${updatedScene.scene_order ?? ''} voice regenerated.` });
  } catch (requestError) {
    voiceRegenerateError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Voice regeneration failed.";
    isAudioLoading.value = false;
  } finally {
    voiceRegeneratePending.value = false;
  }
}

async function persistSceneScript(sceneId, scriptText) {
  scriptSaveTimer = null;
  scriptSaveState.value = "saving";
  scriptSaveError.value = "";

  const currentScene = scenes.value.find((scene) => scene.id === sceneId);
  const voiceSettings = {
    ...((currentScene?.voice_settings || currentScene?.voice_settings_json || {}) ?? {}),
    is_outdated: true,
  };

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      script_text: scriptText,
      status: "edited",
      voice_settings_json: voiceSettings,
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Scene save returned no payload.");
    }

    replaceSceneInCollection(updatedScene);

    if (activeSceneId.value === updatedScene.id) {
      sceneScriptDraft.value = updatedScene.script_text || "";
    }

    scriptSaveState.value = "saved";
    window.setTimeout(() => {
      if (scriptSaveState.value === "saved") {
        scriptSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    scriptSaveState.value = "error";
    scriptSaveError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Save failed.";
  }
}

async function persistVoiceSettings(sceneId, nextSettings) {
  voiceSaveTimer = null;
  voiceSaveState.value = "saving";
  voiceSaveError.value = "";

  const currentScene = scenes.value.find((scene) => scene.id === sceneId);
  const currentVoice = currentScene?.voice_settings || {};
  const mergedSettings = {
    ...currentVoice,
    voice_id: nextSettings.voice_id,
    speed: nextSettings.speed,
    stability: nextSettings.stability,
    is_outdated: true,
  };

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      voice_settings_json: mergedSettings,
      status: "edited",
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Voice save returned no payload.");
    }

    scenes.value = scenes.value.map((scene) =>
      scene.id === updatedScene.id ? { ...scene, ...updatedScene } : scene
    );

    voiceSaveState.value = "saved";
    window.setTimeout(() => {
      if (voiceSaveState.value === "saved") {
        voiceSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    voiceSaveState.value = "error";
    voiceSaveError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Voice save failed.";
  }
}

async function persistCaptionSettings(sceneId, nextSettings) {
  captionSaveTimer = null;
  captionSaveState.value = "saving";
  captionSaveError.value = "";

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      caption_settings_json: nextSettings,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene);

    if (!updatedScene) {
      throw new Error("Caption update returned no scene payload.");
    }

    replaceSceneInCollection(updatedScene);
    captionSaveState.value = "saved";
    window.setTimeout(() => {
      if (captionSaveState.value === "saved") {
        captionSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    captionSaveState.value = "error";
    captionSaveError.value =
      requestError.response?.data?.error?.message ?? "Caption save failed";
  }
}

async function persistMotionSettings(sceneId, nextSettings) {
  motionSaveTimer = null;
  motionSaveState.value = "saving";
  motionSaveError.value = "";

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      motion_settings_json: nextSettings,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene);
    if (updatedScene) replaceSceneInCollection(updatedScene);
    motionSaveState.value = "saved";
    window.setTimeout(() => {
      if (motionSaveState.value === "saved") motionSaveState.value = "idle";
    }, 1200);
  } catch (requestError) {
    motionSaveState.value = "error";
    motionSaveError.value =
      requestError.response?.data?.error?.message ?? "Motion save failed";
  }
}

// ── Characters ──────────────────────────────────────────────────────────────
async function loadCharacters() {
  try {
    const response = await api.get("/characters");
    characters.value = response.data?.data?.characters ?? [];
  } catch {
    characters.value = [];
  }
}

const activeCharacter = computed(() => {
  const id = activeScene.value?.character_id;
  if (!id) return null;
  return characters.value.find((c) => c.id === id) ?? null;
});

// The character currently bound to the in-progress add-scene draft.
// Backs the rich chip + popover above the Style grid in both add-scene
// popovers. Null until the user picks one.
const addSceneCharacter = computed(() => {
  const id = addSceneCharacterId.value;
  if (!id) return null;
  return characters.value.find((c) => c.id === Number(id)) ?? null;
});

function selectAddSceneCharacter(id) {
  addSceneCharacterId.value = id;
  addSceneCharPopoverOpen.value = false;
}

async function selectCharacter(characterId) {
  const scene = activeScene.value;
  if (!scene) return;
  characterPopoverOpen.value = false;
  try {
    const response = await api.patch(`/scenes/${scene.id}`, { character_id: characterId });
    const updated = response.data?.data?.scene;
    if (updated) {
      scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
    }
  } catch (e) {
    // Silent revert — scene unchanged.
  }
}

function pickCharacterFile(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!file.type.startsWith("image/")) {
    createCharacterError.value = "Please pick an image file (PNG or JPG).";
    return;
  }
  if (file.size > 10 * 1024 * 1024) {
    createCharacterError.value = "Image must be 10MB or less.";
    return;
  }
  createCharacterError.value = "";
  createCharacterFile.value = file;
  if (createCharacterPreviewUrl.value) URL.revokeObjectURL(createCharacterPreviewUrl.value);
  createCharacterPreviewUrl.value = URL.createObjectURL(file);
}

function clearCharacterFile() {
  if (createCharacterPreviewUrl.value) URL.revokeObjectURL(createCharacterPreviewUrl.value);
  createCharacterFile.value = null;
  createCharacterPreviewUrl.value = "";
}

function closeCharacterModal() {
  createCharacterOpen.value = false;
  createCharacterName.value = "";
  createCharacterDescription.value = "";
  clearCharacterFile();
  createCharacterError.value = "";
}

async function createCharacter() {
  const name = createCharacterName.value.trim();
  if (!name) {
    createCharacterError.value = "Name is required.";
    return;
  }
  createCharacterSaving.value = true;
  createCharacterError.value = "";
  try {
    // If user attached an image, upload it as a workspace asset first.
    let referenceAssetId = null;
    if (createCharacterFile.value) {
      const fd = new FormData();
      fd.append("title", name);
      fd.append("asset_type", "image");
      fd.append("asset_file", createCharacterFile.value);
      const upload = await api.post("/assets", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      referenceAssetId = upload.data?.data?.asset?.id ?? null;
    }

    const response = await api.post("/characters", {
      name,
      description: createCharacterDescription.value.trim() || null,
      reference_asset_id: referenceAssetId,
    });
    const created = response.data?.data?.character;
    if (created) {
      characters.value = [created, ...characters.value];
      await selectCharacter(created.id);
    }
    closeCharacterModal();
  } catch (e) {
    createCharacterError.value =
      e.response?.data?.error?.message ?? "Could not create character.";
  } finally {
    createCharacterSaving.value = false;
  }
}

function hookScoreClass(score) {
  if (score == null) return "score-none";
  if (score >= 80) return "score-green";
  if (score >= 60) return "score-yellow";
  return "score-red";
}

async function useHook(option) {
  // Apply hook text to the first hook-type scene (or the first scene if none).
  const hookScene =
    scenes.value.find((s) => s.scene_type === "hook") ?? scenes.value[0] ?? null;
  if (!hookScene) return;

  try {
    const response = await api.patch(`/scenes/${hookScene.id}`, {
      script_text: option.hook_text,
    });
    const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updated) {
      replaceSceneInCollection(updated);
      if (activeSceneId.value === hookScene.id) {
        sceneScriptDraft.value = option.hook_text;
      }
    }
  } catch {
    // silent — hook text can be copied manually if PATCH fails
  }
}

async function swapVisual() {
  if (!activeScene.value || visualSwapPending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  currentVisualUrl.value = null;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/swap-visual`, {
      query: visualQueryDraft.value || activeScene.value.visual_prompt || "",
      visual_type: selectedVisualType(),
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Visual swap returned no payload.");
    }

    replaceSceneInCollection(updatedScene);

    visualQueryDraft.value = updatedScene.visual_prompt || "";
    preloadSceneVisual(updatedScene);
  } catch (requestError) {
    visualSwapError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Visual swap failed.";
    visualLoadFailed.value = true;
  } finally {
    visualSwapPending.value = false;
  }
}

async function generateAIImage() {
  if (!activeScene.value || aiImagePending.value || activeSceneAIImagePending.value) return;

  aiImagePending.value = true;
  aiImageError.value = "";

  try {
    await api.post(`/scenes/${activeScene.value.id}/generate-image`, {
      style: visualStyleDraft.value ?? activeScene.value?.visual_style ?? activeScene.value?.image_generation_settings?.style ?? "cinematic",
      prompt_override: aiImagePromptOverride.value || undefined,
      model_key: aiImageModelKey.value,
    });
    // Reverb fires the result — polling is a safety net if the socket drops
    pollSceneUntilVisual(activeScene.value.id)
  } catch (err) {
    // 409 means the backend already has a generation in progress — treat as pending, not an error
    if (err.response?.status === 409) {
      aiImageError.value = "";
      // Stay pending; Reverb will fire when it completes
      return;
    }
    aiImageError.value =
      err.response?.data?.error?.message ||
      err.message ||
      "Image generation failed.";
    aiImagePending.value = false;
  }
  // aiImagePending stays true until Reverb fires completed/failed
}

// ── i2v Animation (rung 4) ──────────────────────────────────────────────────
function openAnimateModal() {
  if (!canAnimateActiveScene.value || activeSceneAnimationPending.value) return;
  // Pre-fill with the scene's last animation settings if any — saves the
  // re-animate flow a tier/duration click.
  const lastSettings = activeScene.value?.image_generation_settings ?? {};
  animateTier.value = ['quick','balanced','premium','seedance_lite','seedance_pro'].includes(lastSettings.animation_tier)
    ? lastSettings.animation_tier
    : 'quick';
  animateDuration.value = lastSettings.animation_duration === 10 ? 10 : 5;
  // Pre-fill priority: prior animation prompt → parser-suggested motion
  // (stashed by one-shot prompt flow on scene.image_generation_settings.
  // suggested_motion_prompt) → empty.
  animateMotionPrompt.value =
    lastSettings.animation_motion_prompt
    || lastSettings.suggested_motion_prompt
    || "";
  animateError.value = "";
  animateModalOpen.value = true;
}

function closeAnimateModal() {
  if (animateSubmitting.value) return;
  animateModalOpen.value = false;
}

const canRevertAnimation = computed(() => {
  const settings = activeScene.value?.image_generation_settings ?? {};
  return activeSceneAlreadyAnimated.value && Boolean(settings.animation_original_image_asset_id);
});

const activeSceneAnimationHistory = computed(() => {
  const items = activeScene.value?.image_generation_settings?.animation_history;
  return Array.isArray(items) ? items : [];
});

async function useHistoryAnimation(assetId) {
  const scene = activeScene.value;
  if (!scene) return;
  try {
    const response = await api.post(`/scenes/${scene.id}/animate/use-history`, { asset_id: assetId });
    const updated = response.data?.data?.scene;
    if (updated) {
      scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
    }
  } catch (err) {
    pushToast({
      id: `history-fail-${scene.id}-${Date.now()}`,
      title: 'Could not swap',
      message: err.response?.data?.error?.message ?? 'That animation is no longer available.',
    });
  }
}

async function cancelAnimation() {
  const scene = activeScene.value;
  if (!scene || !activeSceneAnimationPending.value) return;
  try {
    const response = await api.post(`/scenes/${scene.id}/animate/cancel`);
    const refunded = response.data?.data?.refunded_credits ?? 0;
    const updated = response.data?.data?.scene;
    if (updated) {
      scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
    }
    pushToast({
      id: `anim-cancel-${scene.id}-${Date.now()}`,
      title: 'Animation cancelled',
      message: refunded > 0 ? `${refunded} credits refunded.` : 'Refund not applicable.',
    });
  } catch (err) {
    pushToast({
      id: `anim-cancel-fail-${scene.id}-${Date.now()}`,
      title: 'Could not cancel',
      message: err.response?.data?.error?.message ?? 'Cancel request failed.',
    });
  }
}

async function revertAnimation() {
  const scene = activeScene.value;
  if (!scene || !canRevertAnimation.value) return;
  try {
    const response = await api.post(`/scenes/${scene.id}/animate/revert`);
    const updated = response.data?.data?.scene;
    if (updated) {
      scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
    }
    pushToast({ id: `revert-${scene.id}-${Date.now()}`, title: 'Reverted', message: 'Original image restored.' });
  } catch (err) {
    pushToast({
      id: `revert-fail-${scene.id}-${Date.now()}`,
      title: 'Could not revert',
      message: err.response?.data?.error?.message ?? 'The original image is no longer available.',
    });
  }
}

async function submitAnimate() {
  const scene = activeScene.value;
  if (!scene) return;
  animateSubmitting.value = true;
  animateError.value = "";
  try {
    const response = await api.post(`/scenes/${scene.id}/animate`, {
      tier: animateTier.value,
      duration_seconds: animateDuration.value,
      motion_prompt: animateMotionPrompt.value.trim() || null,
    });
    const updated = response.data?.data?.scene;
    if (updated) {
      scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
    }
    animateModalOpen.value = false;
    pollSceneUntilVisual(scene.id);
  } catch (err) {
    animateError.value =
      err.response?.data?.error?.message ?? "Animation could not start.";
  } finally {
    animateSubmitting.value = false;
  }
}

// Max attempts × ~5s/attempt sets the polling ceiling. Replicate i2v on
// premium tier (Kling 2.1) routinely takes 3–5 min; image gen with character
// (gpt-image-2) takes 30–90s. 80 attempts (~6–7 min) covers the slowest path
// without leaving the spinner up forever if Reverb dropped the completion
// event mid-run.
const POLL_MAX_ATTEMPTS = 80;

async function pollSceneUntilVisual(sceneId, attempt = 0) {
  if (attempt >= POLL_MAX_ATTEMPTS) {
    // Last-ditch: one final refresh so the cached scene state reflects
    // reality even if we just gave up. Without this, animation_in_progress
    // stays true locally and the UI shows "Animating…" indefinitely while
    // the backend has actually completed.
    try {
      const response = await api.get(`/scenes/${sceneId}/preview`);
      const refreshed = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (refreshed) replaceSceneInCollection(refreshed);
    } catch {
      // Silent — if even this fails, the user can hard-refresh.
    }
    aiImagePending.value = false;
    return;
  }

  window.setTimeout(async () => {
    try {
      const response = await api.get(`/scenes/${sceneId}/preview`);
      const refreshed = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (refreshed) {
        replaceSceneInCollection(refreshed);
        const settings = refreshed.image_generation_settings ?? {};

        // Image generation OR animation job still running — keep polling.
        if (settings.in_progress || settings.animation_in_progress) {
          pollSceneUntilVisual(sceneId, attempt + 1);
          return;
        }

        // Animation failure surfaces via animation_last_error — bail with a toast.
        if (settings.animation_last_error) {
          aiImagePending.value = false;
          pushToast({ id: `anim-fail-${refreshed.id}-${Date.now()}`, title: 'Animation failed', message: settings.animation_last_error.slice(0, 240) });
          return;
        }

        // Animation success: new visual_asset will be a video.
        if (settings.animation_video_asset_id && refreshed.visual_asset?.asset_type === 'video') {
          aiImagePending.value = false;
          pushToast({ id: `anim-done-${refreshed.id}-${Date.now()}`, title: 'Animation ready', message: `Scene ${refreshed.scene_order ?? ''} is now a video clip.` });
          return;
        }

        // Image gen failed
        if (settings.needs_visual) {
          aiImagePending.value = false;
          aiImageError.value =
            settings.last_error ||
            "Image generation failed. Please revise the prompt and try again.";
          return;
        }

        // Image gen succeeded — visual_asset is now the newly generated image
        if (refreshed.visual_asset) {
          aiImagePending.value = false;
          aiImageError.value = "";
          pushToast({ id: `ai-image-done-${refreshed.id}-${Date.now()}`, title: 'Image ready', message: `Scene ${refreshed.scene_order ?? ''} AI image generated.` });
          return;
        }
      }
    } catch {
      // Realtime updates may still arrive; keep polling briefly as a fallback.
    }

    pollSceneUntilVisual(sceneId, attempt + 1);
  }, attempt < 3 ? 2500 : 5000);
}

async function queueExport() {
  if (!project.value || exportPending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  exportPending.value = true;
  exportState.value = "saving";

  try {
    const response = await api.post(`/projects/${project.value.id}/export`, {
      aspect_ratio: project.value.aspect_ratio || "9:16",
      language: project.value.primary_language || "en",
      watermark_enabled: false,
    });

    const exportJob = response.data?.data?.export_job ?? null;
    queuedExportJobId.value = exportJob?.id ?? null;
    if (exportJob) {
      exportJobs.value = [
        exportJob,
        ...exportJobs.value.filter((job) => job.id !== exportJob.id),
      ];
    }
    exportState.value = "saved";
    pushToast({
      id: `export-${exportJob?.id || Date.now()}`,
      title: "Export queued",
      message: exportJob?.file_name || "Your export job has been queued.",
      created_at: new Date().toISOString(),
    });
    window.setTimeout(() => {
      if (exportState.value === "saved") {
        exportState.value = "idle";
      }
    }, 2500);
  } catch (requestError) {
    exportState.value = "error";
    const message =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "Export failed.";
    pushToast({
      id: `export-error-${Date.now()}`,
      title: "Export failed",
      message,
      created_at: new Date().toISOString(),
    });
  } finally {
    exportPending.value = false;
  }
}

let projectChannelName = null;

function subscribeProjectChannel() {
  const echo = getEcho();
  if (!echo || !projectId.value) return;

  projectChannelName = `project.${projectId.value}`;

  echo.private(projectChannelName).listen(".generation.progress", (payload) => {
    // Drive the Cruise chat's running action card from the same event
    // stream so the user sees the checklist tick off stage by stage.
    // We no longer suppress the editor refresh — replaceSceneInCollection
    // mutates in place now so per-stage updates are cheap and the Config
    // tab stays consistent with the Assistant's checklist.
    try { cruiseHandleProgressEvent(payload); } catch (_) {}

    if (payload.stage === "tts" && ["completed", "failed"].includes(String(payload.status || ""))) {
      if (payload.scene_id) {
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
      }
      refreshProjectPayload().catch(() => {});
      return;
    }

    // Animation events run on the same project channel — refresh the scene so the
    // editor swaps in the new video asset (or shows the persisted error).
    if (payload.stage === "animation") {
      if (payload.scene_id && ["processing", "completed", "failed"].includes(String(payload.status || ""))) {
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
        if (payload.status === "completed") {
          pushToast({
            id: `anim-done-${payload.scene_id}-${Date.now()}`,
            title: 'Animation ready',
            message: `Scene clip is ready to play.`,
          });
        }
      }
      return;
    }

    if (payload.stage !== "ai_image") return;

    if (payload.status === "processing" && payload.scene_id) {
      api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
        const refreshed = res.data?.data?.scene ?? null;
        if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
      }).catch(() => {});
    } else if (payload.status === "completed" && payload.scene_id) {
      // Refresh the scene so the new visual_asset appears in the editor
      api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
        const refreshed = res.data?.data?.scene ?? null;
        if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
      }).catch(() => {});
      aiImagePending.value = false;
      aiImageError.value = "";
    } else if (payload.status === "failed") {
      aiImagePending.value = false;
      aiImageError.value =
        payload.message || "Image generation failed. Please revise the prompt and try again.";
      if (payload.scene_id) {
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
      }
    }
  });

  echo.private(projectChannelName).listen(".export.progress", (payload) => {
    const jobId = Number(payload.export_job_id);
    const idx = exportJobs.value.findIndex((j) => j.id === jobId);
    const nextJob = {
      ...(idx >= 0 ? exportJobs.value[idx] : {}),
      id: jobId,
      status: payload.status,
      progress_percent: payload.progress_percent,
      failure_reason: payload.failure_reason ?? payload.message ?? null,
      file_name: payload.file_name ?? (idx >= 0 ? exportJobs.value[idx]?.file_name : null),
    };

    if (idx >= 0) {
      exportJobs.value = exportJobs.value.map((job) =>
        job.id === jobId
          ? nextJob
          : job
      );
    } else {
      exportJobs.value = [nextJob, ...exportJobs.value];
    }

    if (["completed", "failed"].includes(String(payload.status || ""))) {
      loadExportJobs();
    }
  });
}

function unsubscribeProjectChannel() {
  const echo = getEcho();
  if (echo && projectChannelName) {
    echo.leave(projectChannelName);
    projectChannelName = null;
  }
}

async function createScene(insertAfterSceneId = null) {
  if (!project.value) return;

  const scriptText = newSceneScript.value.trim();
  const sceneType = String(selectedSceneType.value || "Narration")
    .toLowerCase()
    .replace(/\s+/g, "_");
  const addSceneModeTypeMap = {
    stock_video: addSceneStockSubType.value || "stock_clip",
    stock_image: "image_montage",
    ai_image: "ai_image",
    audiogram: "waveform",
    assets: addScenePickedAsset.value
      ? (addScenePickedAsset.value.asset_type === "video" ? "background_loop" : "image_montage")
      : "stock_clip",
  };
  // When the user clicks Add Scene without picking a source tab (the new
  // default after we stopped auto-defaulting to Stock Video), send the
  // sentinel 'placeholder' so the backend stores a scene with no visual
  // intent. The editor surfaces "No visual assigned — pick one" on that
  // scene and the user fills it in afterward instead of silently getting
  // a random stock clip they didn't ask for.
  const visualType = addSceneVisualMode.value
    ? (addSceneModeTypeMap[addSceneVisualMode.value] ?? "stock_clip")
    : "placeholder";
  // Only send the query (which the backend uses to seed a stock search) when
  // the user typed it OR explicitly chose a source. An untouched popover
  // sends no query → no auto-fetch.
  const visualQuery = addSceneVisualMode.value
    ? (addSceneVisualQuery.value.trim() || scriptText || buildSceneLabel(sceneType))
    : null;

  try {
    const response = await api.post("/scenes", {
      project_id: project.value.id,
      insert_after_scene_id: insertAfterSceneId,
      scene_type: sceneType,
      label: buildSceneLabel(sceneType),
      script_text: scriptText,
      duration_seconds: scriptText
        ? Math.max(
            3,
            Math.min(12, Math.ceil(scriptText.split(/\s+/).filter(Boolean).length / 3))
          )
        : 3,
      visual_type: visualType,
      visual_prompt: visualQuery || null,
      visual_style: addSceneVisualStyle.value,
      // Pass the typed custom descriptor only when 'custom' was actually
      // picked; otherwise leave undefined so the backend falls through to
      // its inherit-from-project default.
      ...(addSceneVisualStyle.value === 'custom' && addSceneCustomVisualStyle.value.trim()
        ? { custom_visual_style: addSceneCustomVisualStyle.value.trim() }
        : {}),
      // Pre-attach the asset picked via MediaPickerModal when Assets tab is used.
      ...(addSceneVisualMode.value === 'assets' && addScenePickedAsset.value
        ? { visual_asset_id: addScenePickedAsset.value.id }
        : {}),
      // Per-scene character override; null = backend inherits project default.
      ...(addSceneCharacterId.value
        ? { character_id: Number(addSceneCharacterId.value) }
        : {}),
    });

    const createdScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (!createdScene) {
      throw new Error("Scene create returned no payload.");
    }

    let nextScene = createdScene;

    if (visualType === "ai_image") {
      aiImagePending.value = true;
      aiImageError.value = "";
      await api.post(`/scenes/${createdScene.id}/generate-image`, {
        style: addSceneVisualStyle.value || "cinematic",
        prompt_override: visualQuery || undefined,
      });
      nextScene = {
        ...createdScene,
        visual_type: "ai_image",
        visual_prompt: visualQuery,
        visual_style: addSceneVisualStyle.value,
      };
    } else if (!["text_card", "waveform"].includes(visualType)) {
      const visualResponse = await api.post(`/scenes/${createdScene.id}/swap-visual`, {
        query: visualQuery,
        visual_type: visualType,
      });
      nextScene = normalizeScenePayload(visualResponse.data?.data?.scene ?? null) || createdScene;
    }

    scenes.value = sortScenesByOrder([
      ...scenes.value.filter((scene) => scene.id !== nextScene.id),
      nextScene,
    ]);
    preloadSceneVisual(nextScene);
    closeAddScene();
    activeSceneId.value = nextScene.id;
    if (visualType === "ai_image") {
      pollSceneUntilVisual(nextScene.id);
    }
  } catch (requestError) {
    pushToast({
      id: `scene-create-error-${Date.now()}`,
      title: "Could not add scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Scene create failed.",
      created_at: new Date().toISOString(),
    });
  }
}

async function duplicateScene(sceneId) {
  try {
    const response = await api.post(`/scenes/${sceneId}/duplicate`);
    const duplicatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!duplicatedScene) {
      throw new Error("Scene duplicate returned no payload.");
    }

    scenes.value = sortScenesByOrder([...scenes.value, duplicatedScene]);
    activeSceneId.value = duplicatedScene.id;
  } catch (requestError) {
    pushToast({
      id: `scene-duplicate-error-${sceneId}`,
      title: "Could not duplicate scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene duplicate failed.",
      created_at: new Date().toISOString(),
    });
  }
}

function promptDeleteScene(sceneId) {
  if (scenes.value.length <= 1) {
    pushToast({
      id: `scene-delete-blocked-${sceneId}`,
      title: "Cannot delete scene",
      message: "Projects must keep at least one scene.",
      created_at: new Date().toISOString(),
    });
    return;
  }

  deleteSceneTarget.value = scenes.value.find((scene) => scene.id === sceneId) ?? null;
}

function closeDeleteSceneModal() {
  if (deleteScenePending.value) return;
  deleteSceneTarget.value = null;
}

async function confirmDeleteScene() {
  const sceneId = deleteSceneTarget.value?.id;
  if (!sceneId || deleteScenePending.value) return;

  deleteScenePending.value = true;

  try {
    await api.delete(`/scenes/${sceneId}`);
    const remaining = scenes.value
      .filter((scene) => scene.id !== sceneId)
      .map((scene, index) => ({ ...scene, scene_order: index + 1 }));
    scenes.value = remaining;

    if (activeSceneId.value === sceneId) {
      activeSceneId.value = remaining[0]?.id ?? null;
    }
    deleteSceneTarget.value = null;
  } catch (requestError) {
    pushToast({
      id: `scene-delete-error-${sceneId}`,
      title: "Could not delete scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene delete failed.",
      created_at: new Date().toISOString(),
    });
  } finally {
    deleteScenePending.value = false;
  }
}

async function moveScene(sceneId, direction) {
  if (sceneReorderPendingId.value !== null) return;

  const index = scenes.value.findIndex((scene) => scene.id === sceneId);
  if (index < 0) return;

  const targetIndex = direction === "up" ? index - 1 : index + 1;
  if (targetIndex < 0 || targetIndex >= scenes.value.length) return;

  const previousScenes = [...scenes.value];
  const reordered = [...scenes.value];
  const [movedScene] = reordered.splice(index, 1);
  reordered.splice(targetIndex, 0, movedScene);
  scenes.value = reordered.map((scene, orderIndex) => ({
    ...scene,
    scene_order: orderIndex + 1,
  }));
  sceneReorderPendingId.value = sceneId;

  try {
    const response = await api.patch("/scenes/reorder", {
      project_id: project.value.id,
      scene_ids: reordered.map((scene) => scene.id),
    });

    scenes.value = sortScenesByOrder(
      (response.data?.data?.scenes ?? [])
        .map((scene) => normalizeScenePayload(scene))
        .filter(Boolean)
    );
  } catch (requestError) {
    scenes.value = previousScenes;
    pushToast({
      id: `scene-reorder-error-${sceneId}`,
      title: "Could not reorder scenes",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene reorder failed.",
      created_at: new Date().toISOString(),
    });
  } finally {
    sceneReorderPendingId.value = null;
  }
}

async function flushActiveSceneDrafts() {
  const scene = activeScene.value;
  if (!scene) return true;

  try {
    if (scriptSaveTimer || scriptSaveState.value === "pending") {
      if (scriptSaveTimer) {
        window.clearTimeout(scriptSaveTimer);
        scriptSaveTimer = null;
      }

      if (sceneScriptDraft.value !== (scene.script_text || "")) {
        await persistSceneScript(scene.id, sceneScriptDraft.value);
      }
    }

    const savedVoice = scene.voice_settings || {};
    const nextVoice = {
      voice_id: voiceProfileKey.value,
      speed: Number(voiceSpeedDraft.value || 1),
      stability: voiceStabilityDraft.value,
    };

    if (
      voiceSaveTimer ||
      String(savedVoice.voice_id || "alloy") !== nextVoice.voice_id ||
      Number(savedVoice.speed ?? 1) !== nextVoice.speed ||
      String(savedVoice.stability || "medium") !== nextVoice.stability
    ) {
      if (voiceSaveTimer) {
        window.clearTimeout(voiceSaveTimer);
        voiceSaveTimer = null;
      }

      await persistVoiceSettings(scene.id, nextVoice);
    }

    const savedCaptions = activeCaptionSettings.value || {};
    const nextCaptions = {
      enabled: captionEnabledDraft.value,
      style_key: captionStyleDraft.value,
      highlight_mode: captionHighlightDraft.value,
      position: captionPositionDraft.value,
      font: captionFontDraft.value,
      highlight_color: savedCaptions.highlight_color || "#ff6b35",
      preset_id: savedCaptions.preset_id || null,
    };

    if (
      captionSaveTimer ||
      (savedCaptions.enabled !== false) !== nextCaptions.enabled ||
      String(savedCaptions.style_key || "impact") !== nextCaptions.style_key ||
      String(savedCaptions.highlight_mode || "keywords") !== nextCaptions.highlight_mode ||
      String(savedCaptions.position || "bottom_third") !== nextCaptions.position ||
      String(savedCaptions.font || DEFAULT_CAPTION_FONT) !== nextCaptions.font
    ) {
      if (captionSaveTimer) {
        window.clearTimeout(captionSaveTimer);
        captionSaveTimer = null;
      }

      await persistCaptionSettings(scene.id, nextCaptions);
    }
  } catch {
    return false;
  }

  return (
    scriptSaveState.value !== "error" &&
    voiceSaveState.value !== "error" &&
    captionSaveState.value !== "error"
  );
}

function toggleAudioPlayback() {
  if (!audioRef.value || !activeSceneAudioUrl.value) return;
  unlockWaveformAudio();
  isAudioLoading.value = true;
  preloadSceneAudio(activeScene.value);
  if (isAudioPlaying.value) {
    audioRef.value.pause();
    if (soundAudioRef.value) { soundAudioRef.value.pause(); }
    isAudioLoading.value = false;
  } else {
    audioRef.value.currentTime = 0;
    audioRef.value.play().catch(() => {
      isAudioPlaying.value = false;
      isAudioLoading.value = false;
    });
    if (soundAudioRef.value && activeSceneSoundUrl.value) {
      soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));
      soundAudioRef.value.currentTime = 0;
      soundAudioRef.value.play().catch(() => {});
    }
  }
}

function syncSceneSoundVolume() {
  if (soundAudioRef.value) {
    soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));
  }
}

onMounted(() => {
  beforeUnloadHandler = (event) => {
    if (
      scriptSaveState.value === "pending" ||
      scriptSaveState.value === "saving" ||
      voiceSaveState.value === "pending" ||
      voiceSaveState.value === "saving" ||
      captionSaveState.value === "pending" ||
      captionSaveState.value === "saving"
    ) {
      event.preventDefault();
      event.returnValue = "";
    }
  };
  window.addEventListener("beforeunload", beforeUnloadHandler);
  window.addEventListener("keydown", onCruiseKeydown);
  loadMe();
  loadProject();
  loadCharacters();
});

watch(showWaveformPreview, (active) => {
  if (active) startWaveformAnimation();
  else stopWaveformAnimation();
}, { immediate: true });

// Reconnect Web Audio when the narration audio element is replaced (scene change)
watch(audioRef, (el) => {
  if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
});

watch(musicAudioRef, (el) => {
  if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", onCruiseKeydown);
  if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
  stopWaveformAnimation();
  if (waveformAudioCtx) {
    try { waveformAudioCtx.close(); } catch {}
    waveformAudioCtx = null;
    waveformAnalyser = null;
    waveformConnectedCount = 0;
    waveformUserActivated = false;
  }
  if (scriptSaveTimer) {
    window.clearTimeout(scriptSaveTimer);
  }
  if (voiceSaveTimer) {
    window.clearTimeout(voiceSaveTimer);
  }
  if (captionSaveTimer) {
    window.clearTimeout(captionSaveTimer);
  }
  mediaPreloaders.forEach((media) => {
    media.onload = null;
    media.onerror = null;
    media.onloadeddata = null;
    media.oncanplaythrough = null;
  });
  mediaPreloaders.clear();
  unsubscribeWorkspaceNotifications();
  unsubscribeProjectChannel();
  if (beforeUnloadHandler) {
    window.removeEventListener("beforeunload", beforeUnloadHandler);
  }
});
</script>

<template>
  <main class="editor-page">
    <section v-if="loading" class="state-card">Loading project...</section>
    <section v-else-if="error" class="state-card error">{{ error }}</section>

    <div v-else class="editor-shell">
      <AppSidebar :user="mePayload" active-page="editor" @logout="logout" />

      <div :class="['main', timelineOpen ? 'sidebar-collapsed' : '']">
        <header class="topbar">
          <div class="topbar-left">
            <div class="topbar-title">Editor</div>
          </div>

          <div class="topbar-right">
            <div
              v-if="latestExportJob"
              :class="['export-pill', `export-pill-${latestExportJob.status}`]"
            >
              {{ exportStatusCopy(latestExportJob) }}
              <span
                v-if="latestExportJob.status === 'failed' && latestExportJob.failure_reason"
                class="export-fail-info"
                tabindex="0"
              >
                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-8-5a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1Zm0 8a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd"/></svg>
                <span class="export-fail-tooltip">{{ latestExportJob.failure_reason }}</span>
              </span>
              <template v-if="latestExportJob.status === 'completed' && latestExportDownloadUrl">
                <span class="export-pill-sep">·</span>
                <a
                  :href="latestExportDownloadUrl"
                  target="_blank"
                  rel="noopener"
                  class="export-pill-link"
                >Open ↗</a>
                <a
                  :href="latestExportDownloadUrl"
                  :download="latestExportJob.file_name || 'export.mp4'"
                  class="export-pill-link"
                >Download ↓</a>
                <span class="export-pill-sep">·</span>
                <button class="export-pill-link export-pill-schedule" @click="scheduleModalOpen = true">📅 Schedule</button>
                <span class="export-pill-sep">·</span>
                <button class="export-pill-link" @click="approvalModalOpen = true">📝 Send for approval</button>
                <span class="export-pill-sep">·</span>
                <button class="export-pill-link" @click="toggleShareLink" :disabled="shareTogglePending" :title="project?.is_shared ? 'Public link is on — click again to copy' : 'Generate a public link anyone can watch'">
                  {{ shareTogglePending ? '…' : (project?.is_shared ? '🔗 Copy share link' : '🔗 Share publicly') }}
                </button>
                <span v-if="shareCopiedToast" class="export-pill-sep">·</span>
                <span v-if="shareCopiedToast" class="export-share-copied">{{ shareCopiedToast }}</span>
              </template>
            </div>
            <button :class="['btn btn-ghost btn-timeline-toggle', timelineOpen ? 'active' : '']" type="button" @click="timelineOpen = !timelineOpen">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="11" height="4" rx="1"/><rect x="3" y="17" width="15" height="4" rx="1"/></svg>
              Timeline
            </button>
            <button class="btn btn-ghost btn-back" type="button" @click="router.push({ name: 'project-variants', params: { projectId: projectId } })">
              Variants
            </button>

            <div class="export-btn-wrap">
              <button
                class="btn btn-primary"
                type="button"
                :disabled="exportPending || !!exportBlockerMessage"
                @click="queueExport"
              >
                {{ exportPending ? "Exporting..." : "Export" }}
              </button>
            </div>
            <button class="btn btn-ghost btn-back" type="button" @click="router.push({ name: 'dashboard' })">
              Back to Dashboard
            </button>
            <NotifBell />
          </div>
        </header>

        <!-- Resume-failed banner — only when 1+ scenes are in a broken state.
             Shows count + click-to-retry; surfaces credit shortage inline. -->
        <div v-if="failedSceneCount > 0" class="resume-failed-banner">
          <div class="resume-failed-msg">
            <span class="resume-failed-icon">⚠</span>
            <div>
              <strong>{{ failedSceneCount }} scene{{ failedSceneCount === 1 ? '' : 's' }} failed</strong> to finish.
              {{ resumeError || 'You can re-run them in one click. Costs ~' + (failedSceneCount * 75) + ' credits at Quick tier.' }}
            </div>
          </div>
          <button
            class="btn btn-primary btn-sm"
            type="button"
            :disabled="resumePending"
            @click="resumeFailedScenes"
          >
            {{ resumePending ? 'Resuming…' : '↻ Resume failed' }}
          </button>
        </div>

        <div class="editor-body">
        <div class="editor active">
          <div class="editor-sidebar">
            <div class="editor-sidebar-header">
              <div class="editor-sidebar-title">Scenes</div>
              <button
                class="btn btn-ghost btn-sm"
                type="button"
                @click="toggleAddScene('top')"
              >
                + Add
              </button>
            </div>

            <div class="scene-list">
              <div
                :class="`add-scene-panel ${
                  addScenePanelPosition === 'top' ? 'open' : ''
                }`"
              >
                <div class="panel-title">
                  Add New Scene
                  <span class="close-x" @click="closeAddScene">×</span>
                </div>
                <div class="micro-label">Scene type</div>
                <div class="scene-type-chips">
                  <div
                    v-for="option in sceneTypeOptions"
                    :key="option"
                    :class="`scene-type-chip ${
                      selectedSceneType === option ? 'selected' : ''
                    }`"
                    @click="selectedSceneType = option"
                  >
                    {{ option }}
                  </div>
                </div>
                <textarea
                  v-model="newSceneScript"
                  class="add-scene-textarea"
                  placeholder="Write your scene script, or click 'AI Generate' to create one..."
                ></textarea>
                <div class="add-scene-visual-source">
                  <div class="visual-type-tabs add-scene-tabs">
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_video' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_video')">Video</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_image' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_image')">Image</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'ai_image' ? 'active ai' : '']" @click="selectAddSceneVisualMode('ai_image')">✦ AI</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'assets' ? 'active' : '']" @click="selectAddSceneVisualMode('assets')">Assets</button>
                  </div>

                  <template v-if="addSceneVisualMode === 'stock_video'">
                    <div class="control-row add-scene-control-row">
                      <span class="control-name">Type</span>
                      <select v-model="addSceneStockSubType" class="control-value">
                        <option value="stock_clip">Clip</option>
                        <option value="background_loop">BG Loop</option>
                        <option value="text_card">Text Only</option>
                      </select>
                    </div>
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'stock_image'">
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'ai_image'">
                    <!-- Reference character — mirrors the chip+thumbnail-grid
                         picker on the right-hand scene panel so the UX is
                         consistent across "edit a scene" and "add a scene".
                         Sits above Style because picking a character is the
                         first decision when a project has saved cast. -->
                    <div class="char-chip-wrap" @click.stop>
                      <div class="char-chip" @click="addSceneCharPopoverOpen = !addSceneCharPopoverOpen">
                        <div class="char-chip-thumb">
                          <img v-if="addSceneCharacter?.reference_asset?.thumbnail_url || addSceneCharacter?.reference_asset?.storage_url"
                               :src="addSceneCharacter.reference_asset.thumbnail_url || addSceneCharacter.reference_asset.storage_url" alt="" />
                          <span v-else-if="addSceneCharacter">{{ addSceneCharacter.name.charAt(0).toUpperCase() }}</span>
                          <span v-else style="opacity:.5;">◐</span>
                        </div>
                        <div class="char-chip-text">
                          <div class="char-chip-name">{{ addSceneCharacter?.name || 'No character' }}</div>
                          <div class="char-chip-trail">{{ addSceneCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                        </div>
                        <span class="char-chip-chev">▾</span>
                      </div>
                      <div v-if="addSceneCharPopoverOpen" class="char-popover">
                        <div v-if="characters.length === 0" class="char-popover-empty">
                          No characters yet. Create your first one.
                        </div>
                        <div v-else class="char-popover-grid">
                          <div
                            v-for="c in characters"
                            :key="`top-add-char-${c.id}`"
                            :class="['char-popover-item', addSceneCharacterId === c.id ? 'selected' : '']"
                            @click="selectAddSceneCharacter(c.id)"
                          >
                            <div class="char-popover-thumb">
                              <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                   :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                              <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                            </div>
                            <div class="char-popover-name">{{ c.name }}</div>
                            <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                          </div>
                        </div>
                        <div class="char-popover-foot">
                          <button v-if="addSceneCharacterId" class="char-popover-none" type="button" @click="selectAddSceneCharacter(null)">Remove</button>
                          <span v-else></span>
                          <button class="char-popover-new" type="button" @click="addSceneCharPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                        </div>
                      </div>
                    </div>

                    <div class="micro-label" style="margin:10px 0 6px;">Style</div>
                    <!-- Custom row-style dropdown, matches the main editor's
                         style picker so the editor reads as one consistent UI. -->
                    <div class="picker-wrap">
                      <button type="button" class="picker-trigger" @click="topAddStylePickerOpen = !topAddStylePickerOpen">
                        <img v-if="imageStylesByKey.get(addSceneVisualStyle)?.sample_url" :src="imageStylesByKey.get(addSceneVisualStyle).sample_url" class="picker-trigger-thumb" alt="" />
                        <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(addSceneVisualStyle)?.icon ?? '✦' }}</span>
                        <span class="picker-trigger-label">{{ imageStylesByKey.get(addSceneVisualStyle)?.label ?? 'Pick a style' }}</span>
                        <span class="picker-trigger-caret">▾</span>
                      </button>
                      <div v-if="topAddStylePickerOpen" class="picker-panel">
                        <div v-for="s in stylePickerRows" :key="`top-add-style-${s.key}`"
                          :class="['picker-row', addSceneVisualStyle === s.key ? 'selected' : '']"
                          @click="addSceneVisualStyle = s.key; topAddStylePickerOpen = false">
                          <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                          <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                          <div class="picker-row-text">
                            <div class="picker-row-name">{{ s.label }}</div>
                            <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                          </div>
                          <span v-if="addSceneVisualStyle === s.key" class="picker-row-check">✓</span>
                        </div>
                      </div>
                    </div>
                    <div v-if="addSceneVisualStyle === 'custom'" class="custom-style-panel">
                      <div class="micro-label" style="margin-bottom:4px;">Custom style descriptor</div>
                      <textarea
                        v-model="addSceneCustomVisualStyle"
                        class="scene-query-input"
                        rows="2"
                        maxlength="500"
                        placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                      ></textarea>
                      <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                        Leave blank to inherit from the project default.
                      </div>
                    </div>

                    <div class="scene-query-label" style="margin-top:10px;">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" maxlength="1000" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'assets'">
                    <div v-if="addScenePickedAsset" class="add-scene-asset-preview">
                      <img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" :alt="addScenePickedAsset.title" />
                      <div class="add-scene-asset-meta">
                        <div class="add-scene-asset-title">{{ addScenePickedAsset.title || 'Selected asset' }}</div>
                        <div class="add-scene-asset-type">{{ addScenePickedAsset.asset_type || 'asset' }}</div>
                      </div>
                      <button class="btn btn-ghost btn-sm" type="button" @click="pickAssetForNewScene">Replace</button>
                    </div>
                    <button v-else class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="pickAssetForNewScene">📁 Pick from your library</button>
                  </template>
                </div>
                <div class="add-scene-actions">
                  <button
                    class="btn btn-ghost btn-sm"
                    type="button"
                    @click="closeAddScene"
                  >
                    Cancel
                  </button>
                  <button class="btn btn-ghost btn-sm purple-btn" type="button" :disabled="addSceneGeneratePending" @click="generateNewSceneDraft">
                    {{ addSceneGeneratePending ? "Generating..." : "✦ AI Generate" }}
                  </button>
                  <button
                    class="btn btn-primary btn-sm"
                    type="button"
                    @click="createScene()"
                  >
                    Add Scene
                  </button>
                </div>
              </div>

              <!-- eslint-disable-next-line vue/no-v-for-template-key -->
              <template v-for="(scene, index) in scenes" :key="scene.id">
                <div
                  :id="`scene-item-${scene.id}`"
                  :class="`scene-item ${
                    activeSceneId === scene.id ? 'active' : ''
                  }`"
                  role="button"
                  tabindex="0"
                  @click="selectScene(scene.id)"
                  @keydown.enter.prevent="selectScene(scene.id)"
                  @keydown.space.prevent="selectScene(scene.id)"
                >
                  <div class="scene-number">
                    Scene {{ scene.scene_order }}
                    <span> · {{ sceneTypeLabel(scene) }}</span>
                    <span :class="sceneVoiceOutdated(scene) ? 'inline-warn' : 'inline-warn state-hidden'">
                      Voice outdated
                    </span>
                  </div>
                  <div class="scene-text">{{ scene.script_text }}</div>
                  <div v-if="sceneVoiceOutdated(scene)" class="scene-regen-row" @click.stop>
                    <button
                      class="scene-regen-btn"
                      type="button"
                      :disabled="voiceRegeneratePending && activeSceneId === scene.id"
                      @click="regenerateVoiceForScene(scene.id)"
                    >
                      {{ voiceRegeneratePending && activeSceneId === scene.id ? '↺ Regenerating…' : '↺ Regenerate voice' }}
                    </button>
                  </div>
                  <div class="scene-meta">
                    <span class="scene-tag">{{ sceneVisualLabel(scene) }}</span>
                    <span v-if="scene.visual_style" class="scene-style-badge">{{ scene.visual_style }}</span>
                    <span>{{
                      formatSceneDuration(scene.duration_seconds)
                    }}</span>
                  </div>
                  <div class="scene-actions" @click.stop>
                    <button
                      class="scene-action-btn"
                      type="button"
                      :disabled="index === 0 || sceneReorderPendingId !== null"
                      @click="moveScene(scene.id, 'up')"
                    >
                      {{ sceneReorderPendingId === scene.id ? "…" : "↑" }}
                    </button>
                    <button
                      class="scene-action-btn"
                      type="button"
                      :disabled="index === scenes.length - 1 || sceneReorderPendingId !== null"
                      @click="moveScene(scene.id, 'down')"
                    >
                      {{ sceneReorderPendingId === scene.id ? "…" : "↓" }}
                    </button>
                    <button
                      class="scene-action-btn"
                      type="button"
                      @click="duplicateScene(scene.id)"
                    >
                      Duplicate
                    </button>
                    <button
                      class="scene-action-btn danger"
                      type="button"
                      :disabled="sceneReorderPendingId !== null || deleteScenePending"
                      @click="promptDeleteScene(scene.id)"
                    >
                      Delete
                    </button>
                  </div>
                </div>

                <div
                  :class="`add-scene-divider ${
                    index === scenes.length - 1 ? 'always-visible' : ''
                  }`"
                >
                  <div
                    class="add-scene-trigger"
                    @click="toggleAddScene(`after-${scene.id}`)"
                  >
                    <svg
                      width="12"
                      height="12"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      viewBox="0 0 24 24"
                    >
                      <path d="M12 5v14M5 12h14"></path>
                    </svg>
                    Insert
                  </div>
                </div>

                <div
                  :class="`add-scene-panel ${
                    addScenePanelPosition === `after-${scene.id}` ? 'open' : ''
                  }`"
                >
                  <div class="panel-title">
                    Add New Scene
                    <span class="close-x" @click="closeAddScene">×</span>
                  </div>
                  <div class="micro-label">Scene type</div>
                  <div class="scene-type-chips">
                    <div
                      v-for="option in sceneTypeOptions"
                      :key="`${scene.id}-${option}`"
                      :class="`scene-type-chip ${
                        selectedSceneType === option ? 'selected' : ''
                      }`"
                      @click="selectedSceneType = option"
                    >
                      {{ option }}
                    </div>
                  </div>
                  <textarea
                    v-model="newSceneScript"
                    class="add-scene-textarea"
                    placeholder="Write your scene script, or click 'AI Generate' to create one..."
                  ></textarea>
                  <div class="add-scene-visual-source">
                    <div class="visual-type-tabs add-scene-tabs">
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_video' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_video')">Video</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_image' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_image')">Image</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'ai_image' ? 'active ai' : '']" @click="selectAddSceneVisualMode('ai_image')">✦ AI</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'assets' ? 'active' : '']" @click="selectAddSceneVisualMode('assets')">Assets</button>
                    </div>

                    <template v-if="addSceneVisualMode === 'stock_video'">
                      <div class="control-row add-scene-control-row">
                        <span class="control-name">Type</span>
                        <select v-model="addSceneStockSubType" class="control-value">
                          <option value="stock_clip">Clip</option>
                          <option value="background_loop">BG Loop</option>
                          <option value="text_card">Text Only</option>
                        </select>
                      </div>
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'stock_image'">
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'ai_image'">
                      <!-- Reference character — chip + thumbnail grid, identical
                           to the right-hand scene panel and the top add-scene
                           popover. Sits above Style on purpose. -->
                      <div class="char-chip-wrap" @click.stop>
                        <div class="char-chip" @click="addSceneCharPopoverOpen = !addSceneCharPopoverOpen">
                          <div class="char-chip-thumb">
                            <img v-if="addSceneCharacter?.reference_asset?.thumbnail_url || addSceneCharacter?.reference_asset?.storage_url"
                                 :src="addSceneCharacter.reference_asset.thumbnail_url || addSceneCharacter.reference_asset.storage_url" alt="" />
                            <span v-else-if="addSceneCharacter">{{ addSceneCharacter.name.charAt(0).toUpperCase() }}</span>
                            <span v-else style="opacity:.5;">◐</span>
                          </div>
                          <div class="char-chip-text">
                            <div class="char-chip-name">{{ addSceneCharacter?.name || 'No character' }}</div>
                            <div class="char-chip-trail">{{ addSceneCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                          </div>
                          <span class="char-chip-chev">▾</span>
                        </div>
                        <div v-if="addSceneCharPopoverOpen" class="char-popover">
                          <div v-if="characters.length === 0" class="char-popover-empty">
                            No characters yet. Create your first one.
                          </div>
                          <div v-else class="char-popover-grid">
                            <div
                              v-for="c in characters"
                              :key="`add-char-${scene.id}-${c.id}`"
                              :class="['char-popover-item', addSceneCharacterId === c.id ? 'selected' : '']"
                              @click="selectAddSceneCharacter(c.id)"
                            >
                              <div class="char-popover-thumb">
                                <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                     :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                                <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                              </div>
                              <div class="char-popover-name">{{ c.name }}</div>
                              <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                            </div>
                          </div>
                          <div class="char-popover-foot">
                            <button v-if="addSceneCharacterId" class="char-popover-none" type="button" @click="selectAddSceneCharacter(null)">Remove</button>
                            <span v-else></span>
                            <button class="char-popover-new" type="button" @click="addSceneCharPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                          </div>
                        </div>
                      </div>

                      <div class="micro-label" style="margin:10px 0 6px;">Style</div>
                      <!-- Dropdown variant — same as the main editor picker. -->
                      <div class="picker-wrap">
                        <button type="button" class="picker-trigger" @click="perSceneAddStyleOpenId = perSceneAddStyleOpenId === scene.id ? null : scene.id">
                          <img v-if="imageStylesByKey.get(addSceneVisualStyle)?.sample_url" :src="imageStylesByKey.get(addSceneVisualStyle).sample_url" class="picker-trigger-thumb" alt="" />
                          <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(addSceneVisualStyle)?.icon ?? '✦' }}</span>
                          <span class="picker-trigger-label">{{ imageStylesByKey.get(addSceneVisualStyle)?.label ?? 'Pick a style' }}</span>
                          <span class="picker-trigger-caret">▾</span>
                        </button>
                        <div v-if="perSceneAddStyleOpenId === scene.id" class="picker-panel">
                          <div v-for="s in stylePickerRows" :key="`add-style-${scene.id}-${s.key}`"
                            :class="['picker-row', addSceneVisualStyle === s.key ? 'selected' : '']"
                            @click="addSceneVisualStyle = s.key; perSceneAddStyleOpenId = null">
                            <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                            <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                            <div class="picker-row-text">
                              <div class="picker-row-name">{{ s.label }}</div>
                              <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                            </div>
                            <span v-if="addSceneVisualStyle === s.key" class="picker-row-check">✓</span>
                          </div>
                        </div>
                      </div>
                      <div v-if="addSceneVisualStyle === 'custom'" class="custom-style-panel">
                        <div class="micro-label" style="margin-bottom:4px;">Custom style descriptor</div>
                        <textarea
                          v-model="addSceneCustomVisualStyle"
                          class="scene-query-input"
                          rows="2"
                          maxlength="500"
                          placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                        ></textarea>
                        <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                          Leave blank to inherit from the project default.
                        </div>
                      </div>

                      <div class="scene-query-label" style="margin-top:10px;">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" maxlength="1000" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'assets'">
                      <div v-if="addScenePickedAsset" class="add-scene-asset-preview">
                        <img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" :alt="addScenePickedAsset.title" />
                        <div class="add-scene-asset-meta">
                          <div class="add-scene-asset-title">{{ addScenePickedAsset.title || 'Selected asset' }}</div>
                          <div class="add-scene-asset-type">{{ addScenePickedAsset.asset_type || 'asset' }}</div>
                        </div>
                        <button class="btn btn-ghost btn-sm" type="button" @click="pickAssetForNewScene">Replace</button>
                      </div>
                      <button v-else class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="pickAssetForNewScene">📁 Pick from your library</button>
                    </template>
                  </div>
                  <div v-if="addSceneGenerateError" class="rewrite-error">
                    {{ addSceneGenerateError }}
                  </div>
                  <div class="add-scene-actions">
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="closeAddScene"
                    >
                      Cancel
                    </button>
                    <button
                      class="btn btn-ghost btn-sm purple-btn"
                      type="button"
                      :disabled="addSceneGeneratePending"
                      @click="generateNewSceneDraft"
                    >
                      {{ addSceneGeneratePending ? "Generating..." : "✦ AI Generate" }}
                    </button>
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      @click="createScene(scene.id)"
                    >
                      Add Scene
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <div class="editor-canvas">
            <div class="preview-container" :style="previewContainerStyle">
              <div class="preview-video-bg">
                <template v-if="currentVisualUrl && activeSceneVisualIsVideo">
                  <video
                    :src="currentVisualUrl"
                    class="preview-fit-bg"
                    autoplay
                    loop
                    muted
                    playsinline
                  ></video>
                  <video
                    :src="currentVisualUrl"
                    class="preview-image preview-video-contain"
                    autoplay
                    loop
                    muted
                    playsinline
                  ></video>
                </template>
                <img
                  v-else-if="currentVisualUrl"
                  :src="currentVisualUrl"
                  :class="['preview-image', activeMotionClass]"
                  alt=""
                />
                <div v-else-if="showTextCardPreview" class="preview-fallback preview-fallback-text">
                  <div class="text-only-card">
                    <div class="text-only-label">TEXT CARD</div>
                    <div class="text-only-copy">
                      {{ sceneScriptDraft || activeScene?.script_text || activeScene?.label }}
                    </div>
                  </div>
                </div>
                <div v-else-if="showWaveformPreview" class="preview-fallback preview-fallback-waveform" :style="audiogramBgStyle">
                  <div class="waveform-shell">
                    <!-- Classic bars: upward from bottom -->
                    <div v-if="audiogramStyle === 'bars'" class="ag-bars">
                      <span
                        v-for="(bar, i) in waveformLive" :key="`bar-${i}`"
                        class="ag-bar"
                        :style="{ height: `${Math.round(bar * 100)}%`, background: `linear-gradient(to top, ${audiogramColor}99, ${audiogramColor})`, boxShadow: `0 0 12px ${audiogramColor}44` }"
                      ></span>
                    </div>

                    <!-- Mirror wave: grows from center up and down -->
                    <div v-else-if="audiogramStyle === 'mirror'" class="ag-mirror">
                      <span
                        v-for="(bar, i) in waveformLive" :key="`mir-${i}`"
                        class="ag-mirror-bar"
                        :style="{ height: `${Math.round(bar * 100)}%`, background: audiogramColor, boxShadow: `0 0 10px ${audiogramColor}55` }"
                      ></span>
                    </div>

                    <!-- Radial / circle: SVG bars radiating from center -->
                    <div v-else-if="audiogramStyle === 'circle'" class="ag-circle-wrap">
                      <svg viewBox="0 0 200 200" width="200" height="200">
                        <g transform="translate(100,100)">
                          <line
                            v-for="(bar, i) in waveformLive" :key="`rad-${i}`"
                            :transform="`rotate(${i * (360 / waveformLive.length)})`"
                            x1="0" :y1="38"
                            x2="0" :y2="`${38 + bar * 52}`"
                            :stroke="audiogramColor"
                            stroke-width="6"
                            stroke-linecap="round"
                            :opacity="0.6 + bar * 0.4"
                          />
                        </g>
                        <circle cx="100" cy="100" r="30" :fill="audiogramColor" opacity="0.15" />
                        <circle cx="100" cy="100" r="20" :fill="audiogramColor" opacity="0.25" />
                      </svg>
                    </div>

                    <!-- Minimal: thin compact bars -->
                    <div v-else-if="audiogramStyle === 'minimal'" class="ag-minimal">
                      <span
                        v-for="(bar, i) in [...waveformLive, ...waveformLive.slice().reverse()]" :key="`min-${i}`"
                        class="ag-minimal-bar"
                        :style="{ height: `${Math.round(bar * 80) + 8}%`, background: audiogramColor, opacity: 0.5 + bar * 0.5 }"
                      ></span>
                    </div>
                  </div>
                </div>
                <div v-else class="preview-fallback"></div>
                <div v-if="activeSceneAIImagePending" class="preview-loading">
                  Generating AI image...
                </div>
                <div v-else-if="activeSceneVisualGenerationError" class="preview-loading error">
                  {{ activeSceneVisualGenerationError }}
                </div>
                <div v-else-if="isVisualLoading" class="preview-loading">
                  Loading scene media...
                </div>
                <div v-else-if="visualLoadFailed" class="preview-loading error">
                  Media unavailable
                </div>
                <div v-if="activeMusicTrack" class="preview-music-indicator">
                  <div class="preview-music-waves">
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                  </div>
                  <span class="preview-music-name">{{ activeMusicTrack.title }}</span>
                </div>
                <div class="preview-watermark">WYVSTUDIO</div>
                <div class="preview-timer">{{ previewTimer.elapsed }}</div>
                <div
                  v-if="captionEnabledDraft && captionHighlightDraft !== 'none'"
                  class="preview-caption"
                  :class="captionPreviewClass"
                  :style="[captionPositionStyle, captionFontStyle]"
                >
                  <span
                    v-for="(word, index) in previewWords(
                      sceneScriptDraft || activeScene?.script_text
                    )"
                    :key="`${index}-${word.text}`"
                    :class="`caption-word ${word.highlighted ? 'highlight' : 'normal'}`"
                    :style="{ color: word.highlighted ? captionHighlightColorDraft : captionColorDraft }"
                  >
                    {{ word.text }}
                  </span>
                </div>
              </div>
            </div>

            <div class="playback-controls">
              <div class="preview-mode-toggle">
                <button :class="['preview-mode-btn', previewMode === 'scene' ? 'active' : '']" type="button" @click="setPreviewMode('scene')">Scene</button>
                <button :class="['preview-mode-btn', previewMode === 'full' ? 'active' : '']" type="button" @click="setPreviewMode('full')">Full Video</button>
              </div>
              <div class="preview-scrubber" @click="scrubberSeek">
                <div class="scrubber-track">
                  <div class="scrubber-fill" :style="{ width: playProgress + '%' }"></div>
                  <template v-if="previewMode === 'full'">
                    <span v-for="pct in sceneBoundaryPcts" :key="pct" class="scrubber-marker" :style="{ left: pct + '%' }"></span>
                  </template>
                  <div class="scrubber-thumb" :style="{ left: playProgress + '%' }"></div>
                </div>
              </div>
              <div class="playback-btns">
                <button class="play-skip-btn" type="button" :disabled="activeSceneIndex <= 0" @click="skipToScene(-1)" title="Previous scene">⏮</button>
                <button class="play-btn" type="button" @click="togglePreviewPlay">{{ isPreviewPlaying ? '⏸' : '▶' }}</button>
                <button class="play-skip-btn" type="button" :disabled="activeSceneIndex >= scenes.length - 1" @click="skipToScene(1)" title="Next scene">⏭</button>
              </div>
              <div class="play-time-row">
                <span class="time-display">{{ previewTimer.elapsed }}</span>
                <span class="time-display" style="opacity:.35;">/</span>
                <span class="time-display">{{ previewTimer.total }}</span>
                <span class="play-scene-label">Scene {{ String(activeSceneIndex + 1).padStart(2, '0') }}</span>
              </div>
            </div>
          </div>

          <div class="editor-right">
            <!-- Cruise Control rail toggle. Flips between Config (the
                 existing accordion) and Assistant (chat-driven editing,
                 Phase 1B). Brand orange accent — see spec/CRUISE_CONTROL_PLAN.md. -->
            <div class="cruise-toggle-bar">
              <button
                type="button"
                :class="['cruise-toggle-pill', cruiseTab === 'config' ? 'active' : '']"
                @click="cruiseTab = 'config'"
              >Config</button>
              <button
                type="button"
                :class="['cruise-toggle-pill', cruiseTab === 'assistant' ? 'active' : '']"
                @click="cruiseTab = 'assistant'"
              >
                Assistant
                <span v-if="cruiseAssistantPending" class="cruise-toggle-dot"></span>
              </button>
            </div>

            <div v-show="cruiseTab === 'config'" class="cruise-config-view">

            <!-- Cruise apply toast — fires after an Assistant action succeeds -->
            <div v-if="cruiseToast" class="cruise-toast">{{ cruiseToast }}</div>

            <!-- Project metadata — first section -->
            <div :class="`panel-section ${panelState.project ? 'collapsed' : ''}`">
              <div class="panel-section-header" @click="togglePanel('project')">
                <div class="panel-label panel-label-tight">Project Settings</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row">
                  <span class="control-name">Name</span>
                  <input
                    v-if="editingTitle"
                    id="editor-title-input-panel"
                    v-model="titleDraft"
                    class="control-value-input"
                    type="text"
                    @blur="commitTitle"
                    @keydown.enter.prevent="commitTitle"
                    @keydown.esc.prevent="editingTitle = false"
                  />
                  <span
                    v-else
                    class="control-value control-value-editable"
                    @click="startEditTitle"
                  >{{ projectTitle }}</span>
                </div>
                <div class="control-row">
                  <span class="control-name">Aspect Ratio</span>
                  <span class="control-value">{{ project?.aspect_ratio || '9:16' }}</span>
                </div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.script ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('script')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Scene Script</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Voice outdated
                  </span>
                  <span v-if="activeSceneScriptError" class="section-error-icon" :data-tip="activeSceneScriptError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <textarea
                  v-model="sceneScriptDraft"
                  class="add-scene-textarea script-textarea"
                ></textarea>
                <div class="helper-copy">
                  This text is spoken by the voice and rendered as captions.
                </div>
                <div class="duration-row">
                  <label class="duration-label">Duration (s)</label>
                  <input
                    v-model="sceneDurationDraft"
                    class="duration-input"
                    type="number"
                    min="1"
                    max="600"
                    step="0.5"
                    :placeholder="String(activeScene?.duration_seconds ?? 12)"
                    @change="saveSceneDuration"
                  />
                  <span v-if="sceneDurationSaving" class="duration-saving">saving…</span>
                  <span class="duration-hint">Fallback when no voice is generated</span>
                </div>
                <div v-if="scriptSaveCopy()" :class="scriptSaveState === 'error' ? 'script-save-copy error' : 'script-save-copy'">
                  {{ scriptSaveCopy() }}
                </div>
                <div class="panel-inline-actions">
                  <button
                    class="btn btn-ghost btn-sm rewrite-trigger"
                    type="button"
                    @click.stop="toggleRewriteTools"
                  >
                    {{ rewriteToolsVisible ? "Hide rewrite tools" : "✦ Rewrite with AI" }}
                  </button>
                </div>
                <div v-if="rewriteToolsVisible" class="rewrite-tools">
                  <div class="chips chips-tight">
                    <button
                      v-for="option in rewriteOptions"
                      :key="option"
                      class="chip"
                      :class="{ disabled: rewritePending }"
                      type="button"
                      @click="submitRewrite(option)"
                    >
                      {{ option }}
                    </button>
                  </div>
                  <div class="rewrite-custom">
                    <input
                      v-model="rewriteCustomInstruction"
                      class="rewrite-custom-input"
                      placeholder="Custom instruction..."
                    />
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      :disabled="rewritePending"
                      @click="submitRewriteCustom"
                    >
                      Apply
                    </button>
                  </div>
                  <div class="rewrite-note">
                    Applies to this scene only · Preserves locked facts
                  </div>
                  <div v-if="rewriteError" class="rewrite-error">
                    {{ rewriteError }}
                  </div>
                </div>
                <div v-if="rewritePreviewVisible" class="rewrite-preview">
                  <div class="rewrite-preview-title">AI Rewrite Candidate</div>
                  <div class="rewrite-preview-copy">
                    {{ rewritePreviewCopy }}
                  </div>
                  <div class="rewrite-preview-actions">
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="hideRewritePreview"
                    >
                      Reject
                    </button>
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="rewriteApplyPending"
                      @click="acceptRewrite"
                    >
                      {{ rewriteApplyPending ? "Applying..." : "Accept Rewrite" }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div
              :class="['panel-section', panelState.visual ? 'collapsed' : '', cruisePulseClass('visual')]"
            >
              <div class="panel-section-header" @click="togglePanel('visual')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Visual Source</div>
                  <span v-if="activeScene?.visual_type === 'ai_image'" class="panel-badge new">AI</span>
                  <span v-if="activeSceneVisualError" class="section-error-icon" :data-tip="activeSceneVisualError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Visual type tabs -->
                <div class="visual-type-tabs">
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Stock Video' ? 'active' : '']" @click="selectedSwapVisualSource = 'Stock Video'">Video</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Stock Image' ? 'active' : '']" @click="selectedSwapVisualSource = 'Stock Image'">Image</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'AI Image' ? 'active ai' : '']" @click="selectedSwapVisualSource = 'AI Image'">✦ AI</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'My Assets' ? 'active' : '']" @click="selectedSwapVisualSource = 'My Assets'">Assets</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Audiogram' ? 'active' : '']" @click="selectedSwapVisualSource = 'Audiogram'">Audio</button>
                </div>

                <!-- Stock Video -->
                <template v-if="selectedSwapVisualSource === 'Stock Video'">
                  <div class="control-row" style="margin-top:10px;">
                    <span class="control-name">Type</span>
                    <select v-model="stockVideoSubType" class="control-value">
                      <option value="stock_clip">Clip</option>
                      <option value="background_loop">BG Loop</option>
                    </select>
                  </div>
                  <div class="scene-query-label" style="margin-top:10px;">Search query</div>
                  <textarea v-model="visualQueryDraft" class="scene-query-input" rows="2" placeholder="e.g. 'city skyline at sunset' — leave blank to use scene script"></textarea>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="swapVisual">
                    {{ visualSwapPending ? 'Swapping…' : '↻ Swap Visual' }}
                  </button>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- Stock Image -->
                <template v-else-if="selectedSwapVisualSource === 'Stock Image'">
                  <div class="scene-query-label" style="margin-top:10px;">Search query</div>
                  <textarea v-model="visualQueryDraft" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="swapVisual">
                    {{ visualSwapPending ? 'Swapping…' : '↻ Swap Image' }}
                  </button>
                  <!-- Animate works on any image asset, including stock ones. -->
                  <button
                    v-if="canAnimateActiveScene && !activeSceneAnimationPending && !activeSceneVisualIsVideo"
                    class="btn btn-sm animate-btn panel-full-btn"
                    style="margin-top:8px;"
                    type="button"
                    @click="openAnimateModal"
                  >
                    {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate this image' }}
                  </button>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- AI Image generation -->
                <template v-else-if="selectedSwapVisualSource === 'AI Image'">
                  <!-- Character chip — pick a workspace character to bind to this scene -->
                  <div class="char-chip-wrap" @click.stop>
                    <div class="char-chip" @click="characterPopoverOpen = !characterPopoverOpen">
                      <div class="char-chip-thumb">
                        <img v-if="activeCharacter?.reference_asset?.thumbnail_url || activeCharacter?.reference_asset?.storage_url"
                             :src="activeCharacter.reference_asset.thumbnail_url || activeCharacter.reference_asset.storage_url" alt="" />
                        <span v-else-if="activeCharacter">{{ activeCharacter.name.charAt(0).toUpperCase() }}</span>
                        <span v-else style="opacity:.5;">◐</span>
                      </div>
                      <div class="char-chip-text">
                        <div class="char-chip-name">{{ activeCharacter?.name || 'No character' }}</div>
                        <div class="char-chip-trail">{{ activeCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                      </div>
                      <span class="char-chip-chev">▾</span>
                    </div>
                    <div v-if="characterPopoverOpen" class="char-popover">
                      <div v-if="characters.length === 0" class="char-popover-empty">
                        No characters yet. Create your first one.
                      </div>
                      <div v-else class="char-popover-grid">
                        <div
                          v-for="c in characters"
                          :key="c.id"
                          :class="['char-popover-item', activeScene?.character_id === c.id ? 'selected' : '']"
                          @click="selectCharacter(c.id)"
                        >
                          <div class="char-popover-thumb">
                            <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                 :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                            <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                          </div>
                          <div class="char-popover-name">{{ c.name }}</div>
                          <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                        </div>
                      </div>
                      <div class="char-popover-foot">
                        <button v-if="activeScene?.character_id" class="char-popover-none" type="button" @click="selectCharacter(null)">Remove</button>
                        <span v-else></span>
                        <button class="char-popover-new" type="button" @click="characterPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                      </div>
                    </div>
                  </div>

                  <!-- Current result preview -->
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-result">
                    <div class="ai-image-preview">
                      <div class="ai-image-overlay-badge">{{ activeSceneVisualIsVideo ? '⚡ AI Animated' : '✦ AI Generated' }}</div>
                      <video
                        v-if="currentVisualUrl && activeSceneVisualIsVideo"
                        :src="currentVisualUrl"
                        style="width:100%;height:100%;object-fit:cover;"
                        autoplay loop muted playsinline
                      ></video>
                      <img v-else-if="currentVisualUrl" :src="currentVisualUrl" style="width:100%;height:100%;object-fit:cover;" alt="" />
                      <div v-else class="ai-image-placeholder">
                        <div class="ai-image-ico">{{ activeSceneAIImagePending ? '⏳' : '🖼️' }}</div>
                        <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;">
                          {{ activeSceneAIImagePending ? 'Generating…' : 'No image yet' }}
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Style picker grid -->
                  <div class="micro-label" style="margin-bottom:6px;margin-top:10px;">
                    Style
                    <span v-if="visualStyleSaveState === 'saved'" style="opacity:.5; font-weight:400; margin-left:4px;">saved</span>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image' && (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style)" class="current-style-note">
                    Last generated with <strong>{{ AI_IMAGE_STYLES.find(s => s.key === (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style))?.label ?? (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style) }}</strong>
                  </div>
                  <!-- Custom row-style style picker — collapsed button that
                       opens a panel of thumbnail rows. Replaces the old emoji
                       grid; thumbnails come from /image-styles (B2-hosted
                       samples rendered by generate:style-samples). -->
                  <div class="picker-wrap">
                    <button
                      type="button"
                      class="picker-trigger"
                      @click="stylePickerOpen = !stylePickerOpen"
                    >
                      <template v-if="(() => { const k = visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style; const s = imageStylesByKey.get(k); return s && s.sample_url; })()">
                        <img
                          :src="imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.sample_url"
                          alt=""
                          class="picker-trigger-thumb"
                        />
                      </template>
                      <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.icon ?? '✦' }}</span>
                      <span class="picker-trigger-label">
                        {{ imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.label ?? 'Pick a style' }}
                      </span>
                      <span class="picker-trigger-caret">▾</span>
                    </button>
                    <div v-if="stylePickerOpen" class="picker-panel">
                      <div
                        v-for="s in stylePickerRows"
                        :key="s.key"
                        :class="['picker-row', (visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key ? 'selected' : '']"
                        @click="visualStyleDraft = s.key; stylePickerOpen = false;"
                      >
                        <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                        <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                        <div class="picker-row-text">
                          <div class="picker-row-name">{{ s.label }}</div>
                          <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                        </div>
                        <span v-if="(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key" class="picker-row-check">✓</span>
                      </div>
                    </div>
                  </div>

                  <!-- Custom style descriptor — appears when 'custom' is picked. -->
                  <div v-if="visualStyleDraft === 'custom'" class="custom-style-panel">
                    <div class="micro-label" style="margin-bottom:4px;">
                      Custom style descriptor
                      <span style="font-weight:400;opacity:.5;">
                        — {{ customVisualStyleSaveState === 'pending' ? 'saving…' : customVisualStyleSaveState === 'saved' ? 'saved ✓' : 'auto-saved' }}
                      </span>
                    </div>
                    <textarea
                      v-model="customVisualStyleDraft"
                      class="ai-prompt-area"
                      rows="2"
                      maxlength="500"
                      placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                    ></textarea>
                    <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                      Appended to this scene's image prompt instead of a preset. Be concrete — name the director, film stock, mood, color grade. {{ customVisualStyleDraft.length }}/500.
                    </div>
                  </div>

                  <!-- Image model picker — same row-list pattern as styles
                       but text-only (no thumbnail needed). Right rail shows
                       cost so users can compare price/quality at a glance. -->
                  <div class="micro-label" style="margin-bottom:4px;">
                    Model <span style="font-weight:400;opacity:.5;">— {{ activeImageModelMeta?.cost ?? 15 }} cr · {{ activeImageModelMeta?.render ?? '~20s' }}</span>
                  </div>
                  <div class="picker-wrap">
                    <button type="button" class="picker-trigger" @click="modelPickerOpen = !modelPickerOpen">
                      <span class="picker-trigger-label">{{ activeImageModelLabel }}</span>
                      <span class="picker-trigger-sub">{{ activeImageModelMeta?.sub ?? '' }}</span>
                      <span class="picker-trigger-cost">{{ activeImageModelMeta?.cost ?? 15 }} cr</span>
                      <span class="picker-trigger-caret">▾</span>
                    </button>
                    <div v-if="modelPickerOpen" class="picker-panel">
                      <div
                        v-for="m in availableImageModelsEditor"
                        :key="m.key"
                        :class="['picker-row', aiImageModelKey === m.key ? 'selected' : '', (m.requires_reference && !activeSceneHasCharacter) ? 'disabled' : '']"
                        :title="m.requires_reference && !activeSceneHasCharacter ? 'Needs a character reference on this scene' : ''"
                        @click="!(m.requires_reference && !activeSceneHasCharacter) && (aiImageModelKey = m.key, modelPickerOpen = false)"
                      >
                        <div class="picker-row-text picker-row-text-flex">
                          <div class="picker-row-name">{{ m.label }}</div>
                          <div class="picker-row-desc">{{ m.sub }}{{ m.requires_reference && !activeSceneHasCharacter ? ' (needs character)' : '' }}</div>
                        </div>
                        <span class="picker-row-cost">{{ m.cost }} cr · {{ m.render }}</span>
                        <span v-if="aiImageModelKey === m.key" class="picker-row-check">✓</span>
                      </div>
                    </div>
                  </div>

                  <!-- Prompt override -->
                  <div class="micro-label" style="margin-bottom:4px;margin-top:8px;">
                    Prompt override <span style="font-weight:400;opacity:.5;">(optional)</span>
                  </div>
                  <textarea
                    v-model="aiImagePromptOverride"
                    class="ai-prompt-area"
                    rows="2"
                    maxlength="1000"
                    placeholder="Leave blank to use scene script as the generation prompt…"
                  ></textarea>

                  <div class="ai-gen-footer">
                    <button class="btn btn-primary btn-sm" type="button" :disabled="aiImagePending || activeSceneAIImagePending" @click="generateAIImage">
                      {{ (aiImagePending || activeSceneAIImagePending) ? '✦ Generating…' : '✦ Generate' }}
                    </button>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-actions">
                    <button class="btn btn-ghost btn-sm" style="flex:1;" type="button" :disabled="aiImagePending || activeSceneAIImagePending || activeSceneAnimationPending" @click="generateAIImage">
                      {{ (aiImagePending || activeSceneAIImagePending) ? 'Generating…' : 'Regenerate' }}
                    </button>
                    <button v-if="canAnimateActiveScene && !activeSceneAnimationPending" class="btn btn-sm animate-btn" type="button" :disabled="aiImagePending" @click="openAnimateModal">
                      {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate' }}
                    </button>
                    <button v-else-if="activeSceneAnimationPending" class="btn btn-ghost btn-sm anim-cancel-btn" type="button" @click="cancelAnimation">
                      ✕ Cancel animation
                    </button>
                    <button v-if="canRevertAnimation && !activeSceneAnimationPending" class="btn btn-ghost btn-sm" type="button" :disabled="aiImagePending" @click="revertAnimation" title="Restore original still">↺</button>
                  </div>
                  <div v-if="activeSceneAnimationError" class="panel-error-copy">{{ activeSceneAnimationError }}</div>

                  <!-- Animation history — last 3 clips, click to swap back -->
                  <div v-if="activeSceneAnimationHistory.length > 1" class="anim-history-row">
                    <div class="anim-history-label">Past animations</div>
                    <div class="anim-history-strip">
                      <div
                        v-for="h in activeSceneAnimationHistory"
                        :key="h.asset_id"
                        :class="['anim-history-item', activeScene?.visual_asset_id === h.asset_id ? 'current' : '']"
                        @click="useHistoryAnimation(h.asset_id)"
                        :title="`${h.tier} · ${h.duration}s${h.motion_prompt ? ' · ' + h.motion_prompt : ''}`"
                      >
                        <video v-if="h.video_url" :src="h.video_url" muted playsinline></video>
                        <span class="anim-history-tier">{{ h.tier?.[0]?.toUpperCase() }}</span>
                      </div>
                    </div>
                  </div>
                  <div v-if="aiImageError || activeSceneVisualGenerationError" class="panel-error-copy">{{ aiImageError || activeSceneVisualGenerationError }}</div>
                  <div v-if="aiImagePending || activeSceneAIImagePending" class="panel-hint-copy">This takes ~15s (PuLID can take up to 3 min on character scenes)</div>
                  <div v-if="visualStyleDraft && activeScene?.visual_type === 'ai_image' && !(aiImagePending || activeSceneAIImagePending) && visualStyleDraft !== (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style ?? visualStyleDraft)" class="style-regen-hint">
                    Style changed — regenerate to apply.
                  </div>
                </template>

                <!-- My Assets -->
                <template v-else-if="selectedSwapVisualSource === 'My Assets'">
                  <!-- Has a visual asset assigned from library -->
                  <template v-if="activeSceneVisualIsFromLibrary">
                    <div class="asset-current-preview">
                      <div class="asset-current-thumb">
                        <!-- Videos: thumbnail (SVG placeholder) only — never load full video in img tag -->
                        <!-- Images: thumbnail preferred, fall back to actual image URL -->
                        <img
                          v-if="activeSceneVisualAsset.thumbnail_url || (!activeSceneVisualIsVideo && currentVisualUrl)"
                          :src="activeSceneVisualAsset.thumbnail_url || currentVisualUrl"
                          alt=""
                        />
                        <div v-else class="asset-current-placeholder">
                          <span style="font-size:20px;">{{ activeSceneVisualIsVideo ? '🎥' : '🖼️' }}</span>
                        </div>
                        <div class="asset-current-badge">{{ activeSceneVisualIsVideo ? 'VIDEO' : 'IMG' }}</div>
                      </div>
                      <div class="asset-current-title">{{ activeSceneVisualAsset.title || 'Asset' }}</div>
                      <div class="asset-current-type">{{ activeSceneVisualIsVideo ? 'Video' : 'Image' }} · From library</div>
                    </div>
                    <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="openMediaPicker('visual')">
                      Change Visual
                    </button>
                    <!-- Animate works on any image asset (uploaded photos,
                         stock images, AI-generated). Hidden for video assets
                         (which are already moving). -->
                    <button
                      v-if="canAnimateActiveScene && !activeSceneAnimationPending && !activeSceneVisualIsVideo"
                      class="btn btn-sm animate-btn panel-full-btn"
                      style="margin-top:8px;"
                      type="button"
                      @click="openAnimateModal"
                    >
                      {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate this image' }}
                    </button>
                  </template>
                  <!-- No asset from library yet -->
                  <template v-else>
                    <div class="asset-empty-state">
                      <div class="asset-empty-icon">🗂️</div>
                      <div class="asset-empty-title">Pick from your library</div>
                      <div class="asset-empty-sub">Browse and assign images or videos you've uploaded to your workspace</div>
                    </div>
                    <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('visual')">
                      Open Library
                    </button>
                  </template>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- Audiogram -->
                <template v-else-if="selectedSwapVisualSource === 'Audiogram'">
                    <!-- Design picker -->
                    <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">
                      Design
                      <span v-if="audiogramSaveState === 'saved'" style="opacity:.45;font-weight:400;margin-left:4px;">saved</span>
                    </div>
                    <div class="ag-style-grid">
                      <button
                        v-for="s in AUDIOGRAM_STYLES" :key="s.key"
                        :class="['ag-style-opt', audiogramStyle === s.key ? 'selected' : '']"
                        type="button"
                        @click="selectAudiogramStyle(s.key)"
                      >
                        <!-- Mini preview of each style -->
                        <div class="ag-style-mini" :style="audiogramBgStyle">
                          <!-- bars -->
                          <template v-if="s.key === 'bars'">
                            <span v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" class="ag-mini-bar" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                          <!-- mirror -->
                          <template v-else-if="s.key === 'mirror'">
                            <span v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" class="ag-mini-bar ag-mini-mirror" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                          <!-- circle -->
                          <template v-else-if="s.key === 'circle'">
                            <svg viewBox="0 0 40 40" width="38" height="38" style="overflow:visible">
                              <g transform="translate(20,20)">
                                <line v-for="(h, i) in [0.5,0.8,0.6,0.9,0.55,0.75,0.65,0.85]" :key="i" :transform="`rotate(${i*45})`" x1="0" y1="7" :x2="0" :y2="`${7+h*10}`" :stroke="audiogramColor" stroke-width="2.5" stroke-linecap="round" />
                              </g>
                            </svg>
                          </template>
                          <!-- minimal -->
                          <template v-else-if="s.key === 'minimal'">
                            <span v-for="(h, i) in [0.3,0.5,0.4,0.7,0.5,0.6,0.35,0.55,0.45,0.65]" :key="i" class="ag-mini-minimal" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                        </div>
                        <div class="ag-style-label">{{ s.label }}</div>
                      </button>
                    </div>

                    <!-- Color presets + custom -->
                    <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Color</div>
                    <div class="ag-colors">
                      <button
                        v-for="c in AUDIOGRAM_COLORS" :key="c"
                        :class="['ag-color-swatch', audiogramColor === c ? 'selected' : '']"
                        :style="{ background: c }"
                        type="button"
                        @click="selectAudiogramColor(c)"
                        :title="c"
                      ></button>
                      <label class="ag-color-custom" title="Custom color">
                        <input type="color" :value="audiogramColor" @input="e => { audiogramColor = e.target.value; queueAudiogramColorSave(); }" />
                        <span class="ag-color-custom-icon">＋</span>
                      </label>
                    </div>

                    <!-- Background -->
                    <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Background</div>
                    <div class="ag-bg-row">
                      <button
                        v-for="bg in AUDIOGRAM_BACKGROUNDS" :key="bg.key"
                        :class="['ag-bg-opt', audiogramBg === bg.key ? 'selected' : '']"
                        :style="{ background: bg.css }"
                        type="button"
                        @click="selectAudiogramBg(bg.key)"
                      >{{ bg.label }}</button>
                    </div>

                    <!-- Apply audiogram to this scene -->
                    <button
                      v-if="activeScene?.visual_type !== 'waveform'"
                      class="btn btn-primary btn-sm panel-full-btn"
                      style="margin-top:14px;"
                      type="button"
                      @click="saveAudiogramSettings({ apply: true })"
                    >Apply Audiogram to Scene</button>
                    <div v-else class="micro-label" style="margin-top:12px;opacity:.7;text-align:center;">
                      Audiogram is active on this scene
                    </div>
                </template>

              </div>
            </div>

            <!-- Motion panel — visible only when scene visual is a still image -->
            <div
              v-if="activeSceneIsStillImage"
              :class="['panel-section', panelState.motion ? 'collapsed' : '', cruisePulseClass('motion')]"
            >
              <div class="panel-section-header" @click="togglePanel('motion')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Motion</div>
                  <span v-if="motionEffectDraft !== 'static'" class="panel-badge new">KB</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row" style="margin-top:4px;">
                  <span class="control-name">Effect</span>
                  <select v-model="motionEffectDraft" class="control-value">
                    <option value="zoom_in">Zoom In</option>
                    <option value="zoom_out">Zoom Out</option>
                    <option value="pan_left">Pan Left</option>
                    <option value="pan_right">Pan Right</option>
                    <option value="pan_up">Pan Up</option>
                    <option value="pan_down">Pan Down</option>
                    <option value="pan_zoom">Pan + Zoom</option>
                    <option value="static">Static (no motion)</option>
                  </select>
                </div>
                <div v-if="motionEffectDraft !== 'static'" class="control-row">
                  <span class="control-name">Intensity</span>
                  <select v-model="motionIntensityDraft" class="control-value">
                    <option value="subtle">Subtle</option>
                    <option value="moderate">Moderate</option>
                    <option value="dramatic">Dramatic</option>
                  </select>
                </div>
                <div v-if="motionSaveState === 'error'" class="panel-error-copy">{{ motionSaveError }}</div>
              </div>
            </div>

            <div
              :class="['panel-section', panelState.voice ? 'collapsed' : '', cruisePulseClass('voice')]"
            >
              <div class="panel-section-header" @click="togglePanel('voice')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Voice</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Outdated
                  </span>
                  <span v-if="activeSceneVoiceError" class="section-error-icon" :data-tip="activeSceneVoiceError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Voice tabs -->
                <div class="voice-tabs">
                  <button
                    type="button"
                    :class="['voice-tab', voiceTab === 'default' ? 'active' : '']"
                    @click="voiceTab = 'default'"
                  >Default</button>
                  <button
                    type="button"
                    :class="['voice-tab', voiceTab === 'custom' ? 'active' : '']"
                    @click="voiceTab = 'custom'"
                  >Custom</button>
                </div>

                <template v-if="voiceTab === 'default'">
                <div class="voice-preview">
                  <div class="voice-avatar">🎙</div>
                  <div class="voice-info">
                    <div class="voice-name">{{ activeVoiceName }}</div>
                    <div class="voice-desc">
                      {{ activeScene?.voice_settings?.language?.toUpperCase() || "EN" }} ·
                      {{ activeVoiceSpeed }}x
                    </div>
                  </div>
                  <div
                    class="voice-play"
                    :class="{ disabled: !activeSceneAudioUrl }"
                    :title="activeSceneAudioUrl ? '' : 'No audio generated'"
                    @click="toggleAudioPlayback"
                  >
                    {{ isAudioLoading ? "…" : isAudioPlaying ? "⏸" : "▶" }}
                  </div>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Volume</span>
                  <div class="volume-slider-wrap">
                    <input v-model.number="sceneVoiceVolume" type="range" class="music-slider" min="0" max="200" @input="scheduleSceneVoiceVolumeSave" />
                    <span class="music-slider-val">{{ sceneVoiceVolume }}%</span>
                  </div>
                </div>
                <div class="control-row">
                  <span class="control-name">Speed</span>
                  <select v-model="voiceSpeedDraft" class="control-value">
                    <option value="0.8">0.8x</option>
                    <option value="1.0">1.0x</option>
                    <option value="1.1">1.1x</option>
                    <option value="1.2">1.2x</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Stability</span>
                  <select v-model="voiceStabilityDraft" class="control-value">
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Voice</span>
                  <select v-model="voiceProfileKey" class="control-value">
                    <option v-for="profile in voiceProfiles" :key="profile.id" :value="profile.provider_voice_key">
                      {{ profile.name }}
                    </option>
                  </select>
                </div>
                <div :class="activeVoiceOutdated ? 'voice-warning-row' : 'voice-warning-row state-hidden'">
                  <span class="voice-warning-copy">Script changed — voice outdated</span>
                  <button class="regen-btn" type="button" :disabled="voiceRegeneratePending" @click="regenerateVoice">
                    {{ voiceRegeneratePending ? "Regenerating..." : "Regenerate" }}
                  </button>
                </div>
                <div v-if="voiceSaveCopy()" :class="voiceSaveState === 'error' ? 'script-save-copy error' : 'script-save-copy'">
                  {{ voiceSaveCopy() }}
                </div>
                <div v-if="isAudioLoading" class="voice-loading-copy">
                  Loading audio...
                </div>
                </template>

                <!-- ── Custom voice tab ── -->
                <template v-else-if="voiceTab === 'custom'">
                <div class="custom-voice-intro">
                  Upload an audio file or record your own voice. Captions are generated from the audio.
                </div>

                <!-- Upload + record buttons -->
                <div v-if="voiceUploadStatus === 'idle'" class="voice-action-row">
                  <label class="voice-upload-btn">
                    <input type="file" accept="audio/*" hidden @change="handleVoiceFileUpload">
                    ⬆ Upload
                  </label>
                  <button
                    type="button"
                    :class="['voice-record-btn', voiceRecording ? 'recording' : '']"
                    @click="voiceRecording ? stopVoiceRecording() : startVoiceRecording()"
                  >
                    <template v-if="voiceRecording">■ {{ Math.floor(voiceRecordSeconds/60) }}:{{ String(voiceRecordSeconds%60).padStart(2,'0') }}</template>
                    <template v-else>● Record</template>
                  </button>
                </div>

                <!-- Local preview before uploading -->
                <div v-else-if="voiceUploadStatus === 'previewing'" class="voice-preview-panel">
                  <div class="voice-preview-head">
                    <span class="voice-preview-icon">🎧</span>
                    <span class="voice-preview-title">Preview</span>
                    <span class="voice-preview-name">{{ voicePreviewName }}</span>
                  </div>

                  <!-- Custom audio player -->
                  <audio
                    ref="voicePreviewRef"
                    :src="voicePreviewUrl"
                    preload="metadata"
                    @timeupdate="onVoicePreviewTime"
                    @loadedmetadata="onVoicePreviewTime"
                    @play="voicePreviewPlaying = true"
                    @pause="voicePreviewPlaying = false"
                    @ended="voicePreviewPlaying = false"
                  ></audio>
                  <div class="vp-player">
                    <button type="button" class="vp-play-btn" @click="toggleVoicePreviewPlay">
                      <span v-if="voicePreviewPlaying">⏸</span>
                      <span v-else>▶</span>
                    </button>
                    <div class="vp-track" @click="onVoicePreviewSeek">
                      <div class="vp-fill" :style="{ width: (voicePreviewDuration ? (voicePreviewCurrent / voicePreviewDuration) * 100 : 0) + '%' }"></div>
                    </div>
                    <span class="vp-time">{{ fmtVoicePreviewTime(voicePreviewCurrent) }} / {{ fmtVoicePreviewTime(voicePreviewDuration) }}</span>
                  </div>

                  <div class="voice-preview-actions">
                    <button class="btn btn-primary btn-sm vp-action" @click="confirmUploadFromPreview">
                      ✓ Use this audio
                    </button>
                    <button class="btn btn-ghost btn-sm vp-action" @click="cancelVoiceUpload">
                      ↻ Redo
                    </button>
                  </div>
                  <div class="voice-preview-hint">
                    Captions and transcript are generated automatically after you confirm.
                  </div>
                </div>

                <!-- Status banners during upload/transcription -->
                <div v-else-if="voiceUploadStatus === 'uploading'" class="voice-status">
                  ⏳ Uploading…
                </div>
                <div v-else-if="voiceUploadStatus === 'transcribing'" class="voice-status">
                  <span class="voice-status-spin">◐</span> Transcribing for captions…
                  <button class="voice-status-cancel" @click="cancelVoiceUpload">Cancel</button>
                </div>
                <div v-else-if="voiceUploadStatus === 'ready'" class="voice-ready-panel">
                  <div class="voice-ready-head">
                    <span>✓ Ready</span>
                    <button class="voice-status-cancel" @click="cancelVoiceUpload">Discard</button>
                  </div>
                  <div v-if="voiceUploadAsset?.transcript_text" class="voice-transcript-preview">
                    <div class="voice-transcript-label">Transcript</div>
                    <div class="voice-transcript-text">{{ voiceUploadAsset.transcript_text.slice(0, 220) }}{{ voiceUploadAsset.transcript_text.length > 220 ? '…' : '' }}</div>
                  </div>
                  <div class="voice-ready-actions">
                    <button class="btn btn-primary btn-sm" @click="applyVoiceUpload({ updateScript: true })">
                      Use voice + update script
                    </button>
                    <button class="btn btn-ghost btn-sm" @click="applyVoiceUpload({ updateScript: false })">
                      Use voice only
                    </button>
                  </div>
                </div>
                <div v-else-if="voiceUploadStatus === 'error'" class="voice-error">
                  <div>⚠ {{ voiceUploadError }}</div>
                  <button class="voice-status-cancel" @click="cancelVoiceUpload">Dismiss</button>
                </div>

                <!-- Browse previously uploaded audio -->
                <div class="micro-label" style="margin-top:14px;margin-bottom:6px;">
                  Previously uploaded
                </div>
                <button
                  v-if="customAudioAssets.length === 0 && !customAudioLoading"
                  class="btn btn-ghost btn-sm panel-full-btn"
                  type="button"
                  @click="loadCustomAudioAssets"
                >Browse audio assets</button>
                <template v-else>
                  <input v-model="customAudioSearch" class="asset-search-input" placeholder="Search audio…" style="margin-bottom:6px;" />
                  <div v-if="customAudioLoading" class="asset-loading">Loading...</div>
                  <div v-else-if="customAudioAssetsFiltered.length === 0" class="asset-empty">No audio assets found.</div>
                  <div v-else class="custom-audio-list">
                    <div
                      v-for="asset in customAudioAssetsFiltered"
                      :key="asset.id"
                      :class="['custom-audio-row', activeScene?.voice_settings?.audio_asset_id === asset.id && activeScene?.voice_settings?.custom_audio ? 'active' : '']"
                      @click="assignCustomAudio(asset)"
                    >
                      <span class="custom-audio-icon">🎵</span>
                      <span class="custom-audio-name">{{ asset.title || 'Untitled' }}</span>
                      <span v-if="activeScene?.voice_settings?.audio_asset_id === asset.id && activeScene?.voice_settings?.custom_audio" class="custom-audio-check">✓</span>
                    </div>
                  </div>
                </template>
                </template>

                <!-- Save as voice profile (default tab only) -->
                <template v-if="voiceTab === 'default'">
                <div class="preset-save-row" style="margin-top:12px;">
                  <template v-if="!voiceProfileSaveOpen">
                    <button class="btn btn-ghost btn-sm" type="button" @click="voiceProfileSaveOpen = true">
                      + Save voice as profile
                    </button>
                  </template>
                  <template v-else>
                    <input
                      v-model="voiceProfileSaveName"
                      class="preset-name-input"
                      placeholder="Profile name…"
                      maxlength="80"
                      @keydown.enter="saveVoiceProfile"
                      @keydown.escape="voiceProfileSaveOpen = false; voiceProfileSaveName = ''"
                    />
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="voiceProfileSaving || !voiceProfileSaveName.trim()"
                      @click="saveVoiceProfile"
                    >{{ voiceProfileSaving ? "Saving…" : "Save" }}</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="voiceProfileSaveOpen = false; voiceProfileSaveName = ''">Cancel</button>
                  </template>
                </div>
                </template>
              </div>
            </div>

            <!-- Sounds panel — per-scene SFX -->
            <div :class="`panel-section ${panelState.sounds ? 'collapsed' : ''}`">
              <div class="panel-section-header" @click="togglePanel('sounds')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Sounds</div>
                  <span v-if="activeSceneSoundAsset" class="panel-badge new">Set</span>
                  <span class="panel-scope-hint">Per scene</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Currently assigned sound -->
                <template v-if="activeSceneSoundAsset">
                  <div class="sound-current-row">
                    <span class="sound-current-icon">🔊</span>
                    <div class="sound-current-info">
                      <div class="sound-current-name">{{ activeSceneSoundAsset.title }}</div>
                      <div class="sound-current-meta">Sound effect · This scene</div>
                    </div>
                    <button class="sound-remove-btn" type="button" title="Remove sound" @click="assignSceneSound(null)">✕</button>
                  </div>
                  <div class="control-row top-space">
                    <span class="control-name">Volume</span>
                    <div class="volume-slider-wrap">
                      <input v-model.number="sceneSoundVolume" type="range" class="music-slider" min="0" max="200" @input="syncSceneSoundVolume(); scheduleSceneSoundVolumeSave()" />
                      <span class="music-slider-val">{{ sceneSoundVolume }}%</span>
                    </div>
                  </div>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('sound')">
                    Change Sound
                  </button>
                </template>
                <template v-else>
                  <div class="asset-empty-state">
                    <div class="asset-empty-icon">🔊</div>
                    <div class="asset-empty-title">No sound effect</div>
                    <div class="asset-empty-sub">Add a sound that plays alongside the voice for this scene</div>
                  </div>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('sound')">
                    Pick Sound
                  </button>
                </template>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.captions ? 'collapsed' : ''}`"
            >
              <div
                class="panel-section-header"
                @click="togglePanel('captions')"
              >
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Captions</div>
                  <span class="panel-scope-hint">All scenes</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Preset selector -->
                <div v-if="captionPresets.length > 0" class="preset-row">
                  <select class="preset-select" @change="applyCaptionPreset(captionPresets.find(p => p.id === Number($event.target.value)) || {}); $event.target.value = ''">
                    <option value="">Apply a preset…</option>
                    <option v-for="p in captionPresets" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                  <button
                    v-for="p in captionPresets"
                    :key="'del-'+p.id"
                    v-show="false"
                  ></button>
                </div>
                <!-- Preset management: delete chips -->
                <div v-if="captionPresets.length > 0" class="preset-chips">
                  <div v-for="p in captionPresets" :key="p.id" class="preset-chip">
                    <span class="preset-chip-name" @click="applyCaptionPreset(p)">{{ p.name }}</span>
                    <button
                      class="preset-chip-del"
                      type="button"
                      :disabled="captionPresetDeleting === p.id"
                      @click.stop="deleteCaptionPreset(p.id)"
                    >✕</button>
                  </div>
                </div>
                <!-- Save as preset -->
                <div class="preset-save-row">
                  <template v-if="!captionPresetSaveOpen">
                    <button class="btn btn-ghost btn-sm" type="button" @click="captionPresetSaveOpen = true">
                      + Save as preset
                    </button>
                  </template>
                  <template v-else>
                    <input
                      v-model="captionPresetSaveName"
                      class="preset-name-input"
                      placeholder="Preset name…"
                      maxlength="80"
                      @keydown.enter="saveCaptionPreset"
                      @keydown.escape="captionPresetSaveOpen = false; captionPresetSaveName = ''"
                    />
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="captionPresetSaving || !captionPresetSaveName.trim()"
                      @click="saveCaptionPreset"
                    >{{ captionPresetSaving ? "Saving…" : "Save" }}</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="captionPresetSaveOpen = false; captionPresetSaveName = ''">Cancel</button>
                  </template>
                </div>
                <div class="caption-toggle-row">
                  <span></span>
                  <label class="caption-toggle">
                    <input v-model="captionEnabledDraft" type="checkbox" />
                    <span>On</span>
                  </label>
                </div>
                <div class="micro-label" style="margin-bottom:6px;">Caption effect</div>
                <div class="caption-style-grid">
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'impact' ? 'active' : '']"
                    @click="captionStyleDraft = 'impact'"
                  >
                    <div class="preview-text accent-text">Impact</div>
                    <div class="style-name">Impact</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'editorial' ? 'active' : '']"
                    @click="captionStyleDraft = 'editorial'"
                  >
                    <div class="preview-text serif-text">Editorial</div>
                    <div class="style-name">Editorial</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'hacker' ? 'active' : '']"
                    @click="captionStyleDraft = 'hacker'"
                  >
                    <div class="preview-text mono-text">Hacker</div>
                    <div class="style-name">Hacker</div>
                  </div>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Highlight</span>
                  <select v-model="captionHighlightDraft" class="control-value">
                    <option value="keywords">Keywords</option>
                    <option value="word_by_word">Word-by-word</option>
                    <option value="line_by_line">Line-by-line</option>
                    <option value="none">None</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Position</span>
                  <select v-model="captionPositionDraft" class="control-value">
                    <option value="bottom_third">Bottom third</option>
                    <option value="center">Center</option>
                    <option value="top_third">Top third</option>
                  </select>
                </div>
                <!-- Font picker -->
                <div class="font-picker-block">
                  <div class="micro-label" style="margin-bottom:6px;">Font</div>
                  <div class="font-dropdown">
                    <button
                      type="button"
                      class="font-dropdown-trigger"
                      @click="fontDropdownOpen = !fontDropdownOpen"
                    >
                      <span class="font-trigger-copy">
                        <span class="font-trigger-name" :style="captionFontStyle">
                          {{ selectedCaptionFont.font }}
                        </span>
                        <span class="font-trigger-group">{{ selectedCaptionFont.group }}</span>
                      </span>
                      <span class="font-trigger-chevron">▾</span>
                    </button>
                    <div v-if="fontDropdownOpen" class="font-dropdown-menu">
                      <div
                        v-for="group in CAPTION_FONT_GROUPS"
                        :key="group.label"
                        class="font-group"
                      >
                        <div class="font-group-label">{{ group.label }}</div>
                        <button
                          v-for="font in group.fonts"
                          :key="font"
                          type="button"
                          :class="['font-option', captionFontDraft === font ? 'active' : '']"
                          @click="selectCaptionFont(font)"
                        >
                          <span class="font-option-preview" :style="{ fontFamily: fontFamilyValue(font) }">
                            {{ font }}
                          </span>
                          <span class="font-option-name">{{ font }}</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Caption text color -->
                <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Text color</div>
                <div class="caption-color-row">
                  <div
                    v-for="swatch in CAPTION_COLOR_SWATCHES"
                    :key="'tc-'+swatch"
                    :class="['color-swatch', captionColorDraft === swatch ? 'active' : '']"
                    :style="{ background: swatch, borderColor: swatch === '#ffffff' ? '#555' : swatch }"
                    @click="captionColorDraft = swatch"
                  ></div>
                  <label class="color-custom-wrap" title="Custom color">
                    <span class="color-custom-preview color-custom-square" :style="{ background: captionColorDraft }"></span>
                    <input type="color" v-model="captionColorDraft" class="color-custom-input" />
                  </label>
                </div>

                <!-- Highlight color -->
                <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">Highlight color</div>
                <div class="caption-color-row">
                  <div
                    v-for="swatch in CAPTION_COLOR_SWATCHES"
                    :key="'hc-'+swatch"
                    :class="['color-swatch', captionHighlightColorDraft === swatch ? 'active' : '']"
                    :style="{ background: swatch, borderColor: swatch === '#ffffff' ? '#555' : swatch }"
                    @click="captionHighlightColorDraft = swatch"
                  ></div>
                  <label class="color-custom-wrap" title="Custom highlight color">
                    <span class="color-custom-preview color-custom-square" :style="{ background: captionHighlightColorDraft }"></span>
                    <input type="color" v-model="captionHighlightColorDraft" class="color-custom-input" />
                  </label>
                </div>

                <!-- Caption size -->
                <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">Text size</div>
                <div class="caption-size-row">
                  <button
                    v-for="sz in [['small','S'],['medium','M'],['large','L'],['xlarge','XL']]"
                    :key="sz[0]"
                    type="button"
                    :class="['size-opt', captionSizeDraft === sz[0] ? 'active' : '']"
                    @click="captionSizeDraft = sz[0]"
                  >{{ sz[1] }}</button>
                </div>

              </div>
            </div>

            <div
              :class="['panel-section', panelState.music ? 'collapsed' : '', cruisePulseClass('music')]"
            >
              <div class="panel-section-header" @click="togglePanel('music')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Music</div>
                  <span class="panel-scope-hint">All scenes</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Selected track summary -->
                <div class="music-selected-summary">
                  <div class="music-selected-copy">
                    <span class="music-selected-label">Now</span>
                    <strong>{{ activeMusicTrack ? activeMusicTrack.title : "No music" }}</strong>
                  </div>
                  <span>{{ activeMusicTrack ? trackMoodLabel(activeMusicTrack) : "Silence" }}</span>
                </div>

                <!-- Music panel tabs -->
                <div class="music-panel-tabs">
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'library' ? 'active' : '']"
                    @click="musicPanelTab = 'library'"
                  >Library</button>
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'uploads' ? 'active' : '']"
                    @click="musicPanelTab = 'uploads'; openMediaPicker('music')"
                  >My Uploads</button>
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'ai' ? 'active' : '']"
                    @click="musicPanelTab = 'ai'"
                  >✦ AI Generate</button>
                </div>

                <!-- AI Music tab -->
                <template v-if="musicPanelTab === 'ai'">
                  <div class="micro-label" style="margin:8px 0 6px;">Mood / genre</div>
                  <div class="ai-music-moods">
                    <button v-for="m in AI_MUSIC_MOODS" :key="m"
                      type="button"
                      :class="['ai-music-mood-chip', aiMusicMood === m ? 'selected' : '']"
                      @click="aiMusicMood = m"
                    >{{ m }}</button>
                  </div>
                  <input
                    v-model="aiMusicMood"
                    class="scene-query-input"
                    style="margin-top:8px;"
                    maxlength="100"
                    placeholder="…or type your own: e.g. tense neon synth wave"
                  />
                  <div style="font-size:11px;color:var(--text-muted);margin:6px 0 10px;line-height:1.5;">
                    Replaces the current background music. Costs 5 credits. Generation takes ~30s; you'll see it appear when ready.
                  </div>
                  <button class="btn btn-primary btn-sm panel-full-btn" type="button"
                    :disabled="aiMusicPending || !aiMusicMood.trim()"
                    @click="regenerateAIMusic"
                  >
                    {{ aiMusicPending ? '✦ Generating…' : '✦ Generate music (5 cr)' }}
                  </button>
                  <div v-if="aiMusicError" class="panel-error-copy" style="margin-top:8px;">{{ aiMusicError }}</div>
                </template>

                <!-- Library tab -->
                <template v-if="musicPanelTab === 'library'">
                  <!-- Mood filter chips -->
                  <div class="music-filter-row">
                    <div v-for="mood in ['all','dark','upbeat','calm','epic']" :key="mood"
                      :class="['filter-chip', musicMoodFilter === mood ? 'active' : '']"
                      @click="musicMoodFilter = mood"
                    >{{ mood.charAt(0).toUpperCase() + mood.slice(1) }}</div>
                  </div>

                  <!-- Track list -->
                  <div class="music-track-scroll">
                    <!-- No music option -->
                    <div :class="['music-track', selectedMusicTrackId === null ? 'selected' : '']"
                      @click="selectMusicTrack(null)">
                      <div class="music-track-thumb" style="background:var(--bg-elevated);">🚫</div>
                      <div class="music-track-info">
                        <div class="music-track-name">No music</div>
                        <div class="music-track-meta">Silence</div>
                      </div>
                      <button class="music-play-btn" type="button" disabled @click.stop>▶</button>
                    </div>
                    <div v-for="group in musicTrackGroups" :key="group.mood" class="music-category">
                      <div class="music-category-title">
                        <span>{{ moodEmoji(group.mood) }}</span>
                        {{ moodLabel(group.mood) }}
                      </div>
                      <div class="music-track-list">
                        <div v-for="track in group.tracks" :key="track.id"
                          :class="['music-track', selectedMusicTrackId === track.id ? 'selected' : '']"
                          @click="selectMusicTrack(track.id)">
                          <div class="music-track-thumb" :style="{ background: moodGradient((track.tags ?? []).find(t => t !== 'music')) }">{{ moodEmoji((track.tags ?? []).find(t => t !== 'music')) }}</div>
                          <div class="music-track-info">
                            <div class="music-track-name">{{ track.title }}</div>
                            <div class="music-track-meta">{{ trackMoodLabel(track) }}</div>
                          </div>
                          <div class="music-track-duration">{{ formatTrackDuration(track.duration_seconds) }}</div>
                          <button class="music-play-btn" type="button" @click.stop="toggleMusicAudition(track)">
                            {{ auditionMusicTrackId === track.id ? "⏸" : "▶" }}
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Music controls -->
                <div class="music-controls">
                  <div class="music-control-row">
                    <span class="music-control-label">Volume</span>
                    <input v-model.number="musicVolume" type="range" class="music-slider" min="0" max="100" @input="scheduleMusicSave(); syncPreviewMusicVolume()" />
                    <span class="music-slider-val">{{ musicVolume }}%</span>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Duck vol.</span>
                    <input v-model.number="musicDuckVolume" type="range" class="music-slider" min="0" max="50" @input="scheduleMusicSave(); syncPreviewMusicVolume()" />
                    <span class="music-slider-val">{{ musicDuckVolume }}%</span>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Fade in</span>
                    <input v-model.number="musicFadeInMs" type="range" class="music-slider" min="0" max="2000" step="100" @input="scheduleMusicSave" />
                    <span class="music-slider-val">{{ (musicFadeInMs / 1000).toFixed(1) }}s</span>
                  </div>
                  <div class="music-control-row" style="margin-top:6px;">
                    <span class="music-control-label">Loop track</span>
                    <div style="flex:1;"></div>
                    <label class="toggle-wrap">
                      <input v-model="musicLoop" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Duck voice</span>
                    <div style="flex:1;"></div>
                    <label class="toggle-wrap">
                      <input v-model="musicDuckDuringVoice" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div v-if="musicSaveError" class="micro-error">{{ musicSaveError }}</div>
                  <div v-if="musicSaveState === 'saved'" class="micro-copy" style="margin-top:4px;">Saved</div>
                </div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.brand ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('brand')">
                <div class="panel-label panel-label-tight">Brand Kit</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row">
                  <span class="control-name">Channel</span>
                  <select v-model="projectChannelId" class="control-value">
                    <option value="">No channel</option>
                    <option v-for="channel in channels" :key="channel.id" :value="String(channel.id)">
                      {{ channel.name }}
                    </option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Brand Kit</span>
                  <select v-model="projectBrandKitId" class="control-value">
                    <option value="">No brand kit</option>
                    <option v-for="brandKit in brandKits" :key="brandKit.id" :value="String(brandKit.id)">
                      {{ brandKit.name }}
                    </option>
                  </select>
                </div>
                <div v-if="selectedProjectChannel" class="micro-copy">
                  Channel defaults can prefill brand kit for future changes. Existing scenes are not rewritten automatically.
                </div>
                <div v-if="projectDefaultsSaveError" class="micro-error">{{ projectDefaultsSaveError }}</div>
                <button class="btn btn-ghost btn-full" type="button" @click="saveProjectDefaults">
                  {{ projectDefaultsSaveState === "saving" ? "Saving…" : projectDefaultsSaveState === "saved" ? "Saved" : "Save Defaults" }}
                </button>
                <div v-if="sortedHookOptions.length" class="hooks-block">
                  <div class="micro-label hooks-title">Hook options — sorted by score</div>
                  <div
                    v-for="option in sortedHookOptions"
                    :key="option.id"
                    class="hook-card"
                  >
                    <div class="hook-card-top">
                      <span v-if="option.hook_score != null" :class="['hook-score-badge', hookScoreClass(option.hook_score)]">{{ option.hook_score }}</span>
                      <span v-else class="hook-score-badge score-none">—</span>
                      <span class="hook-card-text">{{ option.hook_text }}</span>
                    </div>
                    <div v-if="option.hook_score_reason" class="hook-card-reason">{{ option.hook_score_reason }}</div>
                    <button class="btn btn-ghost btn-sm hook-use-btn" type="button" @click="useHook(option)">Use Hook</button>
                  </div>
                </div>
              </div>
            </div>

            </div>
            <!-- /cruise-config-view -->

            <!-- Cruise Control Assistant — Phase 1B chat UI.
                 Scope bar → message list → input. Action cards inline
                 with each assistant turn that proposes one. -->
            <div v-show="cruiseTab === 'assistant'" class="cruise-assistant-view">
              <div class="cruise-scope-bar">
                <span class="cruise-scope-label">Editing</span>
                <span class="cruise-scope-pill">{{ cruiseScopeLabel }}</span>
                <span v-if="cruiseScopeSceneId !== null" class="cruise-scope-flip" @click="cruiseScopeSceneId = null">↻ whole project</span>
                <span v-else-if="activeScene" class="cruise-scope-flip" @click="cruiseScopeSceneId = activeScene.id">↻ Scene {{ activeScene.scene_order }}</span>
              </div>

              <div class="cruise-assistant-body" ref="cruiseChatScrollRef">
                <!-- Empty state until the user types something -->
                <div v-if="cruiseMessages.length === 0" class="cruise-coming-soon">
                  <div class="cruise-coming-icon">✦</div>
                  <div class="cruise-coming-title">What should I change?</div>
                  <div class="cruise-coming-body">
                    Try: <em>"swap voice on scene 2 to onyx"</em>,
                    <em>"change music to upbeat indie pop"</em>,
                    or <em>"use my logo asset on scene 1"</em>.
                  </div>
                </div>

                <div v-for="m in cruiseMessages" :key="m.id" :class="['cruise-msg', `cruise-msg-${m.role}`]">
                  <div class="cruise-msg-avatar">{{ m.role === 'user' ? (mePayload?.name?.[0] ?? 'U') : '✦' }}</div>
                  <div class="cruise-msg-body">
                    <div class="cruise-msg-text">{{ m.text }}</div>

                    <!-- Action cards: one per entry in m.actions[]. Stacked
                         vertically. Multi-action turns get an "Apply all"
                         master footer that runs them sequentially. -->
                    <div v-if="(m.actions || []).length" class="cruise-actions-stack">
                      <div v-if="m.actions.length > 1" class="cruise-actions-meta">
                        Plan: {{ m.actions.length }} actions · ~{{ cruiseTotalCost(m) }} cr total
                      </div>
                      <div v-for="(card, idx) in m.actions" :key="idx" class="cruise-action">
                        <div class="cruise-action-head">
                          <span class="cruise-action-icon">{{ cruiseActionIcon(card.affected_section) }}</span>
                          <span class="cruise-action-step" v-if="m.actions.length > 1">{{ idx + 1 }}.</span>
                          <span class="cruise-action-title">{{ cruiseActionTitle(card.tool) }}</span>
                          <span class="cruise-action-cost">~{{ card.estimated_cost }} cr</span>
                        </div>
                        <div class="cruise-action-body">
                          <div v-for="(line, i) in card.diff_lines" :key="i">{{ line }}</div>
                        </div>
                        <div v-if="card.status === 'running'" class="cruise-action-status running">
                          <ul v-if="(card.expected_stages || []).length > 1" class="cruise-action-checklist">
                            <li v-for="stage in card.expected_stages" :key="stage"
                                :class="['cruise-checklist-item', (card.completed_stages || []).includes(stage) ? 'done' : 'pending']">
                              <span class="cruise-checklist-icon">
                                <span v-if="(card.completed_stages || []).includes(stage)">✓</span>
                                <span v-else class="cruise-action-spinner"></span>
                              </span>
                              <span class="cruise-checklist-label">{{ cruiseStageLabel(stage, (card.completed_stages || []).includes(stage)) }}</span>
                            </li>
                          </ul>
                          <template v-else>
                            <span class="cruise-action-spinner"></span>
                            <span class="cruise-action-running-text">{{ card.progress_text || 'Working…' }}<span class="cruise-action-dots"><span>.</span><span>.</span><span>.</span></span></span>
                          </template>
                        </div>
                        <div v-else-if="card.status === 'applied'" class="cruise-action-status applied">
                          ✓ {{ card.progress_text || 'Applied' }} · spent {{ card.credits || card.estimated_cost }} cr
                        </div>
                        <div v-else-if="card.status === 'failed'" class="cruise-action-status failed">
                          ✕ {{ card.error || 'Apply failed' }}
                        </div>
                        <div v-else-if="card.status === 'skipped'" class="cruise-action-status skipped">
                          ⏭ Skipped
                        </div>
                        <div v-else-if="cruiseUserBalance < (card.estimated_cost ?? 0)" class="cruise-action-foot">
                          <span class="cruise-action-shortfall">Need {{ card.estimated_cost }} cr · you have {{ cruiseUserBalance }}</span>
                          <button class="cruise-action-btn cruise-action-btn-primary" type="button" @click="router.push({ name: 'settings', query: { section: 'billing' } })">
                            Top up →
                          </button>
                        </div>
                        <div v-else class="cruise-action-foot">
                          <button class="cruise-action-btn cruise-action-btn-ghost" @click="cruiseSkipAction(m, idx)">Skip</button>
                          <button class="cruise-action-btn cruise-action-btn-primary" :disabled="cruiseApplying" @click="cruiseApplyAction(m, idx)">
                            {{ cruiseApplying === `${m.id}:${idx}` ? 'Applying…' : 'Apply' }}
                          </button>
                        </div>
                      </div>
                      <!-- Apply all master footer: only when >1 still-proposed -->
                      <div v-if="cruiseProposedCount(m) > 1 && cruiseCanApplyAll(m)" class="cruise-actions-master-foot">
                        <button class="cruise-action-btn cruise-action-btn-ghost" :disabled="cruiseApplying" @click="cruiseSkipAll(m)">Skip all</button>
                        <button class="cruise-action-btn cruise-action-btn-primary" :disabled="cruiseApplying" @click="cruiseApplyAll(m)">
                          Apply all ({{ cruiseProposedCount(m) }}) →
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-if="cruiseResolving" class="cruise-msg cruise-msg-ai">
                  <div class="cruise-msg-avatar">✦</div>
                  <div class="cruise-msg-body">
                    <div class="cruise-msg-text cruise-msg-thinking">Thinking…</div>
                  </div>
                </div>
              </div>

              <!-- Quick-prompt chips: only render when the chat is empty so
                   they fight blank-box paralysis without nagging on every turn. -->
              <div v-if="cruiseMessages.length === 0" class="cruise-quick-row">
                <span v-for="qp in CRUISE_QUICK_PROMPTS" :key="qp" class="cruise-quick-chip" @click="cruiseUseQuickPrompt(qp)">
                  {{ qp }}
                </span>
              </div>

              <div class="cruise-input-wrap">
                <div class="cruise-input-box">
                  <textarea
                    v-model="cruiseInputText"
                    class="cruise-input"
                    rows="2"
                    placeholder="Describe what you want to change…"
                    @keydown.meta.enter.prevent="cruiseSubmitIntent"
                    @keydown.ctrl.enter.prevent="cruiseSubmitIntent"
                  ></textarea>
                  <div class="cruise-input-row">
                    <span class="cruise-input-mode">{{ cruiseScopeLabel }}</span>
                    <button class="cruise-input-send" :disabled="!cruiseInputText.trim() || cruiseResolving" @click="cruiseSubmitIntent">Send ⌘⏎</button>
                  </div>
                </div>
              </div>

              <!-- Footer: auto-apply pref + gear + balance hint -->
              <div class="cruise-assistant-foot">
                <label class="cruise-auto-toggle" :title="cruiseAutoApply ? 'Cheap, reversible edits apply immediately. Paid/structural changes still prompt.' : 'Every action shows an Apply button before running.'">
                  <input type="checkbox" :checked="cruiseAutoApply" @change="setCruiseAutoApply($event.target.checked)" />
                  <span>Auto-apply low-risk</span>
                </label>
                <button class="cruise-prefs-trigger" type="button" :title="cruisePrefsOpen ? 'Hide assistant preferences' : 'Assistant preferences'" @click="cruisePrefsOpen = !cruisePrefsOpen">
                  ⚙
                </button>
                <span class="cruise-balance-hint">Balance: {{ cruiseUserBalance }} cr</span>
              </div>

              <!-- Preferences popover — defaults the LLM biases towards
                   when the user doesn't name a model/tier/source.
                   "Hint" semantics: explicit user words still win. -->
              <div v-if="cruisePrefsOpen" class="cruise-prefs-panel">
                <div class="cruise-prefs-head">
                  <span>Assistant defaults</span>
                  <button class="cruise-prefs-close" type="button" @click="cruisePrefsOpen = false">✕</button>
                </div>
                <div class="cruise-prefs-row">
                  <label>Image model</label>
                  <select :value="cruisePrefs.image_model || ''" @change="setCruisePref('image_model', $event.target.value || null)">
                    <option value="">Let me pick per turn</option>
                    <option value="gpt-image-1">gpt-image-1 · 15 cr · photoreal</option>
                    <option value="gpt-image-2">gpt-image-2 · 35 cr · newer OpenAI</option>
                    <option value="nano-banana">nano-banana · 15 cr · Google fast</option>
                    <option value="flux-schnell">flux-schnell · 3 cr · fastest</option>
                    <option value="sdxl-lightning">sdxl-lightning · 3 cr · stylish</option>
                  </select>
                </div>
                <div class="cruise-prefs-row">
                  <label>Animation tier</label>
                  <select :value="cruisePrefs.animation_tier || ''" @change="setCruisePref('animation_tier', $event.target.value || null)">
                    <option value="">Let me pick per turn</option>
                    <option value="quick">quick · Wan 2.5 · 60 cr</option>
                    <option value="seedance_lite">seedance lite · 100 cr</option>
                    <option value="balanced">balanced · Hailuo · 120 cr</option>
                    <option value="seedance_pro">seedance pro · 200 cr</option>
                    <option value="premium">premium · Kling · 240 cr</option>
                  </select>
                </div>
                <div class="cruise-prefs-row">
                  <label>Visual source</label>
                  <select :value="cruisePrefs.visual_source || 'auto'" @change="setCruisePref('visual_source', $event.target.value)">
                    <option value="auto">Auto — assistant decides</option>
                    <option value="ai_image">AI image</option>
                    <option value="stock_video">Stock video</option>
                    <option value="stock_image">Stock image</option>
                    <option value="audiogram">Audiogram</option>
                  </select>
                </div>
                <div class="cruise-prefs-hint">
                  These are hints — if you say "use Kling for this one" the assistant still does.
                </div>
              </div>
            </div>

          </div>
        </div>
        <EditorTimeline
          v-if="timelineOpen"
          style="height: 210px;"
          :scenes="scenes"
          :active-scene-id="activeSceneId"
          :play-progress="playProgress"
          :total-duration="totalVideoDuration"
          :is-playing="isPreviewPlaying"
          :music-track="activeMusicTrack"
          :preview-mode="previewMode"
          @scene-select="selectScene"
          @seek="timelineSeek"
          @reorder="timelineReorder"
          @pick-sound="openMediaPicker('sound')"
          @close="timelineOpen = false"
        />
        </div><!-- end editor-body -->
      </div>
    </div>

    <div :class="`drawer-backdrop ${notificationDrawerOpen ? 'open' : ''}`" @click="notificationDrawerOpen = false"></div>
    <aside :class="`drawer drawer-notif ${notificationDrawerOpen ? 'open' : ''}`">
      <div class="drawer-header">
        <div class="drawer-title">Notifications</div>
        <button class="mark-read-btn" type="button" @click="markAllRead">Mark all read</button>
      </div>
      <div v-if="notifications.length === 0" class="notif-empty">No notifications yet</div>
      <article
        v-for="item in notifications"
        :key="item.id"
        :class="`notif-item ${item.is_read ? '' : 'unread'}`"
        @click="!item.is_read && markNotificationRead(item.id)"
      >
        <div :class="`notif-icon-wrap ${item.type === 'success' ? 'success' : item.type === 'error' ? 'error' : 'warning'}`">
          {{ item.type === 'success' ? '✓' : item.type === 'error' ? '✕' : '•' }}
        </div>
        <div class="notif-body">
          <div class="notif-msg">{{ item.title }}</div>
          <div class="notif-time">{{ formatNotifTime(item.created_at) }}</div>
          <div class="notif-detail">{{ item.message }}</div>
        </div>
        <div v-if="!item.is_read" class="notif-unread-dot"></div>
      </article>
    </aside>

    <div class="toast-container">
      <div v-for="toast in notificationToasts" :key="toast.id" class="toast">
        <div class="toast-dot"></div>
        <div class="toast-content">
          <div class="toast-msg"><strong>{{ toast.title }}</strong> — {{ toast.message }}</div>
        </div>
      </div>
    </div>

    <div
      :class="`modal-backdrop ${deleteSceneTarget ? 'open' : ''}`"
      @click="closeDeleteSceneModal"
    ></div>
    <div v-if="deleteSceneTarget" class="modal-shell" role="dialog" aria-modal="true">
      <div class="confirm-modal">
        <div class="confirm-modal-title">Delete this scene?</div>
        <div class="confirm-modal-copy">
          This removes
          <strong>{{ deleteSceneTarget.label || `Scene ${deleteSceneTarget.scene_order}` }}</strong>
          from the project timeline.
        </div>
        <div class="confirm-modal-actions">
          <button class="btn btn-ghost" type="button" :disabled="deleteScenePending" @click="closeDeleteSceneModal">
            Cancel
          </button>
          <button class="btn btn-primary danger-btn" type="button" :disabled="deleteScenePending" @click="confirmDeleteScene">
            {{ deleteScenePending ? "Deleting..." : "Delete Scene" }}
          </button>
        </div>
      </div>
    </div>

    <audio
      v-if="activeSceneAudioUrl"
      ref="audioRef"
      :src="activeSceneAudioUrl"
      :crossorigin="mediaCrossOriginMode(activeSceneAudioUrl)"
      preload="metadata"
      @loadstart="isAudioLoading = true"
      @canplay="isAudioLoading = false"
      @loadeddata="isAudioLoading = false; syncSceneVoiceVolume()"
      @ended="handleSceneAudioPause"
      @play="handleSceneAudioPlay"
      @pause="handleSceneAudioPause"
      @error="isAudioLoading = false"
    ></audio>
    <audio
      v-if="activeMusicTrackUrl"
      ref="musicAudioRef"
      :src="activeMusicTrackUrl"
      :crossorigin="mediaCrossOriginMode(activeMusicTrackUrl)"
      preload="metadata"
      :loop="musicLoop"
      @loadedmetadata="syncPreviewMusicVolume"
    ></audio>
    <audio
      v-if="activeSceneSoundUrl"
      ref="soundAudioRef"
      :src="activeSceneSoundUrl"
      :crossorigin="mediaCrossOriginMode(activeSceneSoundUrl)"
      preload="auto"
      @loadeddata="syncSceneSoundVolume"
    ></audio>
    <audio
      v-if="auditionMusicTrack?.storage_url"
      ref="musicAuditionRef"
      :src="auditionMusicTrack.storage_url"
      :crossorigin="mediaCrossOriginMode(auditionMusicTrack?.storage_url)"
      preload="metadata"
      @ended="auditionMusicTrackId = null"
      @pause="auditionMusicTrackId = null"
    ></audio>

    <MediaPickerModal
      :mode="mediaPickerMode"
      :visible="mediaPickerVisible"
      :music-tracks="musicTracks"
      :selected-music-track-id="selectedMusicTrackId"
      :current-asset-id="mediaPickerMode === 'visual' ? (activeScene?.visual_asset_id ?? null) : null"
      @close="mediaPickerVisible = false"
      @select="handleMediaPickerSelect"
    />
    <SchedulePostModal
      v-if="scheduleModalOpen"
      :export-job-id="latestExportJob?.id ?? null"
      @close="scheduleModalOpen = false"
      @scheduled="onPostScheduled"
    />

    <!-- Approval link modal -->
    <Teleport to="body">
      <div v-if="approvalModalOpen" class="ap-backdrop" @click.self="closeApprovalModal">
        <div class="ap-modal">
          <div class="ap-head">
            <div class="ap-title">Send for approval</div>
            <button class="ap-close" @click="closeApprovalModal">×</button>
          </div>

          <template v-if="!approvalCreated">
            <div class="ap-meta">A unique review link will be emailed to your reviewer. They don't need a WyvStudio account.</div>
            <div class="ap-field">
              <label class="ap-label">Reviewer email *</label>
              <input v-model="approvalForm.email" type="email" class="ap-input" placeholder="client@example.com" />
            </div>
            <div class="ap-field">
              <label class="ap-label">Reviewer name (optional)</label>
              <input v-model="approvalForm.name" type="text" class="ap-input" placeholder="Their name" />
            </div>
            <div class="ap-field">
              <label class="ap-label">Note for them (optional)</label>
              <textarea v-model="approvalForm.message" rows="3" class="ap-input" placeholder="Anything specific you want them to look at"></textarea>
            </div>
            <div class="ap-field">
              <label class="ap-label">Expires in</label>
              <select v-model.number="approvalForm.expires_in_days" class="ap-input">
                <option :value="3">3 days</option>
                <option :value="7">7 days</option>
                <option :value="14">14 days</option>
                <option :value="30">30 days</option>
              </select>
            </div>
            <div v-if="approvalError" class="ap-error">{{ approvalError }}</div>
            <div class="ap-footer">
              <button class="btn btn-ghost btn-sm" @click="closeApprovalModal">Cancel</button>
              <button class="btn btn-primary btn-sm" :disabled="approvalSubmitting" @click="submitApproval">
                {{ approvalSubmitting ? 'Sending…' : 'Send link' }}
              </button>
            </div>
          </template>

          <template v-else>
            <div class="ap-success">
              <div class="ap-success-icon">✓</div>
              <div class="ap-success-title">Approval link sent</div>
              <div class="ap-success-sub" v-if="approvalCreated.warning">
                ⚠ {{ approvalCreated.warning }}
              </div>
              <div class="ap-success-sub" v-else>
                We sent the link to <strong>{{ approvalCreated.approval?.reviewer_email }}</strong>. You can also share it directly:
              </div>
              <div class="ap-link-box">
                <input :value="approvalCreated.public_url" class="ap-input" readonly />
                <button class="btn btn-ghost btn-sm" @click="copyApprovalUrl">Copy</button>
              </div>
              <div class="ap-footer">
                <button class="btn btn-primary btn-sm" @click="closeApprovalModal">Done</button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Teleport>

    <!-- Create-character modal — opened from the character chip popover -->
    <Teleport to="body">
      <div v-if="createCharacterOpen" class="ap-backdrop" @click.self="closeCharacterModal">
        <div class="ap-modal" style="max-width:480px;">
          <div class="ap-head">
            <div class="ap-title">＋ New character</div>
            <button class="ap-close" @click="closeCharacterModal">×</button>
          </div>

          <div class="ap-field">
            <label class="ap-label">Reference image <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <label v-if="!createCharacterPreviewUrl" class="char-upload-drop">
              <input type="file" accept="image/*" hidden @change="pickCharacterFile" />
              <div class="char-upload-ico">⬆</div>
              <div class="char-upload-copy">Drop or click to upload</div>
              <div class="char-upload-sub">PNG or JPG · up to 10MB</div>
            </label>
            <div v-else class="char-upload-preview">
              <img :src="createCharacterPreviewUrl" alt="" />
              <button type="button" class="char-upload-clear" @click="clearCharacterFile">✕ Remove</button>
            </div>
            <div class="ap-hint">Shown as the character's thumbnail. Will guide visual consistency when IP-Adapter is wired.</div>
          </div>

          <div class="ap-field">
            <label class="ap-label">Name</label>
            <input v-model="createCharacterName" class="ap-input" placeholder="e.g. Marcus the Detective" maxlength="120" />
          </div>
          <div class="ap-field">
            <label class="ap-label">Description <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <textarea v-model="createCharacterDescription" class="ap-input" rows="3" placeholder="A weathered 50-year-old detective in a worn trench coat, sharp eyes…" maxlength="2000"></textarea>
            <div class="ap-hint">Used in scene prompts to keep the character consistent across episodes.</div>
          </div>
          <div v-if="createCharacterError" class="ap-error">{{ createCharacterError }}</div>
          <div class="ap-foot">
            <button class="btn btn-ghost btn-sm" type="button" @click="closeCharacterModal">Cancel</button>
            <button class="btn btn-primary btn-sm" type="button" :disabled="createCharacterSaving" @click="createCharacter">
              {{ createCharacterSaving ? (createCharacterFile ? 'Uploading…' : 'Saving…') : 'Create' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Animate scene modal — opened from the "⚡ Animate" button on AI image scenes -->
    <Teleport to="body">
      <div v-if="animateModalOpen" class="ap-backdrop" @click.self="closeAnimateModal">
        <div class="ap-modal" style="max-width:560px;">
          <div class="ap-head">
            <div class="ap-title">⚡ Animate scene</div>
            <button class="ap-close" @click="closeAnimateModal">×</button>
          </div>

          <div class="ap-field">
            <label class="ap-label">Quality tier</label>
            <div class="anim-tier-grid">
              <button
                v-for="key in ['quick','balanced','premium']"
                :key="key"
                type="button"
                :class="['anim-tier', animateTier === key ? 'selected' : '']"
                @click="animateTier = key"
              >
                <div class="anim-tier-head">
                  <span class="anim-tier-name">{{ ANIMATE_TIER_META[key].name }}</span>
                  <span v-if="key === 'balanced'" class="anim-tier-pill">RECOMMENDED</span>
                </div>
                <div class="anim-tier-sub">{{ ANIMATE_TIER_META[key].sub }}</div>
                <div class="anim-tier-row"><span>Quality</span><span>{{ ANIMATE_TIER_META[key].quality }}</span></div>
                <div class="anim-tier-row"><span>Render</span><span>{{ ANIMATE_TIER_META[key].render }}</span></div>
                <div class="anim-tier-row"><span>Cost ({{ ANIMATE_TIER_DURATIONS[key][0] }} s)</span><span class="anim-tier-cost">{{ ANIMATE_TIER_COSTS_5S[key] }} credits</span></div>
              </button>
            </div>
          </div>

          <div class="ap-field">
            <label class="ap-label">Duration</label>
            <div class="anim-duration-row">
              <button
                v-for="d in animateDurations"
                :key="d"
                type="button"
                :class="['anim-dpill', animateDuration === d ? 'active' : '']"
                @click="animateDuration = d"
              >{{ d }} s</button>
            </div>
            <div class="ap-hint">
              Long clip costs 2×. The
              <strong>{{ ANIMATE_TIER_META[animateTier].name }}</strong>
              tier renders in {{ animateDurations.join(' s or ') }} s chunks.
            </div>
          </div>

          <div class="ap-field">
            <label class="ap-label">Motion prompt <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <div class="anim-prompt-chips">
              <button
                v-for="s in ANIMATE_PROMPT_SUGGESTIONS"
                :key="s.label"
                type="button"
                class="anim-prompt-chip"
                @click="animateMotionPrompt = s.text"
              >{{ s.label }}</button>
            </div>
            <textarea v-model="animateMotionPrompt" class="ap-input" rows="2" maxlength="1000" placeholder="e.g. slow camera push-in, subtle hair movement, drifting fog"></textarea>
            <div class="ap-hint">Pick a quick start above or type your own. Leave blank for a sensible default.</div>
          </div>

          <div v-if="animateError" class="ap-error">{{ animateError }}</div>

          <div class="anim-foot">
            <div class="anim-total">
              <span class="anim-total-credits">{{ animateCost }} credits</span>
              <span class="anim-total-sub">{{ animateDuration }} s clip · regenerate if uncanny</span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="animateSubmitting" @click="closeAnimateModal">Cancel</button>
              <button class="btn btn-primary btn-sm" type="button" :disabled="animateSubmitting" @click="submitAnimate">
                {{ animateSubmitting ? 'Starting…' : '⚡ Animate' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </main>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Space+Mono:wght@400;700&display=swap");
@import url("https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat&family=Raleway&family=Nunito&family=Lato&family=Roboto+Mono&family=Roboto+Slab&family=Libre+Baskerville&family=Playfair+Display&family=Dancing+Script&family=Fredoka+One&family=Sacramento&family=Luckiest+Guy&family=Orbitron&family=Satisfy&family=Permanent+Marker&family=Noto+Sans&family=Amatic+SC&family=Days+One&family=Rock+Salt&family=New+Rocker&family=Passion+One&family=Indie+Flower&family=Quicksand&family=Shadows+Into+Light&family=Source+Code+Pro&family=Aladin&family=Calligraffitti&display=swap");

.editor-page {
  --bg-deep: #0a0a0f;
  --bg-panel: #111118;
  --bg-card: #17171f;
  --bg-elevated: #1d1d28;
  --bg-soft: #15151d;
  --border: #2a2a36;
  --border-active: #494960;
  --text-primary: #ececf3;
  --text-secondary: #a1a1b5;
  --text-muted: #6a6a7c;
  --accent: #ff6b35;
  --accent-hover: #ff875a;
  --accent-glow: rgba(255, 107, 53, 0.14);
  --green: #34d399;
  --green-bg: rgba(52, 211, 153, 0.12);
  --blue: #60a5fa;
  --blue-bg: rgba(96, 165, 250, 0.12);
  --yellow: #fbbf24;
  --yellow-bg: rgba(251, 191, 36, 0.12);
  --purple: #a78bfa;
  --purple-bg: rgba(167, 139, 250, 0.12);
  --red: #f87171;
  --red-bg: rgba(248, 113, 113, 0.12);
  --radius-sm: 6px;
  --radius: 12px;
  --radius-lg: 16px;
  --shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
  min-height: 100vh;
  font-family: "DM Sans", sans-serif;
  overflow-x: hidden;
  background: radial-gradient(
      circle at top right,
      rgba(255, 107, 53, 0.09),
      transparent 28%
    ),
    radial-gradient(
      circle at bottom left,
      rgba(96, 165, 250, 0.08),
      transparent 24%
    ),
    var(--bg-deep);
  color: var(--text-primary);
}

.state-card {
  margin: 24px;
  padding: 20px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-secondary);
}

.state-card.error {
  border-color: rgba(248, 113, 113, 0.2);
  background: rgba(248, 113, 113, 0.08);
  color: #fca5a5;
}

button,
input,
select,
textarea {
  font: inherit;
}

button {
  background: none;
  border: none;
}

.main {
  margin-left: var(--sidebar-width, 220px);
  min-height: 100vh;
  transition: margin-left 0.2s ease;
}
.main.sidebar-collapsed {
  margin-left: 56px;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 90;
  height: 64px;
  background: rgba(17, 17, 24, 0.88);
  border-bottom: 1px solid var(--border);
  backdrop-filter: blur(14px);
  padding: 0 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.topbar-left,
.topbar-right {
  display: flex;
  align-items: center;
}

.topbar-left {
  gap: 18px;
}

.topbar-right {
  gap: 10px;
}

.export-pill {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: rgba(19, 19, 28, 0.92);
  color: var(--text-secondary);
  font-size: 12px;
  white-space: nowrap;
}

.export-pill-queued,
.export-pill-processing {
  color: #f6c453;
  border-color: rgba(246, 196, 83, 0.25);
}

.export-pill-completed {
  color: #9fe3b5;
  border-color: rgba(52, 211, 153, 0.3);
}

.export-pill-failed {
  color: #ff8f8f;
  border-color: rgba(255, 107, 107, 0.3);
}

.export-btn-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.export-blocker-tip {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  background: #2a1010;
  border: 1px solid rgba(255, 107, 107, 0.35);
  color: #ff9999;
  font-size: 11px;
  line-height: 1.4;
  padding: 6px 10px;
  border-radius: 6px;
  white-space: nowrap;
  max-width: 320px;
  white-space: normal;
  z-index: 50;
  pointer-events: none;
}

.export-fail-info {
  position: relative;
  display: inline-flex;
  align-items: center;
  cursor: default;
  opacity: 0.8;
  margin-left: 4px;
}
.export-fail-info:hover .export-fail-tooltip,
.export-fail-info:focus .export-fail-tooltip {
  display: block;
}
.export-fail-tooltip {
  display: none;
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  background: #1e1e2e;
  border: 1px solid rgba(255, 107, 107, 0.35);
  color: #ff9999;
  font-size: 12px;
  line-height: 1.45;
  padding: 8px 12px;
  border-radius: 7px;
  white-space: normal;
  width: 240px;
  z-index: 100;
  pointer-events: none;
}

.export-pill-sep {
  color: var(--border);
}

.export-pill-link {
  color: var(--text-primary);
  text-decoration: none;
  font-weight: 600;
}

.export-pill-link:hover {
  color: var(--accent);
}
.export-pill-link:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Copied-link feedback chip — small inline confirmation after Share. */
.export-share-copied {
  color: #34d399;
  font-weight: 600;
  font-size: 11px;
  font-family: "Space Mono", monospace;
}

/* Resume-failed banner — sits between header and editor body. Soft red
   so it reads as "needs attention" without being alarming. */
.resume-failed-banner {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; margin: 8px 14px 0; padding: 11px 14px;
  border-radius: 8px; border: 1px solid rgba(248,113,113,0.32);
  background: rgba(248,113,113,0.06);
  font-size: 13px;
}
.resume-failed-msg { display: flex; align-items: center; gap: 10px; line-height: 1.45; color: var(--color-text-primary); }
.resume-failed-icon { color: #f87171; font-size: 18px; }
.resume-failed-msg strong { color: #f87171; }

.export-pill-schedule {
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: inherit;
  padding: 0;
}

.btn-back {
  white-space: nowrap;
}

.btn-timeline-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.btn-timeline-toggle.active {
  background: rgba(255, 107, 53, 0.08);
  border-color: rgba(255, 107, 53, 0.3);
  color: var(--accent);
}

.topbar-title {
  font-size: 16px;
  font-weight: 600;
}

.topbar-breadcrumb {
  color: var(--text-muted);
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.topbar-project-name {
  color: var(--text-secondary);
  cursor: text;
}
.topbar-project-name:hover { color: var(--color-accent); }

.topbar-sep { color: var(--text-muted); }

.topbar-title-input {
  font-size: 13px;
  font-family: inherit;
  color: var(--text-primary);
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-accent);
  border-radius: 4px;
  padding: 1px 8px;
  outline: none;
  min-width: 160px;
}

.topbar-ratio-select {
  font-size: 11px;
  font-family: "Space Mono", monospace;
  font-weight: 600;
  color: var(--text-muted);
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 5px;
  padding: 2px 6px;
  cursor: pointer;
  outline: none;
}
.topbar-ratio-select:hover { border-color: var(--color-border-active); color: var(--text-secondary); }

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 16px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: 0.2s ease;
  font-size: 13px;
  font-weight: 500;
}

.btn-primary {
  background: var(--accent);
  color: #fff;
}

.btn-primary:hover {
  background: var(--accent-hover);
  box-shadow: 0 0 24px var(--accent-glow);
}

.btn-ghost {
  color: var(--text-secondary);
  background: transparent;
  border: 1px solid var(--border);
}

.btn-ghost:hover {
  color: var(--text-primary);
  border-color: var(--border-active);
}

.btn-sm {
  padding: 5px 10px;
  font-size: 12px;
}

.notif-bell-btn {
  position: relative;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: 0.15s;
}

.notif-bell-btn:hover {
  color: var(--text-primary);
  border-color: var(--border-active);
}

.drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(4, 5, 10, 0.45);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 180;
}

.drawer-backdrop.open {
  opacity: 1;
  pointer-events: auto;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(4, 5, 10, 0.6);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 210;
}

.modal-backdrop.open {
  opacity: 1;
  pointer-events: auto;
}

.modal-shell {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 211;
}

.confirm-modal {
  width: min(420px, 100%);
  border: 1px solid var(--border-active);
  border-radius: 16px;
  background: rgba(17, 17, 24, 0.98);
  box-shadow: var(--shadow);
  padding: 20px;
}

.confirm-modal-title {
  font-size: 18px;
  font-weight: 700;
}

.confirm-modal-copy {
  margin-top: 10px;
  color: var(--text-secondary);
  font-size: 14px;
  line-height: 1.5;
}

.confirm-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}

.danger-btn {
  background: #e15c5c;
}

.danger-btn:hover {
  background: #f06c6c;
  box-shadow: none;
}

.drawer {
  position: fixed;
  top: 0;
  right: 0;
  width: 360px;
  max-width: calc(100vw - 24px);
  height: 100vh;
  background: rgba(17, 17, 24, 0.98);
  border-left: 1px solid var(--border);
  transform: translateX(100%);
  transition: transform 0.24s ease;
  z-index: 190;
  padding: 20px;
  overflow-y: auto;
}

.drawer.open {
  transform: translateX(0);
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

.drawer-title {
  font-size: 16px;
  font-weight: 700;
}

.mark-read-btn {
  color: var(--accent);
  font-size: 12px;
  cursor: pointer;
}

.notif-empty {
  color: var(--text-muted);
  font-size: 13px;
}

.notif-item {
  display: grid;
  grid-template-columns: 28px 1fr auto;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid rgba(255, 255, 255, 0.04);
  cursor: pointer;
}

.notif-item:first-of-type {
  border-top: 0;
}

.notif-item.unread .notif-msg {
  color: var(--text-primary);
}

.notif-icon-wrap {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
}

.notif-icon-wrap.success {
  background: rgba(52, 211, 153, 0.12);
  color: #34d399;
}

.notif-icon-wrap.error {
  background: rgba(248, 113, 113, 0.12);
  color: #f87171;
}

.notif-icon-wrap.warning {
  background: rgba(251, 191, 36, 0.12);
  color: #fbbf24;
}

.notif-body {
  min-width: 0;
}

.notif-msg {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-secondary);
}

.notif-time,
.notif-detail {
  font-size: 12px;
  color: var(--text-muted);
}

.notif-detail {
  margin-top: 3px;
  line-height: 1.45;
}

.notif-unread-dot {
  width: 8px;
  height: 8px;
  margin-top: 6px;
  border-radius: 999px;
  background: var(--accent);
}

.toast-container {
  position: fixed;
  right: 20px;
  top: 20px;
  display: grid;
  gap: 12px;
  z-index: 220;
}

.toast {
  display: flex;
  gap: 10px;
  min-width: 280px;
  max-width: 360px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: rgba(17, 17, 24, 0.96);
  box-shadow: var(--shadow);
}

.toast-dot {
  width: 10px;
  height: 10px;
  margin-top: 4px;
  border-radius: 999px;
  background: var(--accent);
}

.toast-msg {
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-secondary);
}

/* ── Cruise Control rail toggle + Assistant placeholder (Phase 1A) ─── */
.cruise-toggle-bar {
  display: flex;
  gap: 4px;
  padding: 10px 10px;
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  background: var(--color-bg-panel, var(--color-bg-elevated));
  z-index: 5;
}
.cruise-toggle-pill {
  flex: 1;
  padding: 7px 0;
  border: 0;
  border-radius: 6px;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  background: transparent;
  color: var(--color-text-muted);
  position: relative;
  transition: background 0.18s, color 0.18s;
}
.cruise-toggle-pill:hover:not(.active) { color: var(--color-text-primary); }
.cruise-toggle-pill.active {
  background: var(--color-accent);
  color: #fff;
}
.cruise-toggle-dot {
  position: absolute;
  top: 6px; right: 12px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-bg-panel);
}

/* .cruise-config-view is the wrapper around the existing panel-sections
   — no styling needed, the panel-section rules already apply. */

.cruise-assistant-view { display: flex; flex-direction: column; min-height: 0; flex: 1; }
.editor-right > .cruise-assistant-view { flex: 1; min-height: 0; }
.cruise-assistant-view > .cruise-scope-bar,
.cruise-assistant-view > .cruise-quick-row,
.cruise-assistant-view > .cruise-input-wrap,
.cruise-assistant-view > .cruise-assistant-foot,
.cruise-assistant-view > .cruise-prefs-panel { flex: 0 0 auto; }
.cruise-scope-bar {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  font-size: 11.5px;
}
.cruise-scope-label { color: var(--color-text-muted); }
.cruise-scope-pill {
  background: rgba(255, 107, 53, 0.12);
  color: var(--color-accent);
  padding: 2px 8px;
  border-radius: 999px;
  font-family: "Space Mono", monospace;
  font-weight: 600;
}
.cruise-scope-flip {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 11px;
  cursor: pointer;
}
.cruise-scope-flip:hover { color: var(--color-text-primary); }

.cruise-assistant-body {
  flex: 1; overflow-y: auto;
  padding: 24px 18px;
  display: flex; flex-direction: column; gap: 14px;
}
.cruise-coming-soon {
  margin: auto;
  text-align: center;
  max-width: 240px;
}
.cruise-coming-icon {
  font-size: 28px;
  color: var(--color-accent);
  opacity: 0.8;
  margin-bottom: 8px;
}
.cruise-coming-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
  margin-bottom: 6px;
}
.cruise-coming-body {
  font-size: 12px;
  color: var(--color-text-muted);
  line-height: 1.55;
}
.cruise-coming-body em { color: var(--color-text-secondary); font-style: normal; background: rgba(255,255,255,0.03); padding: 1px 4px; border-radius: 3px; }

.cruise-input-wrap {
  padding: 10px 14px;
  border-top: 1px solid var(--color-border);
}
.cruise-input-box {
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 9px;
  padding: 8px 10px;
}
.cruise-input-disabled { opacity: 0.55; }
.cruise-input {
  width: 100%;
  background: transparent;
  border: 0;
  outline: 0;
  color: var(--color-text-primary);
  font-size: 12.5px;
  font-family: inherit;
  resize: none;
}
.cruise-input::placeholder { color: var(--color-text-muted); }
.cruise-input-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.cruise-input-mode { padding: 2px 8px; border-radius: 999px; background: rgba(255,107,53,0.12); color: var(--color-accent); font-size: 10.5px; font-weight: 600; font-family: "Space Mono", monospace; }
.cruise-input-send { margin-left: auto; background: var(--color-accent); color: #fff; border: 0; border-radius: 6px; padding: 5px 12px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.cruise-input-send:disabled { opacity: 0.45; cursor: not-allowed; }

/* Chat messages */
.cruise-msg { display: flex; gap: 9px; }
.cruise-msg-avatar { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; font-weight: 600; }
.cruise-msg-user .cruise-msg-avatar { background: rgba(255,255,255,0.06); color: var(--color-text-primary); }
.cruise-msg-ai .cruise-msg-avatar { background: rgba(255,107,53,0.14); color: var(--color-accent); }
.cruise-msg-body { flex: 1; min-width: 0; font-size: 12.5px; line-height: 1.55; }
.cruise-msg-text { color: var(--color-text-primary); }
.cruise-msg-thinking { color: var(--color-text-muted); font-style: italic; }

/* Multi-action stack — wraps the per-card cruise-action blocks plus
   the Plan: header and the Apply-all master footer. */
.cruise-actions-stack { display: flex; flex-direction: column; gap: 0; margin-top: 6px; }
.cruise-actions-meta { font-size: 10.5px; color: var(--color-text-muted); padding: 4px 2px 2px; letter-spacing: 0.02em; }
.cruise-action-step { font-weight: 700; color: var(--color-text-muted); font-size: 11px; font-family: "Space Mono", monospace; }
.cruise-actions-master-foot {
  display: flex; gap: 6px; justify-content: flex-end;
  padding: 8px 0 2px; margin-top: 4px;
}

/* Action card */
.cruise-action { margin-top: 8px; border: 1px solid var(--color-border); border-radius: 9px; overflow: hidden; background: var(--color-bg-card); }
.cruise-action-head { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.cruise-action-icon { color: var(--color-accent); }
.cruise-action-title { font-weight: 600; font-size: 12px; flex: 1; color: var(--color-text-primary); }
.cruise-action-cost { font-family: "Space Mono", monospace; color: var(--color-text-muted); font-size: 10.5px; }
.cruise-action-body { padding: 10px 12px; font-size: 11.5px; color: var(--color-text-muted); line-height: 1.7; }
.cruise-action-foot { padding: 8px 10px; display: flex; gap: 6px; border-top: 1px solid var(--color-border); }
.cruise-action-btn { padding: 5px 11px; border-radius: 6px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-primary); font-size: 11.5px; cursor: pointer; font-family: inherit; }
.cruise-action-btn:hover:not(:disabled) { border-color: var(--color-border-active, rgba(255,255,255,0.18)); }
.cruise-action-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.cruise-action-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; font-weight: 600; }
.cruise-action-btn-ghost { color: var(--color-text-muted); }
.cruise-action-status { padding: 8px 12px; border-top: 1px solid var(--color-border); font-size: 11.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.cruise-action-status.applied { color: #34d399; background: rgba(52,211,153,0.06); }
.cruise-action-status.failed { color: #f87171; background: rgba(248,113,113,0.06); }
.cruise-action-status.running { color: var(--color-accent, #ff6b35); background: rgba(255,107,53,0.06); }
.cruise-action-status.skipped { color: var(--color-text-muted, #94a3b8); background: rgba(148,163,184,0.06); }
.cruise-action-spinner { width: 12px; height: 12px; border: 2px solid rgba(255,107,53,0.25); border-top-color: var(--color-accent, #ff6b35); border-radius: 50%; animation: cruise-spinner-rot 0.8s linear infinite; flex-shrink: 0; }
@keyframes cruise-spinner-rot { to { transform: rotate(360deg); } }
.cruise-action-running-text { display: inline-flex; align-items: baseline; }
.cruise-action-checklist { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; width: 100%; }
.cruise-checklist-item { display: flex; align-items: center; gap: 8px; font-size: 11.5px; transition: color 0.2s, opacity 0.2s; }
.cruise-checklist-item.pending { color: var(--color-accent, #ff6b35); }
.cruise-checklist-item.done { color: var(--color-text-muted, #94a3b8); opacity: 0.7; }
.cruise-checklist-item.done .cruise-checklist-label { text-decoration: line-through; }
.cruise-checklist-icon { display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; flex-shrink: 0; }
.cruise-checklist-icon > span { font-weight: 700; color: #34d399; }
.cruise-action-dots { display: inline-flex; margin-left: 1px; }
.cruise-action-dots > span { opacity: 0.2; animation: cruise-dots-blink 1.4s infinite both; }
.cruise-action-dots > span:nth-child(2) { animation-delay: 0.2s; }
.cruise-action-dots > span:nth-child(3) { animation-delay: 0.4s; }
@keyframes cruise-dots-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }
.cruise-action-shortfall { font-size: 11.5px; color: var(--color-text-muted); margin-right: auto; padding: 6px 0; }

/* Quick prompt chips — only shown when conversation is empty */
.cruise-quick-row {
  display: flex; flex-wrap: wrap; gap: 5px;
  padding: 8px 14px;
  border-top: 1px solid var(--color-border);
}
.cruise-quick-chip {
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 10.5px;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: 0.15s;
}
.cruise-quick-chip:hover { border-color: rgba(255,107,53,0.4); color: var(--color-text-primary); }

.cruise-prefs-trigger {
  background: transparent; border: 1px solid var(--color-border);
  color: var(--color-text-muted); cursor: pointer; padding: 2px 7px;
  border-radius: 6px; font-size: 13px; font-family: inherit;
}
.cruise-prefs-trigger:hover { color: var(--color-accent); border-color: var(--color-accent); }
.cruise-prefs-panel {
  border-top: 1px solid var(--color-border);
  padding: 12px 14px; background: var(--color-bg-card);
  display: flex; flex-direction: column; gap: 10px;
  flex: 0 0 auto;
}
.cruise-prefs-head {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 11.5px; font-weight: 600; color: var(--color-text-primary);
}
.cruise-prefs-close {
  background: transparent; border: 0; color: var(--color-text-muted);
  cursor: pointer; font-size: 12px; padding: 2px 4px;
}
.cruise-prefs-close:hover { color: var(--color-text-primary); }
.cruise-prefs-row { display: flex; flex-direction: column; gap: 4px; }
.cruise-prefs-row label { font-size: 10.5px; color: var(--color-text-muted); letter-spacing: 0.02em; }
.cruise-prefs-row select {
  background: var(--color-bg-card); border: 1px solid var(--color-border);
  border-radius: 6px; padding: 6px 8px; font-size: 11.5px;
  color: var(--color-text-primary); font-family: inherit;
}
.cruise-prefs-row select:focus { outline: none; border-color: var(--color-accent); }
.cruise-prefs-hint { font-size: 10.5px; color: var(--color-text-muted); line-height: 1.5; }

/* Assistant footer — auto-apply pref + balance */
.cruise-assistant-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px;
  padding: 8px 14px;
  border-top: 1px solid var(--color-border);
  font-size: 10.5px;
  font-family: "Space Mono", monospace;
  color: var(--color-text-muted);
}
.cruise-auto-toggle {
  display: flex; align-items: center; gap: 6px;
  cursor: pointer;
}
.cruise-auto-toggle input[type="checkbox"] { accent-color: var(--color-accent); cursor: pointer; }
.cruise-balance-hint { white-space: nowrap; }

/* Apply toast at top of Config view */
.cruise-toast {
  margin: 8px 10px;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(52,211,153,0.10);
  border: 1px solid rgba(52,211,153,0.32);
  color: #34d399;
  font-size: 12px;
  font-weight: 600;
  animation: cruise-toast-in 0.25s ease-out;
}
@keyframes cruise-toast-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

/* Pulse animation on Config sections after a Cruise apply lands */
.panel-section.cruise-pulse {
  animation: cruise-section-pulse 1.6s ease-out;
}
@keyframes cruise-section-pulse {
  0%   { box-shadow: inset 0 0 0 2px rgba(52,211,153,0.55); background-color: rgba(52,211,153,0.10); }
  60%  { box-shadow: inset 0 0 0 1px rgba(52,211,153,0.30); background-color: rgba(52,211,153,0.05); }
  100% { box-shadow: inset 0 0 0 0 transparent; background-color: transparent; }
}

.notif-badge {
  position: absolute;
  top: -5px;
  right: -5px;
  width: 17px;
  height: 17px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid var(--bg-deep);
}

.editor-body {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 64px);
  overflow: hidden;
}

.editor {
  display: flex;
  flex: 1;
  min-height: 0;
}

.editor-sidebar,
.editor-right {
  background: var(--bg-panel);
}

.editor-sidebar {
  width: 320px;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
}

.editor-sidebar-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.editor-sidebar-title {
  font-size: 14px;
  font-weight: 600;
}

.scene-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
}

.scene-item {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px;
  margin-bottom: 4px;
  cursor: pointer;
  transition: 0.2s;
}

.scene-item:hover {
  border-color: var(--border-active);
}

.scene-item.active {
  border-color: var(--accent);
  background: var(--accent-glow);
}

.scene-number {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 6px;
}

.inline-warn {
  font-size: 9px;
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--yellow-bg);
  color: var(--yellow);
  font-weight: 600;
  margin-left: 4px;
}

.state-hidden {
  display: none !important;
}

.scene-regen-row {
  margin-top: 6px;
}

.scene-regen-btn {
  font-size: 10px;
  font-family: inherit;
  padding: 3px 8px;
  border-radius: 5px;
  border: 1px solid rgba(251, 191, 36, 0.35);
  background: rgba(251, 191, 36, 0.08);
  color: #fbbf24;
  cursor: pointer;
  transition: 0.15s;
}

.scene-regen-btn:hover:not(:disabled) {
  background: rgba(251, 191, 36, 0.16);
}

.scene-regen-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.scene-text {
  font-size: 13px;
  line-height: 1.5;
  color: var(--text-secondary);
}

.scene-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  font-size: 11px;
  color: var(--text-muted);
}

.scene-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.scene-action-btn {
  padding: 4px 8px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: rgba(10, 10, 15, 0.55);
  color: var(--text-secondary);
  font-size: 11px;
  line-height: 1;
  cursor: pointer;
}

.scene-action-btn:hover:not(:disabled) {
  border-color: var(--border-active);
  color: var(--text-primary);
}

.scene-action-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.scene-action-btn.danger {
  color: #ff9d9d;
}

/* ── Custom row-list pickers (style + model) ─────────────────────────
   One rounded trigger button that opens a panel of stacked rows. Each
   row: [thumbnail | label / desc | cost? | check]. Visually richer than
   a native <select> while still being a single concentrated control. */
.picker-wrap { position: relative; }
.picker-trigger {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 8px 12px; border-radius: 8px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text-primary); cursor: pointer;
  text-align: left; font-family: inherit; font-size: 13px;
  transition: border-color 0.15s;
}
.picker-trigger:hover { border-color: rgba(255,107,53,0.4); }
.picker-trigger-thumb { width: 28px; height: 28px; border-radius: 6px; object-fit: cover; flex-shrink: 0; }
.picker-trigger-glyph { width: 28px; height: 28px; border-radius: 6px; background: var(--color-bg-card); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.picker-trigger-label { font-weight: 600; flex-shrink: 0; }
.picker-trigger-sub { font-size: 11.5px; color: var(--color-text-muted); margin-left: 4px; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.picker-trigger-cost { font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-accent); font-weight: 600; }
.picker-trigger-caret { font-size: 9px; opacity: 0.55; margin-left: 4px; }

.picker-panel {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: var(--color-bg-panel); border: 1px solid var(--color-border);
  border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,0.5);
  max-height: 360px; overflow-y: auto; padding: 4px; z-index: 30;
}
.picker-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 7px; cursor: pointer;
  transition: background 0.12s;
}
.picker-row:hover:not(.disabled) { background: var(--color-bg-elevated); }
.picker-row.selected { background: rgba(255,107,53,0.08); }
.picker-row.disabled { opacity: 0.4; cursor: not-allowed; }
.picker-row-thumb { width: 44px; height: 44px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--color-bg-card); }
.picker-row-glyph { width: 44px; height: 44px; border-radius: 6px; background: var(--color-bg-card); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.picker-row-text { flex: 1; min-width: 0; }
.picker-row-text-flex { display: flex; flex-direction: column; gap: 1px; }
.picker-row-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.picker-row-desc { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; line-height: 1.4; }
.picker-row-cost { font-size: 10.5px; color: var(--color-text-muted); font-family: "Space Mono", monospace; flex-shrink: 0; }
.picker-row.selected .picker-row-cost { color: var(--color-accent); }
.picker-row-check { color: var(--color-accent); font-size: 14px; font-weight: 700; flex-shrink: 0; margin-left: 4px; }

.scene-tag {
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--bg-elevated);
  font-size: 10px;
}

.scene-style-badge {
  padding: 2px 5px;
  border-radius: 3px;
  font-size: 9px;
  font-weight: 500;
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Visual style picker */
.visual-style-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.visual-style-btn {
  padding: 5px 0;
  border-radius: 5px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}

.visual-style-btn:hover {
  border-color: rgba(99,102,241,0.4);
  color: var(--text);
}

.visual-style-btn.active {
  border-color: #6366f1;
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
}

.style-regen-hint {
  margin-top: 6px;
  font-size: 11px;
  color: #fbbf24;
  opacity: 0.85;
}
.current-style-note {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-bottom: 6px;
}
.current-style-note strong {
  color: var(--color-text-secondary);
}

/* Font picker */
.font-picker-block {
  margin-top: 10px;
  border-top: 1px solid var(--border);
  padding-top: 10px;
}

.font-dropdown {
  position: relative;
}

.font-dropdown-trigger {
  width: 100%;
  min-height: 52px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  color: var(--text);
  cursor: pointer;
  text-align: left;
}

.font-trigger-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.font-trigger-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 18px;
  line-height: 1.15;
  color: var(--text-primary);
}

.font-trigger-group {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
}

.font-trigger-chevron {
  color: var(--text-muted);
  flex: 0 0 auto;
}

.font-dropdown-menu {
  position: absolute;
  z-index: 20;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  max-height: 320px;
  overflow-y: auto;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #111118;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
}

.font-group {
  margin-bottom: 10px;
}

.font-group:last-child {
  margin-bottom: 0;
}

.font-group-label {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
  margin-bottom: 4px;
  opacity: 0.6;
}

.font-option {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 8px;
  border-radius: 6px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--text);
  cursor: pointer;
  text-align: left;
  transition: all 0.12s;
}

.font-option:hover {
  background: rgba(255,255,255,0.04);
  border-color: var(--border);
}

.font-option.active {
  background: rgba(99,102,241,0.15);
  border-color: #6366f1;
  color: #a5b4fc;
}

.font-option-preview {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 18px;
  line-height: 1.2;
}

.font-option-name {
  flex: 0 0 auto;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 10px;
  color: var(--text-muted);
}

.add-scene-divider {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 18px;
  opacity: 0;
  transition: opacity 0.15s;
}

.scene-item:hover + .add-scene-divider,
.add-scene-divider:hover {
  opacity: 1;
}

.add-scene-divider.always-visible {
  opacity: 0.5;
}

.add-scene-trigger {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-muted);
  cursor: pointer;
  padding: 2px 10px;
  border-radius: 10px;
  transition: 0.15s;
  border: 1px dashed transparent;
}

.add-scene-trigger:hover {
  color: var(--accent);
  border-color: var(--accent);
  background: var(--accent-glow);
}

.add-scene-panel {
  background: var(--bg-card);
  border: 1px solid var(--accent);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 6px;
  display: none;
  animation: slideDown 0.2s ease;
}

.add-scene-panel.open {
  display: block;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-6px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.panel-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.close-x {
  cursor: pointer;
  color: var(--text-muted);
  font-size: 16px;
  line-height: 1;
  padding: 2px;
}

.micro-label {
  font-size: 11px;
  color: var(--text-muted);
  margin-bottom: 8px;
  margin-top: 14px;
}
.micro-label:first-child { margin-top: 0; }

/* Slightly taller picker trigger in panel so labels don't crowd the
   caret / cost chip. Adds breathing room when stacked under labels. */
.picker-wrap + .micro-label,
.picker-wrap + .ai-image-actions { margin-top: 16px; }

/* Style picker grid (used by "Add scene" inline) — slightly more
   spacing so the small thumbnails breathe. */
.style-picker-grid { row-gap: 8px; }
.style-opt { padding: 8px 6px; }
.style-opt-thumb {
  width: 36px; height: 36px; border-radius: 6px;
  object-fit: cover; display: block; margin: 0 auto 6px;
}
/* Custom-style descriptor panel — give it room above so it doesn't
   touch the cards / hint text. */
.custom-style-panel { margin-top: 12px; }
.current-style-note { margin: 2px 0 12px; }

.scene-type-chips,
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.chips-tight {
  gap: 6px;
  margin-top: 0;
}

.chips-tight .chip {
  border-radius: var(--radius-sm);
}

.scene-type-chip,
.chip {
  cursor: pointer;
  transition: 0.15s ease;
}

.scene-type-chip {
  padding: 4px 10px;
  border-radius: 4px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  font-size: 11px;
  color: var(--text-secondary);
}

.scene-type-chip.selected {
  border-color: var(--accent);
  background: var(--accent-glow);
  color: var(--accent);
}

.chip {
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-secondary);
  font-size: 12px;
}

.chip:hover,
.scene-type-chip:hover,
.add-scene-visual-opt:hover {
  border-color: var(--border-active);
}

.chip.disabled {
  opacity: 0.45;
  cursor: wait;
  pointer-events: none;
}

.add-scene-textarea,
.rewrite-custom-input,
.control-value {
  width: 100%;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-family: inherit;
}

.control-value-editable {
  cursor: text;
  padding: 6px 10px;
  font-size: 12px;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.control-value-editable:hover { border-color: var(--color-accent); color: var(--color-accent); }

.control-value-input {
  width: 100%;
  border-radius: 8px;
  border: 1px solid var(--color-accent);
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  padding: 6px 10px;
  outline: none;
}

.control-value {
  background: var(--bg-card);
  font-size: 12px;
  padding: 4px 10px;
}

.add-scene-textarea {
  padding: 8px 10px;
  font-size: 12px;
  resize: vertical;
  min-height: 52px;
  margin-bottom: 8px;
}

.script-textarea {
  min-height: 72px;
  margin-bottom: 0;
}

.add-scene-textarea:focus,
.rewrite-custom-input:focus,
.control-value:focus {
  outline: none;
  border-color: rgba(255, 107, 53, 0.45);
}

.add-scene-visual-source {
  margin-top: 12px;
  margin-bottom: 10px;
}

.add-scene-tabs {
  margin-bottom: 10px;
}

.add-scene-control-row {
  margin-top: 8px;
}

/* Prominent query input used in both add-scene and edit visual panels */
.scene-query-label {
  font-size: 11px;
  color: var(--text-muted);
  font-weight: 500;
  letter-spacing: 0.04em;
  margin-bottom: 5px;
}

.scene-query-input {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 10px 12px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  line-height: 1.5;
  resize: none;
  transition: border-color 0.15s;
  margin-bottom: 2px;
}

.scene-query-input::placeholder {
  color: var(--text-muted);
  opacity: 0.7;
}

.scene-query-input:focus {
  outline: none;
  border-color: rgba(255, 107, 53, 0.45);
  background: var(--bg-card);
}

.add-scene-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.purple-btn {
  color: var(--purple);
  border-color: var(--purple);
}

.editor-canvas {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--bg-deep);
  position: relative;
}

.preview-container {
  /* width/height come from :style binding (previewContainerStyle) to match the project aspect ratio */
  width: 270px;
  height: 480px;
  background: #000;
  border-radius: 16px;
  overflow: hidden;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}

.preview-video-bg {
  width: 100%;
  height: 100%;
  position: relative;
  overflow: hidden;
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
}

.preview-image,
.preview-fallback {
  width: 100%;
  height: 100%;
}

.preview-image {
  object-fit: cover;
  transform-origin: center center;
}

/* ── Character chip + popover (inside Visual panel AI Image section) ─── */
.char-chip-wrap { position: relative; margin-bottom: 10px; }
.char-chip {
  display: flex; align-items: center; gap: 9px;
  padding: 7px 9px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.char-chip:hover { border-color: rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); }
.char-chip-thumb {
  width: 30px; height: 30px; border-radius: 6px;
  background: linear-gradient(135deg, #ff6b35 0%, #cf4f1d 100%);
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px; color: #0a0a0f;
  overflow: hidden;
}
.char-chip-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.char-chip-text { flex: 1; min-width: 0; }
.char-chip-name { font-size: 12.5px; font-weight: 600; line-height: 1.2; }
.char-chip-trail { font-size: 10.5px; opacity: 0.55; margin-top: 1px; }
.char-chip-chev { font-size: 11px; opacity: 0.55; }
.char-popover {
  position: absolute; top: calc(100% + 6px); left: 0; right: 0;
  background: #14141c;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 10px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.55);
  z-index: 30;
  padding: 10px;
}
.char-popover-empty { font-size: 12px; opacity: 0.55; text-align: center; padding: 18px 6px; }
.char-popover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; max-height: 200px; overflow-y: auto; }
.char-popover-item {
  border-radius: 7px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  padding: 7px 5px;
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  cursor: pointer; text-align: center;
  transition: border-color 0.15s, background 0.15s;
}
.char-popover-item:hover { border-color: rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); }
.char-popover-item.selected { border-color: rgba(255,107,53,0.55); background: rgba(255,107,53,0.12); }
.char-popover-thumb {
  width: 36px; height: 36px; border-radius: 6px;
  background: linear-gradient(135deg, #ff6b35 0%, #cf4f1d 100%);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; color: #0a0a0f;
  overflow: hidden;
}
.char-popover-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Upload zone in the create-character modal */
.char-upload-drop {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  border: 1.5px dashed rgba(255,255,255,0.16); border-radius: 10px;
  padding: 22px 12px; text-align: center; cursor: pointer;
  background: rgba(255,255,255,0.02); transition: 0.15s;
}
.char-upload-drop:hover { border-color: rgba(255,107,53,0.45); background: rgba(255,107,53,0.05); }
.char-upload-ico { font-size: 22px; opacity: 0.55; }
.char-upload-copy { font-size: 13px; font-weight: 500; margin-top: 4px; }
.char-upload-sub { font-size: 11px; opacity: 0.5; margin-top: 2px; }
.char-upload-preview { position: relative; width: 100%; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: #0a0a0f; }
.char-upload-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
.char-upload-clear {
  position: absolute; top: 8px; right: 8px;
  background: rgba(0,0,0,0.55); color: #fff;
  border: 1px solid rgba(255,255,255,0.15);
  font-size: 11px; padding: 4px 8px; border-radius: 6px;
  cursor: pointer; font-family: inherit;
}
.char-upload-clear:hover { background: rgba(0,0,0,0.75); }
.char-popover-name { font-size: 10.5px; font-weight: 600; line-height: 1.15; word-break: break-word; }
.char-popover-trail { font-size: 9.5px; opacity: 0.45; margin-top: 2px; }
.char-popover-foot {
  display: flex; justify-content: space-between; align-items: center;
  border-top: 1px solid rgba(255,255,255,0.08);
  padding-top: 8px; margin-top: 8px;
}
.char-popover-none {
  background: transparent; border: none; color: rgba(255,255,255,0.5);
  font-size: 11px; cursor: pointer; font-family: inherit;
}
.char-popover-none:hover { color: #fff; }
.char-popover-new {
  background: transparent; border: none; color: #ff6b35;
  font-size: 11px; font-weight: 600; cursor: pointer; font-family: inherit;
}
.char-popover-new:hover { color: #ff8055; }

/* Hooks for the create-character modal — reuses .ap-* styles */
.ap-hint { font-size: 11px; opacity: 0.55; margin-top: 4px; }
.ap-error { font-size: 12px; color: #ff6b6b; margin: 10px 0; }


.preview-fit-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  filter: blur(18px) saturate(1.05);
  transform: scale(1.1);
}

.preview-video-contain {
  object-fit: contain;
  position: relative;
  z-index: 1;
}

/* Ken Burns canvas preview — approximate visual (real motion rendered by FFmpeg) */
@keyframes kb-zoom-in {
  from { transform: scale(1); }
  to   { transform: scale(1.25); }
}
@keyframes kb-zoom-out {
  from { transform: scale(1.25); }
  to   { transform: scale(1); }
}
@keyframes kb-pan-left {
  from { transform: scale(1.15) translateX(8%); }
  to   { transform: scale(1.15) translateX(-8%); }
}
@keyframes kb-pan-right {
  from { transform: scale(1.15) translateX(-8%); }
  to   { transform: scale(1.15) translateX(8%); }
}
@keyframes kb-pan-up {
  from { transform: scale(1.15) translateY(8%); }
  to   { transform: scale(1.15) translateY(-8%); }
}
@keyframes kb-pan-down {
  from { transform: scale(1.15) translateY(-8%); }
  to   { transform: scale(1.15) translateY(8%); }
}
@keyframes kb-pan-zoom {
  from { transform: scale(1) translateX(-5%); }
  to   { transform: scale(1.25) translateX(5%); }
}

.kb-zoom-in  { animation: kb-zoom-in  6s ease-in-out infinite alternate; }
.kb-zoom-out { animation: kb-zoom-out 6s ease-in-out infinite alternate; }
.kb-pan-left { animation: kb-pan-left 6s ease-in-out infinite alternate; }
.kb-pan-right { animation: kb-pan-right 6s ease-in-out infinite alternate; }
.kb-pan-up   { animation: kb-pan-up   6s ease-in-out infinite alternate; }
.kb-pan-down { animation: kb-pan-down 6s ease-in-out infinite alternate; }
.kb-pan-zoom { animation: kb-pan-zoom 6s ease-in-out infinite alternate; }

.preview-fallback {
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
}

.preview-fallback-text,
.preview-fallback-waveform {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px;
}

.text-only-card {
  width: 100%;
  height: 100%;
  border-radius: 22px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(circle at top left, rgba(255, 107, 53, 0.22), transparent 35%),
    linear-gradient(180deg, rgba(30, 30, 44, 0.96), rgba(12, 12, 21, 0.96));
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 18px;
}

.text-only-label {
  font-family: "Space Mono", monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  color: rgba(255, 255, 255, 0.45);
}

.text-only-copy {
  font-size: 28px;
  line-height: 1.28;
  font-weight: 700;
  color: #fff;
  text-align: center;
}

.waveform-shell {
  width: 100%;
  height: 100%;
  padding: 28px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
}

/* ── Classic bars ── */
.ag-bars {
  height: 200px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 6px;
  width: 100%;
}
.ag-bar {
  flex: 1;
  max-width: 18px;
  min-height: 14%;
  border-radius: 4px 4px 0 0;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Mirror wave ── */
.ag-mirror {
  height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
}
.ag-mirror-bar {
  flex: 1;
  max-width: 16px;
  border-radius: 999px;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Radial / Circle ── */
.ag-circle-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── Minimal ── */
.ag-minimal {
  height: 120px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 3px;
  width: 100%;
}
.ag-minimal-bar {
  flex: 1;
  max-width: 8px;
  min-height: 8%;
  border-radius: 2px 2px 0 0;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Animations ── */
@keyframes ag-bounce {
  0%   { transform: scaleY(0.55); }
  100% { transform: scaleY(1); }
}
@keyframes ag-rad-pulse {
  0%   { transform: scaleY(0.5); opacity: 0.6; }
  100% { transform: scaleY(1); opacity: 1; }
}

/* ── Panel: audiogram settings ── */
.ag-style-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
}
.ag-style-opt {
  background: var(--color-bg-elevated);
  border: 1.5px solid var(--color-border);
  border-radius: 8px;
  padding: 8px 6px 6px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  transition: border-color 0.15s;
}
.ag-style-opt:hover { border-color: var(--color-border-active); }
.ag-style-opt.selected { border-color: var(--color-accent); }
.ag-style-mini {
  width: 100%;
  height: 44px;
  border-radius: 5px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 2px;
  overflow: hidden;
  padding: 4px 4px 3px;
}
.ag-mini-bar {
  flex: 1;
  border-radius: 2px 2px 0 0;
  opacity: 0.9;
}
.ag-mini-mirror {
  border-radius: 999px;
  align-self: center;
}
.ag-mini-minimal {
  flex: 1;
  max-width: 4px;
  border-radius: 1px 1px 0 0;
  opacity: 0.85;
}
.ag-style-label {
  font-size: 10px;
  color: var(--color-text-muted);
  font-weight: 500;
}

/* Color swatches */
.ag-colors {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.ag-color-swatch {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  transition: border-color 0.12s, transform 0.12s;
  padding: 0;
}
.ag-color-swatch:hover { transform: scale(1.15); }
.ag-color-swatch.selected { border-color: #fff; box-shadow: 0 0 0 1px rgba(255,255,255,.25); }
.ag-color-custom {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 1.5px dashed var(--color-border-active);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.ag-color-custom input[type="color"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}
.ag-color-custom-icon {
  font-size: 13px;
  color: var(--color-text-muted);
  pointer-events: none;
  line-height: 1;
}

/* Background row */
.ag-bg-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 5px;
}
.ag-bg-opt {
  height: 32px;
  border-radius: 6px;
  border: 1.5px solid transparent;
  cursor: pointer;
  font-size: 9px;
  font-weight: 600;
  color: rgba(255,255,255,.7);
  letter-spacing: .04em;
  transition: border-color 0.12s;
}
.ag-bg-opt:hover { border-color: var(--color-border-active); }
.ag-bg-opt.selected { border-color: #fff; }

.preview-loading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(10, 10, 15, 0.46);
  color: rgba(255, 255, 255, 0.82);
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.preview-loading.error {
  color: #fca5a5;
}

.preview-caption {
  position: absolute;
  bottom: 100px;
  left: 16px;
  right: 16px;
  text-align: center;
}

.caption-word {
  font-size: 22px;
  font-weight: 700;
  display: inline;
  line-height: 1.4;
}

.caption-word.highlight {
  color: var(--accent);
}

.caption-word.normal {
  color: #fff;
}

.caption-hidden {
  display: none !important;
}

/* Editorial style — italic, serif highlight */
.caption-style-editorial .caption-word {
  font-style: italic;
  font-weight: 400;
}
.caption-style-editorial .caption-word.highlight {
  color: #fff;
  font-style: italic;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.caption-style-editorial .caption-word.normal {
  color: rgba(255, 255, 255, 0.75);
}

/* Hacker style — monospace, yellow highlight */
.caption-style-hacker .caption-word {
  font-size: 16px;
  font-weight: 400;
}
.caption-style-hacker .caption-word.highlight {
  color: var(--yellow);
}
.caption-style-hacker .caption-word.normal {
  color: rgba(255, 255, 255, 0.9);
}

.preview-watermark {
  position: absolute;
  top: 16px;
  left: 16px;
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: rgba(255, 255, 255, 0.3);
}

.preview-timer {
  position: absolute;
  top: 16px;
  right: 16px;
  font-family: "Space Mono", monospace;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.5);
  background: rgba(0, 0, 0, 0.4);
  padding: 3px 8px;
  border-radius: 4px;
}

.playback-controls {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
}

.play-btn {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-size: 18px;
  transition: 0.2s;
}

.play-btn:hover {
  transform: scale(1.05);
  box-shadow: 0 0 24px var(--accent-glow);
}

.time-display {
  font-family: "Space Mono", monospace;
  font-size: 12px;
  color: var(--text-muted);
}

.editor-right {
  width: 300px;
  border-left: 1px solid var(--border);
  /* Flex column so the active view (Config or Assistant) can claim the
     remaining height after the toggle bar and handle its own scrolling.
     Was overflow-y:auto on the rail itself — that pushed the Assistant
     input + footer off the bottom when the chat grew tall. */
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
.editor-right > .cruise-toggle-bar { flex: 0 0 auto; }
.editor-right > .cruise-config-view {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.panel-section {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}

.panel-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  cursor: pointer;
}

.panel-label {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 10px;
  font-weight: 500;
}

.panel-label-tight {
  margin-bottom: 0;
}

.panel-label-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.panel-badge {
  padding: 2px 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
}

.panel-badge.warn {
  background: var(--yellow-bg);
  color: var(--yellow);
}

.panel-badge.new {
  background: rgba(167, 139, 250, 0.15);
  color: #a78bfa;
}

/* ── Section error info icon + tooltip ── */
.section-error-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgba(251, 191, 36, 0.15);
  color: #fbbf24;
  font-size: 11px;
  cursor: default;
  position: relative;
  flex-shrink: 0;
  line-height: 1;
}

.section-error-icon::after {
  content: attr(data-tip);
  position: absolute;
  left: 50%;
  bottom: calc(100% + 8px);
  transform: translateX(-50%);
  background: #1e1e2a;
  border: 1px solid rgba(251, 191, 36, 0.3);
  color: #fbbf24;
  font-size: 12px;
  font-weight: 400;
  line-height: 1.5;
  padding: 8px 12px;
  border-radius: 8px;
  white-space: normal;
  width: 220px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s;
  z-index: 200;
}

.section-error-icon:hover::after {
  opacity: 1;
}

.panel-scope-hint {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--color-text-muted);
  background: rgba(255,255,255,.04);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 2px 7px;
}

.visual-type-tabs {
  display: flex;
  gap: 4px;
  background: var(--bg-deep);
  border-radius: 8px;
  padding: 3px;
}

.visual-type-tab {
  flex: 1;
  padding: 5px 8px;
  border-radius: 6px;
  border: none;
  background: transparent;
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
}

.visual-type-tab.active {
  background: var(--bg-card);
  color: var(--text-primary);
}

.visual-type-tab.active.ai {
  background: rgba(167, 139, 250, 0.15);
  color: #a78bfa;
}

.ai-style-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.ai-style-btn {
  padding: 5px 4px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--bg-deep);
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}

.ai-style-btn:hover {
  border-color: var(--border-active);
  color: var(--text-primary);
}

.ai-style-btn.active {
  border-color: #a78bfa;
  background: rgba(167, 139, 250, 0.12);
  color: #a78bfa;
}

.panel-error-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--red);
}

.panel-hint-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
  font-style: italic;
}

.panel-chevron {
  color: var(--text-muted);
  font-size: 14px;
  transition: transform 0.2s ease;
}

.panel-section.collapsed .panel-chevron {
  transform: rotate(-90deg);
}

.panel-section-body {
  margin-top: 12px;
}

.panel-section.collapsed .panel-section-body {
  display: none;
}

.helper-copy,
.rewrite-note {
  font-size: 11px;
  color: var(--text-muted);
}

.script-save-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
}

.script-save-copy.error {
  color: #fca5a5;
}

.rewrite-error {
  margin-top: 8px;
  color: #fca5a5;
  font-size: 12px;
}

.helper-copy {
  margin-top: 6px;
}

.duration-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.duration-label {
  font-size: 11px;
  color: var(--text-muted, #888);
  white-space: nowrap;
}

.duration-input {
  width: 72px;
  padding: 4px 6px;
  border: 1px solid var(--border, #333);
  border-radius: 4px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.duration-saving {
  font-size: 11px;
  color: var(--text-muted, #888);
}

.duration-hint {
  font-size: 11px;
  color: var(--text-muted, #888);
  opacity: 0.6;
  flex-basis: 100%;
}

/* Caption / Voice presets */
.preset-row {
  margin-bottom: 8px;
}

.preset-select {
  width: 100%;
  padding: 5px 8px;
  border: 1px solid var(--border, #333);
  border-radius: 5px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.preset-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 8px;
}

.preset-chip {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 3px 7px;
  border-radius: 12px;
  background: var(--bg-elevated, #222);
  border: 1px solid var(--border, #333);
  font-size: 11px;
}

.preset-chip-name {
  cursor: pointer;
  color: var(--text, #eee);
}

.preset-chip-name:hover {
  color: var(--accent, #ff6b35);
}

.preset-chip-del {
  background: none;
  border: none;
  color: var(--text-muted, #888);
  cursor: pointer;
  font-size: 10px;
  padding: 0 1px;
  line-height: 1;
}

.preset-chip-del:hover {
  color: var(--danger, #e05);
}

.preset-save-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.preset-name-input {
  flex: 1;
  min-width: 100px;
  padding: 4px 8px;
  border: 1px solid var(--border, #333);
  border-radius: 4px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.panel-inline-actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.rewrite-tools {
  margin-top: 10px;
}

.rewrite-custom {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}

.rewrite-custom-input {
  flex: 1;
  padding: 5px 8px;
  border-radius: var(--radius-sm);
  font-size: 12px;
}

.rewrite-preview {
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid rgba(167, 139, 250, 0.35);
  background: rgba(167, 139, 250, 0.08);
}

.rewrite-preview-title {
  font-size: 11px;
  color: var(--purple);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 6px;
}

.rewrite-preview-copy {
  font-size: 12px;
  line-height: 1.5;
  color: var(--text-primary);
}

.rewrite-preview-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

.control-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  gap: 10px;
}

.control-row:last-child {
  margin-bottom: 0;
}

.top-space {
  margin-top: 10px;
}

.control-name {
  font-size: 13px;
  color: var(--text-secondary);
}

select.control-value {
  appearance: none;
  cursor: pointer;
  padding-right: 24px;
  background-color: var(--bg-card);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b8b9e'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
}

.query-input {
  width: 100%;
  box-sizing: border-box;
}

.panel-full-btn {
  margin-top: 8px;
  width: 100%;
  justify-content: center;
}

.btn-full {
  width: 100%;
  justify-content: center;
  margin-top: 8px;
}

.micro-copy,
.micro-error {
  margin-top: 8px;
  font-size: 11px;
  line-height: 1.45;
}

.micro-copy {
  color: var(--text-muted);
}

.micro-error {
  color: var(--red);
}

.voice-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px;
}

.voice-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--purple));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.voice-info {
  flex: 1;
}

.voice-name {
  font-size: 13px;
  font-weight: 500;
}

.voice-desc {
  font-size: 11px;
  color: var(--text-muted);
}

.voice-play {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  cursor: pointer;
  transition: 0.15s;
  flex-shrink: 0;
}

.voice-play:hover {
  background: var(--bg-card);
  color: var(--text-primary);
}

.voice-play.disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.voice-warning-row {
  margin-top: 10px;
  padding: 8px 10px;
  background: var(--yellow-bg);
  border: 1px solid rgba(251, 191, 36, 0.2);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.voice-warning-copy {
  font-size: 11px;
  color: var(--yellow);
}

.voice-loading-copy {
  margin-top: 10px;
  color: var(--text-secondary);
  font-size: 12px;
}

.regen-btn {
  background: var(--yellow);
  color: #000;
  font-weight: 600;
  padding: 4px 10px;
  font-size: 11px;
  border-radius: 6px;
  cursor: pointer;
}

.regen-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.caption-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.caption-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--text-muted);
  cursor: pointer;
}

.caption-toggle input {
  accent-color: var(--accent);
}

.caption-style-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.caption-style-opt {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px;
  text-align: center;
  cursor: pointer;
  transition: 0.2s;
}

.caption-style-opt.active {
  border-color: var(--accent);
  background: var(--accent-glow);
}

.preview-text {
  font-size: 11px;
  font-weight: 700;
  line-height: 1.3;
  margin-bottom: 4px;
}

.accent-text {
  color: var(--accent);
}

.serif-text {
  color: #fff;
  font-style: italic;
}

.mono-text {
  color: var(--yellow);
  font-family: "Space Mono", monospace;
}

.style-name {
  font-size: 9px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.hooks-block {
  margin-top: 12px;
}

.hooks-title {
  margin-bottom: 6px;
}

.hook-card {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  font-size: 12px;
  line-height: 1.5;
  color: var(--text-secondary);
  margin-top: 8px;
}

.hook-card-top {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.hook-score-badge {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 20px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding: 0 4px;
}

.score-green  { background: #14532d; color: #4ade80; }
.score-yellow { background: #713f12; color: #fbbf24; }
.score-red    { background: #450a0a; color: #f87171; }
.score-none   { background: var(--bg-deep); color: var(--text-muted); }

.hook-card-text {
  flex: 1;
  line-height: 1.5;
}

.hook-card-reason {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
  font-style: italic;
  line-height: 1.4;
}

.hook-use-btn {
  margin-top: 8px;
  width: 100%;
}

/* Hint box */
.hint-box {
  font-size: 11px;
  color: var(--text-muted);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 8px 10px;
  margin-bottom: 10px;
  line-height: 1.5;
}

/* Asset current preview (My Assets tab) */
.asset-current-preview {
  margin-top: 10px;
  margin-bottom: 10px;
}
.asset-current-thumb {
  width: 100%;
  height: 160px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--color-bg-elevated);
  position: relative;
  margin-bottom: 8px;
}
.asset-current-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.asset-current-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-card);
}
.asset-current-badge {
  position: absolute;
  top: 6px;
  left: 6px;
  background: rgba(0,0,0,.7);
  border-radius: 4px;
  padding: 2px 6px;
  font-size: 9px;
  font-weight: 700;
  font-family: var(--font-mono, monospace);
  color: var(--color-text-muted);
}
.asset-current-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.asset-current-type {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

/* Asset empty state */
.asset-empty-state {
  text-align: center;
  padding: 20px 8px 14px;
}
.asset-empty-icon { font-size: 24px; margin-bottom: 8px; }
.asset-empty-title { font-size: 13px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.asset-empty-sub { font-size: 11px; color: var(--color-text-muted); line-height: 1.5; }

/* Sounds panel */
.sound-current-row {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 10px 10px;
  margin-top: 6px;
  margin-bottom: 8px;
}
.sound-current-icon { font-size: 16px; flex-shrink: 0; }
.sound-current-info { flex: 1; min-width: 0; }
.sound-current-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sound-current-meta { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; }
.sound-remove-btn {
  width: 22px; height: 22px; border-radius: 50%; border: none;
  background: rgba(255,255,255,.06); color: var(--color-text-muted);
  cursor: pointer; font-size: 11px; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: .15s;
}
.sound-remove-btn:hover { background: rgba(255,60,60,.15); color: #ef4444; }

/* Music panel tabs */
.music-panel-tabs {
  display: flex;
  background: var(--color-bg-deep, var(--bg-deep));
  border-radius: 8px;
  padding: 3px;
  gap: 2px;
  margin-bottom: 10px;
}
.music-panel-tab {
  flex: 1;
  padding: 6px 8px;
  border-radius: 5px;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--color-text-muted, var(--text-muted));
  background: transparent;
  border: none;
  transition: .15s;
}
.music-panel-tab.active {
  background: var(--color-bg-panel, var(--bg-panel));
  color: var(--color-text-primary, var(--text-primary));
}
.music-panel-tab:hover:not(.active) {
  color: var(--color-text-primary, var(--text-primary));
}

/* Mood filter chips */
.filter-chip {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-muted);
  cursor: pointer;
  font-size: 12px;
  transition: 0.15s;
}

.filter-chip:hover {
  border-color: rgba(255,255,255,0.2);
}

.filter-chip.active {
  background: rgba(255,107,53,0.12);
  border-color: rgba(255,107,53,0.35);
  color: var(--accent);
}

/* Music panel */
.music-selected-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 10px;
  margin-bottom: 10px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid rgba(255,107,53,0.35);
  background: rgba(255,107,53,0.08);
  color: var(--text-muted);
  font-size: 10px;
}

.music-selected-copy {
  min-width: 0;
}

.music-selected-label {
  display: block;
  margin-bottom: 2px;
  color: var(--text-muted);
  font-family: "Space Mono", monospace;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.music-selected-copy strong {
  display: block;
  color: var(--text-primary);
  font-size: 12px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-music-moods { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
.ai-music-mood-chip {
  font-size: 11.5px;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  cursor: pointer;
  transition: 0.15s;
  font-family: inherit;
}
.ai-music-mood-chip:hover { color: var(--text-primary); border-color: var(--border-active); }
.ai-music-mood-chip.selected {
  background: rgba(255,107,53,0.12);
  border-color: rgba(255,107,53,0.55);
  color: var(--accent);
}

.music-filter-row {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.music-filter-row .filter-chip {
  padding: 4px 9px;
  font-size: 11px;
}

.music-track-scroll {
  max-height: 330px;
  overflow-y: auto;
  display: grid;
  gap: 10px;
  padding-right: 4px;
  margin-bottom: 14px;
}

.music-track-scroll::-webkit-scrollbar {
  width: 6px;
}

.music-track-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.music-track-scroll::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.18);
  border-radius: 999px;
}

.music-category {
  display: grid;
  gap: 6px;
}

.music-category-title {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-muted);
  font-family: "Space Mono", monospace;
  font-size: 10px;
  text-transform: uppercase;
}

.music-track-list {
  display: grid;
  gap: 6px;
}

.music-track {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border);
  background: var(--bg-card);
  cursor: pointer;
  transition: 0.15s;
}

.music-track:hover {
  border-color: rgba(255,255,255,0.2);
}

.music-track.selected {
  border-color: var(--accent);
  background: rgba(255,107,53,0.08);
}

.music-track-thumb {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.music-track-info {
  flex: 1;
  min-width: 0;
}

.music-track-name {
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-primary);
}

.music-track-meta {
  font-size: 10px;
  color: var(--text-muted);
  margin-top: 1px;
}

.music-track-duration {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  flex-shrink: 0;
}

.music-play-btn {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  flex-shrink: 0;
  cursor: pointer;
}

.music-track.selected .music-play-btn {
  border-color: var(--accent);
  color: var(--accent);
}

.music-controls {
  padding: 12px;
  border-radius: var(--radius-sm, 6px);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
}

.music-control-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.music-control-row:last-child {
  margin-bottom: 0;
}

.music-control-label {
  font-size: 11px;
  color: var(--text-muted);
  width: 70px;
  flex-shrink: 0;
}

.volume-slider-wrap { display: flex; align-items: center; gap: 8px; flex: 1; }
.volume-slider-wrap .music-slider { flex: 1; }
.music-slider {
  flex: 1;
  appearance: none;
  height: 4px;
  border-radius: 999px;
  background: var(--border);
  outline: none;
  cursor: pointer;
}

.music-slider::-webkit-slider-thumb {
  appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--accent);
  cursor: pointer;
}

.music-slider-val {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  width: 28px;
  text-align: right;
}

/* Canvas music wave indicator */
.preview-music-indicator {
  position: absolute;
  bottom: 16px;
  left: 16px;
  right: 16px;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 6px;
  background: rgba(0,0,0,0.5);
  backdrop-filter: blur(4px);
  pointer-events: none;
}

.preview-music-waves {
  display: flex;
  align-items: center;
  gap: 2px;
}

.music-wave {
  width: 2px;
  border-radius: 999px;
  background: var(--accent);
  animation: wave 0.6s ease-in-out infinite alternate;
  animation-play-state: paused;
}

.music-wave.playing {
  animation-play-state: running;
}

.music-wave:nth-child(2) { animation-delay: 0.1s; }
.music-wave:nth-child(3) { animation-delay: 0.2s; }
.music-wave:nth-child(4) { animation-delay: 0.3s; }

@keyframes wave {
  from { height: 4px; opacity: 0.5; }
  to   { height: 12px; opacity: 1; }
}

.preview-music-name {
  font-size: 10px;
  color: rgba(255,255,255,0.7);
  font-family: "Space Mono", monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Playback controls */
.preview-mode-toggle {
  display: flex;
  gap: 3px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 999px;
  padding: 3px;
}

.preview-mode-btn {
  padding: 4px 14px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  border: none;
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.2s;
}

.preview-mode-btn.active {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.preview-scrubber {
  width: 270px;
  position: relative;
  padding: 6px 0;
  cursor: pointer;
}

.scrubber-track {
  height: 3px;
  border-radius: 999px;
  background: var(--border);
  position: relative;
}

.scrubber-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--accent);
  width: 0%;
  pointer-events: none;
}

.scrubber-thumb {
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: var(--accent);
  left: 0%;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s;
}

.preview-scrubber:hover .scrubber-thumb {
  opacity: 1;
}

.playback-btns {
  display: flex;
  align-items: center;
  gap: 14px;
}

.play-skip-btn {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  flex-shrink: 0;
}

.play-skip-btn:hover:not(:disabled) {
  border-color: var(--border-active, rgba(255,255,255,0.2));
  color: var(--text-primary);
}

.play-skip-btn:disabled {
  opacity: 0.3;
  cursor: default;
}

.play-time-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.play-scene-label {
  font-size: 10px;
  color: var(--text-muted);
  margin-left: 6px;
}

/* Visual Source panel — style picker grid */
.style-picker-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
  margin-bottom: 12px;
}

.style-opt {
  padding: 7px 4px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border);
  background: var(--bg-card);
  text-align: center;
  cursor: pointer;
  transition: 0.15s;
}

.style-opt:hover {
  border-color: rgba(167,139,250,.4);
}

.style-opt.selected {
  border-color: #a78bfa;
  background: rgba(167,139,250,.1);
}

.style-opt-ico {
  font-size: 14px;
  display: block;
  margin-bottom: 2px;
}

.style-opt-name {
  font-size: 9px;
  color: var(--text-muted);
}

.style-opt.selected .style-opt-name {
  color: #a78bfa;
}

.style-opt.accent {
  border-color: rgba(255,107,53,.35);
  background: rgba(255,107,53,.04);
}
.style-opt.accent:hover {
  border-color: rgba(255,107,53,.5);
}
.style-opt.accent.selected {
  border-color: #ff6b35;
  background: rgba(255,107,53,.12);
}
.style-opt.accent.selected .style-opt-name {
  color: #ff6b35;
}

.custom-style-panel {
  padding: 10px 12px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid rgba(255,107,53,.2);
  background: rgba(255,107,53,.04);
  margin: 8px 0 12px;
}

/* Picked-asset preview card inside the add-scene Assets tab. */
.add-scene-asset-preview {
  display: flex; align-items: center; gap: 10px;
  padding: 10px; border-radius: 8px;
  border: 1px solid rgba(255,107,53,.25);
  background: rgba(255,107,53,.04);
  margin-top: 8px;
}
.add-scene-asset-preview img {
  width: 56px; height: 56px; object-fit: cover; border-radius: 6px; flex-shrink: 0;
}
.add-scene-asset-meta { flex: 1; min-width: 0; }
.add-scene-asset-title {
  font-size: 12px; font-weight: 500; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.add-scene-asset-type {
  font-size: 10px; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em;
  font-family: "Space Mono", monospace; margin-top: 2px;
}

/* Asset image browser */
.asset-search-input {
  width: 100%; box-sizing: border-box; height: 32px; border-radius: 6px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text-primary); padding: 0 8px; font-size: 12px;
}
.asset-search-input:focus { outline: none; border-color: var(--color-accent); }
.asset-loading { font-size: 12px; color: var(--color-text-muted); padding: 12px 0; text-align: center; }
.asset-empty { font-size: 12px; color: var(--color-text-muted); padding: 20px 0; text-align: center; }
.asset-image-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-top: 8px; max-height: 240px; overflow-y: auto;
}
.asset-thumb {
  position: relative; aspect-ratio: 1; border-radius: 5px; overflow: hidden;
  border: 2px solid transparent; cursor: pointer; transition: 0.12s;
}
.asset-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.asset-thumb:hover { border-color: rgba(167,139,250,.5); }
.asset-thumb.active { border-color: #a78bfa; }
.asset-thumb-badge {
  position: absolute; top: 3px; right: 3px; width: 16px; height: 16px;
  border-radius: 50%; background: #a78bfa; color: #fff; font-size: 9px;
  display: flex; align-items: center; justify-content: center; font-weight: 700;
}

/* Custom audio list */
/* Approval link modal */
.ap-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 300; }
.ap-modal { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 22px; width: min(460px, calc(100vw - 32px)); max-height: 90vh; overflow-y: auto; }
.ap-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.ap-title { font-size: 15px; font-weight: 600; }
.ap-close { background: none; border: none; color: var(--color-text-muted); font-size: 22px; cursor: pointer; line-height: 1; padding: 0; }
.ap-meta { font-size: 12px; color: var(--color-text-muted); line-height: 1.5; margin-bottom: 14px; }
.ap-field { margin-bottom: 12px; }
.ap-label { display: block; font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; font-weight: 500; }
.ap-input { width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 7px; color: var(--color-text-primary); padding: 9px 11px; font-size: 13px; font-family: inherit; outline: none; }
.ap-input:focus { border-color: rgba(255,107,53,.5); }
.ap-error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.2); color: #fca5a5; border-radius: 7px; padding: 8px 11px; font-size: 12px; margin-bottom: 12px; }
.ap-footer { display: flex; justify-content: flex-end; gap: 8px; padding-top: 14px; border-top: 1px solid var(--color-border); margin-top: 10px; }
.ap-success { text-align: center; padding: 8px 0; }
.ap-success-icon { width: 48px; height: 48px; border-radius: 50%; background: rgba(52,211,153,.15); color: #34d399; font-size: 22px; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.ap-success-title { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
.ap-success-sub { font-size: 12px; color: var(--color-text-muted); line-height: 1.55; margin-bottom: 14px; }
.ap-link-box { display: flex; gap: 8px; margin-bottom: 14px; }

/* Voice tabs */
.voice-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-border); margin-bottom: 12px; }
.voice-tab { flex: 1; padding: 6px 12px; font-size: 12px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; border: none; border-bottom: 2px solid transparent; background: transparent; font-family: inherit; transition: .15s; }
.voice-tab:hover { color: var(--color-text-primary); }
.voice-tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); }
.custom-voice-intro { font-size: 11px; color: var(--color-text-muted); line-height: 1.45; margin-bottom: 10px; }

/* Voice preview panel (before upload) */
.voice-preview-panel { background: rgba(96,165,250,.04); border: 1px solid rgba(96,165,250,.2); border-radius: 10px; padding: 12px; margin-bottom: 6px; }
.voice-preview-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 12px; }
.voice-preview-icon { font-size: 13px; }
.voice-preview-title { color: #60a5fa; font-weight: 500; }
.voice-preview-name { color: var(--color-text-muted); margin-left: auto; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; }

/* Custom audio player */
.vp-player { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.04); border-radius: 8px; margin-bottom: 10px; }
.vp-play-btn { width: 30px; height: 30px; border-radius: 50%; background: var(--color-accent); border: none; color: #fff; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; flex-shrink: 0; transition: .15s; font-family: inherit; }
.vp-play-btn:hover { transform: scale(1.06); }
.vp-track { flex: 1; height: 4px; background: rgba(255,255,255,.08); border-radius: 999px; cursor: pointer; overflow: hidden; position: relative; }
.vp-fill { height: 100%; background: var(--color-accent); border-radius: 999px; transition: width .1s linear; }
.vp-time { font-size: 10px; color: var(--color-text-muted); font-family: "Space Mono", monospace; flex-shrink: 0; white-space: nowrap; }

.voice-preview-actions { display: flex; gap: 6px; margin-bottom: 8px; }
.vp-action { flex: 1; justify-content: center; white-space: nowrap; }
.voice-preview-hint { font-size: 10px; color: var(--color-text-muted); opacity: .75; line-height: 1.4; }

/* Custom voice upload/record */
.voice-action-row { display: flex; gap: 6px; margin-bottom: 8px; }
.voice-upload-btn, .voice-record-btn { flex: 1; padding: 8px 10px; border-radius: 7px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); font-size: 12px; font-weight: 500; cursor: pointer; text-align: center; transition: .15s; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 4px; }
.voice-upload-btn:hover, .voice-record-btn:hover { background: var(--color-bg-card); border-color: var(--color-border-active); }
.voice-record-btn.recording { background: #b91c1c; border-color: #b91c1c; color: #fff; animation: pulse-record 1.4s ease-in-out infinite; }
@keyframes pulse-record { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
.voice-status { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 7px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); font-size: 12px; color: var(--color-text-muted); margin-bottom: 6px; }
.voice-status-spin { display: inline-block; animation: spin 1s linear infinite; color: var(--color-accent); }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.voice-status-cancel { margin-left: auto; background: transparent; border: none; color: var(--color-text-muted); font-size: 11px; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.voice-status-cancel:hover { color: var(--color-text-primary); background: rgba(255,255,255,.04); }
.voice-ready-panel { background: rgba(52,211,153,.06); border: 1px solid rgba(52,211,153,.25); border-radius: 8px; padding: 10px 12px; margin-bottom: 6px; }
.voice-ready-head { display: flex; align-items: center; justify-content: space-between; font-size: 12px; font-weight: 500; color: #34d399; margin-bottom: 8px; }
.voice-transcript-preview { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
.voice-transcript-label { font-size: 10px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.voice-transcript-text { font-size: 12px; line-height: 1.5; color: var(--color-text-primary); max-height: 90px; overflow-y: auto; }
.voice-ready-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.voice-error { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.25); color: #fca5a5; border-radius: 7px; padding: 8px 12px; font-size: 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }

.custom-audio-list { max-height: 160px; overflow-y: auto; display: flex; flex-direction: column; gap: 3px; }
.custom-audio-row {
  display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 6px;
  border: 1px solid var(--color-border); cursor: pointer; transition: 0.12s;
  font-size: 12px; color: var(--color-text-secondary);
}
.custom-audio-row:hover { border-color: rgba(167,139,250,.4); }
.custom-audio-row.active { border-color: #a78bfa; background: rgba(167,139,250,.08); color: var(--color-text-primary); }
.custom-audio-icon { flex-shrink: 0; }
.custom-audio-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.custom-audio-check { flex-shrink: 0; color: #a78bfa; font-weight: 700; }

/* Caption color + size */
.caption-color-row { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.color-swatch {
  width: 22px; height: 22px; border-radius: 50%; cursor: pointer;
  border: 2px solid transparent; flex-shrink: 0; transition: 0.12s;
}
.color-swatch:hover { transform: scale(1.15); }
.color-swatch.active { outline: 2px solid var(--color-accent); outline-offset: 2px; }
.color-custom-wrap {
  position: relative; width: 22px; height: 22px; cursor: pointer; flex-shrink: 0;
}
.color-custom-preview {
  display: block; width: 22px; height: 22px; border-radius: 50%;
  border: 2px solid var(--color-border); pointer-events: none;
}
.color-custom-square {
  border-radius: 4px;
}
.color-custom-input {
  position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; padding: 0;
}
.caption-size-row { display: flex; gap: 5px; }
.size-opt {
  flex: 1; height: 28px; border-radius: 6px; border: 1px solid var(--color-border);
  background: var(--color-bg-elevated); color: var(--color-text-muted);
  font-size: 11px; font-family: var(--font-mono); cursor: pointer; transition: 0.12s;
}
.size-opt:hover { border-color: rgba(167,139,250,.4); }
.size-opt.active { border-color: #a78bfa; background: rgba(167,139,250,.1); color: #a78bfa; }

/* AI image result preview */
.ai-image-result {
  border-radius: var(--radius-sm, 6px);
  overflow: hidden;
  position: relative;
  margin-top: 10px;
  margin-bottom: 8px;
}

.ai-image-preview {
  width: 100%;
  height: 120px;
  background: linear-gradient(160deg, #1a1a3e 0%, #0d1a2e 40%, #1a0d2e 70%, #2e1a0d 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.ai-image-overlay-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  background: rgba(167,139,250,.25);
  color: #a78bfa;
  border: 1px solid rgba(167,139,250,.3);
  backdrop-filter: blur(4px);
  z-index: 1;
}

.ai-image-placeholder {
  text-align: center;
}

.ai-image-ico {
  font-size: 36px;
  opacity: 0.35;
}

.ai-image-actions {
  display: flex;
  gap: 6px;
  margin-top: 6px;
}

/* ── Animate (i2v rung 4) — button + modal ─────────────────────────────── */
.animate-btn {
  flex: 1;
  background: rgba(255, 107, 53, 0.12);
  border: 1px solid rgba(255, 107, 53, 0.4);
  color: #ff8055;
  font-weight: 600;
}
.animate-btn:hover:not(:disabled) {
  background: rgba(255, 107, 53, 0.22);
  border-color: rgba(255, 107, 53, 0.7);
}
.anim-cancel-btn {
  flex: 1;
  border: 1px solid rgba(220, 80, 80, 0.45);
  color: #ff8888;
}
.anim-cancel-btn:hover { background: rgba(220, 80, 80, 0.12); border-color: rgba(220, 80, 80, 0.7); }

.anim-history-row { margin-top: 8px; }
.anim-history-label {
  font-size: 10.5px; opacity: 0.5; letter-spacing: 0.04em;
  text-transform: uppercase; margin-bottom: 5px;
}
.anim-history-strip { display: flex; gap: 6px; }
.anim-history-item {
  position: relative;
  width: 54px; aspect-ratio: 9/16;
  border-radius: 6px; overflow: hidden;
  border: 1.5px solid rgba(255,255,255,0.1);
  cursor: pointer; transition: 0.15s;
  background: #000;
}
.anim-history-item:hover { border-color: rgba(255,255,255,0.3); }
.anim-history-item.current { border-color: #ff6b35; box-shadow: 0 0 0 1px rgba(255,107,53,0.4); }
.anim-history-item video {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.anim-history-tier {
  position: absolute; top: 3px; left: 4px;
  background: rgba(0,0,0,0.65); color: #ff8055;
  font-size: 9px; font-weight: 700;
  padding: 1px 4px; border-radius: 3px;
  pointer-events: none;
}

.anim-tier-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
}
.anim-tier {
  background: rgba(255,255,255,0.03);
  border: 1.5px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  padding: 12px 10px;
  text-align: left;
  cursor: pointer;
  font-family: inherit;
  color: inherit;
  position: relative;
  transition: 0.15s;
}
.anim-tier:hover { border-color: rgba(255,255,255,0.2); }
.anim-tier.selected {
  border-color: #ff6b35;
  background: rgba(255,107,53,0.1);
  box-shadow: inset 0 0 0 1px rgba(255,107,53,0.4);
}
.anim-tier-head {
  display: flex; align-items: center; justify-content: space-between; gap: 6px;
}
.anim-tier-name { font-size: 13px; font-weight: 700; }
.anim-tier-pill {
  background: #ff6b35; color: #0a0a0f;
  font-size: 8.5px; font-weight: 700; letter-spacing: 0.06em;
  padding: 2px 6px; border-radius: 4px;
}
.anim-tier-sub {
  font-size: 10px; opacity: 0.55;
  letter-spacing: 0.04em; text-transform: uppercase;
  margin-top: 2px; margin-bottom: 10px;
}
.anim-tier-row {
  display: flex; justify-content: space-between;
  font-size: 11px; margin-top: 3px; color: rgba(255,255,255,0.7);
}
.anim-tier-row span:first-child { opacity: 0.55; }
.anim-tier-cost { color: #ff6b35; font-weight: 700; }

.anim-duration-row { display: flex; gap: 6px; }
.anim-dpill {
  flex: 1;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 7px;
  padding: 8px 0;
  font-family: inherit; font-size: 12.5px; color: rgba(255,255,255,0.75);
  cursor: pointer;
  transition: 0.15s;
}
.anim-dpill:hover { border-color: rgba(255,255,255,0.2); }
.anim-dpill.active {
  background: rgba(255,107,53,0.15);
  border-color: rgba(255,107,53,0.55);
  color: #ff8055;
  font-weight: 600;
}

.anim-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid rgba(255,255,255,0.08);
  padding-top: 14px;
  margin-top: 10px;
}
.anim-total-credits {
  color: #ff6b35; font-weight: 700; font-size: 15px; margin-right: 6px;
}
.anim-total-sub { font-size: 11px; opacity: 0.55; }

.anim-prompt-chips {
  display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px;
}
.anim-prompt-chip {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 999px;
  padding: 5px 11px;
  font-family: inherit; font-size: 11px;
  color: rgba(255,255,255,0.7);
  cursor: pointer; transition: 0.15s;
}
.anim-prompt-chip:hover {
  background: rgba(255,107,53,0.1);
  border-color: rgba(255,107,53,0.4);
  color: #ff8055;
}

/* AI prompt area */
.ai-prompt-area {
  width: 100%;
  min-height: 54px;
  resize: none;
  padding: 8px 10px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm, 6px);
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  margin-bottom: 8px;
  box-sizing: border-box;
}

.ai-prompt-area:focus {
  outline: none;
  border-color: rgba(167,139,250,.45);
}

.ai-gen-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}

.ai-gen-meta {
  font-size: 11px;
  color: var(--text-muted);
}

.ai-gen-meta span {
  color: #a78bfa;
}

@media (max-width: 1180px) {
  .editor {
    display: grid;
    grid-template-columns: 320px 1fr;
    height: auto;
    min-height: calc(100vh - 64px);
  }

  .editor-canvas {
    min-height: 560px;
  }

  .editor-right {
    width: auto;
    grid-column: 1 / -1;
    border-left: 0;
    border-top: 1px solid var(--border);
  }
}

@media (max-width: 860px) {
  .main {
    margin-left: 0;
  }

  .topbar {
    padding: 12px 16px;
    height: auto;
    align-items: flex-start;
    flex-direction: column;
    gap: 12px;
  }

  .topbar-left,
  .topbar-right {
    width: 100%;
    justify-content: space-between;
    flex-wrap: wrap;
  }

  .editor {
    grid-template-columns: 1fr;
  }

  .editor-sidebar {
    width: auto;
  }

  .editor-canvas {
    min-height: 520px;
    padding: 24px 16px;
  }
}
</style>
