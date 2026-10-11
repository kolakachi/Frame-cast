<script setup>
// Where a presenter is filmed (owner, 2026-10-11): "Auto" lets the director pick; a place is passed to the video model
// as the kind of place only, so it can still choose its own spot, angle and time of day.
defineProps({
  settings: { type: Array, default: () => [] },
  modelValue: { type: String, default: null },
  // Compact: one scrolling row for the presenter's mini settings; otherwise a grid for the presenters window.
  compact: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <div :class="['ubp', { 'ubp--row': compact }]" role="radiogroup" aria-label="Background">
    <button type="button" role="radio" :aria-checked="!modelValue" :class="['ubp-auto', { on: !modelValue }]" @click="emit('update:modelValue', null)">
      <span class="ubp-spark">✦</span><span>Auto</span>
    </button>
    <button v-for="s in settings" :key="s.key" type="button" role="radio" :aria-checked="modelValue === s.key" :title="s.place"
      :class="['ubp-tile', { on: modelValue === s.key }]" @click="emit('update:modelValue', s.key)">
      <img :src="s.image_url" alt="" loading="lazy" />
      <span>{{ s.label }}</span>
    </button>
  </div>
</template>

<style scoped>
.ubp { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); grid-auto-rows: max-content; gap: 6px; }
.ubp--row { display: flex; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; }
.ubp-auto, .ubp-tile { position: relative; font: inherit; padding: 0; border-radius: 8px; border: 1px solid var(--color-border-active); background: var(--color-bg-soft); color: var(--color-text-secondary); cursor: pointer; overflow: hidden; aspect-ratio: 9 / 14; }
.ubp--row .ubp-auto, .ubp--row .ubp-tile { flex: 0 0 52px; }
.ubp-auto { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-size: 11px; }
.ubp-spark { color: var(--color-accent); font-size: 14px; }
.ubp-tile img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
.ubp-tile span { position: absolute; left: 0; right: 0; bottom: 0; padding: 10px 4px 3px; font-size: 10px; line-height: 1.15; color: #fff; text-align: center; background: linear-gradient(transparent, rgba(0, 0, 0, .75)); }
.ubp-auto.on, .ubp-tile.on { border-color: var(--color-accent); box-shadow: 0 0 0 1px var(--color-accent); color: var(--color-text-primary); }
.ubp-auto:hover, .ubp-tile:hover { border-color: var(--color-accent); }
</style>
