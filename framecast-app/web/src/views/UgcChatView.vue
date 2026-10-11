<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import ComposerTray from '../components/create/ComposerTray.vue'
import RefThumb from '../components/create/RefThumb.vue'
import UgcBackgroundPicker from '../components/ugc/UgcBackgroundPicker.vue'
import UgcPresenterModal from '../components/ugc/UgcPresenterModal.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
import { apiErrorMessage } from '../composables/apiError'

// UGC Ads as a conversation (owner, 2026-10-10; mockup docs/product/ugc-chat-todo.md). Everything goes in one prompt box
// at once: product photos, footage, a link, a sample UGC, a script or an idea, presenters with their voices and an
// optional type. The director asks only what it cannot infer, then pitches ONE video with its price. Make it hands the
// pitch to the UGC pipeline, which makes it as a Classic project (Fine-tune in editor).

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const workspaceStore = useWorkspaceStore()
const canUgc = computed(() => workspaceStore.capabilities?.ugc_ads !== false)
const canOwnCharacter = computed(() => workspaceStore.capabilities?.custom_characters !== false)

const TYPES = [
  ['auto', 'Auto', 'We pick from your prompt, files and sample'],
  ['testimonial', 'Testimonial', 'A customer talks about their results'],
  ['problem_solution', 'Problem → Solution', 'Names the pain, then shows the fix'],
  ['green_screen', 'Green screen', 'Talks over your page, a post or a screenshot'],
  ['before_after', 'Before / After', 'Cuts or splits from before to after'],
  ['unboxing', 'Unboxing', 'Opens the package, first impressions'],
  ['comparison', 'Comparison', 'Yours next to the usual alternative'],
  ['pov_hook', 'POV hook', 'An on-screen text hook: "POV: you finally…"'],
  ['asmr', 'ASMR', 'Close, quiet, textures and sounds'],
  ['day_in_life', 'Day in the life', 'The product inside a real routine'],
  ['skit', 'Skit', 'A short scene with a twist'],
  ['listicle', 'Listicle', '"3 reasons I switched…"'],
  ['founder_story', 'Founder story', 'Why it was made, told by its maker'],
  ['reaction', 'Reaction', 'A silent reaction with a headline'],
]
const TYPE_LABEL = Object.fromEntries(TYPES.map(([k, l]) => [k, l]))
// Listed, not offered yet (the API refuses them): skit needs every person cast first; POV hook and reaction come next.
const SOON = ['skit', 'pov_hook', 'reaction']
const PACE = [['slower', 'Slower'], ['natural', 'Natural'], ['faster', 'Faster']]
const DELIVERY = [['friendly', 'Friendly'], ['excited', 'Excited'], ['calm', 'Calm expert'], ['deadpan', 'Deadpan']]
const LABEL = Object.fromEntries([...PACE, ...DELIVERY])
const LENGTHS = [null, 15, 30, 45, 60]

const chatId = ref(route.params.chatId || null)
const chat = ref(null)
const recent = ref([])
const balance = ref(null)
const characters = ref([])
const error = ref('')
const sending = ref(false)
const text = ref('')
const uploads = ref([])
const link = ref(null)
const presenters = ref([])
const sameVoice = ref(false)
const type = ref('auto')
const length = ref(null)
const menu = ref('')
const expanded = ref(null)
// Every presenter, in a window opened from the More card (owner, 2026-10-11); the prompt box shows only a few.
const castModal = ref(false)
// Where presenters can be filmed, each with its background photo.
const settings = ref([])
const settingLabel = computed(() => Object.fromEntries(settings.value.map(s => [s.key, s.label])))
// An actor or character opened for a closer look before they are added (owner, 2026-10-10).
const previewing = ref(null)
const rewriteState = ref('')
const rewriteDismissed = ref('')
const confirmFor = ref(null)
const consent = ref(false)
const making = ref(false)
const ownPhoto = ref(null)
const ownConsent = ref(false)
const ownName = ref('')
const ownBusy = ref(false)
const thread = ref(null)
const prompt = ref(null)
const fileInput = ref(null)
const sampleInput = ref(null)
const photoInput = ref(null)
let poll = null
let disposed = false

const messages = computed(() => chat.value?.messages ?? [])
const title = computed(() => chat.value?.title || 'New ad')
const last = computed(() => messages.value[messages.value.length - 1] ?? null)
const stock = computed(() => characters.value.filter(c => c.is_stock))
const mine = computed(() => characters.value.filter(c => !c.is_stock))
const byId = computed(() => new Map(characters.value.map(c => [c.id, c])))
const imageOf = c => c?.reference_asset?.thumbnail_url || ''
const hasUploading = computed(() => uploads.value.some(u => u.uploading))
const canSend = computed(() => !sending.value && !hasUploading.value && (text.value.trim() || uploads.value.some(u => u.asset_id) || link.value))

const trayItems = computed(() => [
  ...uploads.value.map(u => ({ key: u.key, title: u.role === 'sample' ? `Sample · ${u.name}` : u.name, type: u.type, url: u.preview, uploading: u.uploading, progress: u.progress, error: u.error, upload: u })),
  ...(link.value ? [{ key: 'link', title: link.value.replace(/^https?:\/\//, ''), link: true, isLink: true }] : []),
])

const placeholder = computed(() => {
  if (!last.value) return 'Describe your ad, paste your script, or drop your product photos, footage and link…'
  if (last.value.kind === 'question') return 'Answer above, or type anything…'
  if (last.value.kind === 'pitch' && !last.value.payload?.made) return 'Say a change, or press Make it'
  if (last.value.kind === 'making') return 'Say a change for the next one…'
  return 'Type anything…'
})

// A pasted script (in quotes, or long enough to be spoken words) gets the "Rewrite with AI?" offer once.
const looksLikeScript = computed(() => {
  const t = text.value.trim()
  return /^["“'‘]/.test(t) && t.split(/\s+/).length >= 8
})
const showRewrite = computed(() => looksLikeScript.value && rewriteDismissed.value !== text.value.trim() && rewriteState.value !== 'done')

const presenterLabel = computed(() => {
  if (!presenters.value.length) return 'Let us pick'
  return presenters.value.map(p => p.name).join(' + ') + (presenters.value.length === 1 && presenters.value[0].voice ? ' · ' + (LABEL[presenters.value[0].voice.delivery] || 'friendly').toLowerCase() : '')
})
const shapeLabel = computed(() => '9:16 · ' + (length.value ? `${length.value} s` : 'auto length'))
// A few faces in the prompt box, the chosen ones first; the rest are behind More.
const filteredStock = computed(() => {
  const on = id => presenters.value.some(p => p.character_id === id)
  return [...stock.value.filter(c => on(c.id)), ...stock.value.filter(c => !on(c.id))].slice(0, 6)
})

function voiceText(p) {
  const place = p.setting && settingLabel.value[p.setting] ? ` · ${settingLabel.value[p.setting]}` : ''
  if (!p.voice) return (p.character_id ? 'Default voice' : 'Voice: set by the model') + place
  return [LABEL[p.voice.pace] || 'Natural', LABEL[p.voice.delivery] || 'Friendly'].join(' · ') + (p.voice.direction ? ` · "${p.voice.direction}"` : '') + place
}
function addPresenter(c, setting = null) {
  presentersTouched = true
  if (c && !c.is_stock && !canOwnCharacter.value) { error.value = 'Casting your own character is a Creator feature. Pick one of our actors, or upgrade to use your own.'; return }
  if (c && presenters.value.some(p => p.character_id === c.id)) return
  if (!c && presenters.value.some(p => !p.character_id)) return
  if (presenters.value.length >= 5) { error.value = 'Five presenters at most, one video each.'; return }
  const voice = sameVoice.value && presenters.value[0]?.voice ? { ...presenters.value[0].voice } : null
  presenters.value.push({ key: crypto.randomUUID(), character_id: c?.id ?? null, name: c ? c.name : 'Picked for you', image: imageOf(c), voice, setting })
}
const AGE = { young_adult: 'Young adult', adult: 'Adult', senior: 'Senior' }
const previewList = computed(() => previewing.value?.is_stock ? stock.value : mine.value)
const previewTags = computed(() => {
  const c = previewing.value
  if (!c) return []
  return [c.is_stock ? 'Actor' : 'Your character', AGE[c.age_group], c.gender ? c.gender[0].toUpperCase() + c.gender.slice(1) : '', ...(c.situations || []).slice(0, 3)].filter(Boolean)
})
const previewAdded = computed(() => !!previewing.value && presenters.value.some(p => p.character_id === previewing.value.id))
function stepPreview(dir) {
  const list = previewList.value, i = list.findIndex(c => c.id === previewing.value?.id)
  if (i >= 0 && list.length) previewing.value = list[(i + dir + list.length) % list.length]
}
function addPreviewed() { const c = previewing.value; if (c) { addPresenter(c); previewing.value = null } }
function removePresenter(p) {
  presentersTouched = true; presenters.value = presenters.value.filter(x => x !== p); if (expanded.value === p.key) expanded.value = null }
function setVoice(p, key, value) {
  presentersTouched = true
  const apply = x => { x.voice = { pace: 'natural', delivery: 'friendly', direction: '', ...(x.voice || {}), [key]: value } }
  if (sameVoice.value) presenters.value.forEach(apply)
  else apply(p)
}
watch(sameVoice, on => { if (on && presenters.value[0]?.voice) presenters.value.forEach(x => { x.voice = { ...presenters.value[0].voice } }) })

function toggleMenu(name) { menu.value = menu.value === name ? '' : name; previewing.value = null; if (menu.value === 'cast') { loadCharacters(); loadSettings() } }
function openCastModal() { menu.value = ''; previewing.value = null; castModal.value = true; loadCharacters(); loadSettings() }
function setPlace(p, key) { presentersTouched = true; p.setting = key }
function modalRemove(id) { const p = presenters.value.find(x => x.character_id === id); if (p) removePresenter(p) }
function modalSetPlace({ character_id: id, setting }) { const p = presenters.value.find(x => x.character_id === id); if (p) setPlace(p, setting) }
function modalUpload() { castModal.value = false; menu.value = 'cast'; pickPhoto() }
function escMenus(e) { if (e.key === 'Escape') menu.value = '' }
function closeMenus(e) { if (!e.target.closest('.uc-pop, .uc-pill, .uc-plus')) menu.value = '' }

async function loadCharacters() {
  if (characters.value.length) return
  try {
    const { data } = await api.get('/characters', { params: { include_stock: 1 } })
    characters.value = (data?.data?.characters ?? []).filter(c => c.status !== 'archived')
    restorePresenters()
  } catch { /* the picker shows "Let us pick" alone */ }
}
// The photos' signed links last 12 hours: a tab left open longer fetches fresh ones.
let settingsAt = 0
async function loadSettings() {
  if (settings.value.length && Date.now() - settingsAt < 6 * 3600 * 1000) return
  try { const { data } = await api.get('/ugc/settings'); settings.value = data?.data?.settings ?? []; settingsAt = Date.now() } catch { /* Auto only: the director picks the place */ }
}
async function loadBalance() {
  try { const { data } = await api.get('/me'); balance.value = data?.data?.credits?.balance ?? null } catch { /* cosmetic */ }
}
async function loadRecent() {
  try { const { data } = await api.get('/ugc/chats'); recent.value = (data?.data?.chats ?? []).slice(0, 4) } catch { /* optional */ }
}

// Files: photos are product references, clips are footage; "Sample UGC" marks an ad they like (its structure is read).
function pickFiles(role) { menu.value = ''; (role === 'sample' ? sampleInput : fileInput).value?.click() }
function onFiles(e, role) { addFiles([...(e.target.files || [])], role); e.target.value = '' }
function onDrop(e) { const files = [...(e.dataTransfer?.files || [])]; if (files.length) addFiles(files) }
function onPaste(e) {
  const files = [...(e.clipboardData?.files || [])]
  if (files.length) { e.preventDefault(); addFiles(files) }
}
function addFiles(files, role = null) {
  for (const file of files) {
    const kind = file.type.startsWith('video/') ? 'video' : file.type.startsWith('image/') ? 'image' : null
    if (!kind) { error.value = `${file.name}: use a photo or a video clip.`; continue }
    if (file.size > 104857600) { error.value = `${file.name} is larger than 100 MB.`; continue }
    if (role === 'sample' && kind !== 'video') { error.value = 'A sample UGC is a video clip.'; continue }
    if (uploads.value.length >= 12) { error.value = 'Twelve files at most.'; break }
    const u = { key: crypto.randomUUID(), name: file.name, type: kind, role: role || (kind === 'image' ? 'product' : 'footage'), preview: URL.createObjectURL(file), uploading: true, progress: 0, error: '', asset_id: null }
    uploads.value.push(u)
    // The reactive copy: progress and the finished asset id must reach the pill and the Send button.
    upload(uploads.value[uploads.value.length - 1], file)
  }
}
async function upload(u, file) {
  const fd = new FormData()
  fd.append('title', file.name.replace(/\.[^.]+$/, ''))
  fd.append('asset_type', u.type)
  fd.append('asset_file', file)
  try {
    const { data } = await api.post('/assets', fd, { headers: { 'Content-Type': 'multipart/form-data' }, onUploadProgress: ev => { u.progress = Math.min(99, Math.round(100 * ev.loaded / (ev.total || file.size))) } })
    u.asset_id = data?.data?.asset?.id ?? null
    if (!u.asset_id) throw Error('The upload did not finish.')
  } catch (err) {
    u.error = apiErrorMessage(err, 'Upload failed.')
  } finally {
    u.uploading = false
  }
}
function removeTray(i) {
  if (i.isLink) { dismissedLink.value = link.value; link.value = null; return }
  if (i.upload?.preview) URL.revokeObjectURL(i.upload.preview)
  uploads.value = uploads.value.filter(u => u !== i.upload)
}
function addLink() {
  menu.value = ''
  linkDraft.value = link.value || 'https://'
  linkOpen.value = true
  nextTick(() => document.getElementById('uc-link')?.focus())
}
const linkOpen = ref(false)
const linkDraft = ref('')
function saveLink() {
  const v = linkDraft.value.trim()
  if (v && /^https?:\/\/\S+\.\S+/.test(v)) { link.value = v; linkOpen.value = false; error.value = '' }
  else error.value = 'Paste the full product page address, starting with https://'
}
// A product link pasted into the text becomes the page we read (unless its chip was removed).
const dismissedLink = ref('')
watch(text, t => {
  if (link.value) return
  const m = t.match(/https?:\/\/[^\s"”]+\.[^\s"”]+/)
  const url = m ? m[0].replace(/[).,]+$/, '') : ''
  if (url && url !== dismissedLink.value) link.value = url
})

// Your own presenter: a photo of someone you have the right to use becomes a saved character.
function pickPhoto() { photoInput.value?.click() }
function onPhoto(e) {
  const file = e.target.files?.[0]; e.target.value = ''
  if (!file) return
  if (!canOwnCharacter.value) { error.value = 'Using your own photo is a Creator feature. Pick one of our actors, or upgrade.'; return }
  if (!file.type.startsWith('image/')) { error.value = 'Use a photo (PNG, JPEG or WebP).'; return }
  if (ownPhoto.value?.preview) URL.revokeObjectURL(ownPhoto.value.preview)
  ownPhoto.value = { file, preview: URL.createObjectURL(file) }
  ownName.value = ''
  ownConsent.value = false
}
function cancelOwn() { if (ownPhoto.value?.preview) URL.revokeObjectURL(ownPhoto.value.preview); ownPhoto.value = null }
async function saveOwn() {
  if (!ownPhoto.value || !ownConsent.value || ownBusy.value) return
  ownBusy.value = true
  try {
    const fd = new FormData()
    const name = ownName.value.trim() || 'My presenter'
    fd.append('title', name); fd.append('asset_type', 'image'); fd.append('asset_file', ownPhoto.value.file)
    const up = await api.post('/assets', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    const assetId = up.data?.data?.asset?.id
    const { data } = await api.post('/characters', { name, reference_asset_id: assetId, reference_asset_ids: [assetId], consent: true })
    const c = data?.data?.character
    if (c) { characters.value = [c, ...characters.value]; addPresenter(c) }
    URL.revokeObjectURL(ownPhoto.value.preview)
    ownPhoto.value = null
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not add that presenter.')
  } finally {
    ownBusy.value = false
  }
}

// One chat however many first actions race (Rewrite and Send both create it on a new page).
let creating = null
async function ensureChat() {
  if (chatId.value) return chatId.value
  creating ??= api.post('/ugc/chats').then(({ data }) => {
    chatId.value = data.data.id
    chat.value = data.data
    router.replace({ name: 'ugc-chat', params: { chatId: chatId.value } })
    return chatId.value
  }).finally(() => { creating = null })
  return creating
}

async function rewrite() {
  if (rewriteState.value === 'busy') return
  rewriteState.value = 'busy'
  try {
    const id = await ensureChat()
    const asked = text.value
    const { data } = await api.post(`/ugc/chats/${id}/rewrite`, { script: asked.trim().replace(/^["“'‘]|["”'’]$/g, '') })
    // Only if the box still holds what was rewritten (it may have been sent or edited meanwhile).
    if (text.value === asked) { text.value = `"${data.data.script}"`; rewriteState.value = 'done' } else rewriteState.value = ''
  } catch (err) {
    rewriteState.value = ''
    error.value = apiErrorMessage(err, 'Could not rewrite it just now.')
  }
}

async function send(override = null) {
  const body = override ?? text.value.trim()
  if (sending.value || hasUploading.value || rewriteState.value === 'busy') return
  if (!override && !canSend.value) return
  error.value = ''
  sending.value = true
  const attachments = override ? [] : uploads.value.filter(u => u.asset_id).map(u => ({ asset_id: u.asset_id, role: u.role }))
  const shown = override ? [] : uploads.value.filter(u => u.asset_id).map(u => ({ asset_id: u.asset_id, role: u.role, title: u.name, type: u.type, url: u.preview }))
  const sentLink = override ? null : link.value
  try {
    const id = await ensureChat()
    // Shown at once; the server's copy replaces it when the reply comes back.
    chat.value = { ...chat.value, messages: [...messages.value, { id: 'tmp', role: 'user', kind: 'text', content: body, payload: { attachments: shown, link: sentLink } }] }
    const sentUploads = override ? [] : uploads.value
    if (!override) { text.value = ''; uploads.value = []; link.value = null; rewriteState.value = ''; rewriteDismissed.value = '' }
    setTimeout(() => sentUploads.forEach(u => u.preview && URL.revokeObjectURL(u.preview)), 60000)
    scrollDown()
    const { data } = await api.post(`/ugc/chats/${id}/messages`, {
      text: body, attachments, link: sentLink, type: type.value, length: length.value,
      // Only once the presenters are known: before the actor list loads they look empty and would wipe the chat's.
      ...(presentersKnown() ? { presenters: presenters.value.map(p => ({ character_id: p.character_id, voice: p.voice, setting: p.setting || null })) } : {}),
    }, { timeout: 180000 })
    if (id !== chatId.value) return // the user moved to another chat meanwhile
    chat.value = data.data.chat
    if (data.data.chat?.state?.type) type.value = data.data.chat.state.type
    confirmFor.value = null
    loadRecent()
  } catch (err) {
    if (notAvailable(err)) return
    // A slow reply may still land: keep watching for it rather than inviting a duplicate.
    error.value = err?.code === 'ECONNABORTED' ? 'Still thinking. The reply will appear here.' : apiErrorMessage(err, 'That did not go through. Try again.')
    if (chatId.value) { await load(); if (last.value?.role === 'user') awaitReply() }
  } finally {
    sending.value = false
    scrollDown()
    schedulePoll()
  }
}
function awaitReply(tries = 24) {
  clearTimeout(poll)
  poll = setTimeout(async () => { await load(); if (last.value?.role === 'user' && tries > 1) awaitReply(tries - 1); else { error.value = ''; scrollDown(); schedulePoll() } }, 5000)
}
function presentersKnown() { return presentersTouched || !(chat.value?.state?.presenters?.length) || restoredPresenters }
let presentersTouched = false
let restoredPresenters = false

function canMake(m) { return m.kind === 'pitch' && !m.payload?.made && m.id === lastPitchId.value }
const lastPitchId = computed(() => [...messages.value].reverse().find(m => m.kind === 'pitch')?.id)
function short(m) { return balance.value !== null && balance.value < (m.payload?.credits ?? 0) }
function startMake(m) { confirmFor.value = m.id; consent.value = false }
async function make(m) {
  if (!consent.value || making.value) return
  making.value = true
  error.value = ''
  try {
    if (sending.value) return
    const { data } = await api.post(`/ugc/chats/${chatId.value}/make`, { message_id: m.id, request_id: m.requestKey || (m.requestKey = crypto.randomUUID()), consent: true }, { timeout: 120000 })
    chat.value = data.data.chat
    confirmFor.value = null
    loadBalance()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not start it. Nothing was charged.')
    await load()
  } finally {
    making.value = false
    scrollDown()
    schedulePoll()
  }
}

function pitchNames(m) { return (m.payload?.takes ?? []).map(t => t.name).join(', ') }
function pitchSeconds(m) { return Math.round((m.payload?.plan?.segments ?? []).reduce((s, x) => s + Number(x.seconds || 0), 0)) }
function pitchImage(m) { const t = m.payload?.takes?.[0]; return t?.character_id ? imageOf(byId.value.get(t.character_id)) : '' }
function takeOf(run) { return run.takes?.[0] ?? null }
function runState(run) { const t = takeOf(run); return !t ? 'generating' : t.status === 'ready_for_review' ? 'ready' : t.status === 'needs_attention' ? 'failed' : 'generating' }
// A pitch says what its videos are doing now, read from the "making" message that started them.
function pitchState(m) {
  const runs = messages.value.find(x => x.kind === 'making' && x.payload?.pitch_id === m.id)?.payload?.runs ?? []
  const states = runs.map(runState)
  return !states.length || states.includes('generating') ? 'making' : states.every(x => x === 'ready') ? 'ready' : 'failed'
}
function isMaking(m) { return m.kind === 'making' && (m.payload?.runs ?? []).some(r => runState(r) === 'generating') }
const retrying = ref('')
async function retryRun(m, ri) {
  if (retrying.value) return
  retrying.value = `${m.id}:${ri}`
  error.value = ''
  try {
    const { data } = await api.post(`/ugc/chats/${chatId.value}/retry`, { message_id: m.id, run: ri }, { timeout: 120000 })
    chat.value = data.data.chat
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not start it again.')
  } finally {
    retrying.value = ''
    schedulePoll()
  }
}
// Download hands over the finished file (captions burned in): the take's export, started here when there is none yet.
const finishing = ref({})
const ready = ref({})
async function download(run) {
  const t = takeOf(run)
  if (!t || finishing.value[t.id]) return
  finishing.value = { ...finishing.value, [t.id]: 'Preparing…' }
  error.value = ''
  try {
    // The finished file is made once the take is ready (initial finishing); ask for it if it has not started yet.
    // The newest export is the one to wait on (an older completed one may predate a revision).
    let job = null
    for (let i = 0; i < 120; i++) {
      const jobs = (await api.get(`/projects/${t.id}/exports`)).data?.data?.export_jobs ?? []
      job = jobs[0] ?? null
      if (!job && i === 0) job = (await api.post(`/projects/${t.id}/export`, { initial: true })).data?.data?.export_job ?? null
      if (!job && i > 2) throw new Error('This video could not be finished here. Open it with Review & share.')
      if (job?.status === 'completed' || job?.status === 'failed') break
      await new Promise(r => setTimeout(r, 3000))
      if (disposed || takeOf(run)?.id !== t.id) return
    }
    if (job?.status === 'failed') throw new Error(job.failure_reason || 'The video could not be finished. Try again.')
    const url = job?.status === 'completed' ? job.output_asset?.storage_url : null
    if (!url) throw new Error('The video is taking long to finish. Try again in a minute.')
    // A link the user clicks: a window opened after this long wait would be blocked as a pop-up.
    ready.value = { ...ready.value, [t.id]: url }
  } catch (err) {
    error.value = err?.response ? apiErrorMessage(err, 'Could not prepare the download.') : err.message
  } finally {
    const { [t.id]: _, ...rest } = finishing.value
    finishing.value = rest
  }
}
function openReview(run) { const t = takeOf(run); if (t) router.push({ name: 'ugc-review', params: { projectId: t.id } }) }
function openEditor(run) { const t = takeOf(run); if (t) router.push({ name: 'project-editor', params: { projectId: t.id } }) }
function openRun(run) { if (run.run_id) router.push({ name: 'ugc-run', params: { runId: run.run_id } }) }
function ago(at) {
  const d = new Date(String(at).replace(' ', 'T') + (String(at).includes('Z') || String(at).includes('+') ? '' : 'Z'))
  const mins = Math.round((Date.now() - d) / 60000)
  return mins < 60 ? `${Math.max(1, mins)}m` : mins < 1440 ? `${Math.round(mins / 60)}h` : `${Math.round(mins / 1440)}d`
}
function questionNumber(m) { return messages.value.filter(x => x.kind === 'question' && x.id <= m.id).length }
function isLatest(m) { return last.value?.id === m.id }

async function load() {
  if (!chatId.value) return
  const id = chatId.value
  try {
    const { data } = await api.get(`/ugc/chats/${id}`)
    if (disposed || id !== chatId.value) return
    chat.value = data.data
    restoreSettings()
  } catch (err) {
    if (notAvailable(err)) return
    if (err?.response?.status === 404) { router.replace({ name: 'ugc-new' }); return }
    error.value = apiErrorMessage(err, 'Could not load this chat.')
  }
}
let restored = false
function restoreSettings() {
  if (restored || !chat.value?.state) return
  restored = true
  const s = chat.value.state
  type.value = s.type || 'auto'
  length.value = s.length || null
  restorePresenters()
}
function restorePresenters() {
  const s = chat.value?.state
  if (!s?.presenters?.length || presenters.value.length || !characters.value.length) return
  restoredPresenters = true
  presenters.value = s.presenters.map(p => {
    const c = p.character_id ? byId.value.get(p.character_id) : null
    return { key: crypto.randomUUID(), character_id: p.character_id ?? null, name: c ? c.name : 'Picked for you', image: imageOf(c), voice: p.voice ?? null, setting: p.setting ?? null }
  })
}
function schedulePoll() {
  clearTimeout(poll)
  if (disposed || !messages.value.some(isMaking)) return
  poll = setTimeout(async () => { await load(); schedulePoll() }, 8000)
}
function scrollDown() { nextTick(() => { if (thread.value) thread.value.scrollTop = thread.value.scrollHeight }) }
// Enter sends, except mid-composition (Safari reports keyCode 229 there) and on touch screens, where Enter is a newline.
const touch = typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches
function onKey(e) {
  if (e.key === 'Escape') { menu.value = ''; return }
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229 && !touch) { e.preventDefault(); send() }
}
function focusPrompt() { prompt.value?.focus() }

watch(() => route.params.chatId, id => {
  if ((id || null) === chatId.value) return
  clearTimeout(poll)
  chatId.value = id || null; chat.value = null; restored = false; restoredPresenters = false; presentersTouched = false; presenters.value = []; type.value = 'auto'; length.value = null
  if (id) load().then(() => { scrollDown(); schedulePoll() })
})

// Staff-only until UGC_CHAT_OPEN_TO_ALL: everyone else makes UGC with the step-by-step form. Capabilities load after
// the page on a hard load, so this is checked when they arrive, and on the API's own answer.
function notAvailable(err) {
  if (err?.response?.status === 404 && err?.response?.data?.error?.code === 'not_available') { router.replace({ name: 'ugc-wizard' }); return true }
  return false
}
watch(() => workspaceStore.capabilities, caps => {
  if (!caps || disposed) return
  if (caps.ugc_ads === false) router.replace({ name: 'ugc-ads' })
  else if (caps.ugc_chat === false) router.replace({ name: 'ugc-wizard' })
}, { immediate: true })

onMounted(async () => {
  if (workspaceStore.capabilities && !canUgc.value) return
  if (workspaceStore.capabilities?.ugc_chat === false) return
  document.addEventListener('pointerdown', closeMenus)
  document.addEventListener('keydown', escMenus)
  loadBalance(); loadCharacters(); loadRecent()
  if (chatId.value) { await load(); scrollDown(); schedulePoll() }
})
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(poll)
  document.removeEventListener('pointerdown', closeMenus)
  document.removeEventListener('keydown', escMenus)
  if (ownPhoto.value?.preview) URL.revokeObjectURL(ownPhoto.value.preview)
  uploads.value.forEach(u => u.preview && URL.revokeObjectURL(u.preview))
})
</script>

<template>
  <div class="uc-shell" @dragover.prevent @drop.prevent="onDrop">
    <AppSidebar :user="authStore.user" active-page="ugc-ads" @logout="authStore.logout()" />
    <main class="uc-main">
      <header class="uc-top">
        <div class="uc-crumb"><router-link :to="{ name: 'ugc-ads' }">UGC Ads</router-link> / <b>{{ title }}</b></div>
        <div class="uc-top-r">
          <router-link class="uc-ghost" :to="{ name: 'ugc-ads' }">My ads</router-link>
          <span v-if="balance !== null" class="uc-credits">◆ <b>{{ balance.toLocaleString() }}</b> credits</span>
        </div>
      </header>

      <div ref="thread" class="uc-thread">
        <section v-if="!messages.length" class="uc-hero">
          <h1>Make a UGC ad from one prompt</h1>
          <p>Add your product, a link, a sample ad you like and your script, in any order. Pick a presenter or let us. We ask only what we can't work out, then make one video. You see the price first.</p>
          <div v-if="stock.length" class="uc-faces">
            <span class="uc-face-row"><img v-for="c in stock.slice(0, 5)" :key="c.id" :src="imageOf(c)" alt="" /></span>
            <span>{{ stock.length }} actors, your photo or your characters</span>
          </div>
          <div v-if="recent.length" class="uc-recent">
            <span class="uc-label">Pick up where you left off</span>
            <router-link v-for="c in recent" :key="c.id" :to="{ name: 'ugc-chat', params: { chatId: c.id } }">{{ c.title }} <span class="uc-muted">· {{ ago(c.updated_at) }}</span></router-link>
          </div>
          <router-link class="uc-wizard" :to="{ name: 'ugc-wizard' }">Prefer the step-by-step form?</router-link>
        </section>

        <div v-else class="uc-msgs">
          <template v-for="m in messages" :key="m.id">
            <div v-if="m.role === 'user'" class="uc-user">
              <div class="uc-bubble"><span v-if="m.payload?.attachments?.length || m.payload?.link" class="uc-sent">
                  <span v-for="a in m.payload.attachments || []" :key="a.asset_id" class="uc-sent-pill" :title="a.title"><RefThumb :type="a.type" :url="a.url" /><span>{{ a.role === 'sample' ? 'Sample · ' : '' }}{{ a.title || 'File' }}</span></span>
                  <span v-if="m.payload?.link" class="uc-sent-pill" :title="m.payload.link"><RefThumb link /><span>{{ m.payload.link.replace(/^https?:\/\//, '') }}</span></span>
                </span><span v-if="m.content && m.content.trim() !== m.payload?.link" class="uc-said">{{ m.content }}</span></div>
            </div>

            <div v-else class="uc-bot">
              <span class="uc-mark" aria-hidden="true">W</span>

              <div v-if="m.kind === 'facts'" class="uc-card uc-facts">
                <p v-for="(f, i) in m.payload.facts" :key="i" class="uc-fact"><span class="uc-label uc-label--ok">From your {{ f.source }}</span> {{ f.text }}</p>
              </div>

              <div v-else-if="m.kind === 'question'" class="uc-card">
                <span class="uc-label uc-label--accent">Question {{ questionNumber(m) }}</span>
                <p class="uc-q">{{ m.content }}</p>
                <div v-if="m.payload?.chips?.length" class="uc-chips">
                  <button v-for="c in m.payload.chips" :key="c" type="button" class="uc-chip" :disabled="!isLatest(m) || sending" @click="send(c)">{{ c }}</button>
                  <button v-if="isLatest(m)" type="button" class="uc-chip uc-chip--type" @click="focusPrompt">Type something…</button>
                </div>
              </div>

              <div v-else-if="m.kind === 'pitch'" :class="['uc-card', 'uc-pitch', { 'is-live': canMake(m) }]">
                <div class="uc-pitch-body">
                  <img v-if="pitchImage(m)" class="uc-pitch-img" :src="pitchImage(m)" alt="" />
                  <div>
                    <h3>{{ m.payload.title }}</h3>
                    <p>{{ m.content }}</p>
                    <div class="uc-tags">
                      <span class="uc-tag uc-tag--type">{{ TYPE_LABEL[m.payload.type] || m.payload.type }}</span>
                      <span class="uc-tag">{{ pitchNames(m) }}<template v-if="m.payload.takes.some(t => t.picked)"> · picked for you</template></span>
                      <span class="uc-tag">{{ pitchSeconds(m) }} s</span>
                      <span class="uc-tag">{{ m.payload.aspect }}</span>
                    </div>
                    <details class="uc-script"><summary>The script</summary><p>{{ m.payload.plan?.script }}</p></details>
                    <p v-if="canMake(m) && !m.payload.product_asset_ids?.length" class="uc-tip">No product photo yet, so it will be a generic one. Add a photo with + and send it to show yours at its real size.</p>
                  </div>
                </div>
                <div class="uc-pitch-foot">
                  <span><b>{{ m.payload.takes.length }} video{{ m.payload.takes.length === 1 ? '' : 's' }}</b> · <b>{{ m.payload.credits }}</b> credits</span>
                  <template v-if="canMake(m)">
                    <router-link v-if="short(m)" class="uc-btn" :to="{ name: 'plans' }">Top up · you have {{ balance }}</router-link>
                    <button v-else-if="confirmFor !== m.id" type="button" class="uc-btn uc-btn--primary" :disabled="sending" @click="startMake(m)">Make it</button>
                  </template>
                  <span v-else-if="m.payload.made" :class="['uc-pitch-state', 'is-' + pitchState(m)]">{{ { making: 'Making…', ready: 'Made', failed: 'Needs a look' }[pitchState(m)] }}</span>
                </div>
                <div v-if="confirmFor === m.id && canMake(m)" class="uc-consent">
                  <label><input v-model="consent" type="checkbox" /> I have the right to use {{ pitchNames(m) === 'A fitting presenter' ? "the presenter's" : pitchNames(m) + "'s" }} likeness and voice in this ad, and the product facts I gave are accurate.</label>
                  <div class="uc-consent-row">
                    <button type="button" class="uc-btn" @click="confirmFor = null">Not yet</button>
                    <button type="button" class="uc-btn uc-btn--primary" :disabled="!consent || making" @click="make(m)">{{ making ? 'Starting…' : `Make it · ${m.payload.credits} credits` }}</button>
                  </div>
                </div>
              </div>

              <div v-else-if="m.kind === 'making'" class="uc-runs">
                <div v-for="(run, ri) in m.payload.runs" :key="run.run_id || run.name" class="uc-run">
                  <div v-if="runState(run) === 'generating'" class="uc-card uc-making">
                    <b>Making {{ run.name === 'A fitting presenter' ? 'your video' : `${run.name}'s video` }}</b>
                    <span class="uc-spin" aria-hidden="true"></span>
                    <p class="uc-muted">Usually a few minutes. You can leave this page; it carries on, and it will be in My ads.</p>
                  </div>
                  <div v-else-if="runState(run) === 'ready'" class="uc-ready">
                    <video v-if="takeOf(run)?.thumbnail_type === 'video'" class="uc-video" :src="takeOf(run).thumbnail_url + '#t=0.5'" controls playsinline preload="metadata"></video>
                    <img v-else-if="takeOf(run)?.thumbnail_url" class="uc-video" :src="takeOf(run).thumbnail_url" alt="" />
                    <div class="uc-ready-side">
                      <h3>Your video is ready</h3>
                      <p class="uc-muted">{{ run.name }} · {{ takeOf(run)?.credits ?? run.credits }} credits</p>
                      <div class="uc-ready-btns">
                        <a v-if="ready[takeOf(run)?.id]" class="uc-btn uc-btn--primary" :href="ready[takeOf(run)?.id]" target="_blank" rel="noopener" download>Save video</a>
                        <button v-else type="button" class="uc-btn uc-btn--primary" :disabled="!!finishing[takeOf(run)?.id]" @click="download(run)">{{ finishing[takeOf(run)?.id] || 'Download' }}</button>
                        <button type="button" class="uc-btn" @click="openReview(run)">Review & share</button>
                        <button type="button" class="uc-btn" @click="openEditor(run)">Fine-tune in editor</button>
                      </div>
                    </div>
                  </div>
                  <div v-else class="uc-card uc-failed">
                    <b>{{ run.name === 'A fitting presenter' ? 'Your' : `${run.name}'s` }} video didn't come out.</b>
                    <p class="uc-muted">Nothing was charged for it. Try again picks up where it stopped.</p>
                    <div class="uc-ready-btns">
                      <button type="button" class="uc-btn uc-btn--sm uc-btn--primary" :disabled="retrying === `${m.id}:${ri}`" @click="retryRun(m, ri)">{{ retrying === `${m.id}:${ri}` ? 'Starting…' : 'Try again' }}</button>
                      <button type="button" class="uc-link-btn" @click="openRun(run)">Details</button>
                    </div>
                  </div>
                </div>
                <p v-if="m.content && m.content !== 'Making it now.'" class="uc-muted">{{ m.content }}</p>
              </div>

              <div v-else class="uc-card uc-err">{{ m.content }}</div>
            </div>
          </template>
          <div v-if="sending" class="uc-bot"><span class="uc-mark" aria-hidden="true">W</span><div class="uc-card uc-thinking"><span class="uc-spin"></span> Thinking it through…</div></div>
          <p v-if="last?.kind === 'pitch' && canMake(last)" class="uc-hint">Want something else? Say it below ("make it a before/after", "a man instead", "shorter") or change the type.</p>
        </div>
      </div>

      <div class="uc-dock">
        <p v-if="error" class="uc-error" role="alert">{{ error }} <button type="button" @click="error = ''">×</button></p>
        <div class="uc-box">
          <ComposerTray v-if="trayItems.length" :items="trayItems" :disabled="sending" @remove="removeTray" />
          <div v-if="linkOpen" class="uc-linkrow">
            <input id="uc-link" v-model="linkDraft" type="url" placeholder="https://yourstore.com/product" @keydown.enter.prevent="saveLink" @keydown.esc="linkOpen = false" />
            <button type="button" class="uc-btn" @click="linkOpen = false">Cancel</button>
            <button type="button" class="uc-btn uc-btn--primary" @click="saveLink">Add link</button>
          </div>
          <textarea id="ugc-prompt" ref="prompt" v-model="text" rows="2" aria-label="Describe your ad" :placeholder="placeholder" :disabled="sending" @keydown="onKey" @paste="onPaste"></textarea>
          <div v-if="showRewrite" class="uc-rewrite">
            <span><b>✦ Rewrite with AI?</b> A sharper first line, same length and meaning.</span>
            <span class="uc-rewrite-btns">
              <button type="button" class="uc-btn uc-btn--sm" @click="rewriteDismissed = text.trim()">Keep mine</button>
              <button type="button" class="uc-btn uc-btn--sm uc-btn--primary" :disabled="rewriteState === 'busy'" @click="rewrite">{{ rewriteState === 'busy' ? 'Rewriting…' : 'Rewrite' }}</button>
            </span>
          </div>
          <div class="uc-bar">
            <div class="uc-pop-anchor">
              <button type="button" class="uc-plus" aria-label="Add files or a link" @click="toggleMenu('add')">+</button>
              <div v-if="menu === 'add'" class="uc-pop uc-pop--add">
                <button type="button" @click="pickFiles(null)"><b>Product photos or footage</b><span>Photos are references for its real look and size, never the first frame</span></button>
                <button type="button" @click="pickFiles('sample')"><b>A sample UGC you like</b><span>We copy how it is built, never its words, face or music</span></button>
                <button type="button" @click="addLink"><b>Product link</b><span>We read the page for facts you confirm</span></button>
              </div>
            </div>

            <div class="uc-pop-anchor">
              <button type="button" class="uc-pill" @click="toggleMenu('cast')">
                <span v-if="presenters.length" class="uc-pill-faces"><img v-for="p in presenters.filter(x => x.image).slice(0, 3)" :key="p.key" :src="p.image" alt="" /><span v-if="presenters.some(x => !x.character_id)" class="uc-spark">✦</span></span>
                <span v-else class="uc-spark">✦</span>
                {{ presenterLabel }} <span class="uc-caret">▾</span>
              </button>
              <div v-if="menu === 'cast'" class="uc-pop uc-pop--cast">
                <div class="uc-pop-h">
                  <span class="uc-label">Presenters ({{ presenters.length }})</span>
                  <label v-if="presenters.length > 1" class="uc-switch"><input v-model="sameVoice" type="checkbox" /> Same voice for all</label>
                </div>
                <p v-if="!presenters.length" class="uc-muted uc-pad">None chosen: we pick who fits your product, and the model sets the voice from your prompt.</p>
                <div v-for="p in presenters" :key="p.key" :class="['uc-pres', { 'is-open': expanded === p.key }]">
                  <div class="uc-pres-h" role="button" tabindex="0" :aria-expanded="expanded === p.key" :aria-label="`${p.name} voice settings`" @click="expanded = expanded === p.key ? null : p.key" @keydown.enter.prevent="expanded = expanded === p.key ? null : p.key" @keydown.space.prevent="expanded = expanded === p.key ? null : p.key">
                    <img v-if="p.image" :src="p.image" alt="" /><span v-else class="uc-spark uc-spark--lg">✦</span>
                    <span class="uc-pres-t"><b>{{ p.name }}</b><small>{{ voiceText(p) }}</small></span>
                    <span class="uc-caret">{{ expanded === p.key ? '▴' : '▾' }}</span>
                    <button type="button" class="uc-x" :aria-label="`Remove ${p.name}`" @click.stop="removePresenter(p)">×</button>
                  </div>
                  <div v-if="expanded === p.key" class="uc-voice">
                    <span class="uc-vl">Pacing</span>
                    <span class="uc-opts"><button v-for="[k, l] in PACE" :key="k" type="button" :class="['uc-opt', { on: (p.voice?.pace || (p.voice ? 'natural' : '')) === k }]" @click="setVoice(p, 'pace', k)">{{ l }}</button></span>
                    <span class="uc-vl">Delivery</span>
                    <span class="uc-opts"><button v-for="[k, l] in DELIVERY" :key="k" type="button" :class="['uc-opt', { on: (p.voice?.delivery || (p.voice ? 'friendly' : '')) === k }]" @click="setVoice(p, 'delivery', k)">{{ l }}</button></span>
                    <span class="uc-vl">Direction</span>
                    <input :value="p.voice?.direction || ''" maxlength="300" placeholder="Optional, e.g. like he just found out" @change="setVoice(p, 'direction', $event.target.value)" />
                    <template v-if="settings.length">
                      <span class="uc-vl">Background</span>
                      <UgcBackgroundPicker :model-value="p.setting || null" :settings="settings" compact @update:model-value="k => setPlace(p, k)" />
                    </template>
                    <p v-if="!p.voice" class="uc-muted uc-vnote">{{ p.character_id ? 'Their default voice until you change one of these.' : 'The model sets the voice from your prompt. Change any of these to set it yourself.' }}</p>
                    <button v-else type="button" class="uc-link-btn" @click="p.voice = null">{{ p.character_id ? 'Back to their default voice' : 'Let the model set it' }}</button>
                  </div>
                </div>

                <div v-if="previewing" class="uc-preview">
                  <img v-if="imageOf(previewing)" :src="imageOf(previewing)" :alt="previewing.name" />
                  <span v-else class="uc-preview-none">{{ previewing.name.slice(0, 1) }}</span>
                  <div class="uc-preview-t">
                    <b>{{ previewing.name }}</b>
                    <p v-if="previewing.description">{{ previewing.description }}</p>
                    <span class="uc-preview-tags"><span v-for="t in previewTags" :key="t">{{ t }}</span></span>
                    <span class="uc-preview-btns">
                      <button type="button" class="uc-btn uc-btn--sm uc-btn--primary" :disabled="previewAdded" @click="addPreviewed">{{ previewAdded ? 'Added' : `Add ${previewing.name}` }}</button>
                      <button type="button" class="uc-btn uc-btn--sm" @click="previewing = null">Back</button>
                    </span>
                    <span v-if="previewList.length > 1" class="uc-preview-nav">
                      <button type="button" aria-label="Previous" @click="stepPreview(-1)">‹</button>
                      <button type="button" aria-label="Next" @click="stepPreview(1)">›</button>
                    </span>
                  </div>
                </div>
                <template v-else>
                <span class="uc-label uc-pad">Add a presenter</span>
                <div class="uc-add">
                  <button type="button" class="uc-add-i" title="Let us pick" @click="addPresenter(null)">✦</button>
                  <button type="button" class="uc-add-i" title="Upload a photo of you or someone you have the rights to" @click="pickPhoto">⇪</button>
                  <button v-for="c in filteredStock" :key="c.id" type="button" :class="['uc-add-face', { on: presenters.some(p => p.character_id === c.id) }]" :title="c.name" @click="previewing = c"><img :src="imageOf(c)" :alt="c.name" /></button>
                  <button v-if="stock.length > 6 || mine.length" type="button" class="uc-add-i uc-add-more" :title="`All ${stock.length + mine.length} presenters`" @click="openCastModal">More</button>
                </div>
                <template v-if="mine.length">
                  <span class="uc-label uc-pad">Your characters</span>
                  <div class="uc-add">
                    <button v-for="c in mine" :key="c.id" type="button" :class="['uc-add-face', { on: presenters.some(p => p.character_id === c.id) }]" :title="c.name" @click="previewing = c"><img v-if="imageOf(c)" :src="imageOf(c)" :alt="c.name" /><span v-else>{{ c.name.slice(0, 1) }}</span></button>
                  </div>
                </template>
                </template>
                <div v-if="ownPhoto" class="uc-own">
                  <img :src="ownPhoto.preview" alt="" />
                  <div>
                    <input v-model="ownName" maxlength="120" placeholder="Their name (optional)" />
                    <label><input v-model="ownConsent" type="checkbox" /> I have the rights and consent to use this person's likeness.</label>
                    <div class="uc-consent-row">
                      <button type="button" class="uc-btn uc-btn--sm" @click="cancelOwn">Cancel</button>
                      <button type="button" class="uc-btn uc-btn--sm uc-btn--primary" :disabled="!ownConsent || ownBusy" @click="saveOwn">{{ ownBusy ? 'Adding…' : 'Add presenter' }}</button>
                    </div>
                  </div>
                </div>
                <p class="uc-muted uc-pad">{{ presenters.length > 1 ? `One video per presenter: ${presenters.length} videos.` : 'One video. Add presenters to make one each.' }}</p>
              </div>
            </div>

            <div class="uc-pop-anchor">
              <button type="button" class="uc-pill" @click="toggleMenu('type')"><span class="uc-muted">Type:</span> {{ TYPE_LABEL[type] }} <span class="uc-caret">▾</span></button>
              <div v-if="menu === 'type'" class="uc-pop uc-pop--type">
                <span class="uc-label uc-pad">UGC type</span>
                <button v-for="[k, l, d] in TYPES" :key="k" type="button" :class="['uc-type', { on: type === k, soon: SOON.includes(k) }]" :disabled="SOON.includes(k)" @click="type = k; menu = ''"><b>{{ l }}<template v-if="type === k"> ✓</template><em v-if="SOON.includes(k)" class="uc-soon">Soon</em></b><span>{{ d }}</span></button>
              </div>
            </div>

            <div class="uc-pop-anchor">
              <button type="button" class="uc-pill" @click="toggleMenu('shape')">{{ shapeLabel }} <span class="uc-caret">▾</span></button>
              <div v-if="menu === 'shape'" class="uc-pop uc-pop--shape">
                <span class="uc-label uc-pad">Length</span>
                <button v-for="l in LENGTHS" :key="String(l)" type="button" :class="['uc-type', { on: length === l }]" @click="length = l; menu = ''"><b>{{ l ? `${l} seconds` : 'Auto' }}</b><span>{{ l ? '' : 'From your script, or what fits the type' }}</span></button>
                <p class="uc-muted uc-pad">Vertical 9:16 for now.</p>
              </div>
            </div>

            <button type="button" class="uc-send" :disabled="!canSend" aria-label="Send" @click="send()">↑</button>
          </div>
          <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm" multiple hidden @change="onFiles($event, null)" />
          <input ref="sampleInput" type="file" accept="video/mp4,video/quicktime,video/webm" hidden @change="onFiles($event, 'sample')" />
          <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/webp" hidden @change="onPhoto" />
        </div>
        <p class="uc-foot">One video unless you pick more presenters or ask · you see the price before anything is made</p>
      </div>
    </main>
    <UgcPresenterModal v-if="castModal" :stock="stock" :mine="mine" :presenters="presenters" :settings="settings" :error="error"
      @close="castModal = false" @add="({ character, setting }) => addPresenter(character, setting)" @remove="modalRemove"
      @set-setting="modalSetPlace" @pick-for-me="addPresenter(null)" @upload="modalUpload" />
  </div>
</template>

<style scoped>
.uc-shell { display: flex; height: 100vh; height: 100dvh; background: var(--color-bg-deep); color: var(--color-text-primary); }
.uc-main { margin-left: var(--sidebar-width, 220px); flex: 1; min-width: 0; display: flex; flex-direction: column; height: 100vh; height: 100dvh; }
.uc-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; border-bottom: 1px solid var(--color-border); }
.uc-crumb { font-size: 14px; color: var(--color-text-muted); }
.uc-crumb a { color: inherit; text-decoration: none; }
.uc-crumb b { color: var(--color-text-primary); font-weight: 600; }
.uc-top-r { display: flex; gap: 8px; align-items: center; }
.uc-ghost, .uc-credits { font-size: 13px; padding: 7px 12px; border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-secondary); text-decoration: none; }
.uc-credits b { color: var(--color-text-primary); }

.uc-thread { flex: 1; overflow-y: auto; padding: 24px 24px 12px; }
.uc-hero { max-width: 560px; margin: 40px auto 0; text-align: center; }
.uc-hero h1 { font-size: 26px; margin: 0 0 12px; text-wrap: balance; }
.uc-hero p { color: var(--color-text-secondary); font-size: 14.5px; line-height: 1.55; margin: 0; }
.uc-faces { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 22px; font-size: 13px; color: var(--color-text-muted); }
.uc-face-row { display: inline-flex; }
.uc-face-row img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid var(--color-bg-deep); margin-left: -8px; }
.uc-face-row img:first-child { margin-left: 0; }
.uc-recent { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; margin-top: 26px; align-items: center; }
.uc-recent a { font-size: 12.5px; padding: 5px 10px; border-radius: 999px; border: 1px solid var(--color-border); color: var(--color-text-secondary); text-decoration: none; }
.uc-recent a:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.uc-wizard { display: inline-block; margin-top: 18px; font-size: 12.5px; color: var(--color-text-muted); }

.uc-msgs { max-width: 820px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }
.uc-user { display: flex; justify-content: flex-end; }
.uc-bubble { max-width: 70%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 12px; padding: 10px 14px; font-size: 14px; line-height: 1.5; }
.uc-said { white-space: pre-wrap; }
.uc-sent { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.uc-sent-pill { display: inline-flex; align-items: center; gap: 6px; max-width: 240px; height: 26px; padding: 0 8px 0 3px; border-radius: 4px; background: #333; font-size: 12px; color: #c9cbcf; }
.uc-sent-pill > span:last-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.uc-sent-pill :deep(.ref-thumb) { width: 20px; height: 20px; }
.uc-bot { display: flex; gap: 12px; align-items: flex-start; }
.uc-mark { flex: 0 0 28px; height: 28px; border-radius: 7px; background: var(--color-accent); color: #fff; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
.uc-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 12px 14px; font-size: 14px; line-height: 1.5; max-width: 600px; }
.uc-label { font-family: var(--font-mono); font-size: 10.5px; letter-spacing: .08em; text-transform: uppercase; color: var(--color-text-muted); }
.uc-label--accent { color: var(--color-accent); }
.uc-label--ok { color: #4fd18b; }
.uc-facts { display: flex; flex-direction: column; gap: 8px; }
.uc-fact { margin: 0; padding: 8px 10px; border: 1px dashed var(--color-border-active); border-radius: 8px; color: var(--color-text-secondary); font-size: 13px; }
.uc-q { margin: 4px 0 10px; }
.uc-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.uc-chip { font: inherit; font-size: 13px; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--color-border-active); background: transparent; color: var(--color-text-primary); cursor: pointer; }
.uc-chip:hover:not(:disabled) { border-color: var(--color-accent); color: var(--color-accent); }
.uc-chip:disabled { opacity: .55; cursor: default; }
.uc-chip--type { border-style: dashed; color: var(--color-text-muted); }
.uc-pitch { max-width: 600px; padding: 14px; }
.uc-pitch.is-live { border-color: var(--color-accent); }
.uc-pitch-body { display: flex; gap: 14px; }
.uc-pitch-img { width: 84px; height: 150px; object-fit: cover; border-radius: 8px; flex: 0 0 auto; }
.uc-pitch h3 { margin: 0 0 6px; font-size: 15.5px; }
.uc-pitch p { margin: 0; color: var(--color-text-secondary); }
.uc-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.uc-tag { font-size: 12px; padding: 3px 9px; border-radius: 999px; border: 1px solid var(--color-border-active); color: var(--color-text-secondary); }
.uc-tag--type { border-color: rgba(79, 209, 139, .5); color: #4fd18b; }
.uc-script { margin-top: 10px; font-size: 13px; }
.uc-tip { margin: 10px 0 0 !important; font-size: 12.5px; color: #e8b26a !important; }
.uc-script summary { cursor: pointer; color: var(--color-text-muted); }
.uc-script p { margin-top: 6px; white-space: pre-wrap; }
.uc-pitch-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.uc-pitch-state { font-size: 13px; color: var(--color-text-muted); }
.uc-pitch-state.is-making { color: var(--color-accent); }
.uc-pitch-state.is-ready { color: #4fd18b; }
.uc-pitch-state.is-failed { color: #f0b4ac; }
.uc-consent { margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: var(--color-bg-soft); font-size: 13px; }
.uc-consent label, .uc-own label { display: flex; gap: 8px; align-items: flex-start; line-height: 1.45; }
.uc-consent-row { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
.uc-runs { display: flex; flex-direction: column; gap: 12px; flex: 1; min-width: 0; }
.uc-making { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center; }
.uc-making p { grid-column: 1 / -1; margin: 0; font-size: 12.5px; }
.uc-ready { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
.uc-video { width: 250px; max-width: 100%; aspect-ratio: 9 / 16; object-fit: cover; border-radius: 12px; background: #000; }
.uc-ready-side h3 { margin: 4px 0 4px; font-size: 17px; }
.uc-ready-btns { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.uc-failed p { margin: 4px 0 10px; font-size: 13px; }
.uc-failed .uc-ready-btns { align-items: center; margin-top: 0; }
.uc-err { color: #f0b4ac; border-color: rgba(240, 180, 172, .3); }
.uc-thinking { display: flex; gap: 10px; align-items: center; color: var(--color-text-secondary); }
.uc-hint { margin: 0 0 0 40px; font-size: 12.5px; color: var(--color-text-muted); }
.uc-muted { color: var(--color-text-muted); }
.uc-spin { width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--color-border-active); border-top-color: var(--color-accent); animation: uc-spin .8s linear infinite; display: inline-block; }
@keyframes uc-spin { to { transform: rotate(360deg); } }

.uc-btn { font: inherit; font-size: 13.5px; font-weight: 600; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--color-border-active); background: transparent; color: var(--color-text-primary); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
.uc-btn--primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.uc-btn--primary:hover:not(:disabled) { background: var(--color-accent-hover); }
.uc-btn:disabled { opacity: .5; cursor: default; }
.uc-btn--sm { padding: 4px 10px; font-size: 12.5px; border-radius: 6px; }

.uc-dock { padding: 8px 24px 14px; }
.uc-error { max-width: 820px; margin: 0 auto 8px; padding: 8px 12px; border-radius: 8px; background: rgba(179, 38, 30, .18); color: #f0b4ac; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
.uc-error button { border: 0; background: none; color: inherit; cursor: pointer; font-size: 15px; }
.uc-box { max-width: 820px; margin: 0 auto; background: var(--color-bg-card); border: 1px solid var(--color-border-active); border-radius: 16px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
.uc-box textarea { width: 100%; resize: none; border: 0; background: transparent; color: var(--color-text-primary); font: inherit; font-size: 14.5px; line-height: 1.5; outline: none; min-height: 44px; max-height: 220px; field-sizing: content; }
.uc-linkrow { display: flex; gap: 6px; }
.uc-linkrow input, .uc-search, .uc-own input:not([type=checkbox]), .uc-voice input { flex: 1; min-width: 0; font: inherit; font-size: 13px; padding: 7px 10px; border-radius: 7px; border: 1px solid var(--color-border-active); background: var(--color-bg-soft); color: var(--color-text-primary); }
.uc-rewrite { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 7px 10px; border: 1px dashed rgba(255, 107, 53, .6); border-radius: 8px; font-size: 12.5px; color: var(--color-text-secondary); }
.uc-rewrite b { color: var(--color-accent); }
.uc-rewrite-btns { display: inline-flex; gap: 6px; }
.uc-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.uc-pop-anchor { position: relative; }
.uc-plus { width: 28px; height: 28px; border-radius: 6px; border: 0; background: #333; color: #c9cbcf; font-size: 17px; cursor: pointer; }
.uc-pill { display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 9px; border-radius: 6px; border: 0; background: #333; color: #c9cbcf; font: inherit; font-size: 12.5px; cursor: pointer; max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.uc-pill:hover, .uc-plus:hover { background: #3d3d3d; color: #ececec; }
.uc-pill-faces { display: inline-flex; align-items: center; }
.uc-pill-faces img { width: 18px; height: 18px; border-radius: 4px; object-fit: cover; margin-left: -4px; border: 1px solid #333; }
.uc-pill-faces img:first-child { margin-left: 0; }
.uc-caret { font-size: 9px; color: #8b8b8b; }
.uc-spark { color: var(--color-accent); }
.uc-spark--lg { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; background: var(--color-bg-elevated); }
.uc-send { margin-left: auto; width: 32px; height: 32px; border-radius: 50%; border: 0; background: var(--color-accent); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.uc-send:disabled { opacity: .4; cursor: default; }
.uc-foot { max-width: 820px; margin: 8px auto 0; text-align: center; font-size: 11.5px; color: var(--color-text-muted); }

.uc-pop { position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 30; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 12px; padding: 8px; box-shadow: 0 18px 40px rgba(0, 0, 0, .45); display: flex; flex-direction: column; gap: 4px; max-height: 70vh; overflow-y: auto; }
.uc-pop--add { width: 320px; }
.uc-pop--add button, .uc-type { text-align: left; border: 0; background: transparent; color: var(--color-text-primary); font: inherit; padding: 8px 10px; border-radius: 8px; cursor: pointer; display: grid; gap: 2px; }
.uc-pop--add button span, .uc-type span { font-size: 12px; color: var(--color-text-muted); }
.uc-pop--add button:hover, .uc-type:hover, .uc-type.on { background: var(--color-bg-card); }
.uc-pop--type { width: 340px; }
.uc-type.soon { opacity: .5; cursor: default; }
.uc-type.soon:hover { background: transparent; }
.uc-soon { margin-left: 6px; font-style: normal; font-size: 10px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; padding: 1px 6px; border-radius: 999px; border: 1px solid var(--color-border-active); color: var(--color-text-muted); }
.uc-type { grid-template-columns: 130px 1fr; gap: 10px; font-size: 13px; }
.uc-pop--shape { width: 240px; }
.uc-pop--shape .uc-type { grid-template-columns: 1fr; }
.uc-pop--cast { width: 380px; }
.uc-pop-h { display: flex; justify-content: space-between; align-items: center; padding: 4px 6px; }
.uc-pad { padding: 4px 6px; margin: 0; font-size: 12px; }
.uc-switch { display: inline-flex; gap: 6px; align-items: center; font-size: 12px; color: var(--color-text-secondary); }
.uc-pres { border: 1px solid transparent; border-radius: 9px; background: var(--color-bg-card); }
.uc-pres.is-open { border-color: var(--color-accent); }
.uc-pres-h { display: flex; align-items: center; gap: 10px; padding: 7px 8px; cursor: pointer; }
.uc-pres-h img { width: 32px; height: 32px; border-radius: 6px; object-fit: cover; }
.uc-pres-t { flex: 1; min-width: 0; display: grid; }
.uc-pres-t b { font-size: 13px; }
.uc-pres-t small { font-size: 11.5px; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.uc-x { border: 0; background: none; color: var(--color-text-muted); font-size: 16px; cursor: pointer; padding: 0 4px; }
.uc-voice { display: grid; grid-template-columns: 70px 1fr; gap: 8px 10px; align-items: center; padding: 4px 10px 12px 50px; font-size: 12.5px; }
.uc-vl { color: var(--color-text-muted); }
.uc-opts { display: flex; flex-wrap: wrap; gap: 4px; }
.uc-opt { font: inherit; font-size: 12px; padding: 4px 9px; border-radius: 6px; border: 1px solid transparent; background: #2b2b33; color: var(--color-text-secondary); cursor: pointer; }
.uc-opt.on { border-color: var(--color-accent); color: var(--color-accent); background: rgba(255, 107, 53, .1); }
.uc-vnote { grid-column: 1 / -1; margin: 0; font-size: 11.5px; }
.uc-link-btn { grid-column: 1 / -1; justify-self: start; border: 0; background: none; color: var(--color-text-muted); font: inherit; font-size: 11.5px; text-decoration: underline; cursor: pointer; padding: 0; }
.uc-add { display: flex; flex-wrap: wrap; gap: 6px; padding: 2px 6px 6px; }
.uc-add-i, .uc-add-face { width: 34px; height: 34px; border-radius: 7px; border: 1px dashed var(--color-border-active); background: transparent; color: var(--color-text-secondary); cursor: pointer; padding: 0; overflow: hidden; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; }
.uc-add-face { border-style: solid; }
.uc-add-face.on { border-color: var(--color-accent); box-shadow: 0 0 0 1px var(--color-accent); }
.uc-preview { display: flex; gap: 12px; padding: 8px; border-radius: 9px; background: var(--color-bg-card); }
.uc-preview > img, .uc-preview-none { width: 150px; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 8px; flex: 0 0 auto; }
.uc-preview-none { display: inline-flex; align-items: center; justify-content: center; font-size: 36px; background: var(--color-bg-elevated); color: var(--color-text-muted); }
.uc-preview-t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
.uc-preview-t b { font-size: 15px; }
.uc-preview-t p::first-letter { text-transform: uppercase; }
.uc-preview-t p { margin: 0; font-size: 12.5px; line-height: 1.45; color: var(--color-text-secondary); }
.uc-preview-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.uc-preview-tags span { font-size: 11px; padding: 2px 7px; border-radius: 999px; border: 1px solid var(--color-border-active); color: var(--color-text-secondary); text-transform: capitalize; }
.uc-preview-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.uc-preview-nav { display: flex; gap: 4px; }
.uc-preview-nav button { width: 28px; height: 26px; border-radius: 6px; border: 1px solid var(--color-border-active); background: transparent; color: var(--color-text-secondary); cursor: pointer; font-size: 15px; line-height: 1; }
.uc-preview-nav button:hover { border-color: var(--color-accent); color: var(--color-text-primary); }
.uc-add-face img { width: 100%; height: 100%; object-fit: cover; }
.uc-add-i:hover, .uc-add-face:hover { border-color: var(--color-accent); }
.uc-add-more { width: auto; padding: 0 10px; font-size: 11.5px; font-weight: 600; border-style: solid; color: var(--color-text-primary); }
.uc-search { margin: 0 6px 6px; }
.uc-own { display: flex; gap: 10px; padding: 8px; border-radius: 9px; background: var(--color-bg-card); font-size: 12.5px; }
.uc-own img { width: 56px; height: 72px; object-fit: cover; border-radius: 6px; }
.uc-own > div { flex: 1; display: grid; gap: 8px; }

@media (max-width: 860px) {
  .uc-main { margin-left: 0; box-sizing: border-box; }
  .uc-thread { padding: 16px 16px 8px; }
  .uc-dock { padding: 8px 16px 12px; }
  .uc-bubble { max-width: 88%; }
  .uc-pitch-img { width: 64px; height: 114px; }
  .uc-pop { position: fixed; left: 16px; right: 16px; top: calc(60px + env(safe-area-inset-top)); bottom: auto; width: auto; max-height: calc(100dvh - 300px); }
  .uc-top { padding: 10px 16px; }
  .uc-crumb { display: none; }
  .uc-type { grid-template-columns: 1fr; }
}
</style>
