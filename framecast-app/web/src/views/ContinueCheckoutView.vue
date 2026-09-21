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
    errorMessage.value = error.response?.status >= 500 || !error.response
      ? 'We couldn’t connect to secure checkout. Please try again in a moment.'
      : error.response?.data?.error?.message || 'We couldn’t open checkout. Please try again.'
    state.value = 'failed'
  }
}

onMounted(startCheckout)

</script>

<template>
  <main class="auth-screen auth-bg checkout-screen">
    <section class="auth-card auth-card-centered checkout-card" aria-labelledby="checkout-title" :aria-busy="state === 'working'">
      <div class="checkout-brand"><img src="/favicon.svg" alt="" width="32" height="32">WyvStudio</div>
      <div class="checkout-status" :class="{ 'checkout-status-loading': state === 'working' }" aria-hidden="true">
        <svg v-if="state === 'failed'" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 4h.01"/></svg>
        <span v-else class="checkout-spinner" />
      </div>
      <template v-if="state === 'failed'">
        <h1 id="checkout-title" class="auth-title">We couldn’t open payment</h1>
        <p class="auth-subtitle checkout-description" role="alert">{{ errorMessage }}</p>
        <button class="auth-btn-primary" @click="startCheckout">Retry payment</button>
        <router-link class="auth-link checkout-secondary" :to="{ name: 'plans' }">Choose another plan</router-link>
      </template>
      <template v-else>
        <h1 id="checkout-title" class="auth-title">Opening secure checkout</h1>
        <p class="auth-subtitle checkout-description" role="status">One moment while we get your selected plan ready.</p>
        <p class="checkout-note">You’ll review your order before paying.</p>
      </template>
    </section>
  </main>
</template>

<style scoped>
.checkout-screen { overflow-y: auto; }
.checkout-card { max-width: 440px; margin: auto; }
.checkout-brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 32px; color: var(--color-text-primary); font-size: 18px; font-weight: 700; }
.checkout-status { display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; margin: 0 auto 24px; border-radius: 50%; background: rgba(255, 107, 53, .1); color: var(--color-accent); }
.checkout-description { margin: 12px 0 24px; font-size: 14px; line-height: 1.6; }
.checkout-secondary { display: inline-block; margin-top: 20px; font-size: 14px; }
.checkout-note { color: var(--color-text-muted); font-size: 12px; margin: 0; }
.checkout-spinner { width: 25px; height: 25px; border: 2px solid rgba(255, 107, 53, .2); border-top-color: var(--color-accent); border-radius: 50%; animation: checkout-spin .8s linear infinite; }
@keyframes checkout-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .checkout-spinner { animation: none; } }
@media (max-width: 480px) { .checkout-card { padding: 28px 24px; } }
</style>
