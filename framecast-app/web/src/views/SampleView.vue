<script setup>
// Public /sample/<token> page. No auth required, no app shell. Renders the
// latest succeeded export as a playable <video>, with a tiny title + scene
// tease below. Used as the cold-DM landing surface — the recipient watches
// the demo, then we link them to sign up.

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const token = computed(() => route.params.token)

const loading       = ref(true)
const notFound      = ref(false)
const notReady      = ref(false)
const data          = ref(null)
const playerError   = ref('')

// Use a raw axios instance (no auth interceptor) — this is the public path
// and we don't want a stale JWT or refresh redirect bouncing the request.
const apiBase = import.meta.env.VITE_API_URL || ''
const publicApi = axios.create({ baseURL: `${apiBase}/api/v1` })

async function loadSample() {
  loading.value = true
  try {
    const res = await publicApi.get(`/public/projects/${token.value}`)
    data.value = res.data?.data ?? null
    if (!data.value) {
      notFound.value = true
    } else if (!data.value.export_ready) {
      notReady.value = true
    }
  } catch (e) {
    const code = e?.response?.status
    if (code === 404) notFound.value = true
    else playerError.value = e?.response?.data?.error?.message ?? 'Something went wrong loading this sample.'
  } finally {
    loading.value = false
  }
}

onMounted(() => { loadSample() })

// If the export wasn't ready yet, poll every 6s for ~3min before giving up.
// Common case: owner shared a project mid-export and the recipient lands
// before the file finishes. Quietly retry instead of asking them to refresh.
let pollTimer = null
let pollAttempts = 0
function startPolling() {
  if (pollTimer) return
  pollTimer = window.setInterval(async () => {
    pollAttempts += 1
    if (pollAttempts > 30) { stopPolling(); return }
    try {
      const res = await publicApi.get(`/public/projects/${token.value}`)
      const next = res.data?.data
      if (next?.export_ready) {
        data.value = next
        notReady.value = false
        stopPolling()
      }
    } catch { /* keep trying */ }
  }, 6000)
}
function stopPolling() {
  if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null }
}
onBeforeUnmount(stopPolling)

// Kick off polling when we know the export isn't ready
function onMountedReady() {
  if (notReady.value) startPolling()
}

// Watch via a tiny computed-triggered effect since we don't import watch
// (vue ref + effect would also do it, but this is simpler).
const tickReady = computed(() => {
  if (notReady.value && !pollTimer) startPolling()
  return notReady.value
})
</script>

<template>
  <main class="sample-page">
    <div class="sample-frame">
      <header class="sample-header">
        <a href="/" class="sample-brand">⚡ WyvStudio</a>
      </header>

      <div v-if="loading" class="sample-state">Loading…</div>

      <div v-else-if="notFound" class="sample-state">
        <h1 class="sample-state-title">This share link is unavailable</h1>
        <p class="sample-state-body">The link may have been disabled by the owner or never existed. <a href="/" class="sample-cta">Try WyvStudio →</a></p>
      </div>

      <div v-else-if="playerError" class="sample-state">
        <h1 class="sample-state-title">Something went wrong</h1>
        <p class="sample-state-body">{{ playerError }}</p>
      </div>

      <div v-else-if="data && data.export_ready" class="sample-content">
        <div class="sample-player-wrap" :data-ratio="data.project?.aspect_ratio || '9:16'">
          <video
            class="sample-player"
            :src="data.video_url"
            controls
            playsinline
            preload="metadata"
            crossorigin="anonymous"
          ></video>
        </div>

        <h1 class="sample-title">{{ data.project.title || 'Untitled' }}</h1>
        <div class="sample-meta">
          {{ data.project.scene_count }} scene{{ data.project.scene_count === 1 ? '' : 's' }}
          · {{ data.project.aspect_ratio }}
        </div>

        <div v-if="data.scenes?.length" class="sample-scenes">
          <div class="sample-section-label">What's in this video</div>
          <ol class="sample-scene-list">
            <li v-for="s in data.scenes" :key="s.order" class="sample-scene-item">
              <span class="sample-scene-num">{{ s.order }}</span>
              <span class="sample-scene-text">{{ s.snippet || '(no caption)' }}</span>
            </li>
          </ol>
        </div>

        <div class="sample-cta-row">
          <a href="/" class="sample-cta-btn">Make one like this →</a>
        </div>
      </div>

      <div v-else-if="notReady || tickReady" class="sample-state">
        <h1 class="sample-state-title">Sample still rendering…</h1>
        <p class="sample-state-body">This shared video is finishing up. We'll refresh automatically when it's ready (about 1–2 minutes).</p>
        <div class="sample-spinner">⟳</div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.sample-page { min-height: 100vh; background: #0a0a0f; color: #e8e6e1; display: flex; justify-content: center; align-items: flex-start; padding: 24px 16px; }
.sample-frame { width: 100%; max-width: 560px; }
.sample-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
.sample-brand { font-size: 14px; font-weight: 700; color: #e8e6e1; text-decoration: none; letter-spacing: -0.3px; }

.sample-state { padding: 60px 16px; text-align: center; }
.sample-state-title { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
.sample-state-body { color: #9ca3af; font-size: 14px; line-height: 1.55; max-width: 360px; margin: 0 auto; }
.sample-cta { color: #ff6b35; text-decoration: none; font-weight: 600; }
.sample-cta:hover { text-decoration: underline; }

.sample-player-wrap { position: relative; background: #000; border-radius: 12px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 20px 50px rgba(0,0,0,0.4); }
.sample-player-wrap[data-ratio="9:16"]  { aspect-ratio: 9 / 16; }
.sample-player-wrap[data-ratio="1:1"]   { aspect-ratio: 1 / 1; }
.sample-player-wrap[data-ratio="4:5"]   { aspect-ratio: 4 / 5; }
.sample-player-wrap[data-ratio="16:9"]  { aspect-ratio: 16 / 9; }
.sample-player { width: 100%; height: 100%; display: block; }

.sample-title { font-size: 22px; font-weight: 700; letter-spacing: -0.4px; margin-bottom: 4px; }
.sample-meta { color: #9ca3af; font-size: 12px; font-family: "Space Mono", monospace; margin-bottom: 20px; }

.sample-section-label { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-family: "Space Mono", monospace; }
.sample-scene-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.sample-scene-item { display: flex; gap: 10px; padding: 9px 12px; background: #16161d; border-radius: 8px; font-size: 13px; line-height: 1.4; align-items: flex-start; }
.sample-scene-num { font-family: "Space Mono", monospace; color: #ff6b35; font-weight: 700; flex-shrink: 0; width: 18px; }
.sample-scene-text { color: #c5c3bd; }

.sample-cta-row { margin-top: 28px; padding-top: 20px; border-top: 1px solid #1f1f28; text-align: center; }
.sample-cta-btn { display: inline-block; padding: 12px 22px; background: #ff6b35; color: #fff; text-decoration: none; font-weight: 600; border-radius: 8px; transition: transform 0.18s; }
.sample-cta-btn:hover { transform: translateY(-1px); }

.sample-spinner { margin-top: 16px; font-size: 24px; color: #ff6b35; animation: spin 1.2s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
