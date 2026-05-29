<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'

// Loads the Crisp chat widget if VITE_CRISP_WEBSITE_ID is set.
// When the user is logged in, identifies them so conversations are
// continuous across devices and we can see their workspace context.
const auth = useAuthStore()

onMounted(() => {
  const websiteId = import.meta.env.VITE_CRISP_WEBSITE_ID
  if (!websiteId) return
  if (window.$crisp || document.getElementById('crisp-chat-script')) return

  window.$crisp = []
  window.CRISP_WEBSITE_ID = websiteId

  const s = document.createElement('script')
  s.id = 'crisp-chat-script'
  s.src = 'https://client.crisp.chat/l.js'
  s.async = true
  document.head.appendChild(s)

  s.onload = () => {
    try {
      const u = auth.user
      if (u?.email) window.$crisp.push(['set', 'user:email', [u.email]])
      if (u?.name)  window.$crisp.push(['set', 'user:nickname', [u.name]])
    } catch {}
  }
})
</script>

<template>
  <!-- No DOM — Crisp injects its own widget. -->
</template>
