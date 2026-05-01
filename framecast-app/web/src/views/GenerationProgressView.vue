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
let channelName  = null
let pollTimer    = null

const baseStages = [
  { key: 'script',          label: 'Writing script' },
  { key: 'scene_breakdown', label: 'Breaking into scenes' },
  { key: 'hooks',           label: 'Crafting hooks' },
  { key: 'hooks_scoring',   label: 'Scoring hooks' },
  { key: 'tts',             label: 'Recording voice' },
  { key: 'preview_assembly',label: 'Wrapping up' },
]

function stageDefinitions(project = null) {
  const mode = project?.visual_generation_mode
  const visualStage =
    mode === 'ai_images'    ? { key: 'ai_image',     label: 'Generating AI visuals' }
    : mode === 'stock_images' ? { key: 'visual_match', label: 'Matching stock images' }
    : mode === 'waveform'     ? { key: 'visual_match', label: 'Preparing audiogram' }
    :                           { key: 'visual_match', label: 'Matching stock video' }

  return [...baseStages.slice(0, 4), visualStage, ...baseStages.slice(4)]
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

  for (const [key, stageState] of Object.entries(storedStages)) {
    const status = normalizeEventStatus(stageState?.status)
    markStage(
      key,
      status,
      displayMessage(stageState?.message),
      stageState?.done  ?? null,
      stageState?.total ?? null,
    )

    if (status !== 'pending') {
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
    markStage('script', 'active', 'Processing')
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
    script: 'script', scene_breakdown: 'scene_breakdown',
    hooks: 'hooks', hooks_scoring: 'hooks_scoring',
    visual_match: 'visual_match', ai_image: 'ai_image', tts: 'tts',
  }

  const key  = stageMap[payload.stage]
  if (!key) return

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

    if (key === 'tts') {
      markStage('preview_assembly', 'complete', 'Done')
      maybeOpenEditor()
    }
    return
  }

  if (payload.status === 'failed') {
    markStage(key, 'failed', displayMessage(payload.message) || 'Failed')
  }
}

async function loadProjectStatus() {
  try {
    const response = await api.get(`/projects/${projectId.value}`)
    const project  = response.data?.data?.project
    if (!project) return
    subtitle.value = `${project.title || `Project #${project.id}`} · ${project.primary_language?.toUpperCase?.() || 'EN'} · ${project.aspect_ratio || '9:16'}`
    applyPipelineState(project)
  } catch { /* no-op */ }
}

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
        <div class="gen-title">Generating your video…</div>
        <div class="gen-subtitle">{{ subtitle }}</div>
      </div>

      <!-- Overall progress bar -->
      <div class="gen-progress">
        <div class="gen-progress-fill" :style="{ width: `${progressPercent}%` }"></div>
      </div>
      <div class="gen-progress-label">{{ progressPercent }}%</div>

      <!-- Stages -->
      <div class="gen-stages">
        <div
          v-for="stage in stages"
          :key="stage.key"
          :class="['gen-stage', stage.status]"
        >
          <div class="gen-stage-icon">
            <span v-if="stage.status === 'complete'">✓</span>
            <span v-else-if="stage.status === 'failed'">✕</span>
            <span v-else-if="stage.status === 'active'" class="spin">⟳</span>
            <span v-else>○</span>
          </div>

          <div class="gen-stage-info">
            <div class="gen-stage-top">
              <span class="gen-stage-name">{{ stage.label }}</span>
              <span v-if="countLabel(stage)" class="gen-stage-count">{{ countLabel(stage) }}</span>
            </div>
            <div class="gen-stage-status">{{ stage.statusText }}</div>

            <!-- Per-scene mini progress bar for counting stages -->
            <div
              v-if="stage.status === 'active' && stage.total && stage.done !== null"
              class="gen-mini-bar"
            >
              <div
                class="gen-mini-bar-fill"
                :style="{ width: `${Math.round((stage.done / stage.total) * 100)}%` }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <div class="gen-actions">
        <button class="btn btn-ghost" type="button" @click="router.push({ name: 'dashboard' })">Back to Dashboard</button>
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

.gen-progress { height: 4px; border-radius: 999px; background: var(--color-bg-elevated); overflow: hidden; }
.gen-progress-fill { height: 100%; background: var(--color-accent); border-radius: 999px; transition: width 0.7s ease; }
.gen-progress-label { font-size: 11px; color: var(--color-text-muted); text-align: right; margin-top: 4px; margin-bottom: 24px; }

.gen-stages { display: grid; gap: 8px; margin-bottom: 32px; }
.gen-stage { display: flex; align-items: flex-start; gap: 14px; padding: 14px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-card); transition: border-color 0.25s, background 0.25s; }
.gen-stage.active   { border-color: rgba(255,107,53,0.4); background: rgba(255,107,53,0.05); }
.gen-stage.complete { border-color: rgba(52,211,153,0.25); background: rgba(52,211,153,0.03); }
.gen-stage.failed   { border-color: rgba(248,113,113,0.3); }

.gen-stage-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; margin-top: 1px; }
.gen-stage.pending  .gen-stage-icon { background: var(--color-bg-elevated); color: var(--color-text-muted); }
.gen-stage.active   .gen-stage-icon { background: rgba(255,107,53,0.14); color: var(--color-accent); }
.gen-stage.complete .gen-stage-icon { background: rgba(52,211,153,0.12); color: #34d399; }
.gen-stage.failed   .gen-stage-icon { background: rgba(248,113,113,0.12); color: #f87171; }

.gen-stage-info { flex: 1; min-width: 0; }
.gen-stage-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.gen-stage-name { font-size: 13px; font-weight: 500; color: var(--color-text-primary); }
.gen-stage-count { font-size: 12px; font-weight: 600; color: var(--color-accent); background: rgba(255,107,53,0.1); padding: 1px 7px; border-radius: 999px; white-space: nowrap; }
.gen-stage.complete .gen-stage-count { color: #34d399; background: rgba(52,211,153,0.1); }
.gen-stage-status { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.gen-stage.active   .gen-stage-status { color: rgba(255,107,53,0.7); }
.gen-stage.complete .gen-stage-status { color: rgba(52,211,153,0.7); }
.gen-stage.failed   .gen-stage-status { color: #f87171; }

.gen-mini-bar { height: 3px; border-radius: 999px; background: rgba(255,107,53,0.15); margin-top: 7px; overflow: hidden; }
.gen-mini-bar-fill { height: 100%; background: var(--color-accent); border-radius: 999px; transition: width 0.5s ease; }

.spin { display: inline-block; animation: spin 1.2s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.gen-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-ghost:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
</style>
