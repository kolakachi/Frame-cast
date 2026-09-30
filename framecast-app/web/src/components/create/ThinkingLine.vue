<script setup>
// A live "working" line in the conversation, in the spirit of Claude Code:
// a pulsing mark, the current step, elapsed time and what it is working on.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  label: { type: String, default: '' },      // fixed step text (e.g. the worker's live stage)
  steps: { type: Array, default: () => [] },  // or steps that advance over time
  detail: { type: String, default: '' },
  startedAt: { type: [Number, String, Date], default: null },
  stepSeconds: { type: Number, default: 6 },
})

const GLYPHS = ['·', '✢', '✳', '✶', '✻', '✽', '✻', '✶', '✳', '✢']
const mounted = Date.now()
const now = ref(Date.now()), frame = ref(0)
let tick, spin
const start = computed(() => {
  const t = props.startedAt instanceof Date ? props.startedAt.getTime() : typeof props.startedAt === 'string' ? Date.parse(props.startedAt.replace(' ', 'T') + (/[zZ+]/.test(props.startedAt) ? '' : 'Z')) : props.startedAt
  return Number.isFinite(t) ? t : mounted
})
const seconds = computed(() => Math.max(0, Math.floor((now.value - start.value) / 1000)))
const elapsed = computed(() => seconds.value < 60 ? `${seconds.value}s` : `${Math.floor(seconds.value / 60)}m ${seconds.value % 60}s`)
const text = computed(() => props.label || props.steps[Math.min(props.steps.length - 1, Math.floor((now.value - mounted) / 1000 / props.stepSeconds))] || 'Working')

onMounted(() => {
  tick = setInterval(() => { now.value = Date.now() }, 1000)
  if (!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) spin = setInterval(() => { frame.value = (frame.value + 1) % GLYPHS.length }, 120)
})
onBeforeUnmount(() => { clearInterval(tick); clearInterval(spin) })
</script>

<template>
  <div class="thinking" role="status" aria-live="polite">
    <span class="thinking__glyph" aria-hidden="true">{{ GLYPHS[frame] }}</span>
    <div class="thinking__body">
      <div class="thinking__line"><span class="thinking__label">{{ text }}…</span><span class="thinking__time">({{ elapsed }})</span></div>
      <div v-if="detail" class="thinking__detail">{{ detail }}</div>
    </div>
  </div>
</template>

<style scoped>
.thinking{display:flex;gap:10px;align-items:flex-start;padding:4px 2px}
.thinking__glyph{width:16px;flex-shrink:0;color:var(--accent,#ff6b35);font-size:16px;line-height:20px;text-align:center}
.thinking__body{min-width:0;display:flex;flex-direction:column;gap:2px}
.thinking__line{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
.thinking__label{font-size:14px;font-weight:600;line-height:20px;background:linear-gradient(90deg,var(--text-2,#b7bcc6) 0%,var(--text,#eceef1) 40%,var(--text-2,#b7bcc6) 80%);background-size:200% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:shimmer 2.4s linear infinite}
.thinking__time{font-size:12px;color:var(--text-4,#5d6472);font-family:var(--mono,ui-monospace,monospace)}
.thinking__detail{font-size:12px;color:var(--text-3,#8f95a1);overflow-wrap:anywhere}
@keyframes shimmer{from{background-position:100% 0}to{background-position:-100% 0}}
@media (prefers-reduced-motion: reduce){.thinking__label{animation:none;color:var(--text,#eceef1);background:none}}
</style>
