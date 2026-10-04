<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'

const props = defineProps({ src: { type: String, required: true } })
const frames = ref([]), selected = ref(0), loading = ref(false), error = ref('')
let dispose = () => {}
watch(() => props.src, async src => {
  dispose()
  let cancelled = false
  const video = document.createElement('video')
  const urls = []
  let abortWait = () => {}
  dispose = () => { cancelled = true; abortWait(); video.removeAttribute('src'); video.load(); urls.forEach(URL.revokeObjectURL) }
  frames.value = []; selected.value = 0; error.value = ''; loading.value = Boolean(src)
  if (!src) return
  const wait = event => new Promise((resolve, reject) => {
    const clean = () => { clearTimeout(timer); video.removeEventListener(event, done); video.removeEventListener('error', fail) }
    const done = () => { clean(); resolve() }
    const fail = () => { clean(); reject(new Error('frame unavailable')) }
    const timer = setTimeout(fail, 15000)
    abortWait = fail
    video.addEventListener(event, done, { once: true }); video.addEventListener('error', fail, { once: true })
  })
  try {
    // The version link lives on the API's origin; frames can only be copied from a CORS-enabled request.
    if (!src.startsWith('blob:')) video.crossOrigin = 'anonymous'
    video.muted = true; video.preload = 'auto'; video.playsInline = true
    const ready = wait('loadeddata'); video.src = src; await ready
    if (cancelled) return
    const duration = video.duration
    if (!Number.isFinite(duration) || duration <= 0) throw new Error('invalid duration')
    const canvas = document.createElement('canvas')
    const scale = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight))
    canvas.width = Math.max(1, Math.round(video.videoWidth * scale)); canvas.height = Math.max(1, Math.round(video.videoHeight * scale))
    const context = canvas.getContext('2d')
    // Sample the held designs at evenly spaced midpoints, avoiding transition boundaries.
    const count = Math.min(8, Math.max(3, Math.ceil(duration / 3)))
    for (let i = 0; i < count; i++) {
      if (cancelled) return
      const time = duration * (i + 0.5) / count
      const seeked = wait('seeked'); video.currentTime = time; await seeked
      if (cancelled) return
      context.drawImage(video, 0, 0, canvas.width, canvas.height)
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9))
      if (cancelled) return
      if (!blob) throw new Error('frame unavailable')
      const url = URL.createObjectURL(blob); urls.push(url)
      frames.value = [...frames.value, { url, time }]
    }
  } catch { if (!cancelled) error.value = 'Some storyboard frames could not be loaded. Reopen this version to try again.' }
  finally { if (!cancelled) loading.value = false }
}, { immediate: true })
onBeforeUnmount(() => dispose())
function move(delta) { selected.value = Math.max(0, Math.min(frames.value.length - 1, selected.value + delta)) }
</script>

<template>
  <section class="storyboard" aria-label="Storyboard preview">
    <p class="storyboard__intro">What to expect · browse the still designs. Motion and audio come after approval.</p>
    <p v-if="loading && !frames.length" role="status">Preparing storyboard frames…</p>
    <template v-if="frames.length">
      <div class="storyboard__frame" tabindex="0" aria-label="Storyboard frames. Use left and right arrow keys to browse." @keydown.left.prevent="move(-1)" @keydown.right.prevent="move(1)">
        <img :src="frames[selected].url" :alt="`Storyboard frame ${selected + 1}`" />
      </div>
      <div class="storyboard__nav">
        <button type="button" aria-label="Previous frame" :disabled="selected === 0" @click="move(-1)">←</button>
        <span aria-live="polite">Frame {{ selected + 1 }} of {{ frames.length }} · {{ frames[selected].time.toFixed(1) }}s{{ loading ? ' · loading more…' : '' }}</span>
        <button type="button" aria-label="Next frame" :disabled="selected === frames.length - 1" @click="move(1)">→</button>
      </div>
      <div class="storyboard__strip" aria-label="Choose a storyboard frame">
        <button v-for="(frame, index) in frames" :key="frame.url" type="button" :aria-label="`View frame ${index + 1}`" :aria-pressed="selected === index" @click="selected = index"><img :src="frame.url" alt="" /><span>{{ index + 1 }}</span></button>
      </div>
    </template>
    <p v-if="error" role="status">{{ error }}</p>
  </section>
</template>

<style scoped>
.storyboard{width:100%;padding:16px;color:var(--text-2);box-sizing:border-box}.storyboard__intro{font-size:13px;margin:0 0 14px}.storyboard__frame{background:var(--bg-1,#090a0e);border-radius:12px;overflow:hidden;display:flex;justify-content:center}.storyboard__frame>img{display:block;max-width:100%;height: min(52vh,480px);object-fit:contain}.storyboard__nav{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;font-size:12px}.storyboard button{color:var(--text-1);background:var(--bg-3,#181a21);border:1px solid var(--line-3,#32343d);border-radius:8px;cursor:pointer}.storyboard__nav button{padding:8px 14px}.storyboard button:disabled{opacity:.35;cursor:default}.storyboard button:focus-visible,.storyboard__frame:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.storyboard__strip{display:flex;gap:8px;overflow-x:auto;padding:3px}.storyboard__strip button{padding:4px;flex:0 0 76px}.storyboard__strip button[aria-pressed=true]{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}.storyboard__strip img{width:66px;height:54px;object-fit:contain;display:block}.storyboard__strip span{font-size:11px}
</style>
