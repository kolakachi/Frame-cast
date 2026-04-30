<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { getEcho } from '../services/echo'

const route = useRoute()
const router = useRouter()
const projectId = computed(() => route.params.projectId)
const connected = ref(false)
const isTransitioningToEditor = ref(false)
const subtitle = ref(`Project #${projectId.value}`)
let channelName = null
let pollTimer = null

const baseStages = [
  { key: 'script', label: 'Writing script' },
  { key: 'scene_breakdown', label: 'Breaking into scenes' },
  { key: 'hooks', label: 'Creating hooks' },
  { key: 'hooks_scoring', label: 'Scoring hooks' },
  { key: 'tts', label: 'Generating voice' },
  { key: 'preview_assembly', label: 'Assembling preview' },
]

function stageDefinitions(project = null) {
  const visualStage = project?.visual_generation_mode === 'ai_images'
    ? { key: 'ai_image', label: 'Generating AI visuals' }
    : project?.visual_generation_mode === 'stock_images'
      ? { key: 'visual_match', label: 'Matching stock images' }
      : project?.visual_generation_mode === 'waveform'
        ? { key: 'visual_match', label: 'Preparing audiogram' }
        : { key: 'visual_match', label: 'Matching stock video' }

  return [
    ...baseStages.slice(0, 4),
    visualStage,
    ...baseStages.slice(4),
  ]
}

function freshStages(project = null) {
  return stageDefinitions(project).map((stage) => ({
    ...stage,
    status: 'pending',
    statusText: 'Waiting',
  }))
}

const stages = ref(freshStages())

const progressPercent = computed(() => {
  const completeCount = stages.value.filter((stage) => stage.status === 'complete').length
  const activeBonus = stages.value.some((stage) => stage.status === 'active') ? 0.5 : 0
  return Math.round(((completeCount + activeBonus) / stages.value.length) * 100)
})

function stageByKey(key) {
  return stages.value.find((stage) => stage.key === key)
}

function markStage(key, status, statusText = '') {
  const target = stageByKey(key)
  if (!target) return

  target.status = status
  target.statusText = statusText || (
    status === 'complete' ? 'Done' : status === 'active' ? 'In progress' : status === 'failed' ? 'Failed' : 'Waiting'
  )
}

function displayMessage(message) {
  if (!message) return ''

  if (String(message).includes('insufficient_quota')) {
    return 'Voice generation could not run because the OpenAI quota is exhausted.'
  }

  return message
}

function normalizeEventStatus(status) {
  if (status === 'processing') return 'active'
  if (status === 'completed') return 'complete'
  if (status === 'failed') return 'failed'
  return 'pending'
}

function previousStageKeys(key) {
  const index = stages.value.findIndex((stage) => stage.key === key)
  if (index <= 0) return []
  return stages.value.slice(0, index).map((stage) => stage.key)
}

function applyStoredGenerationState(project) {
  const generationStatus = project?.generation_status_json || {}
  const storedStages = generationStatus.stages || {}

  for (const [key, stageState] of Object.entries(storedStages)) {
    const normalizedStatus = normalizeEventStatus(stageState?.status)
    markStage(key, normalizedStatus, displayMessage(stageState?.message))

    if (normalizedStatus !== 'pending') {
      previousStageKeys(key).forEach((previousKey) => {
        const previous = stageByKey(previousKey)
        if (previous?.status === 'pending') {
          markStage(previousKey, 'complete', 'Done')
        }
      })
    }
  }
}

function applyPipelineState(project) {
  const projectStatus = project?.status
  const nextDefinitions = stageDefinitions(project)
  const currentKeys = stages.value.map((stage) => stage.key).join('|')
  const nextKeys = nextDefinitions.map((stage) => stage.key).join('|')

  if (currentKeys !== nextKeys) {
    stages.value = freshStages(project)
  }

  applyStoredGenerationState(project)

  if (projectStatus === 'ready_for_review') {
    stages.value.forEach((stage) => markStage(stage.key, 'complete', 'Done'))
    maybeOpenEditor()
    return
  }

  if (projectStatus === 'failed') {
    const active = stages.value.find((stage) => stage.status === 'active')
      ?? [...stages.value].reverse().find((stage) => stage.status === 'failed')
      ?? stages.value.find((stage) => stage.status === 'pending')
      ?? stages.value[0]
    markStage(active.key, 'failed', 'Failed')
    return
  }

  if (projectStatus === 'generating' && stages.value.every((stage) => stage.status === 'pending')) {
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
    script: 'script',
    scene_breakdown: 'scene_breakdown',
    hooks: 'hooks',
    hooks_scoring: 'hooks_scoring',
    visual_match: 'visual_match',
    ai_image: 'ai_image',
    tts: 'tts',
  }

  const mappedKey = stageMap[payload.stage]
  if (!mappedKey) return

  if (payload.status === 'processing') {
    markStage(mappedKey, 'active', payload.message || 'Processing')
    return
  }

  if (payload.status === 'completed') {
    markStage(mappedKey, 'complete', payload.message || 'Done')

    if (mappedKey === 'tts') {
      markStage('preview_assembly', 'complete', 'Done')
      maybeOpenEditor()
    }

    return
  }

  if (payload.status === 'failed') {
    markStage(mappedKey, 'failed', displayMessage(payload.message) || 'Failed')
  }
}

async function loadProjectStatus() {
  try {
    const response = await api.get(`/projects/${projectId.value}`)
    const project = response.data?.data?.project

    if (!project) return

    subtitle.value = `${project.title || `Project #${project.id}`} · ${project.primary_language?.toUpperCase?.() || 'EN'} · ${project.aspect_ratio || '9:16'}`
    applyPipelineState(project)
  } catch {
    // no-op
  }
}

function subscribe() {
  const echo = getEcho()

  if (!echo || !projectId.value) {
    connected.value = false
    return
  }

  channelName = `project.${projectId.value}`
  echo.private(channelName).listen('.generation.progress', (payload) => {
    connected.value = true
    updateStageFromEvent(payload)
  })
}

function unsubscribe() {
  const echo = getEcho()

  if (echo && channelName) {
    echo.leave(channelName)
  }
}

function startPolling() {
  pollTimer = window.setInterval(() => {
    loadProjectStatus()
  }, 3000)
}

function stopPolling() {
  if (!pollTimer) return
  window.clearInterval(pollTimer)
  pollTimer = null
}

onMounted(async () => {
  await loadProjectStatus()
  subscribe()
  startPolling()
})

onBeforeUnmount(() => {
  unsubscribe()
  stopPolling()
})
</script>

<template>
  <main class="gen-overlay show">
    <div class="gen-panel">
      <div class="gen-header">
        <div class="gen-title">Generating your video…</div>
        <div class="gen-subtitle">{{ subtitle }}</div>
        <div class="gen-connection">
          Socket: <span :class="connected ? 'ok' : 'waiting'">{{ connected ? 'Connected' : 'Waiting for events' }}</span>
        </div>
      </div>

      <div class="gen-progress">
        <div class="gen-progress-fill" :style="{ width: `${progressPercent}%` }"></div>
      </div>

      <div class="gen-stages">
        <div
          v-for="stage in stages"
          :key="stage.key"
          :class="`gen-stage ${stage.status}`"
        >
          <div class="gen-stage-icon">
            {{ stage.status === 'complete' ? '✓' : stage.status === 'active' ? '⟳' : stage.status === 'failed' ? '✕' : '○' }}
          </div>
          <div class="gen-stage-info">
            <div class="gen-stage-name">{{ stage.label }}</div>
            <div class="gen-stage-status">{{ stage.statusText }}</div>
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
.gen-header { margin-bottom: 32px; }
.gen-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--color-text-primary); }
.gen-subtitle { color: var(--color-text-muted); font-size: 14px; }
.gen-connection { margin-top: 8px; font-size: 12px; color: var(--color-text-muted); }
.gen-connection .ok { color: #34d399; }
.gen-connection .waiting { color: #fbbf24; }
.gen-progress { height: 4px; border-radius: 999px; background: var(--color-bg-elevated); margin-bottom: 32px; overflow: hidden; }
.gen-progress-fill { height: 100%; background: var(--color-accent); border-radius: 999px; transition: width 0.7s ease; }
.gen-stages { display: grid; gap: 10px; margin-bottom: 32px; }
.gen-stage { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-card); transition: border-color 0.2s, background 0.2s; }
.gen-stage.active { border-color: rgba(255,107,53,0.35); background: rgba(255,107,53,0.05); }
.gen-stage.complete { border-color: rgba(52,211,153,0.25); }
.gen-stage.failed { border-color: rgba(248,113,113,0.3); }
.gen-stage-icon { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.gen-stage.pending .gen-stage-icon { background: var(--color-bg-elevated); color: var(--color-text-muted); }
.gen-stage.active .gen-stage-icon { background: rgba(255,107,53,0.14); color: var(--color-accent); }
.gen-stage.complete .gen-stage-icon { background: rgba(52,211,153,0.12); color: #34d399; }
.gen-stage.failed .gen-stage-icon { background: rgba(248,113,113,0.12); color: #f87171; }
.gen-stage-info { flex: 1; }
.gen-stage-name { font-size: 14px; font-weight: 500; color: var(--color-text-primary); }
.gen-stage-status { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }
.gen-stage.active .gen-stage-status { color: var(--color-accent); }
.gen-stage.complete .gen-stage-status { color: #34d399; }
.gen-stage.failed .gen-stage-status { color: #f87171; }
.gen-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-ghost:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
</style>
