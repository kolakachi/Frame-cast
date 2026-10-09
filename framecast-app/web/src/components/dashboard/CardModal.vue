<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api'
import UiSelect from '../UiSelect.vue'
import VoicePicker from './VoicePicker.vue'
import CharacterPicker from './CharacterPicker.vue'
import LibraryPicker from '../create/LibraryPicker.vue'
import { setLaunch } from '../../services/createLaunch.js'

// Clicking a "What do you want to make?" card (2026-10-09 mockup, owner's decisions): write a script or let Script
// Writer draft one that fits the card, set the options Create uses, and Continue starts it in Create, which sends the
// brief so planning begins straight away. A script in the box is kept word for word as the voiceover.
const props = defineProps({ card: { type: Object, default: null }, clonedVoice: { type: Boolean, default: false } })
const emit = defineEmits(['close'])
const router = useRouter()
const dialog = ref(null), box = ref(null), refFile = ref(null), refAsset = ref(null), libOpen = ref('')

const details = ref(''), link = ref(''), writing = ref(false), written = ref(null), lastAbout = ref(''), problem = ref('')
const length = ref(15), screen = ref('9:16'), captions = ref(true), effort = ref('standard'), styleId = ref(''), voice = ref(''), presenter = ref('')
// What goes with the brief: files from the computer and picks from the library (files, or characters by their photo).
const picks = ref([]), styles = ref([]), characters = ref([])
const MAX_PICKS = 6
const ATTACH_TYPES = 'image/png,image/jpeg,image/webp,image/svg+xml'

const isUgc = computed(() => props.card?.key === 'testimonial')
const isReference = computed(() => props.card?.key === 'reference')
const canWrite = computed(() => !isReference.value)
// The video they love: a link, or the file itself (the agent picks a link up from the brief either way).
const ready = computed(() => details.value.trim().length >= 3 && (!isReference.value || /^https:\/\//.test(link.value.trim()) || !!refFile.value || !!refAsset.value))
const LENGTHS = [{ value: 15, label: '15 s' }, { value: 20, label: '20 s' }, { value: 30, label: '30 s' }]
const SCREENS = [{ value: '9:16', label: '9:16 · Stories, Reels, TikTok' }, { value: '1:1', label: '1:1 · Feed' }, { value: '16:9', label: '16:9 · YouTube, web' }, { value: '4:5', label: '4:5 · Instagram feed' }]
const EFFORTS = [{ value: 'quick', label: 'Quick' }, { value: 'standard', label: 'Standard' }, { value: 'thorough', label: 'Thorough' }]
// The details box's example: the card's own for the workspace's industry (D5), else a general one.
const placeholder = computed(() => {
  const ex = props.card?.example
  if (isReference.value) return 'What is your version about? For example: ' + (ex || 'the same pacing, for my candle shop\'s autumn offer.')
  if (isUgc.value) return 'What should they say? Type the script, or describe the product and who it is for, and let Script Writer write a natural testimonial.' + (ex ? ' For example: ' + ex : '')
  return 'Type your script, or describe it in a line and let Script Writer write it. For example: ' + (ex || 'Dewbloom vitamin C serum, 30 ml, for dull skin. 20% off this week.')
})
const styleOptions = computed(() => [{ value: '', label: 'WyvStudio picks' }, ...styles.value.map(x => ({ value: x.id, label: x.name }))])
const refName = computed(() => refFile.value?.name || refAsset.value?.name || '')
function refUpload(list) { const f = list[0]; if (f) { refFile.value = f; refAsset.value = null } libOpen.value = '' }
function refPick(a) { refAsset.value = { id: a.id, name: a.title || 'Your video' }; refFile.value = null; libOpen.value = '' }
function clearRef() { refFile.value = null; refAsset.value = null }

watch(() => props.card, async card => {
  if (!card) { if (dialog.value?.open) dialog.value.close(); return }
  details.value = ''; link.value = ''; written.value = null; lastAbout.value = ''; problem.value = ''
  length.value = card.key === 'testimonial' ? 20 : card.key === 'explainer' || card.key === 'listicle' ? 30 : 15
  screen.value = '9:16'; captions.value = true; effort.value = 'standard'; styleId.value = ''; voice.value = ''; presenter.value = ''; picks.value = []; refFile.value = null; refAsset.value = null; libOpen.value = ''
  // An idea from "Next videos for you" arrives with its description in Video details.
  if (card.prefill?.details) details.value = card.prefill.details
  await nextTick()
  if (!dialog.value.open) dialog.value.showModal()
  box.value?.focus()
  try { styles.value = (await api.get('/create/styles')).data.data || [] } catch { styles.value = [] }
  if (card.key === 'testimonial') { try { characters.value = ((await api.get('/characters')).data.data.characters || []).filter(c => c.reference_asset?.id) } catch { characters.value = [] } }
})

async function writeScript() {
  const about = written.value ? lastAbout.value : details.value.trim()
  if (about.length < 3) { problem.value = 'Describe the product or idea in a line first, for example: "Dewbloom vitamin C serum, for dull skin, 20% off this week".'; return }
  writing.value = true; problem.value = ''
  try {
    const out = (await api.post('/create/script-writer', { type: props.card.key, about, duration_seconds: length.value })).data.data
    lastAbout.value = about
    written.value = out.sections
    details.value = out.sections.map(s => s.text).join('\n')
  } catch (e) { problem.value = e.response?.data?.error?.message || e.response?.data?.message || 'Script Writer could not write a script just now. Try again in a moment.' }
  finally { writing.value = false }
}

const filePicks = computed(() => picks.value.filter(p => p.kind === 'file'))
function addUploads(list) {
  for (const f of list) if (picks.value.length < MAX_PICKS) picks.value.push({ key: 'f' + Math.random().toString(36).slice(2), kind: 'file', file: f, name: f.name, thumb: f.type.startsWith('image/') ? URL.createObjectURL(f) : '' })
  libOpen.value = ''
}
function addFromLibrary(items) { picks.value = [...filePicks.value, ...items].slice(0, MAX_PICKS); libOpen.value = '' }
function removePick(p) { if (p.kind === 'file' && p.thumb) URL.revokeObjectURL(p.thumb); picks.value = picks.value.filter(x => x !== p) }

function go() {
  if (!ready.value) return
  const card = props.card
  const who = isUgc.value && presenter.value ? characters.value.find(c => String(c.id) === presenter.value) : null
  // A script (written or edited from Script Writer) is said word for word; anything else is a description Weave plans from.
  const text = written.value
    ? `${card.title}: ${lastAbout.value}\n\nVoiceover script (say these words exactly):\n${details.value.trim()}`
    : `${card.title}: ${details.value.trim()}`
  const reference = !isReference.value ? '' : link.value.trim() ? `Reference: ${link.value.trim()}` : 'Reference: the attached video. Make mine in its style, with my brand and my words.'
  const featured = picks.value.filter(p => p.kind === 'character').map(p => p.name)
  const brief = [text, reference, who ? `Presenter: ${who.name}.` : '', featured.length ? `Featuring: ${featured.join(', ')}.` : ''].filter(Boolean).join('\n\n')
  setLaunch({
    text: brief, type: card.key, files: [...(isReference.value && refFile.value && !link.value.trim() ? [refFile.value] : []), ...filePicks.value.map(p => p.file)], presenterAssetId: who?.reference_asset?.id || null,
    assetIds: [...new Set([...(isReference.value && refAsset.value && !link.value.trim() ? [refAsset.value.id] : []), ...picks.value.filter(p => p.kind !== 'file').map(p => p.asset_id)])],
    // The card's kind of video is the planner's format playbook (the ids match); a reference brings its own shape.
    settings: { aspect_ratio: screen.value, duration_seconds: length.value, duration_chosen: true, effort: effort.value, ...(isReference.value ? {} : { format: card.key }),
      ...(captions.value ? {} : { no_captions: true }), ...(voice.value ? { voice: voice.value } : {}), ...(styleId.value ? { style_id: styleId.value } : {}) },
  })
  emit('close')
  router.push({ name: 'create' })
}
function close() { emit('close') }
</script>

<template>
  <dialog ref="dialog" class="cm" :aria-label="card?.title" @cancel="e => { if (e.target !== dialog) return; e.preventDefault(); close() }" @click="e => { if (e.target === dialog) close() }">
    <div v-if="card" class="cm-in">
      <header class="cm-hero" :style="{ '--c': card.colour }">
        <img :src="card.image" alt="" />
        <button type="button" class="cm-x" aria-label="Close" @click="close">×</button>
        <h2>{{ card.title }}</h2>
        <p>{{ card.tagline }}</p>
      </header>
      <div class="cm-body">
        <div v-if="isUgc" class="cm-field">
          <div class="cm-label">Presenter <small>Who talks to camera</small></div>
          <CharacterPicker v-model="presenter" :characters="characters" />
        </div>
        <div class="cm-field">
          <div class="cm-label">Voice <small>{{ isUgc ? 'The presenter speaks in their own voice, or yours' : 'WyvStudio picks one to fit, or choose yours' }}</small></div>
          <VoicePicker v-model="voice" :auto-text="isUgc ? 'The presenter\'s own natural voice' : 'A voice that fits, saying your brand name the way you saved it'" />
        </div>
        <div v-if="isReference" class="cm-field">
          <div class="cm-label">The video you love <small>Paste its link, or pick or upload the video</small></div>
          <div class="cm-ref">
            <input v-model="link" class="cm-input" type="url" placeholder="https://www.tiktok.com/@creator/video/… (TikTok, Reel, YouTube or X)" :disabled="!!refName" />
            <span class="cm-or">or</span>
            <button v-if="!refName" type="button" class="cm-upload" @click="libOpen = 'reference'">Choose a video</button>
            <span v-else class="cm-file"><b>{{ refName }}</b><button type="button" class="cm-file-x" :aria-label="'Remove ' + refName" @click="clearRef">×</button></span>
          </div>
        </div>
        <div class="cm-field">
          <div class="cm-label">Video details <small v-if="written">Written by Script Writer for {{ length }} s · {{ written.map(s => s.label).join(' · ') }} · edit anything</small></div>
          <div class="cm-details">
            <textarea ref="box" v-model="details" rows="5" maxlength="2000" :placeholder="placeholder" />
            <div v-if="canWrite" class="cm-details-foot"><button type="button" class="cm-writer" :disabled="writing" @click="writeScript"><i>✦</i>{{ writing ? 'Writing…' : written ? 'Write another' : 'Script Writer' }}</button><small>Free · up to 20 a day</small></div>
          </div>
          <p v-if="problem" class="cm-problem" role="alert">{{ problem }}</p>
        </div>
        <div class="cm-field">
          <div class="cm-label">Options</div>
          <div class="cm-opts">
            <span class="cm-opt cm-opt--sel"><span>Length</span><UiSelect v-model="length" :options="LENGTHS" label="Length" align="left" /></span>
            <span class="cm-opt cm-opt--sel"><span>Screen</span><UiSelect v-model="screen" :options="SCREENS" label="Screen" align="left" /></span>
            <button type="button" class="cm-opt" :aria-pressed="captions" @click="captions = !captions">Captions <b :class="captions ? 'on' : 'off'">{{ captions ? 'ON' : 'OFF' }}</b></button>
            <span class="cm-opt cm-opt--sel"><span>Style</span><UiSelect v-model="styleId" :options="styleOptions" label="Style" align="left" /></span>
            <span class="cm-opt cm-opt--sel"><span>Effort</span><UiSelect v-model="effort" :options="EFFORTS" label="Effort" align="left" /></span>
            <button type="button" class="cm-opt" @click="libOpen = 'attach'">+ {{ picks.length ? 'Add more' : isUgc ? 'Attach a product photo' : 'Attach photos or a logo' }}</button>
          </div>
          <ul v-if="picks.length" class="cm-picks">
            <li v-for="p in picks" :key="p.key" class="cm-pickchip"><img v-if="p.thumb" :src="p.thumb" alt="" /><span v-else class="cm-pickchip-sym" aria-hidden="true">{{ p.kind === 'character' ? '☺' : '▢' }}</span><b>{{ p.name }}</b><button type="button" class="cm-file-x" :aria-label="'Remove ' + p.name" @click="removePick(p)">×</button></li>
          </ul>
        </div>
        <LibraryPicker :open="libOpen === 'attach'" :title="isUgc ? 'Add a product photo' : 'Add photos or a logo'" :filters="isUgc ? ['image'] : ['image', 'characters']" multiple :max="MAX_PICKS - filePicks.length" :chosen="picks" :accept="ATTACH_TYPES" empty-text="Nothing here yet. Upload from your computer above." character-note="A character's saved photo goes with your brief, and the video features them." @close="libOpen = ''" @done="addFromLibrary" @files="addUploads" />
        <LibraryPicker :open="libOpen === 'reference'" title="The video you love" :filters="['video']" accept="video/mp4" empty-text="No videos yet. Upload the one you love above." @close="libOpen = ''" @pick="refPick" @files="refUpload" />
        <footer class="cm-foot"><small>Next you'll see the plan and its price, before the video is made.</small><button type="button" class="cm-go" :disabled="!ready" @click="go">Continue →</button></footer>
      </div>
    </div>
  </dialog>
</template>

<style scoped>
.cm{font-family:"DM Sans",ui-sans-serif,system-ui,sans-serif;margin:auto;padding:0;border:1px solid #2c313b;border-radius:22px;background:#0f1116;color:#eceef1;width:min(720px,calc(100vw - 24px));max-height:calc(100dvh - 32px);overflow:auto;box-shadow:0 40px 90px -30px rgba(0,0,0,.9)}
.cm::backdrop{background:rgba(5,6,9,.62);backdrop-filter:blur(3px)}
.cm[open]{animation:cm-in .22s cubic-bezier(.2,.8,.2,1)}
@keyframes cm-in{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.cm-hero{position:relative;height:190px;display:flex;flex-direction:column;justify-content:flex-end;padding:22px 26px;isolation:isolate;background:var(--c)}
.cm-hero img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 30%;z-index:-2}
.cm-hero::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(180deg,rgba(15,17,22,.05) 0%,rgba(15,17,22,.55) 55%,#0f1116 100%)}
.cm-hero h2{margin:0;font-size:30px;font-weight:800;letter-spacing:-.03em}
.cm-hero p{margin:4px 0 0;color:#b7bcc6}
.cm-x{position:absolute;top:14px;right:14px;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.25);background:rgba(11,13,17,.6);color:#fff;font-size:20px;cursor:pointer}
.cm-body{padding:8px 26px 24px;display:flex;flex-direction:column;gap:18px}
.cm-label{font-size:13px;font-weight:700;margin-bottom:8px;display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap}
.cm-label small{font-weight:400;color:#8f95a1}
.cm-pick{position:relative;display:flex;align-items:center;gap:12px;border:1px dashed #2c313b;border-radius:14px;padding:12px 14px;cursor:pointer}
.cm-av{width:42px;height:42px;border-radius:50%;background:#191d24;display:grid;place-items:center;color:#8f95a1;flex:0 0 42px}
.cm-grow{flex:1;min-width:0}
.cm-grow b{display:block;font-size:14px}
.cm-grow small{color:#8f95a1;font-size:12.5px}
.cm-input{width:100%;background:#14171d;color:#eceef1;border:1px solid #2c313b;border-radius:12px;padding:11px 12px;font:inherit}
.cm-details{border:1px solid rgba(255,107,53,.5);border-radius:14px;background:#14171d;padding:12px 14px;box-shadow:0 0 0 3px rgba(255,107,53,.13)}
.cm-details textarea{width:100%;min-height:118px;background:none;border:0;color:#eceef1;font:14.5px/1.55 "DM Sans",ui-sans-serif,system-ui,sans-serif;resize:vertical;outline:none}
.cm-details-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px}
.cm-details-foot small{color:#5d6472;font-size:12px}
.cm-writer{display:inline-flex;align-items:center;gap:7px;border:1px solid #2c313b;background:#191d24;color:#eceef1;border-radius:999px;padding:7px 12px;font:600 13px "DM Sans",ui-sans-serif,system-ui,sans-serif;cursor:pointer}
.cm-writer i{font-style:normal;color:#ff6b35}
.cm-writer:disabled{opacity:.6;cursor:default}
.cm-problem{margin:8px 0 0;color:#f87171;font-size:13px}
.cm-opts{display:flex;flex-wrap:wrap;gap:8px}
.cm-opt--sel{padding:2px 4px 2px 12px;cursor:default}
.cm-opt--sel :deep(.ui-select-trigger){border:0;background:transparent;min-width:0;padding:5px 6px;color:#eceef1;font-weight:600}
.cm-ref{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cm-ref .cm-input{flex:1 1 280px;min-width:0}
.cm-input:disabled{opacity:.5}
.cm-or{color:#5d6472;font-size:12.5px}
.cm-upload{border:1px solid #2c313b;background:#191d24;color:#eceef1;border-radius:999px;padding:9px 14px;font-weight:600;font-size:13px;cursor:pointer}
.cm-file{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,107,53,.5);border-radius:999px;padding:6px 8px 6px 12px;font-size:13px;max-width:100%}
.cm-file b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px}
.cm-file-x{background:none;border:0;color:#8f95a1;font-size:16px;cursor:pointer}
.cm-picks{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
.cm-pickchip{display:inline-flex;align-items:center;gap:8px;border:1px solid #2c313b;border-radius:10px;padding:4px 6px 4px 4px;font-size:12.5px;max-width:220px}
.cm-pickchip img,.cm-pickchip-sym{width:30px;height:30px;border-radius:7px;object-fit:cover;background:#191d24;display:grid;place-items:center;color:#8f95a1;flex:0 0 30px}
.cm-pickchip b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cm-opt{display:inline-flex;align-items:center;gap:7px;border:1px solid #2c313b;border-radius:999px;padding:6px 12px;font:13px "DM Sans",ui-sans-serif,system-ui,sans-serif;color:#b7bcc6;background:#14171d;cursor:pointer}
.cm-opt span{font:700 10px ui-monospace,Menlo,monospace;letter-spacing:.06em;color:#5d6472;text-transform:uppercase}
.cm-opt select{background:transparent;border:0;color:#eceef1;font:600 13px "DM Sans",ui-sans-serif,system-ui,sans-serif;cursor:pointer}
.cm-opt b{font:700 10px ui-monospace,Menlo,monospace}
.cm-opt b.on{color:#3fcf8e} .cm-opt b.off{color:#5d6472}
.cm-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:4px}
.cm-foot small{color:#8f95a1;font-size:12.5px;max-width:42ch}
.cm-go{background:#ff6b35;color:#1a0d06;border:0;border-radius:999px;padding:12px 22px;font:700 14.5px "DM Sans",ui-sans-serif,system-ui,sans-serif;cursor:pointer}
.cm-go:disabled{opacity:.45;cursor:default}
.cm button:focus-visible,.cm select:focus-visible,.cm input:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media (max-width:560px){.cm-hero{height:160px}.cm-body{padding:8px 16px 20px}.cm-select{max-width:100%}.cm-pick{flex-wrap:wrap}}
@media (prefers-reduced-motion:reduce){.cm[open]{animation:none}}
</style>
