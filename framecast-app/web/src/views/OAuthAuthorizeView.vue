<script setup>
// OAuth consent for MCP connectors (ChatGPT, Claude). A connector sends the
// user here with client_id, redirect_uri, state and a PKCE challenge; the
// API validates all of it and says what approval would grant. Approving
// creates a hidden, 90-day API key for one workspace and sends the browser
// back to the connector with a code. Nothing here holds a secret.
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const authStore = useAuthStore()

const state = ref('loading') // loading | ready | working | error
const errorMessage = ref('')
const context = ref(null)
const workspaceId = ref(null)

const params = computed(() => {
  const out = {}
  for (const k of ['response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method']) {
    if (route.query[k] !== undefined) out[k] = String(route.query[k])
  }
  return out
})

const workspaces = computed(() => context.value?.workspaces ?? [])
const selected = computed(() => workspaces.value.find((w) => w.id === workspaceId.value) ?? null)

onMounted(async () => {
  try {
    const { data } = await api.post('/oauth/authorize/context', params.value)
    context.value = data.data
    const current = workspaces.value.find((w) => w.id === authStore.user?.workspace_id)
    workspaceId.value = (current ?? workspaces.value[0])?.id ?? null
    state.value = 'ready'
  } catch (err) {
    state.value = 'error'
    errorMessage.value = err.response?.data?.error?.message ?? 'This connection request is invalid. Start again from the app you are connecting.'
  }
})

async function decide(decision) {
  state.value = 'working'
  errorMessage.value = ''
  try {
    const body = { ...params.value, decision }
    if (decision === 'approve') body.workspace_id = workspaceId.value
    const { data } = await api.post('/oauth/authorize/decide', body)
    // Back to the connector. A full navigation on purpose: the redirect is
    // theirs, not a route of ours.
    window.location.assign(data.data.redirect_url)
  } catch (err) {
    state.value = 'ready'
    errorMessage.value = err.response?.data?.error?.message ?? 'Something went wrong. Try again.'
  }
}
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card auth-card-centered">
      <template v-if="state === 'loading'">
        <div class="auth-magic-icon auth-magic-icon-pulse">✦</div>
        <h1 class="auth-title centered">One moment…</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">Checking the connection request.</p>
      </template>

      <template v-else-if="state === 'error' && !context">
        <h1 class="auth-title centered">Can't connect</h1>
        <p class="auth-subtitle centered">{{ errorMessage }}</p>
      </template>

      <template v-else>
        <h1 class="auth-title centered">Connect {{ context.client.name }} to WyvStudio</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">
          {{ context.client.name }} is asking to make videos in your workspace.
        </p>

        <ul class="oauth-permissions">
          <li v-for="p in context.permissions" :key="p">{{ p }}</li>
        </ul>

        <template v-if="workspaces.length === 0">
          <div class="auth-error">
            None of your workspaces can be connected. API access needs the Creator plan or above, and you must be an owner or admin.
          </div>
        </template>
        <template v-else>
          <label v-if="workspaces.length > 1" class="oauth-workspace">
            <span>Workspace</span>
            <select v-model="workspaceId">
              <option v-for="w in workspaces" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </label>
          <p v-else class="oauth-workspace-single">Workspace: <strong>{{ selected?.name }}</strong></p>

          <p class="oauth-fineprint">
            This creates a private API key for {{ context.client.name }} that expires in {{ context.key_lifetime_days }} days.
            You can revoke it any time from Settings → API keys.
          </p>
        </template>

        <div v-if="errorMessage" class="auth-error">{{ errorMessage }}</div>

        <div class="oauth-actions">
          <button type="button" class="auth-btn-secondary" :disabled="state === 'working'" @click="decide('deny')">Cancel</button>
          <button type="button" class="auth-btn-primary" :disabled="state === 'working' || !selected" @click="decide('approve')">
            {{ state === 'working' ? 'Connecting…' : 'Allow' }}
          </button>
        </div>
      </template>
    </div>
  </main>
</template>

<style scoped>
.oauth-permissions { margin: 1.25rem 0; padding-left: 1.25rem; text-align: left; line-height: 1.7; }
.oauth-workspace { display: flex; flex-direction: column; gap: 0.35rem; text-align: left; margin-bottom: 1rem; }
.oauth-workspace select { padding: 0.6rem; border-radius: 8px; border: 1px solid rgba(0,0,0,0.15); font: inherit; }
.oauth-workspace-single { text-align: left; margin-bottom: 1rem; }
.oauth-fineprint { font-size: 0.85rem; opacity: 0.75; text-align: left; margin-bottom: 1rem; }
.oauth-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
</style>
