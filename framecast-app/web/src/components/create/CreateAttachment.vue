<script setup>
import { ref, watch } from 'vue'
const props = defineProps({ asset: Object, removable: Boolean, disabled: Boolean })
defineEmits(['remove'])
// Preserve a playing attachment through unrelated conversation refreshes.
const src = ref(props.asset.preview_url || ''), failed = ref(false)
watch(() => props.asset.preview_url, value => { if (!src.value || failed.value) { src.value = value || ''; failed.value = false } })
</script>
<template>
  <article class="attachment-card">
    <div class="attachment-media">
      <img v-if="asset.asset_type === 'image' && src && !failed" :src="src" :alt="asset.title" @error="failed = true" />
      <video v-else-if="asset.asset_type === 'video' && src && !failed" :src="src" controls playsinline preload="metadata" :aria-label="asset.title" @error="failed = true" />
      <audio v-else-if="asset.asset_type === 'audio' && src && !failed" :src="src" controls preload="metadata" :aria-label="asset.title" @error="failed = true" />
      <span v-else>{{ failed ? 'Preview unavailable' : asset.asset_type === 'audio' ? '♫ Audio' : asset.asset_type === 'video' ? '▷ Video' : '▧ Image' }}</span>
    </div>
    <div class="attachment-info"><strong :title="asset.title">{{ asset.title }}</strong><small>{{ asset.purpose === 'source' ? 'Reuse in creation' : 'Inspiration only' }}</small></div>
    <button v-if="removable" type="button" :disabled="disabled" :aria-label="`Remove ${asset.title}`" @click="$emit('remove')">×</button>
  </article>
</template>
<style scoped>
.attachment-card{position:relative;border:1px solid #ffffff16;background:#181820;border-radius:12px;overflow:hidden;min-width:0}.attachment-media{height:110px;background:#0d0d12;display:flex;align-items:center;justify-content:center;font-size:12px;color:#a9a5b7}.attachment-media img,.attachment-media video{width:100%;height:100%;object-fit:contain}.attachment-media audio{width:100%;max-height:48px}.attachment-info{padding:10px 32px 12px 12px}.attachment-info strong{font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}.attachment-info small{display:block;color:#aaa5b6;font-size:11px;margin-top:5px}button{position:absolute;right:5px;bottom:13px;border:0;border-radius:6px;background:#ffffff09;color:#ddd;width:26px;height:26px;cursor:pointer}button:focus-visible{outline:2px solid #ff6b35}
</style>
