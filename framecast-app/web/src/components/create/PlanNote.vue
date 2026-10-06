<script setup>
// Small print, folded: a caret and a few words; the full text only when asked for.
defineProps({ label: { type: String, default: 'More' }, text: { type: String, default: '' } })
</script>
<template>
  <details v-if="text || $slots.default" class="pn">
    <summary><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>{{ label }}</summary>
    <p v-if="text">{{ text }}</p><slot />
  </details>
</template>
<style scoped>
.pn{font-size:12px;color:#8e8e8e}
.pn summary{list-style:none;display:inline-flex;align-items:center;gap:4px;cursor:pointer}
.pn summary::-webkit-details-marker{display:none}
.pn summary:hover{color:#c9cbcf}
.pn summary:focus-visible{outline:2px solid #ff6b35;outline-offset:2px;border-radius:3px}
.pn svg{width:11px;height:11px;transition:transform .15s}
.pn[open] svg{transform:rotate(90deg)}
.pn p{margin:4px 0 0 15px;line-height:1.5}
@media (prefers-reduced-motion:reduce){.pn svg{transition:none}}
</style>
