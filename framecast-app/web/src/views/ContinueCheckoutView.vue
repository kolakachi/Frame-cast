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

onMounted(async () => {
  const plan = String(route.query.plan ?? '')
  const known = LIFETIME.includes(plan) || MONTHLY.includes(plan)

  if (known) {
    try {
      // Parked before anything can redirect: MagicLinkView reads this after a
      // sign-in and finishes the job there.
      localStorage.setItem('wyv_pending_plan', plan)
    } catch {
      // Private window or storage blocked — a signed-in visitor still gets
      // sent to Kelviq below; a signed-out one lands on the plans page.
    }
  }

  if (!authStore.isAuthenticated) {
    return router.replace({ name: known ? 'login' : 'plans' })
  }

  if (!known) {
    return router.replace({ name: 'plans' })
  }

  try {
    const { data } = await api.post('/billing/kelviq/checkout',
      LIFETIME.includes(plan) ? { lifetime: plan } : { plan })
    if (data?.data?.url) {
      // Consumed — a stale choice must not hijack a later sign-in.
      try { localStorage.removeItem('wyv_pending_plan') } catch { /* ignore */ }
      window.location.href = data.data.url
      return
    }
  } catch {
    // Fall through to the plans page rather than stranding them here.
  }

  state.value = 'failed'
  router.replace({ name: 'plans' })
})
</script>

<template>
  <div class="continue-wrap">
    <p>{{ state === 'failed' ? 'Taking you to the plans page…' : 'Taking you to checkout…' }}</p>
  </div>
</template>

<style scoped>
.continue-wrap {
  min-height: 60vh; display: flex; align-items: center; justify-content: center;
  color: var(--color-text-secondary, #a1a1b5); font-size: 14px;
}
</style>
