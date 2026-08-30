<script setup>
import { useRouter } from 'vue-router'
import { useLimitStore } from './stores/limit'
import { useSidebarStore } from './stores/sidebar'
import LimitModal from './components/LimitModal.vue'
import CookieNotice from './components/CookieNotice.vue'
import CrispChat from './components/CrispChat.vue'

const router = useRouter()
const limitStore = useLimitStore()
const sidebarStore = useSidebarStore()

// Suspended-workspace takeover. Once flagged (by the API interceptor — every
// request 403s at the middleware), the only honest options are contacting us
// or logging out, so the modal has no close button.
import { onMounted, onUnmounted, ref } from 'vue'
import { useAuthStore } from './stores/auth'
const suspended = ref(false)
const authStore = useAuthStore()
function onSuspended() { suspended.value = true }
onMounted(() => window.addEventListener('workspace-suspended', onSuspended))
onUnmounted(() => window.removeEventListener('workspace-suspended', onSuspended))
// ── Impersonation banner ──────────────────────────────────────────────
// Visible the whole time an admin is inside someone else's account: who
// forgot they were impersonating has sent mail as the customer before, in
// other products. Exit restores the stashed admin session.
const impersonating = ref(window.localStorage.getItem('framecast.impersonating') === '1')
function exitImpersonation() {
  const prior = window.localStorage.getItem('framecast.admin_return')
  window.localStorage.removeItem('framecast.impersonating')
  window.localStorage.removeItem('framecast.admin_return')
  if (prior) {
    window.localStorage.setItem('framecast.auth', prior)
    window.location.href = '/admin'
  } else {
    // No stashed session (e.g. impersonation opened in a clean browser) —
    // drop to login rather than stranding a dead token.
    window.localStorage.removeItem('framecast.auth')
    window.location.href = '/login'
  }
}

// ── Rageclick prompt ──────────────────────────────────────────────────
// 3+ clicks in the same ~30px spot within 800ms = frustration. Interrupt AT
// that moment with a way to tell us — silent churners never email support
// afterwards (one refunded with the chat widget on screen the whole time).
const ragePromptOpen = ref(false)
const rageMessage = ref('')
const rageSending = ref(false)
const rageSent = ref(false)
let rageClicks = []
let rageCooldownUntil = 0

function onRageCandidate(e) {
  if (ragePromptOpen.value || suspended.value) return
  const now = Date.now()
  rageClicks = rageClicks.filter(c => now - c.t < 800)
  rageClicks.push({ t: now, x: e.clientX, y: e.clientY })
  if (rageClicks.length < 3) return
  const [a, , c] = [rageClicks[0], rageClicks[1], rageClicks[rageClicks.length - 1]]
  if (Math.abs(a.x - c.x) > 30 || Math.abs(a.y - c.y) > 30) return
  rageClicks = []
  // Only for signed-in users, at most once per 15 minutes.
  if (!authStore.isAuthenticated || now < rageCooldownUntil) return
  rageCooldownUntil = now + 15 * 60 * 1000
  rageSent.value = false
  rageMessage.value = ''
  ragePromptOpen.value = true
}
onMounted(() => window.addEventListener('click', onRageCandidate, true))
onUnmounted(() => window.removeEventListener('click', onRageCandidate, true))

async function sendRageFeedback() {
  if (rageSending.value || !rageMessage.value.trim()) return
  rageSending.value = true
  try {
    const { default: api } = await import('./services/api')
    await api.post('/feedback', {
      message: rageMessage.value.trim(),
      page: window.location.pathname,
      trigger: 'rageclick',
    })
    rageSent.value = true
    setTimeout(() => { ragePromptOpen.value = false }, 2500)
  } catch { /* never make a frustration prompt itself frustrating */ ragePromptOpen.value = false }
  finally { rageSending.value = false }
}

async function suspendedLogout() {
  suspended.value = false
  try { await authStore.logout() } catch { authStore.clearSession() }
  router.push({ name: 'login' })
}

function handleUpgrade() {
  limitStore.close()
  router.push({ name: 'settings', query: { section: 'usage' } })
}
</script>

<template>
  <div :class="{ 'sb-collapsed': sidebarStore.collapsed }">
    <RouterView />

    <!-- Rageclick prompt: bottom-right, dismissible, never modal. -->
    <div v-if="ragePromptOpen" class="rage-card">
      <template v-if="rageSent">
        <div class="rage-title">Got it — thank you. 🙏</div>
        <div class="rage-copy">A human reads every one of these.</div>
      </template>
      <template v-else>
        <button class="rage-close" type="button" @click="ragePromptOpen = false">×</button>
        <div class="rage-title">Something not working?</div>
        <div class="rage-copy">Tell us what you were trying to do — it goes straight to a human.</div>
        <textarea v-model="rageMessage" class="rage-input" rows="3" maxlength="2000"
          placeholder="I was trying to…"></textarea>
        <button class="rage-send" type="button" :disabled="rageSending || !rageMessage.trim()" @click="sendRageFeedback">
          {{ rageSending ? 'Sending…' : 'Send' }}
        </button>
      </template>
    </div>

    <!-- Impersonation: always-visible, with a way OUT. -->
    <div v-if="impersonating" class="imp-banner">
      <span>👤 Impersonating a customer account</span>
      <button type="button" class="imp-exit" @click="exitImpersonation">Exit impersonation</button>
    </div>

    <!-- Suspended workspace: blocking, no dismiss. -->
    <div v-if="suspended" class="susp-backdrop">
      <div class="susp-modal">
        <div class="susp-icon">⛔</div>
        <h2 class="susp-title">This workspace is suspended</h2>
        <p class="susp-copy">
          Your account can't use WyvStudio right now. If you believe this is a
          mistake — or you'd like your data — contact us and a human will look
          into it.
        </p>
        <div class="susp-actions">
          <a class="btn btn-primary" href="mailto:hello@wyvstudio.com?subject=Suspended%20workspace">Contact support</a>
          <button class="btn btn-ghost" type="button" @click="suspendedLogout">Log out</button>
        </div>
      </div>
    </div>

    <LimitModal
      :open="limitStore.open"
      :title="limitStore.title"
      :subtitle="limitStore.subtitle"
      :rows="limitStore.rows"
      @close="limitStore.close()"
      @upgrade="handleUpgrade"
    />

    <CookieNotice />
    <CrispChat />
  </div>
</template>

<style>
.main {
  transition: margin-left 0.2s ease;
  overflow-x: hidden;
}
.sb-collapsed .main {
  margin-left: 56px !important;
}
</style>

<style scoped>
.susp-backdrop { position: fixed; inset: 0; z-index: 4000; background: rgba(5,5,10,.82); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; }
.susp-modal { max-width: 440px; width: 100%; background: #14141d; border: 1px solid #2a2a36; border-radius: 14px; padding: 34px 36px; text-align: center; display: flex; flex-direction: column; gap: 12px; align-items: center; color: #ececf3; }
.susp-icon { font-size: 34px; }
.susp-title { font-size: 19px; font-weight: 700; margin: 0; }
.susp-copy { font-size: 13.5px; line-height: 1.6; color: #a1a1b5; margin: 0; }
.susp-actions { display: flex; gap: 10px; margin-top: 10px; }
.susp-actions .btn { padding: 9px 18px; border-radius: 8px; font-size: 13px; text-decoration: none; }
.susp-actions .btn-primary { background: #ff6b35; color: #fff; border: none; cursor: pointer; }
.susp-actions .btn-ghost { background: transparent; color: #a1a1b5; border: 1px solid #2a2a36; cursor: pointer; }
.imp-banner { position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%); z-index: 5000; display: flex; align-items: center; gap: 14px; background: #7c2d12; color: #ffedd5; border: 1px solid #ea580c; border-radius: 999px; padding: 8px 10px 8px 18px; font-size: 12.5px; font-weight: 600; box-shadow: 0 8px 30px rgba(0,0,0,.45); }
.imp-exit { background: #ea580c; color: #fff; border: none; border-radius: 999px; padding: 6px 14px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; }
.imp-exit:hover { background: #f97316; }
/* bottom: 96px clears the Crisp bubble (~56px + its 20px inset), so the
   Send button is never under the chat icon. */
.rage-card { position: fixed; right: 18px; bottom: 96px; z-index: 3500; width: 300px; background: #17171f; border: 1px solid #2a2a36; border-radius: 12px; padding: 16px; box-shadow: 0 12px 40px rgba(0,0,0,.5); color: #ececf3; display: flex; flex-direction: column; gap: 8px; }
.rage-close { position: absolute; top: 8px; right: 12px; background: none; border: none; color: #a1a1b5; font-size: 16px; cursor: pointer; }
.rage-title { font-size: 13.5px; font-weight: 700; }
.rage-copy { font-size: 12px; color: #a1a1b5; line-height: 1.5; }
.rage-input { width: 100%; padding: 8px 10px; background: #0f0f16; border: 1px solid #2a2a36; border-radius: 8px; color: #ececf3; font-family: inherit; font-size: 12.5px; resize: none; }
.rage-send { align-self: flex-end; background: #ff6b35; color: #fff; border: none; border-radius: 7px; padding: 7px 16px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; }
.rage-send:disabled { opacity: .5; cursor: default; }
</style>
