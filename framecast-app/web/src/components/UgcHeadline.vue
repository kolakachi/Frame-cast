<script setup>
import { computed } from 'vue'
const props = defineProps({ layout: { type: Object, default: () => ({}) }, aspectRatio: { type: String, default: '9:16' } })
// Layout comes from the same server helper that generates the ASS overlay.
const height = computed(() => { const [w, h] = props.aspectRatio.split(':').map(Number); return w > 0 && h > 0 ? 1080 * h / w : 1920 })
const fontSize = computed(() => 1080 * (props.layout.font_width_ratio || 0.041))
</script>

<template>
  <svg v-if="layout.text && layout.lines?.length" class="ugc-headline" :viewBox="`0 0 1080 ${height}`" aria-hidden="true">
    <text v-for="(line, i) in layout.lines" :key="i" x="540"
      :y="height * (layout.top_ratio || 0.10) + fontSize + i * fontSize * (layout.line_height || 1.2)"
      :font-size="fontSize" :font-family="layout.font || 'Arial'" font-weight="700"
      fill="white" stroke="black" :stroke-width="1080 * (layout.stroke_width_ratio || 0.002) * 2"
      paint-order="stroke fill" stroke-linejoin="round" text-anchor="middle">{{ line }}</text>
  </svg>
</template>

<style scoped>
.ugc-headline { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 5; }
</style>
