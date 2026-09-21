<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const state = ref('waiting')
let timer
let stopped = false
let polls = 0
async function checkPayment() {
  state.value = 'waiting'
  try {
    const { data } = await api.get(`/billing/confirmation/${encodeURIComponent(String(route.query.attempt || ''))}`)
    if (stopped) return
    if (data?.data?.confirmed) {
      await auth.refreshUser()
      if (stopped) return
      try { localStorage.removeItem('wyv_pending_plan'); localStorage.removeItem('wyv_pending_confirmation') } catch { /* optional cache */ }
      await router.replace({ name: auth.isOnboarded ? 'settings' : 'onboarding' })
      return
    }
  } catch (error) {
    if (stopped) return
    if ([403, 404, 422].includes(error.response?.status)) {
      try { localStorage.removeItem('wyv_pending_confirmation') } catch { /* optional cache */ }
      state.value = 'invalid'; return
    }
  }
  if (++polls >= 20) { state.value = 'delayed'; return }
  timer = setTimeout(checkPayment, 3000)
}
function retry() { clearTimeout(timer); polls = 0; checkPayment() }
onMounted(() => {
  const attempt = String(route.query.attempt || '')
  if (!/^[0-9a-f-]{36}$/i.test(attempt)) { state.value = 'invalid'; return }
  try { localStorage.setItem('wyv_pending_confirmation', attempt) } catch { /* optional cache */ }
  if (auth.isAuthenticated) checkPayment(); else state.value = 'signed-out'
})
onUnmounted(() => { stopped = true; clearTimeout(timer) })
</script>

<template>
  <main class="auth-screen auth-bg">
    <section class="auth-card auth-card-centered" aria-live="polite">
      <div class="payment-brand"><img src="/favicon.svg" alt="" width="32" height="32">WyvStudio</div>
      <h1 class="auth-title">{{ state === 'signed-out' ? 'Sign in to check your payment' : state === 'invalid' ? 'We couldn’t find this checkout' : 'Confirming your payment' }}</h1>
      <p class="auth-subtitle payment-description">
        {{ state === 'waiting' ? 'We’re waiting for payment confirmation and activating your credits. This usually takes a few moments.' : state === 'signed-out' ? 'After signing in, return to this page to check your purchase.' : state === 'invalid' ? 'Open the confirmation link in the account you used for checkout. Contact support if you have already paid.' : 'Confirmation is taking longer than expected. You can check again or return to this page later. Please don’t pay again.' }}
      </p>
      <button v-if="state === 'delayed'" class="auth-btn-primary" @click="retry">Check payment status</button>
      <router-link v-if="state === 'signed-out'" class="auth-btn-primary auth-btn-link" :to="{ name: 'login' }">Sign in</router-link>
      <a v-if="state !== 'waiting'" class="auth-link payment-help" href="mailto:hello@wyvstudio.com">Contact support</a>
    </section>
  </main>
</template>

<style scoped>
.payment-brand { display: flex; gap: 10px; align-items: center; justify-content: center; margin-bottom: 28px; font-size: 18px; font-weight: 700; color: var(--color-text-primary); }
.payment-description { margin-top: 14px; line-height: 1.6; }
.payment-help { display: inline-block; margin-top: 20px; font-size: 14px; }
@media (max-width: 480px) { .auth-card { padding: 28px 24px; } }
</style>
