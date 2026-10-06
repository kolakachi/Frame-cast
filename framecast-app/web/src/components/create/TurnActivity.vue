<script setup>
import { computed } from 'vue'
// What a turn did before its answer (create-ui chat mockup): "Worked for 1m 12s", then the steps folded into one
// line ("Sorted your files, watched reference-ugc.mp4, planned 5 shots") that opens to each step and what it found.
const props = defineProps({ activity: { type: Object, default: null } })
const steps = computed(() => (props.activity?.steps || []).filter(s => s.label))
const worked = computed(() => {
  const s = Math.max(1, Math.round((props.activity?.worked_ms || 0) / 1000))
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s`
})
const line = computed(() => steps.value.map((s, i) => i ? s.label.charAt(0).toLowerCase() + s.label.slice(1) : s.label).join(', '))
const detailed = computed(() => steps.value.some(s => s.items?.length) || steps.value.length > 1)
</script>
<template>
  <div v-if="activity" class="turn">
    <div class="turn-status">Worked for {{ worked }}</div>
    <details v-if="steps.length" class="activity">
      <summary>
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z" /><path d="M13.5 6.5l4 4" /></svg>
        <span>{{ line }}</span>
        <svg v-if="detailed" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
      </summary>
      <ol v-if="detailed" class="steps">
        <li v-for="(s, i) in steps" :key="i">
          <span class="step">{{ s.label }}</span>
          <ul v-if="s.items?.length"><li v-for="(it, k) in s.items" :key="k">{{ it }}</li></ul>
        </li>
      </ol>
    </details>
  </div>
</template>
<style scoped>
.turn{display:flex;flex-direction:column;gap:14px}
.turn-status{color:#9b9b9b;font-size:13px;padding-bottom:12px;border-bottom:1px solid #333}
.activity{color:#9b9b9b;font-size:13px}
.activity summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:10px;padding:4px 0;border-radius:6px}
.activity summary::-webkit-details-marker{display:none}
.activity summary:hover,.activity[open] summary{color:#ececec}
.activity summary:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
.icon{width:18px;height:18px;flex-shrink:0}
.chev{width:14px;height:14px;flex-shrink:0;transition:transform .15s}
.activity[open] .chev{transform:rotate(90deg)}
.steps{margin:8px 0 0 28px;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}
.step{color:#b5b5b5}
.steps ul{margin:4px 0 0;padding:0 0 0 14px;list-style:none;display:flex;flex-direction:column;gap:3px;color:#6f6f6f;border-left:1px solid #333}
@media (prefers-reduced-motion:reduce){.chev{transition:none}}
</style>
