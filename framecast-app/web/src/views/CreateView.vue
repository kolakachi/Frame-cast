<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import AppSidebar from '../components/AppSidebar.vue'
import FinishedVideoPlayer from '../components/FinishedVideoPlayer.vue'
import UiSelect from '../components/UiSelect.vue'

const auth = useAuthStore(), route = useRoute(), router = useRouter()
const available = ref(false), loaded = ref(false), busy = ref(false), error = ref('')
const history = ref([]), search = ref(''), showHistory = ref(false), details = ref(false)
const data = ref(null), prompt = ref(''), quote = ref(null), selectedRevision = ref(null)
const libraryDialog = ref(null)
const library = ref([]), libraryOpen = ref(false), purpose = ref('source'), rename = ref('')
const media = ref(''), artifactLoading = ref(false)
let timer, epoch = 0, mediaEpoch = 0, mediaKey = '', sendingKey = null, approvalKey = null
const id = computed(() => route.params.conversationId)
const conversation = computed(() => data.value?.conversation)
const revisions = computed(() => data.value?.revisions ?? [])
const currentRevision = computed(() => revisions.value.find(r => r.id === (selectedRevision.value || conversation.value?.head_revision_id)))
const active = computed(() => data.value?.runs?.find(r => ['queued', 'running', 'cancel_requested', 'needs_attention'].includes(r.status)))
const filteredHistory = computed(() => history.value.filter(c => c.title.toLowerCase().includes(search.value.toLowerCase())))
const canWrite = computed(() => ['owner', 'admin', 'editor', 'super_admin', 'platform_admin', 'client_admin', 'client_editor'].includes(auth.user?.role))
const base = () => `/create/conversations/${id.value}`
function message(e) { return e.response?.data?.message || e.response?.data?.error?.message || 'Could not complete this action. Please retry.' }
async function guarded(fn) {
  if (busy.value) return
  busy.value = true; error.value = ''
  try { await fn() } catch (e) { error.value = message(e) } finally { busy.value = false }
}
async function loadHistory() { history.value = (await api.get('/create/conversations')).data.data }
async function refresh() {
  if (!id.value || !available.value) return
  const expected = id.value, ticket = epoch
  const result = await api.get(base())
  if (ticket === epoch && id.value === expected) { const previousTitle = data.value?.conversation.title; data.value = result.data.data; if (previousTitle !== data.value.conversation.title) rename.value = data.value.conversation.title }
}
async function send() {
  if (!prompt.value.trim()) return
  await guarded(async () => {
    if (!id.value) {
      const c = (await api.post('/create/conversations', {})).data.data
      await router.replace({ name: 'create', params: { conversationId: c.id } }); await refresh()
    }
    sendingKey ||= crypto.randomUUID()
    await api.post(`${base()}/messages`, { content: prompt.value.trim(), expected_version: conversation.value.version, idempotency_key: sendingKey })
    sendingKey = null; prompt.value = ''; quote.value = null
    await refresh(); await loadHistory()
  })
}
async function plan() {
  await guarded(async () => { quote.value = (await api.post(`${base()}/quotes`, { expected_version: conversation.value.version })).data.data; approvalKey = crypto.randomUUID() })
}
async function approve() {
  await guarded(async () => { await api.post(`${base()}/runs`, { quote_id: quote.value.id, approved: true, idempotency_key: approvalKey }); quote.value = null; await refresh() })
}
async function cancel() { await guarded(async () => { await api.post(`${base()}/runs/${active.value.id}/cancel`); await refresh() }) }
async function showLibrary() {
  await guarded(async () => { library.value = (await api.get('/assets', { params: { per_page: 100 } })).data.data.assets ?? []; libraryOpen.value = true })
}
async function attach(asset) {
  await guarded(async () => { await api.post(`${base()}/attachments`, { asset_id: asset.id, purpose: purpose.value, expected_version: conversation.value.version }); quote.value = null; libraryOpen.value = false; await refresh() })
}
async function detach(asset) {
  await guarded(async () => { await api.delete(`${base()}/attachments/${asset.asset_id}`, { data: { expected_version: conversation.value.version } }); quote.value = null; await refresh() })
}
async function restore() {
  await guarded(async () => { await api.post(`${base()}/revisions/${currentRevision.value.id}/restore`, { expected_version: conversation.value.version }); selectedRevision.value = null; quote.value = null; await refresh() })
}
async function updateConversation(archived = false) {
  await guarded(async () => { await api.patch(base(), { title: rename.value, archived, expected_version: conversation.value.version }); await loadHistory(); if (archived) await router.push({ name: 'create' }); else await refresh() })
}
async function loadArtifact() {
  const revision = currentRevision.value
  const nextKey = revision?.artifact_hash ? `${id.value}/${revision.id}` : ''
  if (nextKey === mediaKey) return // polling never replaces an existing video src
  mediaKey = nextKey; const ticket = ++mediaEpoch
  if (media.value) URL.revokeObjectURL(media.value)
  media.value = ''; artifactLoading.value = Boolean(nextKey)
  if (!nextKey) return
  try {
    const result = await api.get(`${base()}/revisions/${revision.id}/artifact`, { responseType: 'blob' })
    if (ticket === mediaEpoch) media.value = URL.createObjectURL(result.data)
  } catch (e) { if (ticket === mediaEpoch) { error.value = message(e); mediaKey = '' } }
  finally { if (ticket === mediaEpoch) artifactLoading.value = false }
}
function trapLibrary(event) {
  const items = [...libraryDialog.value.querySelectorAll('button:not(:disabled), a[href], input')]
  const first = items[0], last = items.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
watch(libraryOpen, async open => { if (open) { await nextTick(); libraryDialog.value?.querySelector('button')?.focus() } })
watch(() => currentRevision.value?.id, loadArtifact)
watch(id, async () => {
  epoch++; data.value = null; selectedRevision.value = null; quote.value = null; sendingKey = null; showHistory.value = false
  await loadArtifact()
  try { await refresh() } catch (e) { error.value = message(e) }
})
watch(() => auth.user?.workspace_id, () => { window.location.assign('/create') })
onMounted(async () => {
  try { await api.get('/create/capabilities'); available.value = true; await loadHistory(); await refresh() }
  catch (e) { if (e.response?.status !== 404) error.value = message(e) }
  finally { loaded.value = true }
  timer = setInterval(async () => { if (active.value && active.value.status !== 'needs_attention' && !busy.value) { try { await refresh() } catch (e) { error.value = message(e) } } }, 4000)
})
onBeforeUnmount(() => { clearInterval(timer); epoch++; mediaEpoch++; if (media.value) URL.revokeObjectURL(media.value) })
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="auth.user" active-page="create" @logout="auth.logout()" />
    <main class="main create-main">
      <header class="create-header">
        <div><span class="eyebrow">CREATE</span><h1>{{ conversation?.title || 'What would you like to create?' }}</h1></div>
        <nav aria-label="Creation controls">
          <button @click="router.push({ name: 'create' })">New</button>
          <button :aria-expanded="showHistory" @click="showHistory = !showHistory">History</button>
          <button :aria-expanded="details" @click="details = !details">Details</button>
        </nav>
      </header>
      <p v-if="error" class="create-error" role="alert">{{ error }}</p>
      <p v-if="!loaded">Loading your workspace…</p>
      <section v-else-if="!available" class="create-empty"><h2>Create is not enabled for this workspace yet.</h2><p>Your existing video tools are still available.</p><router-link to="/dashboard">Back to dashboard</router-link></section>
      <template v-else>
        <aside class="local-note">Local integration preview · Paid AI is disabled. You can save briefs, attach assets and test the fixed sample render. Creative model evaluation comes next.</aside>
        <div class="create-body">
          <aside v-if="showHistory" class="create-panel history-panel" aria-label="Conversation history">
            <h2>Your conversations</h2><input v-model="search" aria-label="Search conversations" placeholder="Search conversations…" />
            <router-link v-for="c in filteredHistory" :key="c.id" :to="{ name: 'create', params: { conversationId: c.id } }">{{ c.title }}</router-link>
            <p v-if="!filteredHistory.length">No conversations yet.</p>
          </aside>
          <div class="conversation-column">
            <section v-if="!data?.messages?.length" class="create-empty">
              <div class="create-spark">✦</div><h2>Start with an idea. Bring what you have.</h2>
              <p>Describe your image or video. Add product photos, footage or a reference after saving your brief.</p>
              <div class="examples"><button @click="prompt = 'Turn my product photo into a short launch video.'">A product launch</button><button @click="prompt = 'Add clear, timed explanations to my talking-head footage. Keep my original audio.'">Explain with my footage</button></div>
            </section>
            <article v-for="m in data?.messages || []" :key="m.id" class="message" :class="m.role"><span class="eyebrow">{{ m.role === 'user' ? 'YOU' : 'WYVSTUDIO' }}</span><p>{{ m.content }}</p></article>
            <div v-if="data?.attachments?.length" class="attachments">
              <span v-for="a in data.attachments" :key="a.asset_id">{{ a.title || a.asset_type }} <small>{{ a.purpose === 'source' ? 'Reuse source' : 'Inspiration only' }}</small><button v-if="canWrite" :disabled="busy" :aria-label="`Remove ${a.title}`" @click="detach(a)">×</button></span>
            </div>
            <section v-if="active" class="run-card" aria-live="polite"><strong>{{ active.stage }}</strong><p v-if="active.status === 'needs_attention'">The worker disconnected. Your previous result is safe. This run needs reconciliation before another can start.</p><button v-else-if="canWrite" :disabled="busy || active.status === 'cancel_requested'" @click="cancel">{{ active.status === 'cancel_requested' ? 'Stopping…' : 'Stop' }}</button></section>
            <section v-for="run in (data?.runs || []).filter(r => ['failed','cancelled'].includes(r.status))" :key="run.id" class="run-card"><strong>{{ run.status === 'failed' ? 'Render stopped' : 'Cancelled' }}</strong><p>{{ run.error || run.stage }}</p></section>
            <section v-if="currentRevision" class="result-card">
              <span class="eyebrow">LOCAL SAMPLE PREVIEW</span><p>{{ currentRevision.summary }}</p>
              <p v-if="currentRevision.conflict" class="create-error">The brief changed during this render. This draft is preserved in history; it did not replace your current version.</p>
              <p v-if="artifactLoading">Loading video…</p><FinishedVideoPlayer v-if="media" :src="media" />
              <div class="result-actions"><a v-if="media" :href="media" download="wyvstudio-local-preview.mp4">Download sample</a><button v-if="canWrite && currentRevision.id !== conversation.head_revision_id" :disabled="busy" @click="restore">Restore as a new version</button></div>
            </section>
            <section v-if="quote" class="approval-card"><h2>Review before starting</h2><p>{{ quote.description }}</p><strong>{{ quote.credits_max }} credits · no paid calls</strong><small>Approval expires {{ new Date(quote.expires_at).toLocaleTimeString() }}</small><button class="primary" :disabled="busy" @click="approve">Approve sample render</button><button :disabled="busy" @click="quote = null">Dismiss</button></section>
            <button v-else-if="conversation && canWrite && !active && data?.messages?.length && !conversation.archived_at" :disabled="busy" class="plan-button" @click="plan">Review local sample plan</button>
            <form v-if="canWrite && !conversation?.archived_at" class="create-composer" @submit.prevent="send">
              <label for="create-prompt" class="eyebrow">YOUR BRIEF OR NEXT CHANGE</label><textarea id="create-prompt" v-model="prompt" rows="3" maxlength="10000" placeholder="Describe what you want to make, who it is for, and what should stay unchanged…" @input="sendingKey = null" />
              <footer><button v-if="conversation" type="button" :disabled="busy" @click="showLibrary">＋ Add from library</button><span v-else>Save your brief to add attachments.</span><button class="primary" type="submit" :disabled="busy || !prompt.trim()">{{ busy ? 'Saving…' : 'Save brief ↑' }}</button></footer>
            </form>
          </div>
          <aside v-if="details" class="create-panel details-panel" aria-label="Creation details"><button class="close-details" @click="details = false">Close details ×</button><h2>Details</h2><p>Local sample: 15 seconds · Portrait · 1080p</p><p>Model choice, generated media and final delivery remain disabled in this integration preview.</p>
            <template v-if="conversation"><label for="conversation-title">Conversation name</label><input id="conversation-title" v-model="rename" maxlength="160" /><button v-if="canWrite" :disabled="busy || !rename.trim()" @click="updateConversation()">Save name</button><button v-if="canWrite" :disabled="busy || !!active" @click="updateConversation(true)">Archive conversation</button></template>
            <h3>Version history</h3><button v-for="r in revisions" :key="r.id" @click="selectedRevision = r.id">Version {{ r.number }} {{ r.id === conversation?.head_revision_id ? '· Current' : r.conflict ? '· Saved draft' : '' }}</button><p v-if="!revisions.length">Your first result will appear here.</p>
          </aside>
        </div>
      </template>
      <div v-if="libraryOpen" class="library-overlay" @click.self="libraryOpen = false" @keydown.esc="libraryOpen = false"><section ref="libraryDialog" role="dialog" aria-modal="true" @keydown.tab="trapLibrary" aria-labelledby="library-title" class="create-panel library-dialog"><header><h2 id="library-title">Add from your library</h2><button aria-label="Close library" @click="libraryOpen = false">×</button></header><UiSelect v-model="purpose" label="How to use this asset" :options="[{ value: 'source', label: 'Reuse in my creation' }, { value: 'reference', label: 'Inspiration only' }]" /><p>Reference-only assets are not permission to reuse their footage or people.</p><button v-for="a in library.filter(a => ['image','audio','video'].includes(a.asset_type))" :key="a.id" :disabled="busy" @click="attach(a)">{{ a.title || a.asset_type }}</button><p v-if="!library.length">Your library is empty.</p><router-link to="/assets">Upload files in Assets</router-link></section></div>
    </main>
  </div>
</template>

<style scoped>
.fc-shell{min-height:100vh;background:var(--color-bg-deep,#0a0a0f);color:var(--color-text-primary,#ececf3)}.create-main{margin-left:var(--sidebar-width,220px);min-height:100vh;padding:28px 32px;min-width:0}.create-header{display:flex;align-items:center;justify-content:space-between;gap:24px;border-bottom:1px solid var(--color-border,#2b2b38);padding-bottom:20px}.create-header h1{font-size:22px;margin:8px 0;max-width:650px;overflow-wrap:anywhere}.eyebrow{font-size:11px;letter-spacing:1.4px;color:var(--color-text-secondary,#9693a5)}nav,.result-actions,.examples{display:flex;gap:10px;flex-wrap:wrap}button,.result-actions a{border:1px solid var(--color-border,#2b2b38);border-radius:10px;background:var(--color-bg-secondary,#191820);color:inherit;padding:10px 14px;cursor:pointer;text-decoration:none}button:disabled{opacity:.5;cursor:default}button:focus-visible,a:focus-visible{outline:2px solid #ff6b32;outline-offset:3px}.primary{background:#ff6b32;color:#fff;border-color:#ff6b32}.local-note{font-size:13px;color:#c2bbc9;background:#201b1b;padding:13px 18px;border:1px solid #573226;border-radius:12px;margin:22px 0}.create-body{display:flex;gap:24px;align-items:flex-start}.conversation-column{width:min(100%,820px);margin:auto;min-width:0}.create-empty{padding:70px 0;text-align:center}.create-empty h2{font-size:27px}.create-empty p{max-width:570px;margin:18px auto;color:var(--color-text-secondary,#9693a5);line-height:1.7}.create-spark{font-size:46px;color:#ff6b32}.examples{justify-content:center}.message{padding:20px;border-radius:16px;background:#1c1b25;margin:18px 0}.message p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.7;margin-bottom:0}.attachments{display:flex;gap:10px;flex-wrap:wrap}.attachments>span{padding:10px;border:1px solid #34313d;border-radius:12px}.attachments small{display:block;color:#aaa}.attachments button{padding:3px 8px}.run-card,.approval-card,.result-card{border:1px solid #35323f;border-radius:16px;padding:22px;margin:22px 0}.run-card p,.approval-card p{line-height:1.6;color:#b0aaba}.approval-card{border-color:#9c502e}.approval-card small{display:block;margin:12px 0}.result-actions{margin-top:16px}.create-composer{position:sticky;bottom:18px;border:1px solid #48404a;border-radius:18px;background:#191820;padding:18px;margin-top:28px;box-shadow:0 8px 35px #0006}.create-composer textarea{border:0;resize:vertical;background:transparent;width:100%;color:inherit;font:inherit;padding:12px 0;min-height:80px;max-height:260px}.create-composer footer{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12px;color:#aaa}.create-panel{width:260px;flex-shrink:0;border:1px solid #34313d;background:#15141c;border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:12px}.create-panel h2{font-size:17px}.create-panel a{padding:10px;color:inherit;text-decoration:none;overflow-wrap:anywhere}.create-panel p{font-size:13px;color:#aaa;line-height:1.6}.create-panel input{min-width:0;background:#0b0a0f;color:inherit;padding:12px;border:1px solid #34313d;border-radius:9px}.create-error{color:#ffb3a3;padding:12px;background:#392423;border-radius:10px}.library-overlay{position:fixed;inset:0;z-index:1000;background:#0009;display:grid;place-items:center;padding:24px}.library-dialog{width:min(100%,520px);max-height:80vh;overflow:auto}.library-dialog header{display:flex;justify-content:space-between;align-items:center}.close-details{align-self:flex-end}.plan-button{margin-top:18px}@media(max-width:1200px){.create-body{flex-wrap:wrap}.history-panel{width:100%}.details-panel{position:fixed;right:0;top:0;bottom:0;overflow:auto;z-index:100;width:min(90vw,350px);box-shadow:-20px 0 60px #000a}.conversation-column{flex:1}}@media(max-width:860px){.create-main{margin-left:0;padding:20px 16px 100px}.create-header{align-items:flex-start;flex-direction:column;gap:12px}.create-composer{bottom:85px}.create-empty{padding:32px 0}.create-empty h2{font-size:23px}}
</style>
