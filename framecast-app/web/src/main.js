import { watch } from 'vue'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import * as Sentry from '@sentry/vue'
import App from './App.vue'
import router from './router'
import { configureApiClient, setApiAccessToken } from './services/api'
import { disconnectEcho, initEcho } from './services/echo'
import { useAuthStore } from './stores/auth'
import './style.css'

const app = createApp(App)
const sentryEnvironment = import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE
const sentryAllowLocal = import.meta.env.VITE_SENTRY_ENABLE_LOCAL === 'true'
const sentryEnabled =
  Boolean(import.meta.env.VITE_SENTRY_DSN) &&
  (sentryAllowLocal || !['local', 'development', 'test'].includes(sentryEnvironment))

if (sentryEnabled) {
  Sentry.init({
    app,
    dsn: import.meta.env.VITE_SENTRY_DSN,
    environment: sentryEnvironment,
    sendDefaultPii: true,
    tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.1),
  })
}
const pinia = createPinia()

app.use(pinia)

const authStore = useAuthStore()

// Impersonation handoff — swap session before anything else boots
const _impersonateParam = new URLSearchParams(window.location.search).get('impersonate')
if (_impersonateParam) {
  // Replace session with the impersonation token; fetch /me to get the user object
  authStore.setSession({ accessToken: _impersonateParam, user: null })
  // Strip the param from the URL so refresh / back-button don't re-apply it
  const _clean = new URL(window.location.href)
  _clean.searchParams.delete('impersonate')
  window.history.replaceState({}, '', _clean.pathname + (_clean.search || ''))
  // Fetch the target user so the store has a full user object
  const _apiBase = (import.meta.env.VITE_API_URL ?? '') + '/api/v1'
  fetch(`${_apiBase}/me`, { headers: { Authorization: `Bearer ${_impersonateParam}` } })
    .then(r => r.json())
    .then(json => {
      const user = json?.data?.user ?? null
      if (user) authStore.setSession({ accessToken: _impersonateParam, user })
    })
    .catch(() => {})
} else {
  authStore.hydrate()
}

configureApiClient(authStore)

watch(
  () => authStore.accessToken,
  (token) => {
    setApiAccessToken(token)

    if (!token) {
      disconnectEcho()
      return
    }

    initEcho(token)
  },
  { immediate: true }
)

watch(
  () => authStore.isAuthenticated,
  (isAuthenticated) => {
    if (isAuthenticated) {
      return
    }

    if (router.currentRoute.value.meta.requiresAuth) {
      router.push({ name: 'login' })
    }
  }
)

app.use(router)
app.mount('#app')
