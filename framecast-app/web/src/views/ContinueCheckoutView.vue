<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

/**
 * The landing point for "finish checkout" links we send by email.
 *
 * Public on purpose. /plans requires a session, so a signed-out click on it is
 * bounced to the login page and the plan in the query string is lost — the
 * person then arrives at a plan picker with no memory of what the email was
 * about. Here the choice is parked first and acted on after, so the link
 * behaves the same whether or not they still have a session.
 *
 * A Kelviq URL cannot go in the email directly: creating one needs an
 * authenticated request, and the session it makes would be stale by the time
 * anyone clicked.
 */
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const LIFETIME = ['lifetime_starter', 'lifetime_creator', 'lifetime_agency']
const MONTHLY = ['starter', 'creator', 'pro', 'agency']

const state = ref('working') // 'working' | 'failed'

const errorMessage = ref('')

async function startCheckout() {
  state.value = 'working'
  errorMessage.value = ''
  let plan = route.query.pass ? 'ugc_pass' : String(route.query.plan ?? '')
  const known = value => value === 'ugc_pass' || LIFETIME.includes(value) || MONTHLY.includes(value)
  if (known(plan)) {
    try { localStorage.setItem('wyv_pending_plan', plan) } catch { /* optional cache */ }
  }
  if (!authStore.isAuthenticated) {
    return router.replace({ name: 'login', query: known(plan) ? { plan } : {} })
  }
  try {
    if (!known(plan)) {
      const { data } = await api.get('/billing/status')
      const billing = data?.data?.billing
      if (!billing) throw new Error('Unable to check your selected plan.')
      if (!billing.checkout_required) return router.replace({ name: 'dashboard' })
      plan = billing.checkout_plan || billing.pending_checkout?.plan || ''
      if (!known(plan)) return router.replace({ name: 'plans' })
    }
    const body = plan === 'ugc_pass' ? { pass: true }
      : LIFETIME.includes(plan) ? { lifetime: plan } : { plan }
    const { data } = await api.post('/billing/kelviq/checkout', body)
    if (!data?.data?.url) throw new Error('Payment did not return a checkout link.')
    try { localStorage.removeItem('wyv_pending_plan') } catch { /* optional cache */ }
    window.location.href = data.data.url
  } catch (error) {
    errorMessage.value = error.response?.data?.error?.message || error.message || 'Please try again.'
    state.value = 'failed'
  }
}

onMounted(startCheckout)

</script>

<template>
  <div class="continue-wrap">
    <template v-if="state === 'failed'">
      <h1>We couldn’t open payment</h1>
      <p role="alert">{{ errorMessage }}</p>
      <button class="auth-btn-primary" @click="startCheckout">Retry payment</button>
      <router-link class="auth-link" :to="{ name: 'plans' }">Choose another plan</router-link>
    </template>
    <p v-else>Taking you to secure checkout…</p>
  </div>
</template>

<style scoped>
.continue-wrap {
  min-height: 60vh; display: flex; flex-direction: column; gap: 20px; padding: 24px; text-align: center; align-items: center; justify-content: center;
  color: var(--color-text-secondary, #a1a1b5); font-size: 14px;
}
</style>
