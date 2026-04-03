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
const storageState = ref('idle')
const storageError = ref('')
const storagePayload = ref(null)

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
          <h1 class="mt-3 text-4xl font-semibold">Phase 0 workspace shell</h1>
          <p class="mt-2 max-w-2xl text-sm text-text-secondary">
            Router, auth store, Axios refresh flow, and Reverb client wiring are in place. Domain flows come next.
          </p>
        </div>
        <button
          class="rounded-md border border-border px-4 py-2 text-sm font-medium text-text-secondary transition hover:border-border-active hover:text-text-primary"
          type="button"
          @click="logout"
        >
          Log out
        </button>
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
  </main>
</template>
