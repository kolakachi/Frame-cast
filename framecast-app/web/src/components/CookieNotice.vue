<script setup>
import { ref, onMounted } from 'vue'

// GDPR cookie notice for the app. v2 (2026-10-11, with the Google Ads tag): a choice, Essential only or Allow. The
// choice is kept in localStorage and in the wyv_consent cookie on .wyvstudio.com, so a choice made on the landing page
// (cookie-notice.js) carries over here and the other way round; public/google-ads.js reads it.
const KEY = 'wyv_cookie_notice_v2'
const TTL_DAYS = 180

const visible = ref(false)

function sharedChoice() {
  const m = document.cookie.match(/(?:^|;\s*)wyv_consent=(all|essential)/)
  return m ? m[1] : null
}

function dismissed() {
  if (sharedChoice()) return true
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

function choose(all) {
  const choice = all ? 'all' : 'essential'
  try { localStorage.setItem(KEY, JSON.stringify({ ts: Date.now(), choice })) } catch {}
  const domain = location.hostname.endsWith('wyvstudio.com') ? '; domain=.wyvstudio.com' : ''
  document.cookie = `wyv_consent=${choice}; max-age=${TTL_DAYS * 86400}; path=/; samesite=lax${domain}${location.protocol === 'https:' ? '; secure' : ''}`
  if (typeof window.wyvConsent === 'function') window.wyvConsent(all)
  visible.value = false
}

onMounted(() => { if (!dismissed()) visible.value = true })
</script>

<template>
  <div v-if="visible" class="wcn" role="dialog" aria-label="Cookie notice">
    <div class="wcn-inner">
      <div class="wcn-text">
        We use essential cookies for login and billing, plus anonymized error tracking via Sentry.
        With your OK, Google Ads also uses cookies to measure our ads. See our
        <a href="https://wyvstudio.com/privacy" target="_blank" rel="noopener">Privacy Policy</a>.
      </div>
      <div class="wcn-btns">
        <button type="button" class="wcn-no" @click="choose(false)">Essential only</button>
        <button type="button" class="wcn-ok" @click="choose(true)">Allow</button>
      </div>
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
.wcn-btns { display: flex; gap: 8px; flex-shrink: 0; }
.wcn-no { background: transparent; border: 1px solid #3a3a44; color: #ececf3; font: 600 13px 'DM Sans', sans-serif; padding: 9px 14px; border-radius: 8px; cursor: pointer; }
.wcn-no:hover { border-color: #ff8055; }
@media (max-width: 640px) {
  .wcn-inner { flex-direction: column; align-items: stretch; }
  .wcn-btns { align-self: flex-end; }
}
</style>
