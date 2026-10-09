<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import api from '../../services/api'
import { VOICE_DESCRIPTIONS } from '../../lib/voices.js'

// The card modal's voice choice (2026-10-09): Auto, the workspace's cloned voices, and the built-in voices, each with
// a sample to play. The value is what Create's plan takes: a built-in voice's key, or "clone" for a cloned voice.
const props = defineProps({ modelValue: { type: String, default: '' }, autoText: { type: String, default: 'A voice that fits, saying your brand name the way you saved it' } })
const emit = defineEmits(['update:modelValue'])
const open = ref(false), root = ref(null), profiles = ref([]), loadFailed = ref(false), playing = ref(''), loadingKey = ref(''), failed = ref('')
let audio = null

const cloned = computed(() => profiles.value.filter(p => p.is_cloned && p.status !== 'archived'))
// The built-in voices Create speaks with are the Google (Gemini) ones; those with a written description come first.
const builtIn = computed(() => profiles.value.filter(p => !p.is_cloned && ['google', 'gemini'].includes(p.provider) && p.status !== 'archived')
  .sort((a, b) => (VOICE_DESCRIPTIONS[b.provider_voice_key] ? 1 : 0) - (VOICE_DESCRIPTIONS[a.provider_voice_key] ? 1 : 0)))
const chosen = computed(() => props.modelValue === 'clone' ? cloned.value[0] : builtIn.value.find(p => p.provider_voice_key === props.modelValue))
const title = computed(() => !props.modelValue ? 'Auto' : props.modelValue === 'clone' ? (chosen.value?.name || 'Your cloned voice') : props.modelValue)
const describe = p => VOICE_DESCRIPTIONS[p?.provider_voice_key] || (p?.gender_label ? `${p.gender_label} voice` : 'A WyvStudio voice')
const sub = computed(() => !props.modelValue ? props.autoText : props.modelValue === 'clone' ? 'Your cloned voice' : describe(chosen.value))

function pick(value) { emit('update:modelValue', value); open.value = false; stop() }
function stop() { try { audio?.pause() } catch { /* nothing playing */ } playing.value = '' }
async function play(p) {
  const key = String(p.id)
  if (playing.value === key) { stop(); return }
  stop(); failed.value = ''; loadingKey.value = key
  try {
    const url = (await api.post('/voice-profiles/preview', { voice_profile_id: p.id })).data?.data?.preview_url
    if (!url) throw Error('no sample')
    audio ??= new Audio()
    audio.src = url; audio.onended = () => { playing.value = '' }
    await audio.play(); playing.value = key
  } catch { failed.value = key }
  finally { loadingKey.value = '' }
}
function outside(e) { if (open.value && root.value && !root.value.contains(e.target)) { open.value = false; stop() } }
onMounted(async () => {
  document.addEventListener('pointerdown', outside)
  try { profiles.value = (await api.get('/voice-profiles')).data?.data?.voice_profiles || [] } catch { profiles.value = []; loadFailed.value = true }
})
onBeforeUnmount(() => { document.removeEventListener('pointerdown', outside); stop() })
</script>

<template>
  <div ref="root" class="vp">
    <button type="button" class="vp-trigger" :aria-expanded="open" aria-haspopup="listbox" @click="open = !open">
      <span class="vp-av" aria-hidden="true">♪</span>
      <span class="vp-grow"><b>{{ title }}</b><small>{{ sub }}</small></span>
      <span class="vp-btn">{{ open ? 'Close' : 'Choose a voice' }}</span>
    </button>
    <div v-if="open" class="vp-menu" role="listbox" aria-label="Voices">
      <button type="button" :class="['vp-row', { on: !modelValue }]" role="option" :aria-selected="!modelValue" @click="pick('')">
        <span class="vp-play vp-play--none" aria-hidden="true">✦</span><span class="vp-grow"><b>Auto</b><small>{{ autoText }}</small></span>
      </button>
      <template v-if="cloned.length">
        <div class="vp-group">Your voices</div>
        <div v-for="p in cloned.slice(0, 1)" :key="p.id" :class="['vp-row', { on: modelValue === 'clone' }]" role="option" :aria-selected="modelValue === 'clone'">
          <button type="button" class="vp-play" :aria-label="(playing === String(p.id) ? 'Stop ' : 'Play ') + p.name" @click.stop="play(p)">{{ loadingKey === String(p.id) ? '…' : playing === String(p.id) ? '❚❚' : '▶' }}</button>
          <button type="button" class="vp-grow vp-pickbtn" @click="pick('clone')"><b>{{ p.name }}</b><small>{{ failed === String(p.id) ? 'The sample could not play; you can still choose it' : 'Your cloned voice' }}</small></button>
        </div>
      </template>
      <div class="vp-group">WyvStudio voices</div>
      <p v-if="!builtIn.length" class="vp-empty">{{ loadFailed ? 'The voice list could not load. Refresh to try again, or leave it on Auto.' : 'Loading voices…' }}</p>
      <div v-for="p in builtIn" :key="p.id" :class="['vp-row', { on: modelValue === p.provider_voice_key }]" role="option" :aria-selected="modelValue === p.provider_voice_key">
        <button type="button" class="vp-play" :aria-label="(playing === String(p.id) ? 'Stop ' : 'Play ') + p.provider_voice_key" @click.stop="play(p)">{{ loadingKey === String(p.id) ? '…' : playing === String(p.id) ? '❚❚' : '▶' }}</button>
        <button type="button" class="vp-grow vp-pickbtn" @click="pick(p.provider_voice_key)"><b>{{ p.provider_voice_key }}<span v-if="p.gender_label"> · {{ p.gender_label }}</span></b><small>{{ failed === String(p.id) ? 'The sample could not play; you can still choose it' : describe(p) }}</small></button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.vp{position:relative}
.vp-trigger{width:100%;display:flex;align-items:center;gap:12px;border:1px dashed #2c313b;border-radius:14px;padding:12px 14px;background:transparent;color:#eceef1;text-align:left;font:inherit;cursor:pointer}
.vp-trigger:hover{border-color:#3a404c}
.vp-av{width:42px;height:42px;border-radius:50%;background:#191d24;display:grid;place-items:center;color:#8f95a1;flex:0 0 42px}
.vp-grow{flex:1;min-width:0;display:flex;flex-direction:column}
.vp-grow b{font-size:14px;color:#eceef1}
.vp-grow b span{font-weight:400;color:#8f95a1}
.vp-grow small{color:#8f95a1;font-size:12.5px;line-height:1.35}
.vp-btn{border:1px solid #2c313b;background:#191d24;border-radius:999px;padding:8px 14px;font-weight:600;font-size:13px;white-space:nowrap}
.vp-menu{position:absolute;z-index:5;left:0;right:0;top:calc(100% + 6px);max-height:330px;overflow:auto;background:#14171d;border:1px solid #2c313b;border-radius:14px;padding:6px;box-shadow:0 24px 60px -20px rgba(0,0,0,.85)}
.vp-group{font:700 10px ui-monospace,Menlo,monospace;letter-spacing:.1em;text-transform:uppercase;color:#5d6472;padding:10px 10px 4px}
.vp-row{display:flex;align-items:center;gap:10px;width:100%;padding:8px 10px;border-radius:10px;border:0;background:transparent;text-align:left;font:inherit;color:inherit}
button.vp-row{cursor:pointer}
.vp-row:hover,.vp-row.on{background:#191d24}
.vp-row.on .vp-grow b{color:#ffa47e}
.vp-pickbtn{border:0;background:transparent;padding:0;font:inherit;text-align:left;cursor:pointer}
.vp-play{flex:0 0 32px;width:32px;height:32px;border-radius:50%;border:1px solid #2c313b;background:#191d24;color:#ff6b35;font-size:11px;display:grid;place-items:center;cursor:pointer}
.vp-play--none{cursor:default}
.vp-empty{margin:4px 10px 8px;color:#8f95a1;font-size:13px}
.vp button:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
</style>
