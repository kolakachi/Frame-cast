<script setup>
import { watch, nextTick } from 'vue'
import RefThumb from './RefThumb.vue'
import { useRefRow } from './useRefRow.js'
// The files a brief was sent with, as one row of small pills inside the message (create-ui chat mockup).
const props = defineProps({ assets: { type: Array, default: () => [] } })
const { row, fadeStart, fadeEnd, update } = useRefRow()
watch(() => props.assets.length, () => nextTick(update))
const ROLE = { source: 'Used in the video', reference: 'Reference only' }
// What a link is used for (the API's link_role), so a wrong link shows before planning.
const LINK = { style: 'style reference', facts: 'facts & look', look: 'look only' }
</script>
<template>
  <div ref="row" :class="['refs', { 'fade-start': fadeStart, 'fade-end': fadeEnd }]" role="list" aria-label="References" :tabindex="fadeEnd || fadeStart ? 0 : undefined" @scroll.passive="update">
    <span v-for="a in assets" :key="a.asset_id" class="ref" role="listitem" :title="[a.title, ROLE[a.purpose]].filter(Boolean).join(' · ')">
      <RefThumb :type="a.asset_type" :url="a.preview_url" :link="!!a.source?.requested_url" />
      <span class="ref-name">{{ a.title }}</span><span v-if="LINK[a.link_role]" class="ref-role">{{ LINK[a.link_role] }}</span>
    </span>
  </div>
</template>
<style scoped>
.refs{display:flex;gap:6px;overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x proximity;scrollbar-width:none;margin:0 -20px;padding:0 20px;scroll-padding:0 20px}
.refs::-webkit-scrollbar{display:none}
.refs:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
.refs.fade-end{-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,#000 calc(100% - 40px),transparent)}
.refs.fade-start{-webkit-mask-image:linear-gradient(to right,transparent,#000 40px);mask-image:linear-gradient(to right,transparent,#000 40px)}
.refs.fade-start.fade-end{-webkit-mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent);mask-image:linear-gradient(to right,transparent,#000 40px,#000 calc(100% - 40px),transparent)}
.ref{flex:0 0 auto;max-width:220px;scroll-snap-align:start;font:11px/1.5 ui-monospace,"SF Mono","JetBrains Mono",Menlo,Consolas,monospace;color:#a7abb2;background:#222428;border-radius:4px;padding:2px 8px 2px 2px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.ref-name{overflow:hidden;text-overflow:ellipsis;min-width:0}
.ref-role{flex:0 0 auto;margin-left:6px;color:#ff8a5c}
</style>
