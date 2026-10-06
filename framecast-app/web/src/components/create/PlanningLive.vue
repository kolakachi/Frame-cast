<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
// The turn while it works (create-ui chat mockup's live step): steps already done in grey, the current one
// shimmering with the elapsed time, and the latest thing it found underneath. Steps come from the server as they happen.
const props = defineProps({ live: { type: Object, default: null }, startedAt: { type: Number, default: () => Date.now() }, fallback: { type: String, default: 'Reading your message' } })
const GLYPHS = ['·', '✢', '✳', '✶', '✻', '✽', '✻', '✶', '✳', '✢']
const now = ref(Date.now()), frame = ref(0)
let tick, spin
onMounted(() => {
  tick = setInterval(() => { now.value = Date.now() }, 1000)
  if (!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) spin = setInterval(() => { frame.value = (frame.value + 1) % GLYPHS.length }, 140)
})
onBeforeUnmount(() => { clearInterval(tick); clearInterval(spin) })
const steps = computed(() => props.live?.steps || [])
const done = computed(() => steps.value.slice(0, -1))
const current = computed(() => steps.value[steps.value.length - 1] || { label: props.fallback, items: [] })
const elapsed = computed(() => { const s = Math.max(0, Math.floor((now.value - (props.live?.started_at || props.startedAt)) / 1000)); return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s` })
</script>
<template>
  <div class="working" role="status" aria-live="polite">
    <ul v-if="done.length" class="done"><li v-for="(s, i) in done" :key="i">{{ s.label }}</li></ul>
    <p class="live">
      <span class="live-glyph" aria-hidden="true">{{ GLYPHS[frame] }}</span>
      <span class="label">{{ current.label }}…</span>
      <span class="time">({{ elapsed }})</span>
    </p>
    <p v-if="current.items?.length" class="found">{{ current.items[current.items.length - 1] }}</p>
  </div>
</template>
<style scoped>
.working{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#9b9b9b}
.done{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:4px;color:#6f6f6f}
.done li::before{content:"✓";margin-right:8px;color:#4cc38a}
.live{margin:0;display:flex;flex-wrap:wrap;align-items:baseline;gap:0 6px}
.live-glyph{display:inline-block;width:1em;text-align:center;color:#f26b3a;font-size:15px;line-height:1}
.label{color:#ececec;background:linear-gradient(90deg,#8a8a8a 0%,#ececec 50%,#8a8a8a 100%);background-size:200% 100%;-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:shimmer 2.4s linear infinite}
.time{font-variant-numeric:tabular-nums}
.found{margin:0 0 0 calc(1em + 6px);color:#6f6f6f}
@keyframes shimmer{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.label{animation:none;-webkit-text-fill-color:#ececec}}
</style>
