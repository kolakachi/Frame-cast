<script setup>
import { ref, watch, nextTick } from 'vue'
const props = defineProps({ warning: { type: Object, default: null } })
const emit = defineEmits(['cancel', 'continue', 'update'])
const dialog = ref(null)
watch(() => props.warning, async value => {
  await nextTick()
  if (!dialog.value) return
  if (value && !dialog.value.open) dialog.value.showModal()
  else if (!value && dialog.value.open) dialog.value.close()
}, { immediate: true })
</script>
<template>
  <Teleport to="body">
    <dialog ref="dialog" class="export-update-dialog" aria-labelledby="export-update-title" @cancel.prevent="emit('cancel')">
      <template v-if="warning">
        <h2 id="export-update-title">{{ warning.busy ? 'Updating your video…' : (warning.uncertain ? 'Check your exported video' : 'Your video has newer edits') }}</h2>
        <p aria-live="polite">{{ warning.busy ? 'We’ll finish the updated video before continuing with your action. Closing this dialog does not cancel the export.' : warning.message }}</p>
        <div class="export-update-actions">
          <button type="button" @click="emit('cancel')">{{ warning.busy ? 'Close' : 'Cancel' }}</button>
          <button v-if="!warning.unavailable" type="button" :disabled="warning.busy" @click="emit('continue')">Continue with previous video</button>
          <button class="primary" type="button" :disabled="warning.busy" @click="emit('update')">{{ warning.busy ? 'Updating…' : 'Update video & continue' }}</button>
        </div>
      </template>
    </dialog>
  </Teleport>
</template>
<style scoped>
.export-update-dialog { color: var(--color-text-primary, #eee); background: var(--color-bg-card, #17171f); border: 1px solid var(--color-border, #30303c); border-radius: 16px; padding: 28px; width: min(560px, calc(100vw - 32px)); margin: auto; box-sizing: border-box; box-shadow: 0 24px 80px #0008; }
.export-update-dialog::backdrop { background: #000a; backdrop-filter: blur(5px); }
h2 { margin: 0 0 14px; font-size: 21px; } p { color: var(--color-text-secondary, #aaa); line-height: 1.6; }
.export-update-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin-top: 24px; }
button { padding: 11px 14px; background: transparent; border: 1px solid var(--color-border, #383844); border-radius: 8px; color: inherit; cursor: pointer; }
button.primary { background: var(--color-accent, #ff6b35); border-color: transparent; color: #fff; }
button:disabled { opacity: .5; cursor: wait; } button:focus-visible { outline: 2px solid var(--color-accent, #ff6b35); outline-offset: 3px; }
</style>
