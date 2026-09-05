<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const state = ref('verifying') // 'verifying' | 'expired' | 'error' | 'checkout'

const LIFETIME_PLANS = ['lifetime_starter', 'lifetime_creator', 'lifetime_agency']
const MONTHLY_PLANS = ['starter', 'creator', 'pro', 'agency']

/**
 * Redeem a plan choice parked at registration into a Kelviq checkout redirect.
 * Returns true when the browser is being sent to Kelviq, false to carry on to
 * the dashboard. The stash is cleared either way so a stale choice can't
 * hijack a later sign-in.
 */
async function resumePendingCheckout() {
  let plan = ''
  try {
    plan = localStorage.getItem('wyv_pending_plan') ?? ''
    localStorage.removeItem('wyv_pending_plan')
  } catch {
    return false
  }
  if (!plan) return false

  const body = LIFETIME_PLANS.includes(plan)
    ? { lifetime: plan }
    : MONTHLY_PLANS.includes(plan)
      ? { plan }
      : null
  if (!body) return false

  try {
    state.value = 'checkout'
    const { data } = await api.post('/billing/kelviq/checkout', body)
    if (data?.data?.url) {
      window.location.href = data.data.url
      return true
    }
  } catch {
    // Fall through — Settings has a button for every plan.
  }
  state.value = 'verifying'
  return false
}

onMounted(async () => {
  const token = route.query.token

  if (!token) {
    state.value = 'expired'
    return
  }

  try {
    await authStore.verifyMagicLink(token)

    // Someone who clicked a price on the site parked their choice before going
    // to their inbox. Now that they're authenticated the workspace exists, so
    // the checkout can finally be created — send them straight to Kelviq
    // instead of dropping them on a dashboard with no memory of what they came
    // to buy. Any failure just falls through to the dashboard, where Settings
    // carries a button for every plan.
    if (await resumePendingCheckout()) return

    router.replace({ name: 'dashboard' })
  } catch (err) {
    const code = err.response?.data?.error?.code
    state.value = code === 'invalid_magic_link' ? 'expired' : 'error'
  }
})
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card auth-card-centered">
      <template v-if="state === 'verifying'">
        <div class="auth-magic-icon auth-magic-icon-pulse">✦</div>
        <h1 class="auth-title centered">Signing you in…</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">Verifying your magic link.</p>
      </template>

      <template v-else-if="state === 'checkout'">
        <div class="auth-magic-icon auth-magic-icon-pulse">✦</div>
        <h1 class="auth-title centered">Taking you to checkout…</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">One moment while we open secure payment.</p>
      </template>

      <template v-else-if="state === 'expired'">
        <div class="auth-magic-icon auth-magic-icon-danger">✕</div>
        <h1 class="auth-title centered">Link expired</h1>
        <p class="auth-subtitle centered">
          This magic link has already been used or has expired.<br>
          Links are valid for 15 minutes and can only be used once.
        </p>
        <router-link class="auth-btn-primary auth-btn-link" :to="{ name: 'login' }">Request a new link</router-link>
      </template>

      <template v-else>
        <div class="auth-magic-icon auth-magic-icon-warning">⚠</div>
        <h1 class="auth-title centered">Something went wrong</h1>
        <p class="auth-subtitle centered">We couldn't verify your link. Please try again.</p>
        <router-link class="auth-btn-primary auth-btn-link" :to="{ name: 'login' }">Back to login</router-link>
      </template>
    </div>
  </main>
</template>
