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
</style>
