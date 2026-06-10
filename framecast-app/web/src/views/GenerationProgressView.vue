<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { getEcho } from '../services/echo'

const route  = useRoute()
const router = useRouter()
const projectId  = computed(() => route.params.projectId)
const connected  = ref(false)
const isTransitioningToEditor = ref(false)
const subtitle   = ref(`Project #${projectId.value}`)
// Narration: scenes are re-pulled on every poll/mount, so this is
// refresh-safe by construction — the assistant's play-by-play is always
// derived from persisted state, never from in-memory progress only.
const scenes     = ref([])
const isOneShot  = ref(false)
let channelName  = null
let pollTimer    = null

// One-shot prompt projects skip the script/scene/hook pipeline — the
// LLM parser splits the single prompt into voice/visual/music/motion
// channels up-front, then 3-4 jobs fan out in parallel. Different stage
// list keeps the progress page honest (no perpetually-skipped "Writing
// script" / "Crafting hooks" steps).
function oneShotStageDefinitions(project = null) {
  // animate/skip_image come from the wizard's URL query when present. When
  // the page is reached WITHOUT the query (dashboard -> click a generating
  // project), fall back to the persisted per-scene plan — `animate !== '0'`
  // alone defaulted to TRUE and showed a phantom "Animating scene" stage on
  // image-only projects.
  const animate = route.query.animate !== undefined
    ? route.query.animate !== '0'
    : scenes.value.some((s) => {
        const igs = s.image_generation_settings || {}
        return Boolean(igs.auto_animate || igs.animation_tier || igs.animation_in_progress || s.animation_video_asset_id)
      })
  const skipImage = route.query.skip_image === '1'
  // Visual source (ai_images | stock_video | stock_images | waveform): query
  // param from the wizard, else derived from the scenes' visual_type.
  const vs = route.query.vs || (() => {
    const types = scenes.value.map((s) => s.visual_type)
    if (types.includes('waveform')) return 'waveform'
    if (types.some((t) => t === 'stock_clip' || t === 'stock_image' || t === 'image_montage')) return 'stock_video'
    return 'ai_images'
  })()
  const isStock    = vs === 'stock_video' || vs === 'stock_images'
  const isWaveform = vs === 'waveform'
  // no_music is set by the wizard when the user turned sounds off in the
  // plan step. Drop the music stage entirely — otherwise it sits 'pending'
  // forever (no job fires) and the editor never opens. Without the query,
  // derive from the persisted per-scene plan (include_music); when neither
  // exists (older projects) default to NO music stage — a missing stage
  // self-heals, a phantom pending one blocks the editor.
  const noMusic = route.query.no_music !== undefined
    ? route.query.no_music === '1'
    : !scenes.value.some((s) => Boolean((s.image_generation_settings || {}).include_music))
  return [
    // Visual stage depends on source: AI generates per scene, stock matches
    // footage project-wide (MatchVisualsJob → 'visual_match' events), and
    // audiogram has no visual job at all (waveform renders from the voice).
    ...(isStock ? [{ key: 'visual_match', label: 'Finding visuals' }] : []),
    // When the user uploaded a photo or picked a character, image gen
    // was skipped entirely on the backend. The stage shouldn't appear.
    ...(!isStock && !isWaveform && !skipImage ? [{ key: 'ai_image', label: 'Generating image' }] : []),
    ...(animate && !isStock && !isWaveform ? [{ key: 'animation', label: 'Animating scene' }] : []),
    { key: 'tts',              label: 'Recording voice' },
    ...(noMusic ? [] : [{ key: 'ai_music', label: 'Composing music' }]),
    { key: 'preview_assembly', label: 'Wrapping up' },
  ]
}

function stageDefinitions(project = null) {
  if (project?.source_type === 'prompt') return oneShotStageDefinitions(project)

  const src = project?.source_type
  const isMedia = src === 'audio_upload' || src === 'video_upload'

  const mode = project?.visual_generation_mode
  const visualStage =
    mode === 'ai_images'    ? { key: 'ai_image',     label: 'Generating AI visuals' }
    : mode === 'stock_images' ? { key: 'visual_match', label: 'Matching stock images' }
    : mode === 'waveform'     ? { key: 'visual_match', label: 'Preparing audiogram' }
    :                           { key: 'visual_match', label: 'Matching stock video' }

  return [
    // Audio/video uploads transcribe before scripting — show that step.
    ...(isMedia ? [{ key: 'transcription', label: src === 'video_upload' ? 'Transcribing your video' : 'Transcribing your audio' }] : []),
    // A pasted script is reviewed, not written from scratch.
    { key: 'script',          label: src === 'script' ? 'Reviewing your script' : 'Writing script' },
    { key: 'scene_breakdown', label: 'Breaking into scenes' },
    { key: 'hooks',           label: 'Crafting hooks' },
    { key: 'hooks_scoring',   label: 'Scoring hooks' },
    visualStage,
    { key: 'tts',             label: 'Recording voice' },
    { key: 'preview_assembly',label: 'Wrapping up' },
  ]
}

function freshStages(project = null) {
  return stageDefinitions(project).map((stage) => ({
    ...stage,
    status: 'pending',
    statusText: 'Waiting',
    done: null,
    total: null,
  }))
}

const stages = ref(freshStages())

const progressPercent = computed(() => {
  const completeCount = stages.value.filter((s) => s.status === 'complete').length
  const activeBonus   = stages.value.some((s) => s.status === 'active') ? 0.5 : 0
  return Math.round(((completeCount + activeBonus) / stages.value.length) * 100)
})

function stageByKey(key) {
  return stages.value.find((s) => s.key === key)
}

function markStage(key, status, statusText = '', done = null, total = null) {
  const target = stageByKey(key)
  if (!target) return

  target.status = status
  target.statusText = statusText || (
    status === 'complete' ? 'Done'
    : status === 'active' ? 'In progress'
    : status === 'failed' ? 'Failed'
    : 'Waiting'
  )
  if (done  !== null) target.done  = done
  if (total !== null) target.total = total
}

function countLabel(stage) {
  if (stage.done === null || stage.total === null) return ''
  if (stage.status === 'complete' && stage.key === 'scene_breakdown')
    return `${stage.total} scene${stage.total !== 1 ? 's' : ''}`
  if (stage.done !== null && stage.total !== null)
    return `${stage.done} / ${stage.total}`
  return ''
}

function displayMessage(message) {
  if (!message) return ''
  if (String(message).includes('insufficient_quota'))
    return 'Voice generation could not run — OpenAI quota exhausted.'
  return message
}

function normalizeEventStatus(status) {
  if (status === 'processing') return 'active'
  if (status === 'completed')  return 'complete'
  if (status === 'failed')     return 'failed'
  return 'pending'
}

function previousStageKeys(key) {
  const index = stages.value.findIndex((s) => s.key === key)
  if (index <= 0) return []
  return stages.value.slice(0, index).map((s) => s.key)
}

function applyStoredGenerationState(project) {
  const storedStages = project?.generation_status_json?.stages ?? {}

  // Brief-mode stages are sequential (script -> scenes -> hooks -> ...).
  // One-shot stages are parallel (image + tts + music + animation all
  // fan out from storeOneShot). The "if a later stage is done, all
  // earlier ones must be done too" propagation is correct for the
  // first case and dangerously wrong for the second — TTS finishes in
  // ~8s while image/animation are still running, and propagating would
  // mark them complete in the UI, defeating the ready_for_review guard
  // and dumping the user into the editor mid-generation.
  const isOneShot = project?.source_type === 'prompt'

  for (const [key, stageState] of Object.entries(storedStages)) {
    const status = normalizeEventStatus(stageState?.status)
    markStage(
      key,
      status,
      displayMessage(stageState?.message),
      stageState?.done  ?? null,
      stageState?.total ?? null,
    )

    if (status !== 'pending' && !isOneShot) {
      previousStageKeys(key).forEach((k) => {
        if (stageByKey(k)?.status === 'pending') markStage(k, 'complete', 'Done')
      })
    }
  }
}

function applyPipelineState(project) {
  const nextKeys    = stageDefinitions(project).map((s) => s.key).join('|')
  const currentKeys = stages.value.map((s) => s.key).join('|')
  if (currentKeys !== nextKeys) stages.value = freshStages(project)

  applyStoredGenerationState(project)

  if (project?.status === 'ready_for_review') {
    // For one-shot projects: the project flips to ready_for_review when
    // TTS finishes (GenerateTTSJob:158/170), but image+music+animation
    // run in parallel and frequently outlast TTS. Auto-routing here
    // means landing in the editor mid-generation with "generating image"
    // and "cancel animation" still showing. Block the open until every
    // stage in the one-shot list is terminal (complete OR failed).
    // updateStageFromEvent's all-done check trips the transition once
    // the slowest tail (usually animation, ~70s) lands.
    if (project.source_type === 'prompt') {
      const anyStillRunning = stages.value.some(
        (s) => s.key !== 'preview_assembly'
          && (s.status === 'active' || s.status === 'pending'),
      )
      if (anyStillRunning) return
    }

    stages.value.forEach((s) => markStage(s.key, 'complete', 'Done'))
    maybeOpenEditor()
    return
  }

  if (project?.status === 'failed') {
    const active =
      stages.value.find((s) => s.status === 'active')
      ?? [...stages.value].reverse().find((s) => s.status === 'failed')
      ?? stages.value.find((s) => s.status === 'pending')
      ?? stages.value[0]
    markStage(active.key, 'failed', 'Failed')
    return
  }

  if (project?.status === 'generating' && stages.value.every((s) => s.status === 'pending')) {
    // First stage is what we light up. Brief-mode starts on 'script';
    // one-shot starts on 'ai_image' (image is the first thing to run).
    markStage(stages.value[0].key, 'active', 'Processing')
  }
}

function maybeOpenEditor() {
  if (isTransitioningToEditor.value) return
  isTransitioningToEditor.value = true
  window.setTimeout(() => {
    router.push({ name: 'project-editor', params: { projectId: projectId.value } })
  }, 1200)
}

function updateStageFromEvent(payload) {
  const stageMap = {
    transcription: 'transcription',
    script: 'script', scene_breakdown: 'scene_breakdown',
    hooks: 'hooks', hooks_scoring: 'hooks_scoring',
    visual_match: 'visual_match', ai_image: 'ai_image', tts: 'tts',
    animation: 'animation', ai_music: 'ai_music',
  }

  const key  = stageMap[payload.stage]
  if (!key) return
  // Ignore events for stages this project's pipeline doesn't include.
  // (e.g. brief-mode project getting a stray 'animation' event from a
  // user manually animating a scene mid-generation.)
  if (!stageByKey(key)) return

  const done  = payload.done  ?? null
  const total = payload.total ?? null

  if (payload.status === 'processing') {
    const countStr = done !== null && total !== null ? `${done} / ${total}` : ''
    const label    = stageByKey(key)?.label ?? 'Processing'
    markStage(key, 'active', countStr ? `${label}… ${countStr}` : `${label}…`, done, total)
    return
  }

  if (payload.status === 'completed') {
    const completedText =
      key === 'scene_breakdown' && total ? `${total} scene${total !== 1 ? 's' : ''}` : 'Done'
    markStage(key, 'complete', completedText, done, total)

    // Open the editor once every stage in this project's pipeline is
    // terminal. For brief-mode that's effectively when tts finishes
    // (no later stages run); for one-shot we wait on the slowest tail
    // (typically music or animation, both ~30-60s).
    const allDoneOrSkipped = stages.value.every(
      (s) => s.status === 'complete' || s.status === 'failed',
    )
    if (allDoneOrSkipped) {
      markStage('preview_assembly', 'complete', 'Done')
      maybeOpenEditor()
    } else if (key === 'tts' && !stageByKey('ai_music') && !stageByKey('animation')) {
      // Legacy brief-mode shortcut: tts is the last real stage, so
      // jump to editor without waiting on the preview_assembly fake.
      markStage('preview_assembly', 'complete', 'Done')
      maybeOpenEditor()
    }
    return
  }

  if (payload.status === 'failed') {
    markStage(key, 'failed', displayMessage(payload.message) || 'Failed')
    // For one-shot, music failure is non-fatal — if it fails, treat as
    // done so the wrap-up + editor transition still fire.
    if (key === 'ai_music') {
      const allDoneOrSkipped = stages.value.every(
        (s) => s.status === 'complete' || s.status === 'failed',
      )
      if (allDoneOrSkipped) {
        markStage('preview_assembly', 'complete', 'Done')
        maybeOpenEditor()
      }
    }
  }
}

async function loadProjectStatus() {
  try {
    const response = await api.get(`/projects/${projectId.value}`)
    const project  = response.data?.data?.project
    if (!project) return
    subtitle.value = `${project.title || `Project #${project.id}`} · ${project.primary_language?.toUpperCase?.() || 'EN'} · ${project.aspect_ratio || '9:16'}`
    isOneShot.value = project.source_type === 'prompt'
    const fetchedScenes = response.data?.data?.scenes
    if (Array.isArray(fetchedScenes)) {
      const sorted = [...fetchedScenes].sort((a, b) => (a.scene_order ?? 0) - (b.scene_order ?? 0))
      // Merge IN PLACE — every 4s poll used to replace the whole array with
      // fresh objects, re-mounting each <video>/<img> and making generated
      // scenes (animations especially) flicker + restart. Same-id scenes keep
      // their object identity so Vue only patches changed keys; an unchanged
      // video src is never touched.
      if (!scenes.value.length) {
        scenes.value = sorted
      } else {
        const byId = new Map(scenes.value.map((s) => [s.id, s]))
        const merged = sorted.map((fresh) => {
          const existing = byId.get(fresh.id)
          if (existing) { Object.assign(existing, fresh); return existing }
          return fresh
        })
        const sameOrder = merged.length === scenes.value.length
          && merged.every((s, i) => s.id === scenes.value[i].id)
        if (!sameOrder) scenes.value = merged
      }
    }
    applyPipelineState(project)
  } catch { /* no-op */ }
}

// A scene's visual can be a still image OR (once animation finishes) a video.
// Putting an mp4 in an <img> is the broken-image bug — render <video> for
// video assets instead, and only after the animation is actually done (the
// asset only flips to a video on completion).
function sceneVisualKind(s) {
  // Audiogram/waveform scenes have NO image asset — the bars are a render-time
  // effect. Treating them as 'none' showed a forever-spinner ("waiting for an
  // image") on stock/audiogram briefs. They're their own kind.
  if (s?.visual_type === 'waveform') return 'waveform'
  const a = s?.visual_asset
  if (!a) return 'none'
  if (a.asset_type === 'video' || (a.mime_type || '').startsWith('video') || /\.mp4(\?|$)/i.test(a.storage_url || '')) {
    return 'video'
  }
  return 'image'
}
function sceneVideoUrl(s) {
  return s?.visual_asset?.storage_url || null
}
function sceneImageUrl(s) {
  const a = s?.visual_asset
  if (!a) return null
  // For a video asset, thumbnail_url is the source still (when present).
  if (sceneVisualKind(s) === 'video') return a.thumbnail_url || null
  return a.thumbnail_url || a.storage_url || null
}
// Animation still cooking for this scene → show the still + an overlay,
// never the half-done/empty video.
function sceneAnimating(s) {
  return !!(s?.image_generation_settings?.animation_in_progress
    || s?.image_generation_settings_json?.animation_in_progress)
}

// Title adapts to the flow: prompt → one-shot, otherwise the brief pipeline.
const genTitle = computed(() => (isOneShot.value ? 'Generating your video…' : 'Building from your brief…'))

// Whether this generation actually includes an animation pass. Drive this
// off the real stage list (robust for BOTH flows) — NOT route.query, which
// is only set by the one-shot wizard. The brief flow has no animation stage,
// so its scenes must not wait on an animation that never runs.
const animatePlanned = computed(() => stages.value.some((s) => s.key === 'animation'))

function sceneAnimationDone(s) {
  return sceneVisualKind(s) === 'video'
    || !!(s?.image_generation_settings?.animation_video_asset_id
       || s?.image_generation_settings_json?.animation_video_asset_id)
}

// A scene is "READY" when its visual work is actually finished:
//  - audiogram: ready as soon as it's set up (no image to wait for)
//  - stock / AI image: ready once the visual asset is attached
//  - AI image with animation planned: also wait for the clip
function sceneReady(s) {
  const kind = sceneVisualKind(s)
  if (kind === 'waveform') return true
  if (kind === 'none') return false
  if (animatePlanned.value) return sceneAnimationDone(s)
  return true
}

// One conversational line tied to whatever stage is currently active, so the
// page reads like the assistant narrating its own work — for BOTH flows.
const narrationLine = computed(() => {
  if (isTransitioningToEditor.value) return 'All set — opening the editor…'
  const active = stages.value.find((s) => s.status === 'active')
  if (!active) {
    return stages.value.every((s) => s.status === 'complete')
      ? 'All set — opening the editor…'
      : 'Getting things ready…'
  }
  const n = scenes.value.length
  switch (active.key) {
    case 'transcription':    return 'Transcribing your upload…'
    case 'script':           return 'Writing the script…'
    case 'scene_breakdown':  return 'Splitting it into scenes…'
    case 'hooks':            return 'Drafting hook options…'
    case 'hooks_scoring':    return 'Ranking the hooks…'
    case 'visual_match':     return `${active.label}…`
    case 'ai_image':         return n ? `Painting the visuals for your ${n} scene${n === 1 ? '' : 's'}…` : 'Painting the visuals…'
    case 'animation':        return 'Bringing the scenes to life with motion…'
    case 'tts':              return 'Recording the voiceover…'
    case 'ai_music':         return 'Composing the soundtrack…'
    case 'preview_assembly': return 'Wrapping everything up…'
    default:                 return 'Working on your video…'
  }
})

function subscribe() {
  const echo = getEcho()
  if (!echo || !projectId.value) { connected.value = false; return }

  channelName = `project.${projectId.value}`
  echo.private(channelName).listen('.generation.progress', (payload) => {
    connected.value = true
    updateStageFromEvent(payload)
  })
}

function unsubscribe() {
  const echo = getEcho()
  if (echo && channelName) echo.leave(channelName)
}

function startPolling() { pollTimer = window.setInterval(loadProjectStatus, 4000) }
function stopPolling()  { if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null } }

onMounted(async () => { await loadProjectStatus(); subscribe(); startPolling() })
onBeforeUnmount(() => { unsubscribe(); stopPolling() })
</script>

<template>
  <main class="gen-overlay show">
    <div class="gen-panel">

      <div class="gen-header">
        <div class="gen-title">{{ genTitle }}</div>
        <div class="gen-subtitle">{{ subtitle }}</div>
      </div>

      <!-- Overall progress bar (inline % to the right) -->
      <div class="gen-progress-row">
        <div class="gen-progress"><div class="gen-progress-fill" :style="{ width: `${progressPercent}%` }"></div></div>
        <span class="gen-progress-label">{{ progressPercent }}%</span>
      </div>

      <!-- Stage timeline — connected dots -->
      <div class="gen-timeline">
        <div
          v-for="(stage, i) in stages"
          :key="stage.key"
          :class="['gen-tl-row', stage.status, { last: i === stages.length - 1 }]"
        >
          <span class="gen-tl-dot">
            <span v-if="stage.status === 'complete'" class="gen-tl-glyph">✓</span>
            <span v-else-if="stage.status === 'failed'" class="gen-tl-glyph">✕</span>
            <span v-else-if="stage.status === 'active'" class="gen-tl-pulse"></span>
          </span>
          <span class="gen-tl-name">
            {{ stage.label }}<span v-if="stage.status === 'active'" class="gen-tl-active"> · in progress</span>
          </span>
          <span v-if="countLabel(stage)" class="gen-tl-count">{{ countLabel(stage) }}</span>
        </div>
      </div>

      <!-- Assistant narration + scene reveal. Shows once scenes exist — for
           one-shot that's immediately; for the brief flow, after breakdown. -->
      <template v-if="scenes.length">
        <div v-if="narrationLine" class="gen-narration-line"><span class="gen-narration-bot">🤖</span> {{ narrationLine }}</div>
        <div class="gen-scene-cards">
          <div
            v-for="s in scenes"
            :key="s.id"
            :class="['gen-scene-card', { pending: sceneVisualKind(s) === 'none' }]"
          >
            <span class="gen-scene-thumb" :class="{ ready: sceneReady(s) }">
              <!-- Audiogram scenes have no image — show a waveform glyph, not a spinner. -->
              <span v-if="sceneVisualKind(s) === 'waveform'" class="gen-scene-wave">🎵</span>
              <video
                v-else-if="sceneVisualKind(s) === 'video' && !sceneAnimating(s)"
                :src="sceneVideoUrl(s)"
                muted loop autoplay playsinline preload="metadata"
              ></video>
              <img v-else-if="sceneImageUrl(s)" :src="sceneImageUrl(s)" :alt="`Scene ${s.scene_order}`" />
              <span v-else class="spin gen-scene-spin">⟳</span>
              <span v-if="sceneAnimating(s) && sceneImageUrl(s)" class="gen-scene-animating"><span class="spin">⟳</span></span>
            </span>
            <div class="gen-scene-body">
              <div class="gen-scene-label">Scene {{ s.scene_order }}<span v-if="sceneReady(s)" class="gen-scene-ready"> · READY</span></div>
              <div class="gen-scene-script">{{ s.script_text }}</div>
            </div>
          </div>
        </div>
      </template>

      <!-- Footer: reassurance + dashboard escape -->
      <div class="gen-foot">
        <span class="gen-foot-note">You can leave this page — generation continues in the background.</span>
        <button class="gen-foot-btn" type="button" @click="router.push({ name: 'dashboard' })">← Back to Dashboard</button>
      </div>
    </div>
  </main>
</template>

<style scoped>
.gen-overlay { position: fixed; inset: 0; background: rgba(10,10,15,0.97); z-index: 200; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
.gen-panel { width: 500px; max-width: calc(100vw - 32px); }
.gen-header { margin-bottom: 28px; }
.gen-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--color-text-primary); }
.gen-subtitle { color: var(--color-text-muted); font-size: 13px; }

.gen-progress-row { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
.gen-progress { flex: 1; height: 6px; border-radius: 999px; background: var(--color-bg-elevated); overflow: hidden; }
.gen-progress-fill { height: 100%; background: linear-gradient(90deg, #f97316, #fb923c); border-radius: 999px; transition: width 0.7s ease; }
.gen-progress-label { font-size: 13px; font-weight: 600; color: var(--color-accent); font-family: "Space Mono", monospace; }

.spin { display: inline-block; animation: spin 1.2s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Stage timeline — connected dots */
.gen-timeline { position: relative; margin-bottom: 30px; padding-left: 2px; }
.gen-tl-row { position: relative; display: flex; align-items: center; gap: 14px; padding: 9px 0; }
/* connector line from each dot down to the next */
.gen-tl-row:not(.last)::before {
  content: ""; position: absolute; left: 10px; top: 26px; bottom: -9px; width: 1.5px; background: var(--color-border);
}
.gen-tl-row.complete:not(.last)::before { background: rgba(34,197,94,0.35); }
.gen-tl-dot {
  position: relative; z-index: 1; flex-shrink: 0; width: 21px; height: 21px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--color-bg-panel, #0a0a0b); border: 1.5px solid var(--color-border);
}
.gen-tl-row.complete .gen-tl-dot { background: #22c55e; border-color: #22c55e; }
.gen-tl-row.active   .gen-tl-dot { border: 2px solid var(--color-accent); }
.gen-tl-row.failed   .gen-tl-dot { background: #f87171; border-color: #f87171; }
.gen-tl-glyph { font-size: 12px; color: #0a0a0b; font-weight: 700; }
.gen-tl-pulse { width: 7px; height: 7px; border-radius: 50%; background: var(--color-accent); animation: gen-pulse 1.4s ease-in-out infinite; }
@keyframes gen-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
.gen-tl-name { flex: 1; font-size: 14px; color: var(--color-text-muted); }
.gen-tl-row.active .gen-tl-name { color: var(--color-text-primary); font-weight: 600; }
.gen-tl-active { font-weight: 400; color: var(--color-accent); font-size: 13px; }
.gen-tl-count { font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-text-muted); }
.gen-tl-row.active .gen-tl-count   { color: var(--color-accent); }
.gen-tl-row.complete .gen-tl-count { color: #22c55e; }

/* Narration + scene reveal */
.gen-narration-line { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--color-text-secondary); margin-bottom: 14px; line-height: 1.5; }
.gen-narration-bot { flex-shrink: 0; }
.gen-scene-cards { display: flex; flex-direction: column; gap: 6px; max-height: 34vh; overflow-y: auto; padding-right: 4px; margin-bottom: 20px; }
.gen-scene-card { display: flex; align-items: center; gap: 12px; padding: 10px; border: 0.5px solid rgba(255,255,255,0.06); border-radius: 10px; background: var(--color-bg-card); transition: opacity 0.25s, border-color 0.25s; }
.gen-scene-card.pending { opacity: 0.55; }
.gen-scene-thumb { position: relative; width: 40px; height: 40px; flex-shrink: 0; border-radius: 7px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 14px; }
.gen-scene-thumb.ready { border: 0.5px solid rgba(34,197,94,0.25); }
.gen-scene-thumb img, .gen-scene-thumb video { width: 100%; height: 100%; object-fit: cover; }
.gen-scene-spin { font-size: 15px; }
.gen-scene-wave { font-size: 16px; }
.gen-scene-animating { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(10,10,15,0.45); color: #fff; font-size: 13px; }
.gen-scene-body { flex: 1; min-width: 0; }
.gen-scene-label { font-family: "Space Mono", monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 2px; }
.gen-scene-ready { color: #22c55e; }
.gen-scene-script { font-size: 13px; color: var(--color-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Footer */
.gen-foot { border-top: 0.5px solid rgba(255,255,255,0.08); padding-top: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.gen-foot-note { font-size: 12px; color: var(--color-text-muted); }
.gen-foot-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--color-text-secondary); background: transparent; border: 0.5px solid var(--color-border); padding: 8px 14px; border-radius: 8px; white-space: nowrap; flex-shrink: 0; cursor: pointer; }
.gen-foot-btn:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
</style>
