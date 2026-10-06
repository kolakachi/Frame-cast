<script setup>
// One collapsible part of the plan: a single line (caret, name, what it holds) that opens to the controls.
defineProps({ title: String, summary: { type: String, default: '' }, open: Boolean })
</script>
<template>
  <details class="pg" :open="open">
    <summary>
      <svg class="pg-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
      <span class="pg-title">{{ title }}</span>
      <span v-if="summary" class="pg-sum">{{ summary }}</span>
      <slot name="aside" />
    </summary>
    <div class="pg-body"><slot /></div>
  </details>
</template>
<style scoped>
.pg{border-bottom:1px solid var(--line-2,#262b34)}
.pg>summary{list-style:none;display:flex;align-items:center;gap:8px;padding:11px 0;cursor:pointer;min-width:0}
.pg>summary::-webkit-details-marker{display:none}
.pg>summary:focus-visible{outline:2px solid #ff6b35;outline-offset:2px;border-radius:4px}
.pg-caret{width:13px;height:13px;flex:0 0 auto;color:#8e8e8e;transition:transform .15s}
.pg[open]>summary .pg-caret{transform:rotate(90deg)}
.pg-title{font-weight:600;flex:0 0 auto}
.pg-sum{color:#8e8e8e;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-left:auto;text-align:right}
.pg-body{display:flex;flex-direction:column;gap:8px;padding:0 0 14px 21px}
@media (prefers-reduced-motion:reduce){.pg-caret{transition:none}}
</style>
