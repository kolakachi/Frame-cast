<script setup>
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Loads the Crisp chat widget if VITE_CRISP_WEBSITE_ID is set.
// When the user is logged in, identifies them so conversations are
// continuous across devices and we can see their workspace context.
//
// Hidden on routes that have heavy bottom-right UI (project editor, asset
// library, settings) because the bubble overlapped action buttons there.
// On all other routes the bubble shows in its default bottom-right slot.
const auth = useAuthStore()
const route = useRoute()

// Routes where the floating bubble would collide with primary action UI.
// Add to this set when more pages grow bottom-right action bars.
const CRISP_HIDDEN_ROUTES = new Set([
  'project-editor', // Export, Save Draft, Publish action bar
  'asset-library',  // Upload / Generate / Bulk-action menu at bottom-right
  'settings',       // Save buttons inline; pagination + load-more chrome
])

function syncCrispVisibility(routeName) {
  if (!window.$crisp) return
  try {
    if (CRISP_HIDDEN_ROUTES.has(String(routeName))) {
      window.$crisp.push(['do', 'chat:hide'])
    } else {
      window.$crisp.push(['do', 'chat:show'])
    }
  } catch {}
}

// React to client-side route changes — the SPA never reloads, so we have to
// flip visibility on every navigation rather than relying on the bubble's
// own state.
watch(() => route.name, (next) => syncCrispVisibility(next))

onMounted(() => {
  const websiteId = import.meta.env.VITE_CRISP_WEBSITE_ID
  if (!websiteId) return

  // Already loaded — just reconcile visibility for the current route.
  if (window.$crisp || document.getElementById('crisp-chat-script')) {
    syncCrispVisibility(route.name)
    return
  }

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

      // Apply the route-based show/hide once the widget is actually live.
      syncCrispVisibility(route.name)
    } catch {}
  }
})
</script>

<template>
  <!-- Crisp injects its own DOM at <body> level; this stub satisfies
       Vue's valid-template-root rule without contributing any layout. -->
  <span class="crisp-chat-mount" aria-hidden="true" style="display:none"></span>
</template>
