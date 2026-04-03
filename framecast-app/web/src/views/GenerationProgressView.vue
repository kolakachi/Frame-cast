<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getEcho } from '../services/echo'

const route = useRoute()
const router = useRouter()
const projectId = computed(() => route.params.projectId)
const connected = ref(false)
const lastMessage = ref('Waiting for generation events…')

const stages = ref([
  { key: 'script', label: 'Generate Script', status: 'pending' },
  { key: 'scene_breakdown', label: 'Breakdown Scenes', status: 'pending' },
  { key: 'hooks', label: 'Generate Hooks', status: 'pending' },
  { key: 'visual_match', label: 'Match Visuals', status: 'pending' },
  { key: 'tts', label: 'Generate TTS', status: 'pending' },
])

let channelName = null

function statusClass(status) {
  if (status === 'completed') return 'border-emerald-400/60 bg-emerald-500/10 text-emerald-300'
  if (status === 'processing') return 'border-amber-400/60 bg-amber-500/10 text-amber-200'
  if (status === 'failed') return 'border-red-400/60 bg-red-500/10 text-red-300'
  return 'border-border bg-bg-card text-text-muted'
}

function updateStage(payload) {
  const target = stages.value.find((stage) => stage.key === payload.stage)

  if (!target) {
    return
  }

  target.status = payload.status
  lastMessage.value = payload.message || `${target.label}: ${payload.status}`
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
    updateStage(payload)
  })
}

function unsubscribe() {
  const echo = getEcho()

  if (echo && channelName) {
    echo.leave(channelName)
  }
}

onMounted(() => {
  subscribe()
})

onBeforeUnmount(() => {
  unsubscribe()
})
</script>

<template>
  <main class="min-h-screen bg-bg-deep px-6 py-10 text-text-primary">
    <div class="mx-auto max-w-4xl space-y-6">
      <header class="rounded-lg border border-border bg-bg-panel p-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="font-mono text-xs uppercase tracking-[0.25em] text-accent">Generation Progress</p>
            <h1 class="mt-2 text-3xl font-semibold">Project #{{ projectId }}</h1>
            <p class="mt-2 text-sm text-text-secondary">
              Live pipeline status from Reverb `generation.progress` events.
            </p>
          </div>
          <button
            class="rounded-md border border-border px-4 py-2 text-sm text-text-secondary transition hover:border-border-active hover:text-text-primary"
            type="button"
            @click="router.push({ name: 'dashboard' })"
          >
            Back
          </button>
        </div>
      </header>

      <section class="rounded-lg border border-border bg-bg-panel p-6">
        <p class="text-sm text-text-muted">Socket status: <span :class="connected ? 'text-emerald-300' : 'text-amber-200'">{{ connected ? 'Connected' : 'Waiting for events' }}</span></p>
        <p class="mt-2 text-sm text-text-secondary">{{ lastMessage }}</p>
      </section>

      <section class="grid gap-3">
        <article
          v-for="stage in stages"
          :key="stage.key"
          :class="`rounded-lg border p-4 transition ${statusClass(stage.status)}`"
        >
          <div class="flex items-center justify-between">
            <h2 class="text-base font-medium">{{ stage.label }}</h2>
            <span class="text-xs uppercase tracking-[0.2em]">{{ stage.status }}</span>
          </div>
        </article>
      </section>
    </div>
  </main>
</template>
