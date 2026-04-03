<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const authStore = useAuthStore()
const meState = ref('idle')
const meError = ref('')
const mePayload = ref(null)
const projectIdInput = ref('1')
const storageState = ref('idle')
const storageError = ref('')
const storagePayload = ref(null)
const showCreateModal = ref(false)
const createState = ref('idle')
const createError = ref('')
const sourceType = ref('prompt')
const sourceContent = ref('')
const languageSelections = ref(['en'])
const platformTarget = ref('tiktok')
const aspectRatio = ref('9:16')
const channelId = ref('')
const templateId = ref('')
const brandKitId = ref('')
const contentGoal = ref('')
const tone = ref('')
const title = ref('')
const durationTargetSeconds = ref('')

const sourceOptions = [
  { value: 'prompt', label: 'Prompt input' },
  { value: 'script', label: 'Script input' },
  { value: 'url', label: 'URL/article' },
  { value: 'product_description', label: 'Product description' },
  { value: 'csv_topic', label: 'CSV topic list' },
  { value: 'audio_upload', label: 'Existing audio' },
  { value: 'video_upload', label: 'Existing video' },
]

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

async function loadMe() {
  meState.value = 'loading'
  meError.value = ''

  try {
    const response = await api.get('/me')
    mePayload.value = response.data.data.user
    meState.value = 'success'
  } catch (error) {
    meState.value = 'error'
    meError.value = error.response?.data?.error?.message ?? 'Protected user lookup failed.'
  }
}

async function runStorageSmoke() {
  storageState.value = 'loading'
  storageError.value = ''

  try {
    const response = await api.post('/verification/storage-smoke')
    storagePayload.value = response.data.data
    storageState.value = 'success'
  } catch (error) {
    storageState.value = 'error'
    storageError.value = error.response?.data?.error?.message ?? 'B2 smoke test failed.'
  }
}

function openGenerationProgress() {
  const id = String(projectIdInput.value || '').trim()

  if (!id) {
    return
  }

  router.push({ name: 'generation-progress', params: { projectId: id } })
}

function toggleLanguage(language) {
  if (languageSelections.value.includes(language)) {
    languageSelections.value = languageSelections.value.filter((item) => item !== language)
    return
  }

  languageSelections.value = [...languageSelections.value, language]
}

function openCreateModal() {
  showCreateModal.value = true
  createState.value = 'idle'
  createError.value = ''
}

function closeCreateModal() {
  if (createState.value === 'loading') {
    return
  }

  showCreateModal.value = false
}

async function submitProject() {
  createState.value = 'loading'
  createError.value = ''

  try {
    const response = await api.post('/projects', {
      source_type: sourceType.value,
      source_content_raw: sourceContent.value,
      languages: languageSelections.value,
      platform_target: platformTarget.value,
      aspect_ratio: aspectRatio.value,
      ...(channelId.value ? { channel_id: Number(channelId.value) } : {}),
      ...(templateId.value ? { template_id: Number(templateId.value) } : {}),
      ...(brandKitId.value ? { brand_kit_id: Number(brandKitId.value) } : {}),
      ...(contentGoal.value ? { content_goal: contentGoal.value } : {}),
      ...(tone.value ? { tone: tone.value } : {}),
      ...(title.value ? { title: title.value } : {}),
      ...(durationTargetSeconds.value ? { duration_target_seconds: Number(durationTargetSeconds.value) } : {}),
    })

    const projectId = response.data?.data?.project?.id
    createState.value = 'success'
    showCreateModal.value = false

    if (projectId) {
      router.push({ name: 'generation-progress', params: { projectId } })
    }
  } catch (error) {
    createState.value = 'error'
    createError.value = error.response?.data?.error?.message ?? 'Project creation failed.'
  }
}

onMounted(() => {
  loadMe()
})
</script>

<template>
  <main class="min-h-screen bg-bg-deep px-6 py-10 text-text-primary">
    <div class="mx-auto max-w-6xl space-y-6">
      <header class="flex items-end justify-between gap-4 rounded-lg border border-border bg-bg-panel p-6">
        <div>
          <p class="font-mono text-sm uppercase tracking-[0.3em] text-accent">Framecast</p>
          <h1 class="mt-3 text-4xl font-semibold">Phase 1 generation shell</h1>
          <p class="mt-2 max-w-2xl text-sm text-text-secondary">
            Core generation backend is now wired: script, scene breakdown, hooks, visuals, and TTS pipeline with live Reverb progress events.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <button
            class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
            type="button"
            @click="openCreateModal"
          >
            New Video
          </button>
          <button
            class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
            type="button"
            @click="logout"
          >
            Log out
          </button>
        </div>
      </header>

      <section class="grid gap-4 md:grid-cols-3">
        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">API</p>
          <h2 class="mt-2 text-xl font-semibold">Laravel 11</h2>
          <p class="mt-2 text-sm text-text-muted">Versioned JSON routing, Horizon, Reverb, Redis, and B2 disk config are scaffolded.</p>
        </article>

        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">Web</p>
          <h2 class="mt-2 text-xl font-semibold">Vue 3 + Vite</h2>
          <p class="mt-2 text-sm text-text-muted">Pinia auth state, route guards, Axios interceptor, and Echo bootstrap are ready for real auth endpoints.</p>
        </article>

        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">Infra</p>
          <h2 class="mt-2 text-xl font-semibold">Compose stack</h2>
          <p class="mt-2 text-sm text-text-muted">API, worker, scheduler, reverb, horizon, postgres, redis, and web services are defined at the monorepo root.</p>
        </article>
      </section>

      <section class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-lg border border-border bg-bg-panel p-6">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-sm text-text-secondary">Generation Pipeline</p>
              <h2 class="mt-2 text-xl font-semibold">`project.{id}` Reverb stream</h2>
            </div>
            <button
              class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
              type="button"
              @click="openGenerationProgress"
            >
              Open
            </button>
          </div>

          <p class="mt-3 text-sm text-text-muted">
            Subscribe to `generation.progress` events emitted by each generation job stage on the private project channel.
          </p>

          <div class="mt-5 rounded-lg border border-border bg-bg-card p-4">
            <label class="text-xs uppercase tracking-[0.2em] text-text-muted">Project id</label>
            <input
              v-model="projectIdInput"
              class="mt-2 w-full rounded-md border border-border bg-bg-deep px-3 py-2 text-sm text-text-primary outline-none transition focus:border-border-active"
              inputmode="numeric"
              type="text"
            >
          </div>
        </article>

        <article class="rounded-lg border border-border bg-bg-panel p-6">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-sm text-text-secondary">Protected API</p>
              <h2 class="mt-2 text-xl font-semibold">`GET /api/v1/me`</h2>
            </div>
            <button
              class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
              type="button"
              @click="loadMe"
            >
              Re-run
            </button>
          </div>

          <p class="mt-3 text-sm text-text-muted">
            This request runs on dashboard load. If you corrupt the access token and reload, this call should trigger refresh recovery automatically.
          </p>

          <div class="mt-5 rounded-lg border border-border bg-bg-card p-4">
            <p class="text-xs uppercase tracking-[0.2em] text-text-muted">Status</p>
            <p class="mt-2 text-sm">
              <span v-if="meState === 'loading'">Loading protected user data…</span>
              <span v-else-if="meState === 'success'">PASS: authenticated request succeeded.</span>
              <span v-else-if="meState === 'error'" class="text-red-400">FAIL: {{ meError }}</span>
              <span v-else>Idle.</span>
            </p>

            <pre v-if="mePayload" class="mt-4 overflow-x-auto rounded-md bg-bg-deep p-4 text-xs text-text-secondary">{{ JSON.stringify(mePayload, null, 2) }}</pre>
          </div>
        </article>

        <article class="rounded-lg border border-border bg-bg-panel p-6">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-sm text-text-secondary">B2 Smoke Test</p>
              <h2 class="mt-2 text-xl font-semibold">`POST /api/v1/verification/storage-smoke`</h2>
            </div>
            <button
              class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
              type="button"
              @click="runStorageSmoke"
            >
              Run test
            </button>
          </div>

          <p class="mt-3 text-sm text-text-muted">
            Uploads a small text file to the `b2` disk and returns a temporary signed URL for direct verification.
          </p>

          <div class="mt-5 rounded-lg border border-border bg-bg-card p-4">
            <p class="text-xs uppercase tracking-[0.2em] text-text-muted">Status</p>
            <p class="mt-2 text-sm">
              <span v-if="storageState === 'loading'">Uploading smoke file to B2…</span>
              <span v-else-if="storageState === 'success'">PASS: upload and signed URL generation succeeded.</span>
              <span v-else-if="storageState === 'error'" class="text-red-400">FAIL: {{ storageError }}</span>
              <span v-else>Idle.</span>
            </p>

            <div v-if="storagePayload" class="mt-4 space-y-3 text-sm text-text-secondary">
              <p><span class="text-text-muted">Path:</span> {{ storagePayload.path }}</p>
              <a
                :href="storagePayload.temporary_url"
                class="text-accent underline-offset-4 hover:underline"
                rel="noreferrer"
                target="_blank"
              >
                Open signed URL
              </a>
              <pre class="overflow-x-auto rounded-md bg-bg-deep p-4 text-xs text-text-secondary">{{ JSON.stringify(storagePayload, null, 2) }}</pre>
            </div>
          </div>
        </article>
      </section>
    </div>

    <div
      v-if="showCreateModal"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 px-4 py-10"
      @click.self="closeCreateModal"
    >
      <div class="w-full max-w-3xl rounded-lg border border-border bg-bg-panel p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-xs uppercase tracking-[0.2em] text-text-muted">New Video</p>
            <h2 class="mt-2 text-2xl font-semibold">Create project</h2>
            <p class="mt-2 text-sm text-text-secondary">All 7 source types are available for Phase 1 creation.</p>
          </div>
          <button
            class="rounded-md border border-border px-3 py-1 text-sm text-text-secondary hover:border-border-active hover:text-text-primary"
            type="button"
            @click="closeCreateModal"
          >
            Close
          </button>
        </div>

        <div v-if="createError" class="mt-4 rounded-md border border-red-400/40 bg-red-500/10 p-3 text-sm text-red-300">
          {{ createError }}
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Source type</span>
            <select v-model="sourceType" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2">
              <option v-for="option in sourceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>

          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Platform target</span>
            <select v-model="platformTarget" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2">
              <option value="tiktok">TikTok</option>
              <option value="reels">Instagram Reels</option>
              <option value="shorts">YouTube Shorts</option>
            </select>
          </label>
        </div>

        <div class="mt-4">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Source content</span>
            <textarea
              v-model="sourceContent"
              class="h-32 w-full rounded-md border border-border bg-bg-deep px-3 py-2"
              :placeholder="`Provide content for ${sourceType}.`"
            />
          </label>
        </div>

        <div class="mt-3 rounded-md border border-border bg-bg-card p-3 text-xs text-text-muted">
          <p v-if="sourceType === 'prompt'">Prompt panel: describe the outcome and style you want.</p>
          <p v-else-if="sourceType === 'script'">Script panel: paste your full narration/script text.</p>
          <p v-else-if="sourceType === 'url'">URL panel: provide a valid article URL to transform.</p>
          <p v-else-if="sourceType === 'product_description'">Product Description panel: paste product details and value props.</p>
          <p v-else-if="sourceType === 'csv_topic'">CSV Topic panel: provide CSV rows of topics to expand.</p>
          <p v-else-if="sourceType === 'audio_upload'">Existing Audio panel: use upload path or storage URL as source content.</p>
          <p v-else-if="sourceType === 'video_upload'">Existing Video panel: use upload path or storage URL as source content.</p>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Aspect ratio</span>
            <select v-model="aspectRatio" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2">
              <option value="9:16">9:16</option>
              <option value="1:1">1:1</option>
              <option value="16:9">16:9</option>
            </select>
          </label>
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Duration target (seconds)</span>
            <input v-model="durationTargetSeconds" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" inputmode="numeric" type="text">
          </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-3">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Channel ID (optional)</span>
            <input v-model="channelId" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" inputmode="numeric" type="text">
          </label>
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Template ID (optional)</span>
            <input v-model="templateId" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" inputmode="numeric" type="text">
          </label>
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">BrandKit ID (optional)</span>
            <input v-model="brandKitId" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" inputmode="numeric" type="text">
          </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Content goal</span>
            <input v-model="contentGoal" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" type="text">
          </label>
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Tone</span>
            <input v-model="tone" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" type="text">
          </label>
        </div>

        <div class="mt-4">
          <label class="space-y-2 text-sm">
            <span class="text-text-muted">Title (optional)</span>
            <input v-model="title" class="w-full rounded-md border border-border bg-bg-deep px-3 py-2" type="text">
          </label>
        </div>

        <div class="mt-4">
          <p class="text-sm text-text-muted">Languages</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              :class="`rounded-md border px-3 py-1 text-xs uppercase tracking-[0.12em] ${languageSelections.includes('en') ? 'border-border-active text-text-primary' : 'border-border text-text-muted'}`"
              type="button"
              @click="toggleLanguage('en')"
            >
              English
            </button>
            <button
              :class="`rounded-md border px-3 py-1 text-xs uppercase tracking-[0.12em] ${languageSelections.includes('es') ? 'border-border-active text-text-primary' : 'border-border text-text-muted'}`"
              type="button"
              @click="toggleLanguage('es')"
            >
              Spanish
            </button>
            <button
              :class="`rounded-md border px-3 py-1 text-xs uppercase tracking-[0.12em] ${languageSelections.includes('fr') ? 'border-border-active text-text-primary' : 'border-border text-text-muted'}`"
              type="button"
              @click="toggleLanguage('fr')"
            >
              French
            </button>
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
          <button
            class="rounded-md border border-border px-4 py-2 text-sm text-text-secondary transition hover:border-border-active hover:text-text-primary"
            type="button"
            @click="closeCreateModal"
          >
            Cancel
          </button>
          <button
            class="rounded-md border border-border-active bg-accent/10 px-4 py-2 text-sm text-accent transition hover:bg-accent/20"
            :disabled="createState === 'loading'"
            type="button"
            @click="submitProject"
          >
            {{ createState === 'loading' ? 'Creating…' : 'Create & Start Generation' }}
          </button>
        </div>
      </div>
    </div>
  </main>
</template>
