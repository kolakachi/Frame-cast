<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
const props = defineProps({ open: Boolean, title: String, drawer: Boolean, docked: Boolean })
const emit = defineEmits(['close'])
const dialog = ref(null), wide = ref(false)
let previousFocus, query, ticket = 0, wasModal = false
async function sync() {
  const current = ++ticket
  await nextTick()
  if(current !== ticket || !dialog.value) return
  const modal = !(props.docked && wide.value)
  if(props.open) {
    if(dialog.value.open && modal === wasModal) return
    if(!dialog.value.open) previousFocus = document.activeElement
    else dialog.value.close()
    modal ? dialog.value.showModal() : dialog.value.show()
    wasModal = modal
  } else if(dialog.value.open) { dialog.value.close(); previousFocus?.focus?.() }
}
function resize() {wide.value = query.matches; sync()}
watch(() => props.open, sync)
onMounted(() => {query = matchMedia('(min-width: 1280px)');wide.value = query.matches;query.addEventListener('change',resize);sync()})
onBeforeUnmount(() => {ticket++;query?.removeEventListener('change',resize);dialog.value?.close()})
</script>
<template>
  <dialog ref="dialog" :class="{ drawer, docked }" :aria-label="title" :aria-modal="docked && wide ? undefined : true" @cancel.prevent="emit('close')" @keydown.esc.stop.prevent="emit('close')" @click="e => { if (e.target === dialog) emit('close') }">
    <header><h2>{{ title }}</h2><button type="button" :aria-label="`Close ${title}`" @click="emit('close')">×</button></header>
    <div v-if="open" class="dialog-body"><slot /></div>
  </dialog>
</template>
<style scoped>
/* The app reset zeroes every margin, which drops a dialog's own centring and pins it to the top-left (2026-10-08). */
dialog{margin:auto;background:var(--color-bg-secondary,#17171f);color:var(--color-text-primary,#ededf4);border:1px solid var(--color-border,#33313e);border-radius:18px;padding:0;width:min(620px,calc(100vw - 28px));max-height:88dvh;box-shadow:0 24px 90px #0008}dialog::backdrop{background:#050509b5;backdrop-filter:blur(4px)}dialog.drawer{margin:0 0 0 auto;height:100dvh;max-height:100dvh;width:min(440px,100vw);border-radius:20px 0 0 20px}header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px;border-bottom:1px solid #ffffff12}h2{font-size:18px;margin:0}header button{background:#ffffff08;border:1px solid #ffffff15;border-radius:9px;color:inherit;width:34px;height:34px;font-size:23px;cursor:pointer}.dialog-body{padding:22px}button:focus-visible{outline:2px solid #ff6b35;outline-offset:3px}dialog[open]{animation:cd-in .2s cubic-bezier(.2,.8,.2,1)}dialog.drawer[open]{animation-name:cd-slide}dialog[open]::backdrop{animation:cd-fade .2s ease-out}@keyframes cd-in{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}@keyframes cd-slide{from{opacity:0;transform:translateX(32px)}to{opacity:1;transform:none}}@keyframes cd-fade{from{opacity:0}to{opacity:1}}@media(prefers-reduced-motion:reduce){dialog[open],dialog[open]::backdrop{animation:none}}@media(min-width:1280px){dialog.docked{position:fixed;inset:120px 24px 24px auto;z-index:20;width:330px;height:calc(100dvh - 144px);border-radius:16px;box-shadow:none}}
</style>
