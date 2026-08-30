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
</style>
