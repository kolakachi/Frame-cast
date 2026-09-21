<script setup>
import { ref } from 'vue'

defineProps({ src: { type: String, required: true } })
const video = ref(null)
const shell = ref(null)
const playing = ref(false)
const muted = ref(false)
const current = ref(0)
const duration = ref(0)
const error = ref('')
const time = value => `${Math.floor((value || 0) / 60)}:${String(Math.floor((value || 0) % 60)).padStart(2, '0')}`
function sync() {
  current.value = video.value?.currentTime || 0
  duration.value = Number.isFinite(video.value?.duration) ? video.value.duration : 0
}
async function toggle() {
  if (!video.value) return
  if (!video.value.paused) video.value.pause()
  else {
    try { await video.value.play(); error.value = '' }
    catch { error.value = 'Playback could not start. Try again or download the video.' }
  }
}
function seek(event) { if (video.value && duration.value) video.value.currentTime = Number(event.target.value) }
function toggleMute() { if (video.value) video.value.muted = !video.value.muted }
async function fullscreen() {
  try {
    if (document.fullscreenElement) await document.exitFullscreen()
    else if (shell.value?.requestFullscreen) await shell.value.requestFullscreen()
    else video.value?.webkitEnterFullscreen?.()
  } catch { error.value = 'Fullscreen is unavailable in this browser.' }
}
</script>

<template>
  <div ref="shell" class="finished-player">
    <div class="player-screen">
      <video ref="video" :src="src" playsinline preload="metadata" aria-label="Finished video"
        @click="toggle" @loadedmetadata="sync" @durationchange="sync" @timeupdate="sync"
        @play="playing = true" @pause="playing = false" @ended="playing = false"
        @volumechange="muted = video.muted" @error="error = 'This video could not be loaded. Refresh the page or download it.'" />
      <button v-if="!playing && !error" class="player-play-large" type="button" aria-label="Play video" @click="toggle">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="m9 5 11 7-11 7z" /></svg>
      </button>
    </div>
    <div class="player-controls">
      <input class="player-seek" type="range" aria-label="Video position" :min="0" :max="duration || 1" step="0.1" :value="current" :disabled="!duration" @input="seek" />
      <div class="player-controls-row">
        <button type="button" :aria-label="playing ? 'Pause video' : 'Play video'" @click="toggle">
          <svg viewBox="0 0 24 24" fill="currentColor"><path v-if="playing" d="M6 5h4v14H6zm8 0h4v14h-4z"/><path v-else d="m8 5 11 7-11 7z"/></svg>
        </button>
        <span class="player-time">{{ time(current) }} <span>/ {{ time(duration) }}</span></span>
        <span class="player-spacer"></span>
        <button type="button" :aria-label="muted ? 'Unmute video' : 'Mute video'" :aria-pressed="muted" @click="toggleMute">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M11 5 6 9H3v6h3l5 4z"/><path v-if="muted" d="m16 9 6 6m0-6-6 6"/><path v-else d="M15 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/></svg>
        </button>
        <button type="button" aria-label="Fullscreen" @click="fullscreen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></button>
      </div>
    </div>
    <p v-if="error" class="player-error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.finished-player { overflow: hidden; border: 1px solid var(--color-border, #2a2a33); border-radius: 18px; background: #101015; box-shadow: 0 16px 50px #0004; }
.player-screen { position: relative; display: flex; justify-content: center; background: radial-gradient(ellipse at center, #24242d, #0c0c10); }
.player-screen video { display: block; width: 100%; max-height: 53vh; object-fit: contain; }
.player-play-large { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 64px; height: 64px; border: 1px solid #ffffff30; border-radius: 50%; background: #ff6b35e6; color: white; display: grid; place-items: center; cursor: pointer; box-shadow: 0 8px 30px #0005; }
.player-play-large svg { width: 28px; height: 28px; }
.player-controls { padding: 10px 16px 12px; }
.player-seek { width: 100%; display: block; accent-color: var(--color-accent, #ff6b35); cursor: pointer; margin: 0 0 8px; }
.player-controls-row { display: flex; align-items: center; gap: 10px; }
.player-controls-row button { display: grid; place-items: center; width: 36px; height: 36px; border: 0; border-radius: 8px; background: transparent; color: #e9e9ef; cursor: pointer; }
.player-controls-row button:hover { background: #ffffff12; }
.player-controls-row svg { width: 20px; height: 20px; }
.player-time { font-size: 12px; color: #eee; font-variant-numeric: tabular-nums; }
.player-time span { color: #90909e; }
.player-spacer { flex: 1; }
.player-error { margin: 0; padding: 8px 16px 16px; color: #fca5a5; font-size: 13px; }
button:focus-visible, input:focus-visible { outline: 2px solid var(--color-accent, #ff6b35); outline-offset: 3px; }
.finished-player:fullscreen { display: flex; flex-direction: column; justify-content: center; border: 0; border-radius: 0; }
.finished-player:fullscreen .player-screen { flex: 1; min-height: 0; }
.finished-player:fullscreen video { max-height: calc(100vh - 85px); }
</style>
