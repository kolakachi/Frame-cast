<script setup>
// Custom dropdown that fully replaces a native <select> — styled trigger AND
// styled option list (the native open list can't be themed). Matches the
// composer pill-menu look so editor dropdowns read as one consistent UI.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  placeholder: { type: String, default: 'Select…' },
  align: { type: String, default: 'right' }, // menu edge to anchor to
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const root = ref(null)

const selectedLabel = computed(() => {
  const found = props.options.find((o) => String(o.value) === String(props.modelValue))
  return found ? found.label : props.placeholder
})

function toggle() { open.value = !open.value }
function pick(value) { emit('update:modelValue', value); open.value = false }
function onDocClick(e) { if (root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }

onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey) })
</script>

<template>
  <div class="ui-select" ref="root">
    <button type="button" class="ui-select-trigger" :class="{ open }" @click.stop="toggle">
      <span class="ui-select-value">{{ selectedLabel }}</span>
      <span class="ui-select-caret">▾</span>
    </button>
    <div v-if="open" :class="['ui-select-menu', align === 'left' ? 'ui-select-menu--left' : '']">
      <button
        v-for="o in options"
        :key="o.value"
        type="button"
        :class="['ui-select-option', String(o.value) === String(modelValue) ? 'selected' : '']"
        @click.stop="pick(o.value)"
      >
        <span>{{ o.label }}</span>
        <span v-if="String(o.value) === String(modelValue)" class="ui-select-check">✓</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.ui-select { position: relative; display: inline-block; }
.ui-select-trigger {
  display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
  min-width: 120px; padding: 7px 10px; border-radius: 8px;
  border: 1px solid var(--color-border, #2a2a35); background: var(--color-bg-card, #16161d);
  color: var(--color-text-primary, #e8e8ee); font-size: 12.5px; font-family: inherit;
  cursor: pointer; transition: border-color 0.15s, color 0.15s;
}
.ui-select-trigger:hover, .ui-select-trigger.open { border-color: rgba(255, 107, 53, 0.45); }
.ui-select-value { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ui-select-caret { font-size: 9px; opacity: 0.6; transition: transform 0.15s; }
.ui-select-trigger.open .ui-select-caret { transform: rotate(180deg); }

.ui-select-menu {
  position: absolute; top: calc(100% + 6px); right: 0; min-width: 100%;
  background: var(--color-bg-panel, #1c1c26); border: 1px solid var(--color-border, #2a2a35);
  border-radius: 8px; padding: 4px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4); z-index: 50;
  max-height: 260px; overflow-y: auto;
}
.ui-select-menu--left { right: auto; left: 0; }
.ui-select-option {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  width: 100%; padding: 8px 10px; border: none; background: transparent;
  color: var(--color-text-primary, #e8e8ee); font-size: 12.5px; font-family: inherit;
  cursor: pointer; border-radius: 6px; text-align: left; white-space: nowrap;
}
.ui-select-option:hover { background: var(--color-bg-elevated, #23232e); }
.ui-select-option.selected { background: rgba(255, 107, 53, 0.08); color: var(--color-accent, #ff6b35); }
.ui-select-check { font-size: 11px; }
</style>
