<script setup>
import { ref, onMounted } from 'vue'

// GDPR cookie notice for the app. Same dismissal key as the marketing site
// (cookie-notice.js), so dismissing on one carries across to the other.
const KEY = 'wyv_cookie_notice_v1'
const TTL_DAYS = 180

const visible = ref(false)

function dismissed() {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return false
    const rec = JSON.parse(raw)
    if (!rec?.ts) return false
    const ageDays = (Date.now() - rec.ts) / (1000 * 60 * 60 * 24)
    return ageDays < TTL_DAYS
  } catch {
    return false
  }
}

function dismiss() {
  try { localStorage.setItem(KEY, JSON.stringify({ ts: Date.now() })) } catch {}
  visible.value = false
}

onMounted(() => { if (!dismissed()) visible.value = true })
</script>

<template>
  <div v-if="visible" class="wcn" role="dialog" aria-label="Cookie notice">
    <div class="wcn-inner">
      <div class="wcn-text">
        We use essential cookies for login and billing, plus anonymized error tracking via Sentry to keep WyvStudio working.
        No marketing trackers, no analytics. See our
        <a href="https://wyvstudio.com/privacy" target="_blank" rel="noopener">Privacy Policy</a>.
      </div>
      <button type="button" class="wcn-ok" @click="dismiss">Got it</button>
    </div>
  </div>
</template>

<style scoped>
.wcn {
  position: fixed; left: 16px; right: 16px; bottom: 16px;
  z-index: 9999; font-family: 'DM Sans', -apple-system, system-ui, sans-serif;
}
.wcn-inner {
  max-width: 780px; margin: 0 auto;
  display: flex; align-items: center; gap: 14px;
  padding: 14px 18px;
  background: #14141c; color: #ececf3;
  border: 1px solid #2a2a31; border-radius: 12px;
  box-shadow: 0 14px 40px rgba(0, 0, 0, 0.55);
}
.wcn-text { font-size: 13px; line-height: 1.55; flex: 1; color: #cdcdd4; }
.wcn-text a { color: #ff8055; text-decoration: underline; }
.wcn-ok {
  background: #ff6b35; border: 1px solid #ff6b35; color: #0a0a0f;
  font: 600 13px 'DM Sans', sans-serif;
  padding: 9px 18px; border-radius: 8px; cursor: pointer; flex-shrink: 0;
  transition: 0.15s;
}
.wcn-ok:hover { background: #ff8055; border-color: #ff8055; }
@media (max-width: 640px) {
  .wcn-inner { flex-direction: column; align-items: stretch; }
  .wcn-ok { align-self: flex-end; }
}
</style>
