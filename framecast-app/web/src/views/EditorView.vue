<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()
const projectId = computed(() => route.params.projectId)
const loading = ref(true)
const error = ref('')
const project = ref(null)
const scenes = ref([])
const hookOptions = ref([])
const activeSceneId = ref(null)

const activeScene = computed(() => scenes.value.find((scene) => scene.id === activeSceneId.value) ?? null)

async function loadProject() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get(`/projects/${projectId.value}`)
    project.value = response.data?.data?.project ?? null
    scenes.value = response.data?.data?.scenes ?? []
    hookOptions.value = response.data?.data?.hook_options ?? []
    activeSceneId.value = scenes.value[0]?.id ?? null
  } catch (requestError) {
    error.value = requestError.response?.data?.error?.message ?? 'Project load failed.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadProject()
})
</script>

<template>
  <main class="min-h-screen bg-bg-deep px-6 py-10 text-text-primary">
    <div class="mx-auto max-w-7xl space-y-6">
      <header class="rounded-lg border border-border bg-bg-panel p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="font-mono text-xs uppercase tracking-[0.25em] text-accent">Editor</p>
            <h1 class="mt-2 text-3xl font-semibold">{{ project?.title || `Project #${projectId}` }}</h1>
            <p class="mt-2 text-sm text-text-secondary">
              Review generated scenes, visuals, and voice output.
            </p>
          </div>
          <button
            class="rounded-md border border-border px-4 py-2 text-sm text-text-secondary transition hover:border-border-active hover:text-text-primary"
            type="button"
            @click="router.push({ name: 'dashboard' })"
          >
            Back to Dashboard
          </button>
        </div>
      </header>

      <section v-if="loading" class="rounded-lg border border-border bg-bg-panel p-6 text-sm text-text-secondary">
        Loading project…
      </section>

      <section v-else-if="error" class="rounded-lg border border-red-400/40 bg-red-500/10 p-6 text-sm text-red-300">
        {{ error }}
      </section>

      <section v-else class="grid gap-4 lg:grid-cols-[320px,1fr]">
        <aside class="rounded-lg border border-border bg-bg-panel p-4">
          <div class="flex items-center justify-between">
            <h2 class="text-sm uppercase tracking-[0.2em] text-text-muted">Scenes</h2>
            <span class="text-xs text-text-secondary">{{ scenes.length }}</span>
          </div>

          <div class="mt-4 space-y-2">
            <button
              v-for="scene in scenes"
              :key="scene.id"
              :class="`w-full rounded-md border p-3 text-left transition ${activeSceneId === scene.id ? 'border-border-active bg-bg-card' : 'border-border bg-bg-deep hover:border-border-active/70'}`"
              type="button"
              @click="activeSceneId = scene.id"
            >
              <p class="text-xs uppercase tracking-[0.12em] text-text-muted">Scene {{ scene.scene_order }}</p>
              <p class="mt-1 text-sm font-medium text-text-primary">{{ scene.label || `Scene ${scene.scene_order}` }}</p>
              <p class="mt-1 line-clamp-2 text-xs text-text-secondary">{{ scene.script_text }}</p>
            </button>
          </div>
        </aside>

        <div class="space-y-4">
          <article class="rounded-lg border border-border bg-bg-panel p-5">
            <h2 class="text-sm uppercase tracking-[0.2em] text-text-muted">Project</h2>
            <div class="mt-3 grid gap-2 text-sm text-text-secondary md:grid-cols-3">
              <p><span class="text-text-muted">Status:</span> {{ project?.status || 'unknown' }}</p>
              <p><span class="text-text-muted">Aspect:</span> {{ project?.aspect_ratio || '—' }}</p>
              <p><span class="text-text-muted">Language:</span> {{ project?.primary_language || '—' }}</p>
            </div>
            <div v-if="hookOptions.length" class="mt-4 rounded-md border border-border bg-bg-card p-3">
              <p class="text-xs uppercase tracking-[0.2em] text-text-muted">Hook Options</p>
              <ul class="mt-2 space-y-1 text-sm text-text-secondary">
                <li v-for="option in hookOptions" :key="option.id">{{ option.sort_order }}. {{ option.hook_text }}</li>
              </ul>
            </div>
          </article>

          <article v-if="activeScene" class="rounded-lg border border-border bg-bg-panel p-5">
            <h2 class="text-sm uppercase tracking-[0.2em] text-text-muted">Active Scene</h2>
            <p class="mt-2 text-lg font-semibold">{{ activeScene.label || `Scene ${activeScene.scene_order}` }}</p>
            <p class="mt-3 text-sm text-text-secondary">{{ activeScene.script_text }}</p>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
              <div class="rounded-md border border-border bg-bg-card p-3 text-xs text-text-secondary">
                <p class="uppercase tracking-[0.18em] text-text-muted">Visual</p>
                <p class="mt-2">{{ activeScene.visual_asset?.title || 'No visual asset assigned' }}</p>
                <a
                  v-if="activeScene.visual_asset?.storage_url"
                  :href="activeScene.visual_asset.storage_url"
                  class="mt-2 inline-block text-accent underline-offset-4 hover:underline"
                  rel="noreferrer"
                  target="_blank"
                >
                  Open visual URL
                </a>
              </div>

              <div class="rounded-md border border-border bg-bg-card p-3 text-xs text-text-secondary">
                <p class="uppercase tracking-[0.18em] text-text-muted">Audio</p>
                <p class="mt-2">{{ activeScene.audio_asset?.title || 'No audio asset assigned' }}</p>
                <audio
                  v-if="activeScene.audio_asset?.storage_url"
                  :src="activeScene.audio_asset.storage_url"
                  class="mt-3 w-full"
                  controls
                  preload="none"
                />
              </div>
            </div>
          </article>
        </div>
      </section>
    </div>
  </main>
</template>
