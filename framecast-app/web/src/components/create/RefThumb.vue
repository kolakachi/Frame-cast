<script setup>
import { ref, watch } from 'vue'
// The small square at the start of a reference pill: the image, the video's first frame, or an icon for the kind.
const props = defineProps({ type: String, url: String, link: Boolean, busy: Boolean })
const broken = ref(false)
watch(() => props.url, () => { broken.value = false })
</script>
<template>
  <span :class="['ref-thumb', { 'is-video': type === 'video' && !busy, 'is-busy': busy }]">
    <template v-if="!busy">
      <img v-if="type === 'image' && url && !broken" :src="url" alt="" loading="lazy" @error="broken = true" />
      <video v-else-if="type === 'video' && url && !broken" :src="url + '#t=0.5'" muted playsinline preload="metadata" aria-hidden="true" tabindex="-1" @error="broken = true" />
      <svg v-else-if="type === 'audio'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l10-2v12" /><circle cx="7" cy="18" r="2" /><circle cx="17" cy="16" r="2" /></svg>
      <svg v-else-if="link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 006 0l3-3a4 4 0 00-6-6l-1 1" /><path d="M14 10a4 4 0 00-6 0l-3 3a4 4 0 006 6l1-1" /></svg>
      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h8l4 4v16H6z" /><path d="M14 2v4h4" /></svg>
    </template>
  </span>
</template>
<style scoped>
.ref-thumb{position:relative;flex:0 0 auto;width:18px;height:18px;border-radius:2px;overflow:hidden;background:#2f3238;display:inline-flex;align-items:center;justify-content:center;color:#a7abb2}
.ref-thumb img,.ref-thumb video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.ref-thumb.is-video::after{content:"";position:absolute;border-left:5px solid #fff;border-top:3px solid transparent;border-bottom:3px solid transparent;filter:drop-shadow(0 0 1px rgba(0,0,0,.8))}
.ref-thumb svg{width:11px;height:11px}
.ref-thumb.is-busy::before{content:"";position:absolute;inset:3px;border-radius:999px;border:2px solid #555;border-top-color:#ececec;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.ref-thumb.is-busy::before{animation:none}}
</style>
