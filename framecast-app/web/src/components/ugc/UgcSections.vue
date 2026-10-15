<script setup>
// SECTIONS (owner, 2026-10-15): remake one section of a delivered one-take, charged per section, with one step back.
// A section is one clip; one that carried straight on from it is remade with it, and the price says so.
import { onBeforeUnmount, ref, watch } from 'vue'
import api from '../../services/api'

const props = defineProps({ takeId: { type: Number, required: true } })
const emit = defineEmits(['changed'])

const info = ref(null)
const open = ref(false)
const picked = ref(null)
const note = ref('')
const busy = ref(false)
const error = ref('')
let timer = null

function clock(s) { const n = Math.max(0, Math.round(s)); return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}` }

async function load() {
  try {
    const { data } = await api.get(`/ugc/takes/${props.takeId}/sections`)
    const wasRemaking = info.value?.in_progress
    info.value = data.data
    if (wasRemaking && !info.value.in_progress) emit('changed')
    clearTimeout(timer)
    if (info.value.in_progress) timer = setTimeout(load, 10000)
  } catch { info.value = null }
}

async function remake() {
  const sec = info.value?.sections?.[picked.value]
  if (!sec) return
  busy.value = true; error.value = ''
  try {
    await api.post(`/ugc/takes/${props.takeId}/sections/${sec.i}/remake`, { note: note.value.trim() || null, credits: sec.remake.credits })
    picked.value = null; note.value = ''
    await load()
  } catch (e) {
    error.value = e?.response?.data?.error?.message || 'The section could not be remade. Nothing was charged.'
    if (e?.response?.data?.error?.code === 'price_changed') await load()
  } finally { busy.value = false }
}

async function goBack() {
  busy.value = true; error.value = ''
  try { await api.post(`/ugc/takes/${props.takeId}/sections/revert`); await load(); emit('changed') }
  catch (e) { error.value = e?.response?.data?.error?.message || 'The earlier version could not be restored.' }
  finally { busy.value = false }
}

watch(() => props.takeId, load, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div v-if="info && (info.available || info.in_progress || info.can_go_back)" class="us">
    <p v-if="info.in_progress" class="us-state"><span class="us-spin" aria-hidden="true"></span> Remaking a section. Your video stays as it is until the new one is ready.</p>
    <template v-else>
      <button v-if="info.available" type="button" class="us-toggle" :aria-expanded="open" @click="open = !open">Remake a section <span aria-hidden="true">{{ open ? '▴' : '▾' }}</span></button>
      <div v-if="open && info.available" class="us-body">
        <div class="us-chips" role="radiogroup" aria-label="Section to remake">
          <button v-for="(s, k) in info.sections" :key="s.i" type="button" role="radio" :aria-checked="picked === k" :class="['us-chip', { on: picked === k }]" @click="picked = k">
            <b>{{ clock(s.start) }}–{{ clock(s.end) }}</b><span>{{ s.label || `Section ${k + 1}` }}</span>
          </button>
        </div>
        <template v-if="picked !== null">
          <input v-model="note" class="us-note" maxlength="500" placeholder="What should change? (optional) e.g. she smiles at the end" />
          <p v-if="info.sections[picked].remake.sections.length > 1" class="us-muted">The next {{ info.sections[picked].remake.sections.length - 1 === 1 ? 'section carries' : (info.sections[picked].remake.sections.length - 1) + ' sections carry' }} straight on from this one, so {{ info.sections[picked].remake.sections.length - 1 === 1 ? 'it is' : 'they are' }} remade too.</p>
          <button type="button" class="uc-btn uc-btn--primary uc-btn--sm" :disabled="busy" @click="remake">{{ busy ? 'Starting…' : `Remake · ${info.sections[picked].remake.credits} credits` }}</button>
          <p class="us-muted">Charged only if it is made. Kept until {{ new Date(info.until).toLocaleDateString() }}.</p>
        </template>
      </div>
    </template>
    <button v-if="info.can_go_back && !info.in_progress" type="button" class="us-link" :disabled="busy" @click="goBack">Use the earlier version</button>
    <p v-if="info.error && !info.in_progress" class="us-error">{{ info.error }}</p>
    <p v-if="error" class="us-error">{{ error }}</p>
  </div>
</template>

<style scoped>
.us { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.us-toggle, .us-link { background: none; border: 0; padding: 0; color: var(--color-accent, #ff6b35); font: inherit; font-size: 13px; cursor: pointer; text-align: left; }
.us-link:disabled { opacity: .6; cursor: default; }
.us-body { display: flex; flex-direction: column; gap: 8px; }
.us-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.us-chip { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; max-width: 220px; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--color-border); background: transparent; color: inherit; font: inherit; font-size: 12px; cursor: pointer; text-align: left; }
.us-chip span { color: var(--color-text-muted, #999); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
.us-chip.on { border-color: var(--color-accent, #ff6b35); }
.us-note { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--color-border); background: transparent; color: inherit; font: inherit; font-size: 13px; }
.us-muted { margin: 0; font-size: 12px; color: var(--color-text-muted, #999); }
.us-state { margin: 0; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.us-error { margin: 0; font-size: 12px; color: var(--color-danger, #e5484d); }
.us-spin { width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--color-border); border-top-color: var(--color-accent, #ff6b35); animation: us-spin 1s linear infinite; }
@keyframes us-spin { to { transform: rotate(360deg) } }
</style>
