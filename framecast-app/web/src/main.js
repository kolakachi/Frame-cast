import { watch } from 'vue'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import * as Sentry from '@sentry/vue'
import App from './App.vue'
import router from './router'
import api, { configureApiClient, setApiAccessToken } from './services/api'
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
    // GDPR: don't ship IP / user agent / cookies to Sentry by default.
    // Stack traces + breadcrumbs (no PII) are enough to debug, and this lets
    // the consent banner honestly say "anonymized error tracking only".
    sendDefaultPii: false,
    tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.1),
  })
}

// ── PostHog product analytics ──────────────────────────────────────────────
// Same env-driven init pattern as Sentry — if VITE_POSTHOG_KEY isn't set
// (typical in local dev), the loader silently bails. In production the key
// is injected at build time via the docker-compose.prod build args.
// We keep person_profiles=identified_only so anonymous visitors don't churn
// out person rows; identify() in main.js below fires after the auth store
// hydrates with a user.
const posthogKey = import.meta.env.VITE_POSTHOG_KEY
if (posthogKey) {
  // Dynamic import keeps PostHog out of the first paint chunk for users
  // who hit the app without an existing session.
  import('posthog-js').then(({ default: posthog }) => {
    posthog.init(posthogKey, {
      api_host: import.meta.env.VITE_POSTHOG_HOST || 'https://us.i.posthog.com',
      person_profiles: 'identified_only',
      capture_pageview: true,
      capture_pageleave: true,
      // Don't capture form fields, password inputs, or any text content from
      // <textarea> / <input> — we want event names, not user content.
      autocapture: { dom_event_allowlist: ['click', 'submit'] },
      // Session replay — the richest zero-ask feedback there is. Recording
      // only actually starts if "Record user sessions" is ALSO enabled in the
      // PostHog project settings; this side masks every input and anything
      // marked [data-ph-mask] so credentials and typed content never leave
      // the browser.
      disable_session_recording: false,
      session_recording: {
        maskAllInputs: true,
        maskTextSelector: '[data-ph-mask]',
      },
    })
    window.__posthog = posthog
  })
}
const pinia = createPinia()

app.use(pinia)

const authStore = useAuthStore()

// Impersonation handoff — swap session before anything else boots
const _impersonateParam = new URLSearchParams(window.location.search).get('impersonate')
if (_impersonateParam) {
  // Stash the admin's own session BEFORE the swap so impersonation is exitable.
  // Without this the swap clobbered the only copy of the admin's token
  // (localStorage is origin-wide), and the sole way back was waiting out the
  // impersonation TTL and logging in again.
  const _prior = window.localStorage.getItem('framecast.auth')
  if (_prior) window.localStorage.setItem('framecast.admin_return', _prior)
  window.localStorage.setItem('framecast.impersonating', '1')
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

// The cached user is written at sign-in and refreshed only alongside the access
// token, so anything keyed on a user flag — a nav entry, a route guard — can be
// deciding on a stale copy. Re-reading only when the field was missing was not
// enough: a session that cached a flag as false during a deploy window kept
// that answer forever, because the field was present and simply wrong. One
// authenticated /me per boot is cheap next to being wrong until someone signs
// out. Placed after configureApiClient so the request carries the token.
if (authStore.isAuthenticated && authStore.user) {
  authStore.refreshUser()
}

// Affiliate arrival. The marketing site cannot set a cookie the app will read
// — different host, so it would be third-party and blocked by default — and it
// cannot call the API either, since CORS admits only this origin. It therefore
// puts the code in the URL, and this is where it becomes durable: the POST is
// same-origin, so the cookie it sets is first-party and survives.
;(function captureAffiliate() {
  try {
    const code = new URLSearchParams(window.location.search).get('aff')
    if (!code || !/^[A-Za-z0-9_-]{1,32}$/.test(code)) return

    // Sent before anything else so a visitor who bounces immediately is still
    // counted; the response carries the cookie the checkout later reads.
    api.post('/affiliate/click', { code }).catch(() => {})

    // Kept out of the URL bar so it is not carried into a shared link or a
    // screenshot, and does not end up attributing someone else's visit.
    const url = new URL(window.location.href)
    url.searchParams.delete('aff')
    window.history.replaceState({}, '', url.toString())
  } catch {
    // Never let attribution break a page load.
  }
})()

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

// Tell PostHog who the user is once auth hydrates so events get joined to
// a real person profile. On logout we reset() so anonymous events stop
// being attached to the old user.
watch(
  () => authStore.user?.id,
  (userId) => {
    if (!window.__posthog) return
    if (userId) {
      window.__posthog.identify(String(userId), {
        email: authStore.user?.email,
        workspace_id: authStore.user?.workspace_id,
        plan_tier: authStore.user?.workspace?.plan_tier,
      })
    } else {
      window.__posthog.reset?.()
    }
  },
  { immediate: true }
)

app.use(router)
app.mount('#app')
