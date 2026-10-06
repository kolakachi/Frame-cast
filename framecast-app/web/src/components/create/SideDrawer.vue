<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
// Every side drawer in Create (the plan, recent conversations, this video's details), as in the create-ui chat
// mockup: a fixed header, a body that scrolls, and a footer that always stays in view.
const props = defineProps({ open: Boolean, title: { type: String, default: '' }, meta: { type: String, default: '' } })
const emit = defineEmits(['close'])
const dialog = ref(null)
let previousFocus
watch(() => props.open, async open => {
  await nextTick()
  if (!dialog.value) return
  if (open && !dialog.value.open) { previousFocus = document.activeElement; dialog.value.showModal() }
  else if (!open && dialog.value.open) { dialog.value.close(); previousFocus?.focus?.() }
}, { immediate: true })
onBeforeUnmount(() => dialog.value?.open && dialog.value.close())
</script>
<template>
  <dialog ref="dialog" class="plan-drawer" :aria-label="title" @cancel.prevent="emit('close')" @click="e => { if (e.target === dialog) emit('close') }">
    <div v-if="open" class="pd">
      <header class="pd-head">
        <div class="pd-titles"><h2>{{ title }}</h2><span v-if="meta" class="pd-meta">{{ meta }}</span></div>
        <button type="button" class="pd-x" :aria-label="`Close ${title}`" @click="emit('close')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
      </header>
      <div class="pd-body"><slot /></div>
      <footer v-if="$slots.footer" class="pd-foot"><slot name="footer" /></footer>
    </div>
  </dialog>
</template>
<style scoped>
/* The drawers' surface and the app's lines (CreateView's .fc-shell tokens), with fallbacks. */
.plan-drawer{margin:0 0 0 auto;padding:0;border:0;border-left:1px solid var(--line-3,#2c313b);width:min(420px,100vw);height:100dvh;max-height:100dvh;background:var(--surface,#17171f);color:var(--text,#eceef1);overflow:hidden}
.plan-drawer::backdrop{background:#0008}
.pd{display:flex;flex-direction:column;height:100%;font-size:13px;line-height:1.5}
.pd-head{flex:0 0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px 12px;border-bottom:1px solid var(--line-2,#262b34)}
.pd-titles{display:flex;flex-direction:column;gap:2px;min-width:0}
h2{margin:0;font-size:15px;font-weight:600}
.pd-meta{color:var(--text-3,#8f95a1);font-size:12px}
.pd-x{flex:0 0 auto;width:28px;height:28px;border-radius:6px;border:0;background:transparent;color:#9b9b9b;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.pd-x:hover{background:var(--bg-4,#191d24);color:var(--text,#eceef1)}
.pd-x:focus-visible{outline:2px solid #ff6b35}
.pd-body{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:6px 18px 18px}
.pd-foot{flex:0 0 auto;display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 18px calc(12px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--line-2,#262b34);background:var(--surface,#17171f)}
</style>
