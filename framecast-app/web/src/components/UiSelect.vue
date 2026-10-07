<script setup>
// Custom dropdown that fully replaces a native <select> — styled trigger AND
// styled option list (the native open list can't be themed). Matches the
// composer pill-menu look so editor dropdowns read as one consistent UI.
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

// Only one dropdown is open at a time across the page: opening one closes the one that was open.
let closeOpen = null

const props = defineProps({
  modelValue: { type: [String, Number, Array], default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  multiple: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  label: { type: String, default: 'Select an option' },
  placeholder: { type: String, default: 'Select…' },
  align: { type: String, default: 'right' }, // menu edge to anchor to
  drop: { type: String, default: 'auto' }, // 'auto' | 'up' | 'down'
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const dropUp = ref(false)
const root = ref(null)
// The open menu is placed against the screen (position: fixed), measured from its button, so a drawer's scrolling body
// or any overflow-hidden parent cannot clip its options.
const menuStyle = ref({})
const close = () => { open.value = false; if (closeOpen === close) closeOpen = null }

const MENU_MAX_H = 280 // keep in sync with .ui-select-menu max-height + margin

const selectedLabel = computed(() => {
  if (props.multiple) {
    const values = Array.isArray(props.modelValue) ? props.modelValue : []
    return values.length ? `${values.length} selected` : props.placeholder
  }
  const found = props.options.find((o) => String(o.value) === String(props.modelValue))
  return found ? found.label : props.placeholder
})

function toggle() {
  if (props.disabled) return
  if (!open.value) {
    // Decide direction before showing: explicit prop wins; otherwise flip up
    // when there isn't room below but there is above (bottom-of-screen selects).
    if (props.drop === 'up') {
      dropUp.value = true
    } else if (props.drop === 'down') {
      dropUp.value = false
    } else {
      const rect = root.value?.getBoundingClientRect()
      if (rect) {
        const below = window.innerHeight - rect.bottom
        dropUp.value = below < MENU_MAX_H && rect.top > below
      }
    }
    const r = root.value?.getBoundingClientRect()
    if (r) {
      // The menu stays inside the panel it sits in (a drawer, a dialog), not just the screen: it lines up with whichever
      // side of its button fits, never crosses the panel's edges, and is never wider than the panel.
      const panel = root.value.closest('dialog, aside, [role="dialog"]')?.getBoundingClientRect()
      const lo = Math.max(8, (panel?.left ?? 0) + 8), hi = Math.min(window.innerWidth - 8, (panel?.right ?? window.innerWidth) - 8)
      const room = Math.max(160, hi - lo)
      const width = Math.min(Math.max(r.width, 160), room)
      const maxWidth = Math.min(360, room)
      const preferLeft = props.align === 'left' || r.right - width < lo
      const left = Math.max(lo, Math.min(preferLeft ? r.left : r.right - width, hi - width))
      const rows = dropUp.value ? r.top - 14 : window.innerHeight - r.bottom - 14
      menuStyle.value = { left: left + 'px', minWidth: width + 'px', maxWidth: Math.max(width, Math.min(maxWidth, hi - left)) + 'px',
        maxHeight: Math.max(120, Math.min(260, rows)) + 'px', ...(dropUp.value ? { bottom: (window.innerHeight - r.top + 6) + 'px' } : { top: (r.bottom + 6) + 'px' }) }
    }
    if (closeOpen && closeOpen !== close) closeOpen()
    closeOpen = close
    open.value = true
    return
  }
  close()
}
function selected(value) { return props.multiple ? (props.modelValue || []).some(v => String(v) === String(value)) : String(value) === String(props.modelValue) }
function pick(value) {
  if (props.disabled) return
  if (props.multiple) {
    const values = Array.isArray(props.modelValue) ? props.modelValue : []
    emit('update:modelValue', selected(value) ? values.filter(v => String(v) !== String(value)) : [...values, value])
  } else { emit('update:modelValue', value); close() }
}
function onDocClick(e) { if (open.value && root.value && !root.value.contains(e.target)) close() }
function onKey(e) { if (e.key === 'Escape' && open.value) close() }
// A fixed menu would drift from its button when the page or a drawer scrolls, or the window resizes: it closes instead.
function onScroll(e) { if (open.value && !(e.target instanceof Node && root.value?.contains(e.target))) close() }

onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey); window.addEventListener('scroll', onScroll, true); window.addEventListener('resize', close) })
onBeforeUnmount(() => { if (closeOpen === close) closeOpen = null; document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey); window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', close) })
</script>

<template>
  <div class="ui-select" ref="root">
    <button type="button" class="ui-select-trigger" :disabled="disabled" :aria-label="label" :aria-expanded="open" :class="{ open }" @click.stop="toggle">
      <span class="ui-select-value">{{ selectedLabel }}</span>
      <span class="ui-select-caret">▾</span>
    </button>
    <div v-if="open && !disabled" class="ui-select-menu" :style="menuStyle" role="listbox" :aria-label="label">
      <button
        v-for="o in options"
        :key="o.value"
        type="button"
        :class="['ui-select-option', selected(o.value) ? 'selected' : '']"
        :aria-pressed="selected(o.value)"
        @click.stop="pick(o.value)"
      >
        <span>{{ o.label }}</span>
        <span v-if="selected(o.value)" class="ui-select-check">✓</span>
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
.ui-select-trigger:disabled { opacity: .5; cursor: not-allowed; }
.ui-select-trigger:hover, .ui-select-trigger.open { border-color: rgba(255, 107, 53, 0.45); }
.ui-select-value { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ui-select-caret { font-size: 9px; opacity: 0.6; transition: transform 0.15s; }
.ui-select-trigger.open .ui-select-caret { transform: rotate(180deg); }

.ui-select-menu {
  position: fixed; z-index: 1000;
  background: var(--color-bg-panel, #1c1c26); border: 1px solid var(--color-border, #2a2a35);
  border-radius: 8px; padding: 4px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
  max-height: 260px; overflow-y: auto; overscroll-behavior: contain;
}
.ui-select-option {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  width: 100%; padding: 8px 10px; border: none; background: transparent;
  color: var(--color-text-primary, #e8e8ee); font-size: 12.5px; font-family: inherit;
  cursor: pointer; border-radius: 6px; text-align: left; white-space: normal; line-height: 1.35;
}
.ui-select-option:hover { background: var(--color-bg-elevated, #23232e); }
.ui-select-option.selected { background: rgba(255, 107, 53, 0.08); color: var(--color-accent, #ff6b35); }
.ui-select-check { font-size: 11px; }
</style>
