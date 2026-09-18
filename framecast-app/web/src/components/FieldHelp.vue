<script setup>
defineProps({ text: { type: String, required: true } })
</script>
<template><span class="field-help"><button type="button" class="help-trigger" :aria-label="text">?</button><span class="help-tip" role="tooltip">{{text}}</span></span></template>
<style scoped>
.field-help{position:relative;display:inline-flex;margin-left:6px;vertical-align:middle}.help-trigger{border:1px solid var(--color-border,#41414e);border-radius:50%;width:18px;height:18px;padding:0;background:transparent;color:var(--color-text-muted,#aaa);font-size:11px;cursor:help}.help-tip{display:none;position:absolute;bottom:calc(100% + 8px);left:0;width:220px;max-width:60vw;padding:10px;border:1px solid var(--color-border,#41414e);border-radius:8px;background:var(--color-bg-panel,#252531);color:var(--color-text-primary,#eee);font-size:12px;font-weight:normal;line-height:1.5;z-index:60;box-shadow:0 6px 24px #0006}.field-help:hover .help-tip,.field-help:focus-within .help-tip{display:block}
</style>
