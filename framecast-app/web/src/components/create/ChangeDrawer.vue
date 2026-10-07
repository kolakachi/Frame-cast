<script setup>
import { computed, ref, watch } from 'vue'
import api from '../../services/api'
import SideDrawer from './SideDrawer.vue'
// "Change…" on a finished video (S9, docs/product/create-ui/change-from-video.html): a prompt per paused moment with
// "Suggest a change", a short list of the video's parts with one Change button each, one total. Everything is sent as
// one change request and planned like any other change; nothing is charged until that plan is approved.
const props = defineProps({
  open: Boolean, conversationId: { type: String, default: '' }, revision: { type: Object, default: null },
  version: { type: Number, default: 0 }, src: { type: String, default: '' },
})
const emit = defineEmits(['close', 'planned', 'updated'])
const info = ref(null), loading = ref(false), error = ref(''), sending = ref(false)
const moments = ref([]), edits = ref({}), openPart = ref(''), words = ref([]), showWords = ref(false)
const music = ref('keep'), musicFile = ref(null), voice = ref('keep'), showBreakdown = ref(false), thumbs = ref({})
const IDEAS = ['Slow this down', 'Bigger text', 'Quieter music here']
const base = () => `/create/conversations/${props.conversationId}`
const mmss = s => `${Math.floor((s || 0) / 60)}:${String(Math.round((s || 0) % 60)).padStart(2, '0')}`
const fail = e => e.response?.data?.message || e.response?.data?.error?.message || 'Could not complete this action. Please retry.'

watch(() => [props.open, props.revision?.id], async ([open]) => {
  if (!open || !props.revision?.id || info.value?.revision?.id === props.revision.id) return
  loading.value = true; error.value = ''; edits.value = {}; moments.value = moments.value.filter(m => m.revision === props.revision.id)
  try {
    info.value = (await api.get(`${base()}/revisions/${props.revision.id}/parts`)).data.data
    words.value = [...info.value.words]
    thumbs.value = await partThumbs(info.value.parts.filter(p => p.times))
  } catch (e) { error.value = fail(e) } finally { loading.value = false }
}, { immediate: true })

// A frame of the finished video, as a JPEG blob and a small preview.
function grab(video) {
  const canvas = document.createElement('canvas'), scale = Math.min(1, 720 / Math.max(video.videoWidth, video.videoHeight))
  canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale)
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
  return new Promise((resolve, reject) => {
    try { canvas.toBlob(blob => blob ? resolve({ blob, url: URL.createObjectURL(blob) }) : reject(new Error('frame')), 'image/jpeg', 0.85) } catch (e) { reject(e) }
  })
}
// Each part's thumbnail is how it looks in this video: the frame in the middle of where it is used.
async function partThumbs(parts) {
  if (!props.src || !parts.length) return {}
  const video = document.createElement('video'); video.crossOrigin = 'anonymous'; video.muted = true; video.preload = 'auto'; video.src = props.src
  const out = {}
  try {
    await new Promise((resolve, reject) => { video.onloadeddata = resolve; video.onerror = reject })
    for (const p of parts) {
      video.currentTime = (p.times[0] + p.times[1]) / 2
      await new Promise(resolve => { video.onseeked = resolve })
      out[p.id] = (await grab(video)).url
    }
  } catch { /* thumbnails are a nicety; the list works without them */ }
  return out
}

/** Called by the page when the user pauses and taps "Change this moment". */
async function addMoment(video) {
  const time = Math.round(video.currentTime * 10) / 10
  if (moments.value.some(m => Math.abs(m.time - time) < 0.3)) return
  let frame = null
  try { frame = await grab(video) } catch { /* the note still works without the picture */ }
  moments.value.push({ time, text: '', blob: frame?.blob || null, thumb: frame?.url || '', ideas: [], suggesting: false, revision: props.revision?.id })
}
defineExpose({ addMoment })

async function suggest(m) {
  if (!m.blob || m.suggesting) return
  m.suggesting = true; error.value = ''
  try {
    const form = new FormData(); form.append('time', m.time); form.append('frame', m.blob, 'frame.jpg')
    const s = (await api.post(`${base()}/revisions/${props.revision.id}/suggest`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data
    m.text = s.suggestion; m.ideas = s.ideas || []
  } catch (e) { error.value = fail(e) } finally { m.suggesting = false }
}
function useIdea(m, idea) { m.text = m.text.trim() ? `${m.text.trim().replace(/[.]?$/, '.')} ${idea}.` : `${idea}.` }

// Swapping in your own file: uploaded to this creation as a file to use, then named in the change.
async function pickFile(event, apply) {
  const file = event.target.files?.[0]; event.target.value = ''
  if (!file) return
  error.value = ''
  try {
    const before = new Set((await api.get(base())).data.data.attachments.map(a => a.asset_id))
    const form = new FormData(); form.append('asset_file', file); form.append('purpose', 'source'); form.append('idempotency_key', crypto.randomUUID()); form.append('expected_version', props.version)
    const result = (await api.post(`${base()}/uploads`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data
    emit('updated', result)
    const added = result.attachments.find(a => !before.has(a.asset_id))
    if (added) apply(added)
  } catch (e) { error.value = fail(e) }
}
function setPart(id, edit) { edits.value = { ...edits.value, [id]: edit }; openPart.value = '' }
function clearPart(id) { const next = { ...edits.value }; delete next[id]; edits.value = next }

const lines = computed(() => {
  if (!info.value) return []
  const out = [{ label: 'Planning the change', credits: info.value.estimate.planning }]
  for (const p of info.value.parts) {
    const e = edits.value[p.id]
    if (e?.action === 'file') out.push({ label: `Your file for ${p.name.toLowerCase()}`, credits: 0 })
    if (e?.action === 'remake') out.push({ label: `New ${p.name.toLowerCase()}`, credits: p.remake_credits || 0 })
  }
  if (music.value === 'new') out.push({ label: 'A new music track', credits: info.value.sound.music_credits })
  if (music.value === 'mine' && musicFile.value) out.push({ label: 'Your music', credits: 0 })
  if (voice.value === 'another') out.push({ label: 'A new voiceover', credits: 3 })
  return out
})
const rebuild = computed(() => info.value?.estimate.rebuild || [0, 0])
const total = computed(() => { const fixed = lines.value.reduce((s, l) => s + l.credits, 0); return [fixed + rebuild.value[0], fixed + rebuild.value[1]] })
const changedWords = computed(() => words.value.map((text, index) => ({ index, text })).filter(w => w.text.trim() && w.text !== info.value?.words[w.index]))
const anything = computed(() => moments.value.some(m => m.text.trim()) || Object.keys(edits.value).length || changedWords.value.length || music.value !== 'keep' || voice.value !== 'keep')

function clearAll() { moments.value = []; edits.value = {}; words.value = [...(info.value?.words || [])]; music.value = 'keep'; musicFile.value = null; voice.value = 'keep' }
async function send() {
  if (!anything.value || sending.value) return
  sending.value = true; error.value = ''
  try {
    const kept = moments.value.filter(m => m.text.trim())
    const payload = {
      expected_version: props.version,
      moments: kept.map(m => ({ time: m.time, text: m.text.trim() })),
      parts: Object.entries(edits.value).map(([id, e]) => ({ id, ...e })),
      words: changedWords.value, music: music.value === 'mine' && !musicFile.value ? 'keep' : music.value,
      music_asset_id: musicFile.value?.asset_id || null, voice: voice.value,
    }
    const form = new FormData(); form.append('payload', JSON.stringify(payload))
    kept.forEach((m, i) => { if (m.blob) form.append(`frames[${i}]`, m.blob, `moment-${i}.jpg`) })
    const result = (await api.post(`${base()}/revisions/${props.revision.id}/change`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data
    clearAll(); emit('planned', result)
  } catch (e) { error.value = fail(e) } finally { sending.value = false }
}
</script>

<template>
  <SideDrawer :open="open" title="Change this video" :meta="revision ? `Version ${revision.number} · your edits become a new version` : ''" @close="emit('close')">
    <p v-if="loading" class="muted">Reading this video…</p>
    <p v-if="error" class="cd-error" role="alert">{{ error }}</p>
    <template v-if="info">
      <div class="cd-group">
        <h3>{{ moments.length ? 'This moment' : 'A moment' }}</h3>
        <p v-if="!moments.length" class="cd-hint">Pause the video where you want something different and tap <b>Change this moment</b>.</p>
        <div v-for="(m, i) in moments" :key="m.time" class="cd-moment">
          <img v-if="m.thumb" :src="m.thumb" alt="" class="cd-thumb" />
          <div class="cd-moment__body">
            <div class="cd-moment__top"><b>{{ mmss(m.time) }}</b><button type="button" class="cd-x" :aria-label="`Remove the moment at ${mmss(m.time)}`" @click="moments.splice(i, 1)">✕</button></div>
            <textarea v-model="m.text" rows="3" placeholder="What should be different here?" />
            <div class="cd-ideas">
              <button type="button" class="cd-idea cd-idea--ai" :disabled="!m.blob || m.suggesting" @click="suggest(m)">{{ m.suggesting ? 'Looking…' : '✦ Suggest a change' }}</button>
              <button v-for="idea in (m.ideas.length ? m.ideas : IDEAS)" :key="idea" type="button" class="cd-idea" @click="useIdea(m, idea)">{{ idea }}</button>
            </div>
          </div>
        </div>
      </div>

      <div class="cd-group">
        <h3>Or change a part</h3>
        <div v-for="p in info.parts" :key="p.id" :class="['cd-part', { 'cd-part--on': edits[p.id] }]">
          <div class="cd-row">
            <img v-if="thumbs[p.id]" :src="thumbs[p.id]" alt="" class="cd-thumb cd-thumb--sm" /><span v-else class="cd-thumb cd-thumb--sm cd-thumb--blank" />
            <div class="cd-name">{{ p.name }}<small>{{ edits[p.id]?.action === 'file' ? `Using your ${edits[p.id].title}` : edits[p.id]?.action === 'remake' ? `A new one · ${p.remake_credits} credits` : edits[p.id]?.action === 'describe' ? edits[p.id].text : p.times ? `${mmss(p.times[0])}–${mmss(p.times[1])}` : p.what }}</small></div>
            <button v-if="edits[p.id]" type="button" class="cd-act cd-act--on" @click="clearPart(p.id)">Undo</button>
            <button v-else type="button" class="cd-act" :aria-expanded="openPart === p.id" @click="openPart = openPart === p.id ? '' : p.id">Change</button>
          </div>
          <div v-if="openPart === p.id" class="cd-choices">
            <label class="cd-act">Use my file <b>free</b><input type="file" :accept="p.clip ? 'video/*,image/*' : 'image/*'" hidden @change="pickFile($event, a => setPart(p.id, { action: 'file', asset_id: a.asset_id, title: a.title }))" /></label>
            <button v-if="p.remake_credits" type="button" class="cd-act" @click="setPart(p.id, { action: 'remake' })">Make a new one <b>{{ p.remake_credits }} cr</b></button>
            <button type="button" class="cd-act" @click="setPart(p.id, { action: 'describe', text: '' })">Describe it</button>
          </div>
          <textarea v-if="edits[p.id]?.action === 'describe' || edits[p.id]?.action === 'remake'" v-model="edits[p.id].text" rows="2" class="cd-part__text" :placeholder="edits[p.id].action === 'remake' ? 'Anything to do differently? (optional)' : 'How should it change?'" />
        </div>
        <div v-if="info.sound.music || info.sound.voiceover" :class="['cd-part', { 'cd-part--on': music !== 'keep' || voice !== 'keep' }]">
          <div class="cd-row">
            <span class="cd-thumb cd-thumb--sm cd-thumb--blank">♪</span>
            <div class="cd-name">Music and voice<small>{{ [info.sound.music ? (music === 'keep' ? 'Music as it is' : music === 'new' ? 'A new track' : music === 'none' ? 'No music' : musicFile ? `Your ${musicFile.title}` : 'Your music') : '', info.sound.voiceover ? (voice === 'keep' ? `${info.sound.voice || 'Voice'} as it is` : 'Another voice') : ''].filter(Boolean).join(' · ') }}</small></div>
            <button type="button" class="cd-act" :aria-expanded="openPart === 'sound'" @click="openPart = openPart === 'sound' ? '' : 'sound'">Change</button>
          </div>
          <div v-if="openPart === 'sound'" class="cd-choices cd-choices--col">
            <div v-if="info.sound.music" class="cd-choices"><span class="cd-label">Music</span>
              <button v-for="o in [['keep','Keep'],['new','New track'],['none','None']]" :key="o[0]" type="button" :class="['cd-act', { 'cd-act--on': music === o[0] }]" @click="music = o[0]">{{ o[1] }}<b v-if="o[0] === 'new'">{{ info.sound.music_credits }} cr</b></button>
              <label :class="['cd-act', { 'cd-act--on': music === 'mine' }]">Use mine <b>free</b><input type="file" accept="audio/*" hidden @change="pickFile($event, a => { musicFile = a; music = 'mine' })" /></label>
            </div>
            <div v-if="info.sound.voiceover" class="cd-choices"><span class="cd-label">Voice</span>
              <button type="button" :class="['cd-act', { 'cd-act--on': voice === 'keep' }]" @click="voice = 'keep'">Keep {{ info.sound.voice }}</button>
              <button type="button" :class="['cd-act', { 'cd-act--on': voice === 'another' }]" @click="voice = 'another'">Another voice <b>3 cr</b></button>
            </div>
            <p class="cd-hint">A new voice or track changes timing everywhere, so the whole video is re-timed to it.</p>
          </div>
        </div>
        <button v-if="info.words.length" type="button" class="cd-link" @click="showWords = !showWords">{{ showWords ? 'Hide the words' : 'Edit the words' }}</button>
        <div v-if="showWords" class="cd-words">
          <input v-for="(w, i) in words" :key="i" v-model="words[i]" :aria-label="`Line ${i + 1}`" :class="{ 'cd-changed': w !== info.words[i] }" />
        </div>
      </div>
    </template>
    <template #footer>
      <div v-if="info" class="cd-foot">
        <div class="cd-total"><b>About {{ total[0] }}–{{ total[1] }} credits</b><span class="cd-spacer" /><button type="button" class="cd-link" @click="showBreakdown = !showBreakdown">What's in this</button></div>
        <div v-if="showBreakdown" class="cd-breakdown">
          <div v-for="l in lines" :key="l.label"><span>{{ l.label }}</span><span>{{ l.credits ? `about ${l.credits}` : 'free' }}</span></div>
          <div><span>Rebuilding the video around the changes</span><span>{{ rebuild[0] }}–{{ rebuild[1] }}</span></div>
        </div>
        <span class="cd-note">Nothing is charged until you approve the plan.</span>
        <div class="cd-buttons"><button type="button" class="btn btn--ghost btn--sm" :disabled="!anything || sending" @click="clearAll">Clear</button><button type="button" class="btn btn--primary btn--sm" :disabled="!anything || sending" @click="send">{{ sending ? 'Sending…' : 'Plan the change' }}</button></div>
      </div>
    </template>
  </SideDrawer>
</template>

<style scoped>
.cd-group{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
h3{margin:2px 0 0;font:600 11px/1.4 var(--mono,ui-monospace,monospace);letter-spacing:.08em;text-transform:uppercase;color:var(--text-3,#8f95a1)}
.cd-hint{margin:0;color:var(--text-2,#b5bac4);font-size:12.5px}
.cd-error{margin:0 0 10px;color:#ff8f8f;font-size:12.5px}
.cd-moment{display:grid;grid-template-columns:64px 1fr;gap:10px;padding:10px;border:1px solid rgba(255,107,53,.45);border-radius:12px;background:var(--bg-3,#1d2129)}
.cd-moment__body{display:flex;flex-direction:column;gap:6px;min-width:0}
.cd-moment__top{display:flex;align-items:center;gap:8px;font-size:13px}
.cd-x{margin-left:auto;border:0;background:transparent;color:var(--text-3,#8f95a1);cursor:pointer;font-size:12px}
.cd-thumb{width:64px;height:84px;object-fit:cover;border-radius:7px;border:1px solid var(--line-2,#262b34);background:#000}
.cd-thumb--sm{width:40px;height:52px;flex:none}
.cd-thumb--blank{display:inline-grid;place-items:center;background:var(--bg-2,#151820);color:var(--text-3,#8f95a1)}
textarea,.cd-words input{width:100%;box-sizing:border-box;border:1px solid var(--line-3,#2c313b);border-radius:8px;background:var(--bg-2,#151820);color:var(--text,#eceef1);padding:8px 9px;font:13px/1.45 inherit;resize:none}
.cd-ideas,.cd-choices{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.cd-choices--col{flex-direction:column;align-items:stretch;padding-top:8px}
.cd-idea{border:1px dashed var(--line-3,#2c313b);border-radius:999px;padding:3px 10px;background:transparent;color:var(--text-2,#b5bac4);font-size:12px;cursor:pointer}
.cd-idea--ai{border-style:solid;border-color:rgba(255,107,53,.5);color:#ff6b35}
.cd-idea:disabled{opacity:.6;cursor:default}
.cd-part{display:flex;flex-direction:column;gap:8px;padding:8px 10px;border:1px solid var(--line-3,#2c313b);border-radius:12px;background:var(--bg-3,#1d2129)}
.cd-part--on{border-color:rgba(255,107,53,.5)}
.cd-row{display:flex;align-items:center;gap:10px}
.cd-name{flex:1;min-width:0;font-size:13px;font-weight:600}
.cd-name small{display:block;font-weight:400;color:var(--text-3,#8f95a1);font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cd-act{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--line-3,#2c313b);border-radius:999px;padding:3px 10px;background:var(--bg-2,#151820);color:var(--text,#eceef1);font-size:12px;cursor:pointer;white-space:nowrap}
.cd-act b{font-weight:500;color:var(--text-3,#8f95a1)}
.cd-act--on{border-color:rgba(255,107,53,.6);color:#ff6b35}
.cd-label{font-size:11.5px;color:var(--text-3,#8f95a1);min-width:42px}
.cd-part__text{margin-top:2px}
.cd-link{align-self:flex-start;background:none;border:0;padding:0;color:var(--text-2,#b5bac4);font-size:12.5px;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
.cd-words{display:flex;flex-direction:column;gap:6px}
.cd-changed{border-color:rgba(255,107,53,.6)!important}
.cd-foot{display:flex;flex-direction:column;gap:6px;width:100%}
.cd-total{display:flex;align-items:baseline;gap:8px;font-size:14px}
.cd-spacer{flex:1}
.cd-breakdown{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--text-2,#b5bac4)}
.cd-breakdown div{display:flex;gap:8px}.cd-breakdown div span:last-child{margin-left:auto;font-variant-numeric:tabular-nums;color:var(--text,#eceef1)}
.cd-note{font-size:12px;color:var(--text-3,#8f95a1)}
.cd-buttons{display:flex;justify-content:flex-end;gap:8px}
</style>
