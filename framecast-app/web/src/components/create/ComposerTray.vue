<script setup>
import { watch, nextTick } from 'vue'
import RefThumb from './RefThumb.vue'
import { useRefRow } from './useRefRow.js'
// Files waiting to go with the next message, as one row of pills inside the prompt box (create-ui chat mockup).
// items: {key, title, type, url, link, purpose, uploading, progress, error, note}. A file's role is read from the brief
// when it is sent, so the pill only says so once it is decided.
const props = defineProps({ items: { type: Array, default: () => [] }, disabled: Boolean })
const emit = defineEmits(['remove'])
const { row, fadeStart, fadeEnd, update } = useRefRow()
watch(() => props.items.length, (n, before) => nextTick(() => { update(); if (n > (before || 0) && row.value) row.value.scrollTo({ left: row.value.scrollWidth, behavior: 'smooth' }) }))
function remove(item, event) {
  // Focus moves to the next pill's ×, or back to the prompt when the row empties.
  const pill = event.currentTarget.closest('.att'), next = pill?.nextElementSibling || pill?.previousElementSibling
  emit('remove', item)
  nextTick(() => (next?.querySelector('.att-x') || document.getElementById('create-prompt'))?.focus())
}
const ROLE = { source: 'Used in the video', reference: 'Reference only' }
const tip = i => [i.title, ROLE[i.purpose], i.error, i.note].filter(Boolean).join(' · ')
</script>
<template>
  <div ref="row" :class="['refs', 'tray', { 'fade-start': fadeStart, 'fade-end': fadeEnd }]" role="list" aria-label="Attached references" @scroll.passive="update">
    <span v-for="i in items" :key="i.key" :class="['att', { 'is-uploading': i.uploading, 'is-error': i.error }]" role="listitem" :title="tip(i)">
      <RefThumb :type="i.type" :url="i.url" :link="i.link" :busy="i.uploading" />
      <span class="att-name">{{ i.title }}<template v-if="i.uploading"> · {{ i.progress }}%</template></span>
      <button v-if="!i.uploading" type="button" class="att-x" :disabled="disabled" :aria-label="`Remove ${i.title}`" @click="remove(i, $event)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
    </span>
  </div>
</template>
<style scoped>
.refs{display:flex;gap:6px;overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x proximity;scrollbar-width:none;margin:0 -14px;padding:0 14px 2px;scroll-padding:0 14px}
.refs::-webkit-scrollbar{display:none}
.refs.fade-end{-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent)}
.refs.fade-start{-webkit-mask-image:linear-gradient(to right,transparent,#000 40px);mask-image:linear-gradient(to right,transparent,#000 40px)}
.refs.fade-start.fade-end{-webkit-mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent)}
.att{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;max-width:220px;height:26px;padding:0 2px 0 3px;border-radius:4px;background:#333;font-size:12px;color:#c9cbcf;scroll-snap-align:start}
.att :deep(.ref-thumb){width:20px;height:20px}
.att-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.att-x{flex-shrink:0;width:22px;height:22px;border:0;border-radius:3px;background:transparent;color:#9b9b9b;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0}
.att-x:hover{background:#444;color:#ececec}
.att-x:focus-visible{outline:2px solid #ff6b35}
.att.is-uploading .att-name{color:#9b9b9b}
.att.is-error{background:#3a2424;color:#f0b4ac}
</style>
