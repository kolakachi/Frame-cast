<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import AppSidebar from '../components/AppSidebar.vue'
import FinishedVideoPlayer from '../components/FinishedVideoPlayer.vue'
import UiSelect from '../components/UiSelect.vue'
import CreateDialog from '../components/create/CreateDialog.vue'
import CreateAttachment from '../components/create/CreateAttachment.vue'

const auth = useAuthStore(), route = useRoute(), router = useRouter()
const available = ref(false), loaded = ref(false), busy = ref(false), error = ref(''), conflict = ref(false)
const capabilities = ref(null), history = ref([]), search = ref(''), historyFilter = ref('all'), archivedHistory = ref(false)
const showHistory = ref(false), details = ref(false), libraryOpen = ref(false), compareOpen = ref(false)
const data = ref(null), prompt = ref(''), quote = ref(null), selectedRevision = ref(null), outputKind = ref('video')
const library = ref([]), librarySearch = ref(''), libraryPage = ref(1), libraryLastPage = ref(1)
const purpose = ref('reference'), reuseConfirmed = ref(false), rename = ref(''), uploads = ref([])
const fileInput = ref(null), composer = ref(null), end = ref(null)
const player = ref(null)
const media = ref(''), compareMedia = ref(''), artifactLoading = ref(false), historyLoading = ref(false), dragging = ref(false)
const clock = ref(Date.now())
let timer, searchTimer, epoch = 0, mediaEpoch = 0, historyEpoch = 0, libraryEpoch = 0, compareEpoch = 0
let mediaKey = '', sendingKey = null, approvalKey = null, uploadRunning = false
const id = computed(() => route.params.conversationId)
const conversation = computed(() => data.value?.conversation)
const revisions = computed(() => data.value?.revisions ?? [])
const currentRevision = computed(() => revisions.value.find(r => r.id === (selectedRevision.value || conversation.value?.head_revision_id)))
const currentNumber = computed(() => revisions.value.find(r => r.id === conversation.value?.head_revision_id)?.number)
const isOldRevision = computed(() => currentRevision.value && currentRevision.value.id !== conversation.value?.head_revision_id)
const active = computed(() => data.value?.runs?.find(r => ['queued','running','cancel_requested','needs_attention'].includes(r.status)))
const canWrite = computed(() => ['owner','admin','editor','super_admin','platform_admin','client_admin','client_editor'].includes(auth.user?.role))
const hasUpload = computed(() => uploads.value.some(u => ['queued','uploading'].includes(u.state)))
const locked = computed(() => busy.value || hasUpload.value)
const expiredQuote = computed(() => quote.value && Date.parse(quote.value.expires_at) <= clock.value)
const kind = computed(() => {
  try { return JSON.parse(conversation.value?.settings_json || '{}').output_kind || outputKind.value } catch { return 'video' }
})
const filteredHistory = computed(() => history.value.filter(c => historyFilter.value === 'all' || state(c) === historyFilter.value))
const examples = [
  { title:'Package my footage', copy:'Keep your voice. Add a clear story.', prompt:'Keep my original voice and footage. Add three benefit callouts and a clear ending.', kind:'video', icon:'▷' },
  { title:'Bring a product to life', copy:'Start with your product photos.', prompt:'Make a short product launch video from these photos. Keep the product accurate and use only claims I provide.', kind:'video', icon:'▧' },
  { title:'Explain an idea', copy:'A simple message, thoughtfully paced.', prompt:'Help me turn my idea into a clear, short visual explainer. Ask me for any facts you need.', kind:'video', icon:'✦' },
  { title:'Plan a product image', copy:'Save the brief for image creation.', prompt:'Create a clean product image from my photo, keeping its shape, label and colours unchanged.', kind:'image', icon:'◈' },
]
function state(c) { return ['queued','running','cancel_requested'].includes(c.latest_run_status) ? 'working' : ['failed','needs_attention'].includes(c.latest_run_status) ? 'needs' : c.head_revision_id ? 'done' : 'draft' }
function stateLabel(c) { return c.archived_at ? 'Archived' : ({working:'In progress',needs:'Needs attention',done:'Sample ready',draft:'Brief saved'})[state(c)] }
function date(value) { return new Date(value).toLocaleDateString(undefined,{month:'short',day:'numeric'}) }
function message(e) { return e.response?.data?.message || e.response?.data?.error?.message || 'Could not complete this action. Please retry.' }
const base = value => `/create/conversations/${value || id.value}`
const draftKey = value => `create.draft.${auth.user?.id}.${auth.user?.workspace_id}.${value || 'new'}`
function persistDraft(value, text) { try { text ? sessionStorage.setItem(draftKey(value),text) : sessionStorage.removeItem(draftKey(value)) } catch {} }
function readDraft(value) { try { return sessionStorage.getItem(draftKey(value)) || '' } catch { return '' } }
async function guarded(fn) {
  if (busy.value) return
  busy.value = true; error.value = ''; conflict.value = false
  try { await fn() } catch (e) { error.value = message(e); conflict.value = e.response?.status === 409; if (conflict.value) await refresh().catch(() => {}) }
  finally { busy.value = false }
}
async function loadHistory() {
  const ticket = ++historyEpoch; historyLoading.value = true
  try { const result = await api.get('/create/conversations',{params:{search:search.value,archived:archivedHistory.value}}); if(ticket === historyEpoch) history.value = result.data.data }
  catch(e) { if(ticket === historyEpoch) error.value = message(e) }
  finally { if(ticket === historyEpoch) historyLoading.value = false }
}
async function refresh() {
  if (!id.value || !available.value) return
  const expected = id.value, ticket = epoch
  const result = await api.get(base(expected))
  if(ticket !== epoch || id.value !== expected) return
  const previousTitle = data.value?.conversation.title
  data.value = result.data.data
  if(previousTitle !== data.value.conversation.title) rename.value = data.value.conversation.title
}
async function ensureConversation() {
  if(id.value) return id.value
  const draft = prompt.value
  const c = (await api.post('/create/conversations',{output_kind:outputKind.value})).data.data
  persistDraft(c.id,draft); persistDraft(null,'')
  await router.replace({name:'create',params:{conversationId:c.id}}); persistDraft(null,''); await refresh()
  return c.id
}
async function send() {
  if(!prompt.value.trim() || hasUpload.value) return
  const text = prompt.value.trim()
  await guarded(async () => {
    const target = await ensureConversation()
    sendingKey ||= crypto.randomUUID()
    await api.post(`${base(target)}/messages`,{content:text,expected_version:conversation.value.version,idempotency_key:sendingKey})
    persistDraft(target,''); sendingKey = null; prompt.value = ''; quote.value = null; selectedRevision.value = null
    await refresh(); await loadHistory(); await nextTick(); end.value?.scrollIntoView({behavior:'smooth',block:'end'})
  })
}
async function plan() { await guarded(async () => { quote.value = (await api.post(`${base()}/quotes`,{expected_version:conversation.value.version})).data.data; approvalKey = crypto.randomUUID(); clock.value = Date.now() }) }
async function approve() { if(expiredQuote.value) return; await guarded(async () => { await api.post(`${base()}/runs`,{quote_id:quote.value.id,approved:true,idempotency_key:approvalKey}); quote.value = null; await refresh(); await loadHistory() }) }
async function saveOutput() { await guarded(async () => { await api.post(`${base()}/revisions/${currentRevision.value.id}/save-output`,{expected_version:conversation.value.version}); await refresh() }) }
async function cancel() { await guarded(async () => { await api.post(`${base()}/runs/${active.value.id}/cancel`); await refresh() }) }
async function showLibrary() {
  purpose.value = 'reference'; reuseConfirmed.value = false; librarySearch.value = ''; libraryPage.value = 1
  libraryOpen.value = true; await loadLibrary()
}
async function loadLibrary() {
  const ticket = ++libraryEpoch
  try { const result = await api.get('/assets',{params:{per_page:24,page:libraryPage.value,q:librarySearch.value || undefined}}); if(ticket !== libraryEpoch) return; library.value = (result.data.data.assets ?? []).filter(a => ['image','video','audio'].includes(a.asset_type) && a.status !== 'archived'); libraryLastPage.value = result.data.meta?.pagination?.last_page || 1 }
  catch(e) { if(ticket === libraryEpoch) error.value = message(e) }
}
async function attach(asset) {
  if(purpose.value === 'source' && !reuseConfirmed.value) return
  await guarded(async () => {
    const target = await ensureConversation()
    await api.post(`${base(target)}/attachments`,{asset_id:asset.id,purpose:purpose.value,reuse_confirmed:reuseConfirmed.value || undefined,expected_version:conversation.value.version})
    quote.value = null; libraryOpen.value = false; await refresh(); await loadHistory()
  })
}
async function detach(asset) { await guarded(async () => { await api.delete(`${base()}/attachments/${asset.asset_id}`,{data:{expected_version:conversation.value.version}}); quote.value = null; await refresh() }) }
function chooseFiles(files) {
  const allowed = capabilities.value?.uploads?.mime_types || ['image/png','image/jpeg','image/webp','video/mp4','audio/mpeg','audio/wav','audio/x-wav']
  for(const file of Array.from(files || [])) {
    const validation = file.size > (capabilities.value?.uploads?.max_file_bytes || 104857600) ? 'This file is larger than 100 MB.' : !allowed.includes(file.type) ? 'Use PNG, JPEG, WebP, MP4, MP3 or WAV.' : null
    if(uploads.value.length + (data.value?.attachments?.length || 0) >= 20) { error.value = 'Use at most 20 attachments.'; break }
    uploads.value.push({key:crypto.randomUUID(),file,progress:0,state:'ready',error:validation,purpose:'reference',confirmed:false,preview_url:validation ? '' : URL.createObjectURL(file)})
  }
  if(fileInput.value) fileInput.value.value = ''
  dragging.value = false
}
function removeUpload(u) { if(u.preview_url) URL.revokeObjectURL(u.preview_url); uploads.value = uploads.value.filter(x => x !== u) }
async function upload(u) {
  if(uploadRunning || busy.value || u.error && u.state === 'ready' || u.purpose === 'source' && !u.confirmed) return
  uploadRunning = true; u.state = 'uploading'; u.error = ''; u.progress = 0
  let target
  try {
    target = await ensureConversation()
    const form = new FormData(); form.append('asset_file',u.file); form.append('purpose',u.purpose); form.append('idempotency_key',u.key); form.append('expected_version',conversation.value.version)
    if(u.purpose === 'source') form.append('reuse_confirmed','1')
    const result = await api.post(`${base(target)}/uploads`,form,{headers:{'Content-Type':'multipart/form-data'},onUploadProgress:event => {u.progress = Math.min(99,Math.round(100*(event.loaded/(event.total || u.file.size))))}})
    if(id.value === target) { data.value = result.data.data; rename.value = data.value.conversation.title; quote.value = null }
    removeUpload(u); await loadHistory()
  } catch(e) { u.state = 'failed'; u.error = message(e); if(e.response?.status === 409) { conflict.value = true; await refresh().catch(()=>{}) } }
  finally { uploadRunning = false }
}
async function restore() { await guarded(async () => { await api.post(`${base()}/revisions/${currentRevision.value.id}/restore`,{expected_version:conversation.value.version}); selectedRevision.value = null; quote.value = null; await refresh() }) }
async function updateConversation(archived) {
  await guarded(async () => {
    await api.patch(base(),{title:rename.value.trim(),...(archived === undefined ? {} : {archived}),expected_version:conversation.value.version})
    quote.value = null; await refresh(); await loadHistory()
    if(archived) { details.value = false; await router.push({name:'create'}) }
  })
}
async function loadArtifact() {
  const revision = currentRevision.value, target = id.value
  const nextKey = revision?.artifact_hash ? `${target}/${revision.id}` : ''
  if(nextKey === mediaKey) return
  mediaKey = nextKey; const ticket = ++mediaEpoch
  if(media.value) URL.revokeObjectURL(media.value)
  media.value = ''; artifactLoading.value = Boolean(nextKey)
  if(!nextKey) return
  try { const result = await api.get(`${base(target)}/revisions/${revision.id}/artifact`,{responseType:'blob'}); if(ticket === mediaEpoch) media.value = URL.createObjectURL(result.data) }
  catch(e) { if(ticket === mediaEpoch) {error.value = message(e); mediaKey = ''} }
  finally { if(ticket === mediaEpoch) artifactLoading.value = false }
}
async function compare() {
  player.value?.pause(); compareOpen.value = true; const ticket = ++compareEpoch
  try { const result = await api.get(`${base()}/revisions/${conversation.value.head_revision_id}/artifact`,{responseType:'blob'}); if(ticket === compareEpoch && compareOpen.value) compareMedia.value = URL.createObjectURL(result.data) }
  catch(e) { if(ticket === compareEpoch) error.value = message(e) }
}
function example(item) { if(!id.value) outputKind.value = item.kind; prompt.value = item.prompt; nextTick(()=>composer.value?.focus()) }
watch(prompt, value => persistDraft(id.value,value))
watch([search,archivedHistory], () => {clearTimeout(searchTimer); searchTimer = setTimeout(loadHistory,250)})
watch(showHistory, open => {if(open) loadHistory()})
watch(compareOpen, open => {if(!open) {compareEpoch++; if(compareMedia.value) URL.revokeObjectURL(compareMedia.value); compareMedia.value = ''}})
watch(() => currentRevision.value?.id, loadArtifact)
watch(id, async (value, old) => {
  persistDraft(old,prompt.value); epoch++; data.value = null; selectedRevision.value = null; quote.value = null; sendingKey = null; error.value = ''; conflict.value = false; showHistory.value = false; details.value = false; compareOpen.value = false
  prompt.value = readDraft(value)
  // ensureConversation transfers pending local files into the newly created chat.
  if(old) uploads.value.forEach(removeUpload)
  await loadArtifact(); try {await refresh()} catch(e) {error.value = message(e)}
})
watch(() => auth.user?.workspace_id, () => window.location.assign('/create'))
onMounted(async () => {
  prompt.value = readDraft(id.value)
  try {capabilities.value = (await api.get('/create/capabilities')).data.data; available.value = true; await loadHistory(); await refresh()}
  catch(e) {if(e.response?.status !== 404) error.value = message(e)}
  finally {loaded.value = true}
  timer = setInterval(async () => {clock.value = Date.now(); if(active.value && active.value.status !== 'needs_attention' && !locked.value) {try {await refresh()} catch(e) {error.value = message(e)}}},2000)
})
onBeforeUnmount(() => {clearInterval(timer);clearTimeout(searchTimer);epoch++;mediaEpoch++;historyEpoch++;libraryEpoch++;compareEpoch++;for(const url of [media.value,compareMedia.value,...uploads.value.map(u=>u.preview_url)]) if(url) URL.revokeObjectURL(url)})
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="auth.user" active-page="create" @logout="auth.logout()" />
    <main class="main create-main" :class="{'has-details':details}">
      <header class="create-header">
        <div class="heading"><span class="eyebrow">CREATE <span class="local-pill">LOCAL PREVIEW</span></span><h1>{{ conversation?.title || 'New creation' }}</h1></div>
        <nav aria-label="Creation controls"><button :disabled="locked" @click="router.push({name:'create'})">＋ New</button><button :disabled="locked" @click="showHistory = true">History</button><button :aria-expanded="details" @click="details = !details">Details</button></nav>
      </header>
      <p v-if="!loaded" class="loading">Opening your workspace…</p>
      <section v-else-if="!available" class="create-empty"><h2>Create is not enabled here yet.</h2><router-link to="/dashboard">Back to dashboard</router-link></section>
      <div v-else class="conversation-column" @dragover.prevent="dragging = canWrite" @dragleave.self="dragging = false" @drop.prevent="canWrite && !conversation?.archived_at && chooseFiles($event.dataTransfer.files)">
        <div v-if="dragging" class="drop-overlay">Drop your footage, photos or audio here</div>
        <aside class="local-note"><span>✦</span><p><strong>Your creative workspace, coming together.</strong> Save briefs and prepare media here. The local render uses a fixed sample; paid AI is off.</p></aside>
        <section v-if="!data?.messages?.length && !currentRevision" class="create-empty">
          <div class="create-spark">✦</div><h2>What are we making?</h2><p>Start with an idea. Bring a photo, a recording, or a reference.<br />Keep the whole creative conversation in one place.</p>
          <button v-if="canWrite && !conversation?.archived_at" class="dropzone" @click="fileInput.click()">＋ Drop files here, or browse your device <small>PNG / JPG / WebP · MP4 · MP3 / WAV<br />20 files · 100 MB each · 200 MB total</small></button>
          <div class="examples"><button v-for="item in examples.filter(item => !conversation || item.kind === kind)" :key="item.title" :disabled="!canWrite || !!conversation?.archived_at" @click="example(item)"><span>{{ item.icon }}</span><strong>{{ item.title }}</strong><small>{{ item.copy }}</small></button></div>
        </section>
        <div v-if="conversation?.archived_at" class="notice"><p>This conversation is archived. Its briefs and versions are preserved.</p><button v-if="canWrite" :disabled="locked" @click="updateConversation(false)">Restore conversation</button></div>
        <article v-for="m in data?.messages || []" :key="m.id" class="message" :class="m.role"><span class="eyebrow">{{ m.role === 'user' ? 'YOU' : 'WYVSTUDIO' }}</span><p>{{ m.content }}</p></article>
        <section v-if="data?.attachments?.length" class="attachment-section" aria-label="Attached media"><h2 class="eyebrow">YOUR MATERIAL</h2><div class="attachments"><CreateAttachment v-for="a in data.attachments" :key="a.asset_id" :asset="a" :removable="canWrite && !conversation?.archived_at" :disabled="locked" @remove="detach(a)" /></div></section>
        <section v-if="active" class="run-card" aria-live="polite"><span class="run-indicator" /><div><strong>{{ active.stage }}</strong><p v-if="active.status === 'needs_attention'">This run needs a recovery check before continuing. Your earlier versions are safe; no automatic retry will spend more.</p><small v-else>You can leave and return. Work continues in the background.</small></div><button v-if="canWrite && active.status !== 'needs_attention'" :disabled="locked || active.status === 'cancel_requested'" @click="cancel">{{ active.status === 'cancel_requested' ? 'Stopping…' : 'Stop' }}</button></section>
        <details v-if="data?.runs?.some(r => ['failed','cancelled'].includes(r.status))" class="past-runs"><summary>Earlier attempts</summary><p v-for="run in data.runs.filter(r => ['failed','cancelled'].includes(r.status))" :key="run.id">{{ run.status === 'failed' ? 'Stopped' : 'Cancelled' }} — {{ run.error || run.stage }}</p></details>
        <section v-if="currentRevision" class="result-card">
          <div class="result-heading"><span class="eyebrow">LOCAL SAMPLE PREVIEW</span><span class="version">V{{ currentRevision.number }} · {{ isOldRevision ? 'Earlier version' : 'Current' }}{{ currentRevision.export_job_id ? ' · Saved to Videos' : '' }}</span></div>
          <p>{{ currentRevision.summary }}</p>
          <p v-if="currentRevision.conflict" class="notice">Your brief changed while this was being made. This draft is preserved; your current version stayed unchanged.</p>
          <p v-if="isOldRevision" class="notice">You are viewing version {{ currentRevision.number }}. Version {{ currentNumber }} is still current. Download uses the version shown here.</p>
          <p v-if="artifactLoading">Loading your video…</p><FinishedVideoPlayer ref="player" v-if="media" :src="media" />
          <button v-if="!media && !artifactLoading" @click="loadArtifact">Retry video preview</button>
          <div class="result-actions"><a v-if="media" :href="media" :download="`wyvstudio-sample-v${currentRevision.number}.mp4`" class="primary">↓ Download sample</a><button v-if="canWrite && !isOldRevision && !conversation.archived_at" :disabled="locked || !!currentRevision.export_job_id" @click="saveOutput">{{ currentRevision.export_job_id ? 'Saved to videos' : 'Save sample to videos' }}</button><button v-if="isOldRevision" @click="compare">Compare with current</button><button v-if="canWrite && isOldRevision && !conversation.archived_at" :disabled="locked" @click="restore">Restore as a new version</button><button v-if="isOldRevision" @click="selectedRevision = null">Back to current</button></div>
          <p class="delivery-note">Sharing and scheduling from Create will be available after delivery integration.</p>
        </section>
        <section v-if="quote" class="approval-card"><span class="eyebrow">READY FOR YOUR REVIEW</span><h2>Try the local sample</h2><p>{{ quote.description }}</p><div class="quote-total"><strong>{{ quote.credits_max }} credits</strong><span>No paid calls</span></div><small>{{ expiredQuote ? 'This approval expired. Review a fresh plan to continue.' : `Approval expires at ${new Date(quote.expires_at).toLocaleTimeString()}` }}</small><div class="result-actions"><button v-if="expiredQuote" class="primary" :disabled="locked" @click="plan">Refresh plan</button><button v-else class="primary" :disabled="locked" @click="approve">Approve sample render</button><button :disabled="locked" @click="quote = null">Not now</button></div></section>
        <div v-else-if="conversation && canWrite && !active && data?.messages?.length && !conversation.archived_at" class="next-step"><p v-if="kind === 'image'">Your image brief is saved. Image generation and editing are not enabled in this local preview yet.</p><button v-else :disabled="locked" @click="plan">Review local sample plan →</button></div>
        <div ref="end" />
        <form v-if="canWrite && !conversation?.archived_at" class="composer-dock" @submit.prevent="send">
          <div v-if="error" class="create-error" role="alert"><p>{{ error }}</p><p v-if="conflict">We refreshed the conversation. Your unsent text is still here; review the latest version before trying again.</p><button type="button" aria-label="Dismiss error" @click="error = ''; conflict = false">×</button></div>
          <div v-for="u in uploads" :key="u.key" class="upload-row">
            <img v-if="u.file.type.startsWith('image/') && u.preview_url" :src="u.preview_url" alt="Selected upload" /><span v-else class="file-symbol">{{ u.file.type.startsWith('audio/') ? '♫' : '▷' }}</span>
            <div class="upload-body"><strong>{{ u.file.name }}</strong><small>{{ (u.file.size / 1048576).toFixed(1) }} MB · {{ u.state === 'uploading' ? `Uploading ${u.progress}%` : u.state === 'failed' ? 'Upload needs attention' : 'Ready to upload' }}</small><progress v-if="u.state === 'uploading'" :value="u.progress" max="100" :aria-label="`Uploading ${u.file.name}`" />
              <template v-if="u.state !== 'uploading'"><UiSelect v-model="u.purpose" label="Use uploaded file as" :disabled="u.state === 'failed'" :options="[{value:'reference',label:'Inspiration only'},{value:'source',label:'Reuse in my creation'}]" /><label v-if="u.purpose === 'source'" class="consent"><input v-model="u.confirmed" type="checkbox" /> I own this media or have permission to reuse it.</label><p v-if="u.error" class="upload-error">{{ u.error }}</p></template>
            </div><div class="upload-actions"><button type="button" :disabled="locked || !!u.error && u.state === 'ready' || u.purpose === 'source' && !u.confirmed" @click="upload(u)">{{ u.state === 'failed' ? 'Retry upload' : 'Upload' }}</button><button type="button" :disabled="u.state === 'uploading'" :aria-label="`Remove pending ${u.file.name}`" @click="removeUpload(u)">×</button></div>
          </div>
          <div class="composer-box"><label for="create-prompt" class="eyebrow">YOUR BRIEF OR NEXT CHANGE</label><textarea ref="composer" id="create-prompt" v-model="prompt" rows="3" maxlength="10000" placeholder="Describe what you want to create or change…" @input="sendingKey = null" @keydown.meta.enter.prevent="send" @keydown.ctrl.enter.prevent="send" />
            <footer><div class="composer-tools"><button type="button" :disabled="locked" title="PNG, JPEG, WebP, MP4, MP3 or WAV. Up to 20 files, 100 MB each and 200 MB total." @click="fileInput.click()">＋ Attach files</button><button type="button" :disabled="locked" @click="showLibrary">Add from library</button><UiSelect v-if="!conversation" v-model="outputKind" label="Creation type" :options="[{value:'video',label:'Video brief'},{value:'image',label:'Image brief'}]" /></div><button class="primary send" type="submit" :disabled="locked || !prompt.trim()">{{ busy ? 'Saving…' : 'Save brief ↑' }}</button></footer>
          </div><p class="composer-note">Uploads stay private. Nothing generates or transcribes automatically. ⌘ / Ctrl + Enter to save.</p>
        </form>
        <p v-if="error && (!canWrite || conversation?.archived_at)" class="create-error" role="alert">{{ error }}</p>
      </div>
      <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,audio/mpeg,audio/wav,audio/x-wav" multiple hidden @change="chooseFiles($event.target.files)" />
      <CreateDialog :open="showHistory" title="Conversation history" drawer @close="showHistory = false">
        <input v-model="search" type="search" class="field" aria-label="Search conversations" placeholder="Search titles, briefs or file names…" />
        <div class="history-filters"><button v-for="f in [{value:'all',label:'All'},{value:'working',label:'In progress'},{value:'needs',label:'Needs you'},{value:'done',label:'Ready'}]" :key="f.value" :aria-pressed="historyFilter === f.value" @click="historyFilter = f.value">{{ f.label }}</button></div>
        <label class="consent"><input v-model="archivedHistory" type="checkbox" /> Show archived conversations</label>
        <p class="muted" v-if="historyLoading">Searching…</p><p class="muted" v-else-if="!filteredHistory.length">No matching conversations.</p>
        <router-link v-for="c in filteredHistory" :key="c.id" class="history-item" :to="{name:'create',params:{conversationId:c.id}}" @click="showHistory = false"><div><strong>{{ c.title }}</strong><time>{{ date(c.updated_at) }}</time></div><p>{{ c.last_message || 'Add your first brief or attachment' }}</p><span :class="['status',state(c)]">{{ stateLabel(c) }}</span></router-link><small class="muted">Showing up to 100 matching conversations.</small>
      </CreateDialog>
      <CreateDialog :open="details" title="Creation details" drawer docked @close="details = false">
        <p class="eyebrow">{{ kind === 'image' ? 'IMAGE BRIEF' : 'VIDEO BRIEF' }}</p><p class="muted">{{ kind === 'image' ? 'Image generation, editing and animation are not enabled yet. Your image brief and references are saved here.' : 'The offline video sample is 15 seconds, portrait, 1080p. Other output settings, voice, music and caption choices are not applied yet.' }}</p>
        <template v-if="conversation"><label for="conversation-title">Conversation name</label><input id="conversation-title" v-model="rename" class="field" maxlength="160" :disabled="!canWrite" /><div class="result-actions"><button v-if="canWrite" :disabled="locked || !rename.trim()" @click="updateConversation()">Save name</button><button v-if="canWrite" :disabled="locked || !!active" @click="updateConversation(!conversation.archived_at)">{{ conversation.archived_at ? 'Restore conversation' : 'Archive conversation' }}</button></div><p class="muted" v-if="active">Stop or reconcile the active run before archiving.</p></template>
        <h3>Version history</h3><p class="muted">Inspecting an older version does not replace your current result.</p><button v-for="r in [...revisions].reverse()" :key="r.id" class="revision-item" @click="selectedRevision = r.id; details = false"><strong>Version {{ r.number }}{{ r.id === conversation?.head_revision_id ? ' · Current' : r.conflict ? ' · Saved draft' : '' }}</strong><small>{{ date(r.created_at) }} · {{ r.export_job_id ? 'Saved to Videos' : 'Preview' }}</small></button><p v-if="!revisions.length" class="muted">Your first result will appear here.</p>
      </CreateDialog>
      <CreateDialog :open="libraryOpen" title="Add from your library" @close="libraryOpen = false">
        <form class="library-search" @submit.prevent="libraryPage = 1; loadLibrary()"><input v-model="librarySearch" class="field" type="search" aria-label="Search library" placeholder="Find a photo, video or audio file…" /><button>Search</button></form><UiSelect v-model="purpose" label="How to use this asset" :options="[{value:'reference',label:'Inspiration only'},{value:'source',label:'Reuse in my creation'}]" /><p class="muted">Inspiration helps describe a style. It does not give permission to copy footage, people or branding.</p><label v-if="purpose === 'source'" class="consent"><input v-model="reuseConfirmed" type="checkbox" /> I own this media or have permission to reuse it.</label>
        <div class="library-grid"><button v-for="a in library" :key="a.id" :disabled="locked || purpose === 'source' && !reuseConfirmed" @click="attach(a)"><img v-if="a.asset_type === 'image' && a.storage_url" :src="a.storage_url" alt="" /><span v-else class="file-symbol">{{ a.asset_type === 'video' ? '▷' : '♫' }}</span><strong>{{ a.title || a.asset_type }}</strong><small>{{ a.asset_type }}</small></button></div><p v-if="!library.length" class="muted">No matching media. Attach files directly in the composer.</p><div class="result-actions"><button :disabled="libraryPage <= 1" @click="libraryPage--; loadLibrary()">Previous</button><span>{{ libraryPage }} / {{ libraryLastPage }}</span><button :disabled="libraryPage >= libraryLastPage" @click="libraryPage++; loadLibrary()">Next</button></div><p v-if="error" class="create-error" role="alert">{{ error }}</p>
      </CreateDialog>
      <CreateDialog :open="compareOpen" title="Compare versions" @close="compareOpen = false"><div class="comparison"><section><h3>Version {{ currentRevision?.number }} · Earlier</h3><FinishedVideoPlayer v-if="media" :src="media" /></section><section><h3>Version {{ currentNumber }} · Current</h3><FinishedVideoPlayer v-if="compareMedia" :src="compareMedia" /><p v-else>Loading current version…</p></section></div><p class="muted">Play each version to compare. This does not change the current version.</p></CreateDialog>
    </main>
  </div>
</template>

<style scoped>
.fc-shell{min-height:100vh;background:var(--color-bg-deep,#0a0a0f);color:var(--color-text-primary,#ececf3)}.create-main{margin-left:var(--sidebar-width,220px);min-height:100vh;padding:26px 34px;min-width:0}.create-header{display:flex;align-items:center;justify-content:space-between;gap:24px;border-bottom:1px solid #ffffff10;padding-bottom:20px;margin-bottom:22px}.heading{min-width:0}.create-header h1{font-size:21px;line-height:1.4;margin:8px 0 0;max-width:640px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.eyebrow{font-size:10px;letter-spacing:1.5px;color:#a29aaa;font-weight:600}.local-pill{font-size:9px;letter-spacing:.7px;color:#efae8c;border:1px solid #ab61454a;border-radius:5px;padding:3px 6px;margin-left:9px}nav,.result-actions,.history-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}button,.result-actions a{border:1px solid #ffffff16;border-radius:9px;background:#ffffff05;color:inherit;padding:9px 13px;cursor:pointer;font:inherit;font-size:12px;text-decoration:none;transition:background .15s}button:hover,.result-actions a:hover{background:#ffffff0d}button:disabled{opacity:.4;cursor:default}button:focus-visible,a:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid #ff6b32;outline-offset:3px}.primary,.result-actions a.primary{background:#ff6b32;color:#fff;border-color:#ff6b32}.primary:hover{background:#f75d24}.conversation-column{width:min(100%,880px);margin:auto;min-width:0;position:relative}.local-note{display:flex;align-items:flex-start;gap:10px;color:#9d94a4;border-radius:12px;padding:10px 14px;background:#ffffff03;font-size:12px;line-height:1.65}.local-note>span{color:#ff8453}.local-note p{margin:0}.local-note strong{color:#beb5c5;font-weight:500}.create-empty{text-align:center;padding:34px 0 20px}.create-spark{font-size:30px;color:#ff8250}.create-empty h2{font-size:32px;letter-spacing:-1px;margin:12px 0}.create-empty>p{font-size:14px;line-height:1.8;color:#9c94a6;margin:12px auto 24px}.dropzone{width:100%;border:1px dashed #5b4754;background:linear-gradient(130deg,#ff6b3506,#ffffff02);padding:23px;font-size:13px}.dropzone small{display:block;color:#958c9d;font-size:11px;margin-top:8px}.examples{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:16px}.examples button{text-align:left;padding:18px;display:grid;grid-template-columns:26px 1fr;column-gap:8px}.examples span{color:#e8a586;grid-row:span 2;font-size:20px}.examples strong{font-size:12px;font-weight:500}.examples small{color:#948c9d;font-size:11px;margin-top:6px;line-height:1.5}.message{padding:20px 24px;border-radius:16px;background:#1b1922;border:1px solid #ffffff07;margin:24px 0}.message.assistant{background:transparent;border:0;padding-left:0}.message p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.75;font-size:14px;margin:10px 0 0}.attachment-section{margin:24px 0}.attachments{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.attachment-section h2{margin-bottom:12px}.result-card{margin:28px 0;padding:22px;border:1px solid #ffffff17;border-radius:18px;background:#131219}.result-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.version{font-size:11px;color:#b4aab9}.result-card>p{font-size:13px;line-height:1.7;color:#aaa0b2}.result-actions{margin-top:16px}.delivery-note{font-size:11px!important;color:#837a8d!important;margin-bottom:0}.run-card{display:flex;align-items:flex-start;gap:12px;border:1px solid #ff6b3530;border-radius:14px;padding:18px;margin:20px 0;font-size:13px}.run-card>div{flex:1}.run-indicator{width:8px;height:8px;border-radius:50%;background:#ff8248;margin-top:5px}.run-card small{display:block;color:#9e94a8;font-size:12px;line-height:1.6;margin-top:7px}.run-card p{color:#b8a9c0;line-height:1.7}.past-runs{font-size:12px;color:#a39aaa;margin-top:20px}.past-runs p{line-height:1.7}.past-runs summary{cursor:pointer}.approval-card{border:1px solid #aa553f68;border-radius:14px;padding:24px;margin:22px 0;background:linear-gradient(130deg,#ff6b3508,#16131b)}.approval-card h2{font-size:18px}.approval-card p{color:#aca2b6;font-size:13px;line-height:1.8}.approval-card small{display:block;font-size:11px;line-height:1.6;color:#a69bae;margin-top:12px}.quote-total{display:flex;align-items:center;gap:12px}.quote-total strong{font-size:22px}.quote-total span{font-size:11px;color:#9bcea9}.next-step{margin:24px 0;font-size:13px;color:#b6aabd}.composer-dock{position:sticky;bottom:0;margin-top:26px;padding:12px 0 5px;background:linear-gradient(transparent,#0a0a0f 22%);z-index:2}.composer-box{border:1px solid #5e47534f;border-radius:18px;background:#1b1922;padding:17px 18px;box-shadow:0 8px 32px #0004}.composer-box textarea{background:transparent;border:0;color:inherit;font:inherit;font-size:14px;line-height:1.7;resize:vertical;min-height:75px;max-height:220px;padding:10px 0;width:100%}.composer-box footer{display:flex;align-items:center;justify-content:space-between;gap:12px}.composer-tools{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.composer-tools button{font-size:11px;padding:7px 9px}.composer-note{font-size:10px;color:#94899f;text-align:center;line-height:1.6}.send{white-space:nowrap}.field{display:block;width:100%;min-width:0;box-sizing:border-box;background:#0d0c12;color:inherit;padding:12px;border:1px solid #ffffff18;border-radius:9px;font:inherit;font-size:13px;margin:10px 0}.muted{font-size:12px;color:#a69aaf;line-height:1.8}.consent{display:flex;align-items:flex-start;gap:8px;font-size:12px;line-height:1.6;color:#bfb4c6;margin:12px 0}.consent input{accent-color:#ff6b35;margin-top:3px}.history-filters{margin:14px 0}.history-filters button{padding:7px 10px;font-size:11px}.history-filters [aria-pressed=true]{color:#ff9b70;background:#ff6b3513;border-color:#ff6b3555}.history-item{display:block;color:inherit;text-decoration:none;padding:18px 0;border-bottom:1px solid #ffffff0d}.history-item>div{display:flex;gap:12px;justify-content:space-between;align-items:center}.history-item strong{font-weight:500;font-size:13px;overflow-wrap:anywhere}.history-item time{font-size:10px;color:#8f849b;white-space:nowrap}.history-item p{font-size:12px;color:#92879d;white-space:nowrap;text-overflow:ellipsis;overflow:hidden;margin:8px 0}.status{font-size:10px;color:#b0a4bd}.status.done{color:#82cfa2}.status.needs{color:#efa789}.status.working{color:#a6a0f7}.revision-item{display:block;width:100%;text-align:left;margin:8px 0;padding:14px}.revision-item strong{font-weight:500}.revision-item small{display:block;color:#9b8fa7;margin-top:7px}.create-error{position:relative;color:#f4bba9;padding:12px 40px 12px 16px;background:#352322;border-radius:10px;font-size:12px;line-height:1.6;margin:12px 0}.create-error p{margin:0}.create-error button{position:absolute;right:5px;top:6px;border:0;padding:4px 8px}.notice{background:#a7833210;color:#cbb58a!important;border:1px solid #a7833230;border-radius:10px;padding:12px;font-size:12px;line-height:1.6;margin:16px 0}.upload-row{display:flex;align-items:flex-start;gap:10px;background:#211d28;border:1px solid #ffffff16;border-radius:12px;padding:12px;margin-bottom:8px}.upload-row>img{width:54px;height:54px;object-fit:contain;border-radius:6px;background:#0a0910}.upload-body{min-width:0;flex:1}.upload-body>strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;font-size:12px}.upload-body>small{display:block;color:#a396af;font-size:11px;margin:7px 0}.upload-body :deep(.ui-select){margin-top:8px}.upload-actions{display:flex;gap:5px}.upload-actions button{font-size:11px;padding:7px}.upload-error{color:#efaf98;font-size:12px}.file-symbol{display:grid;place-items:center;width:50px;height:50px;background:#0e0c15;border-radius:7px;color:#b79ba8;font-size:24px}progress{width:100%;accent-color:#ff6b35}.library-search{display:flex;gap:8px;align-items:center;margin-bottom:15px}.library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}.library-grid button{text-align:left;min-width:0;padding:10px}.library-grid img,.library-grid .file-symbol{height:85px;width:100%;object-fit:contain;background:#0d0b13;border-radius:6px;margin-bottom:8px}.library-grid strong{font-size:11px;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.library-grid small{font-size:10px;color:#9f91ad}.comparison{display:grid;grid-template-columns:1fr 1fr;gap:12px}.comparison h3{font-size:13px}.drop-overlay{position:fixed;inset:15%;z-index:20;display:grid;place-items:center;border:2px dashed #ff8e5d;border-radius:24px;background:#1c1526ed;pointer-events:none}.loading{text-align:center;padding:60px;color:#a99eb4}@media(min-width:1280px){.create-main.has-details{padding-right:390px}}@media(min-width:1550px){.conversation-column{max-width:950px}}@media(max-width:860px){.create-main{margin-left:0;padding:22px 16px 90px}.create-header{align-items:flex-start;gap:14px;flex-direction:column}.heading{width:100%}.create-header h1{font-size:19px}.create-empty{padding-top:20px}.create-empty h2{font-size:28px}.local-note{font-size:11px}.composer-dock{position:fixed;left:16px;right:16px;bottom:66px;max-height:50dvh;overflow:auto;border-radius:18px;background:#0a0a0f;padding:0}.composer-note{display:none}.composer-box textarea{min-height:55px;max-height:120px}.create-main{padding-bottom:300px}.composer-box footer{align-items:flex-end}.composer-tools{gap:4px}.composer-tools button{padding:6px}.send{padding:9px}.result-card{padding:14px}.result-heading{align-items:flex-start;flex-direction:column;gap:8px}.examples button{padding:13px;grid-template-columns:1fr}.examples span{display:none}.upload-row{flex-wrap:wrap}.upload-actions{margin-left:auto}.library-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.comparison{grid-template-columns:1fr}}
</style>
