<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import AppSidebar from '../components/AppSidebar.vue'
import FinishedVideoPlayer from '../components/FinishedVideoPlayer.vue'
import UiSelect from '../components/UiSelect.vue'
import CreateDialog from '../components/create/CreateDialog.vue'
import ThinkingLine from '../components/create/ThinkingLine.vue'
import { VOICE_DESCRIPTIONS, voiceHeadline } from '../lib/voices.js'
import SchedulePostModal from '../components/SchedulePostModal.vue'
import { useWorkspaceStore } from '../stores/workspace'

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
const clock = ref(Date.now()), providerApproved = ref(false), variantCount = ref(1)
const settingsDraft = ref({aspect_ratio:'9:16',duration_seconds:15,language:'en',audio:'original',captions:'off',caption_text:'',approved_facts:[]})
const factsText = ref('')
const delivery = ref(null), shareUrl = ref(''), scheduleTarget = ref(null), safeZones = ref(false)
const downloadName = computed(()=>`wyvstudio-v${currentRevision.value?.number}.${imageOutput.value ? (outputMeta.value.media?.mime_type === 'image/jpeg' ? 'jpg' : outputMeta.value.media?.mime_type === 'image/webp' ? 'webp' : 'png') : 'mp4'}`)
function requestDelivery(action) { shareUrl.value=''; delivery.value={action,revision:currentRevision.value,version:conversation.value.version,allowOlder:false} }
async function performDelivery() {
 const d=delivery.value;if(!d)return
 if(d.revision.has_newer_changes && !d.allowOlder)return
 if(d.action==='download'){const a=document.createElement('a');a.href=media.value;a.download=downloadName.value;a.click();delivery.value=null;return}
 if(d.action==='schedule'){scheduleTarget.value=d;delivery.value=null;return}
 await guarded(async()=>{const result=await api.post(`${base()}/revisions/${d.revision.id}/delivery`,{action:d.action,expected_version:d.version,confirmed:true,allow_older:d.allowOlder});shareUrl.value=result.data.data.url || '';await refresh();if(d.action==='unshare')delivery.value=null})
}
function download(){if(currentRevision.value?.has_newer_changes)requestDelivery('download');else{delivery.value={action:'download',revision:currentRevision.value};performDelivery()}}
async function updateForDelivery(){delivery.value=null;selectedRevision.value=null;await plan()}

const paid = computed(() => capabilities.value?.paid_generation && capabilities.value?.mode === 'agent')
const outputMeta = computed(() => {try{return JSON.parse(currentRevision.value?.metadata_json || '{}')}catch{return {}}})
// Delivery checks the worker ran on the final file, in plain words.
const delivery_checks = computed(() => outputMeta.value?.delivery_checks || null)
const checkIssues = computed(() => {
  const c = delivery_checks.value; if(!c) return []
  const at = f => (f.time != null ? ` at ${f.time}s` : '')
  const name = f => (String(f.message || '').match(/"([^"]{1,80})"/)?.[1]) || (f.selector || 'Some text').replace(/^#/, '')
  return [
    ...(c.safe_area || []).map(f => `"${name(f)}"${at(f)} sits where the app's captions and buttons cover it. Move it up, or ask for a change.`),
    ...(c.edges || []).map(f => `"${name(f)}"${at(f)} runs off the edge of the frame.`),
    ...(c.contrast || []).map(f => `"${name(f)}"${at(f)} is hard to read against its background.`),
    ...(c.loudness?.status === 'check_failed' ? ['The sound level could not be checked.'] : []),
  ].slice(0, 8)
})
const imageOutput = computed(() => outputMeta.value.settings?.output_kind === 'image')
async function editResult() {if(imageOutput.value && !currentRevision.value.output_asset_id){await saveOutput();if(!currentRevision.value.output_asset_id)return}prompt.value = imageOutput.value ? 'Keep this image, but change ' : 'Keep this video, but change '; nextTick(()=>composer.value?.focus())}
async function animateResult(){await guarded(async()=>{const rev=currentRevision.value;if(!rev.output_asset_id)throw Error('Save the image to Assets first.');const c=(await api.post('/create/conversations',{output_kind:'video',video_mode:'animate_image',duration_seconds:5,aspect_ratio:outputMeta.value.settings.aspect_ratio,audio:'silent',origin_conversation_id:id.value,origin_revision_id:rev.id})).data.data;await api.post(`/create/conversations/${c.id}/attachments`,{asset_id:rev.output_asset_id,purpose:'source',reuse_confirmed:true,expected_version:0});await router.push({name:'create',params:{conversationId:c.id}});await refresh();prompt.value='Animate this image with gentle motion. Keep the objects and composition consistent.';nextTick(()=>composer.value?.focus())})}
async function saveSettings() {await guarded(async()=>{await api.patch(base(),{expected_version:conversation.value.version,settings:{...settingsDraft.value,approved_facts:factsText.value.split('\n').map(s=>s.trim()).filter(Boolean)}});quote.value=null;await refresh()})}
function openSettings() {try{settingsDraft.value={...settingsDraft.value,...JSON.parse(conversation.value?.settings_json||'{}')};factsText.value=(settingsDraft.value.approved_facts||[]).join('\n')}catch{}details.value=true}

const workspaceStore = useWorkspaceStore()
const credits = computed(() => workspaceStore.usage?.credits_balance)
const panelTab = ref('details'), panelHeading = ref(null)
let panelReturnFocus = null
function togglePanel() { if (details.value) { closePanel(); return } panelReturnFocus = document.activeElement; openSettings(); nextTick(() => panelHeading.value?.focus()) }
function closePanel() { details.value = false; nextTick(() => panelReturnFocus?.focus?.()) }
function onKey(e) { if (e.key === 'Escape' && details.value && !delivery.value && !showHistory.value && !libraryOpen.value && !linkOpen.value && !stylesOpen.value && !styleSave.value && !pronOpen.value && !compareOpen.value) closePanel() }
function time(value) { return value ? new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '' }
function sizeLabel(a) {
  const d = a.dimensions || {}, dims = d.width && d.height ? `${d.width}×${d.height}` : ''
  const dur = a.duration_seconds ? `0:${String(Math.round(a.duration_seconds)).padStart(2, '0')}` : ''
  return [dur, dims].filter(Boolean).join(' · ') || a.asset_type
}
// Attachments sit in the message they were added for: everything attached
// before a brief belongs to that brief; anything attached after the latest
// brief is still waiting in the composer.
const messageAttachments = computed(() => {
  const out = {}, list = [...(data.value?.attachments || [])].sort((a, b) => Date.parse(a.attached_at || 0) - Date.parse(b.attached_at || 0))
  const briefs = (data.value?.messages || []).filter(m => m.role === 'user')
  for (const a of list) {
    const at = Date.parse(a.attached_at || 0)
    const owner = briefs.find(m => Date.parse(m.created_at) >= at)
    if (owner) (out[owner.id] ||= []).push(a)
  }
  return out
})
const pendingAttachments = computed(() => {
  const briefs = (data.value?.messages || []).filter(m => m.role === 'user')
  const last = briefs.length ? Date.parse(briefs[briefs.length - 1].created_at) : -Infinity
  return (data.value?.attachments || []).filter(a => Date.parse(a.attached_at || 0) > last)
})
const headStatus = computed(() => {
  if (!conversation.value) return null
  if (active.value?.status === 'needs_attention') return { cls: 'warn', text: 'NEEDS A CHECK' }
  if (active.value) return { cls: 'info', text: `UPDATING · VERSION ${(currentNumber.value || 0) + 1}` }
  if (currentNumber.value) return { cls: 'neutral', text: `VERSION ${currentNumber.value}` }
  return null
})
const settingsNow = computed(() => { try { return JSON.parse(conversation.value?.settings_json || '{}') } catch { return {} } })
const outputSummary = computed(() => {
  const st = settingsNow.value, ratio = { '9:16': 'Portrait 9:16', '16:9': 'Landscape 16:9', '1:1': 'Square 1:1', '4:5': 'Feed 4:5' }[st.aspect_ratio] || 'Portrait 9:16'
  if ((st.output_kind || kind.value) === 'image') return `${ratio} · image`
  return [ratio, `${st.duration_seconds || 15} seconds`, st.audio === 'silent' ? 'silent' : 'your audio', st.language && st.language !== 'en' ? st.language.toUpperCase() : null].filter(Boolean).join(' · ')
})
const historyGroups = computed(() => {
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const groups = [{ label: 'TODAY', items: [] }, { label: 'YESTERDAY', items: [] }, { label: 'EARLIER', items: [] }]
  for (const c of filteredHistory.value) {
    const t = Date.parse(c.updated_at)
    groups[t >= today.getTime() ? 0 : t >= today.getTime() - 864e5 ? 1 : 2].items.push(c)
  }
  return groups.filter(g => g.items.length)
})
// ---- plan turn ----
const plans = computed(() => data.value?.plans || [])
const planByMessage = computed(() => Object.fromEntries(plans.value.map(p => [p.message_id, p])))
const currentPlan = computed(() => [...plans.value].reverse().find(p => p.status === 'proposed' && !p.stale) || null)
const stalePlan = computed(() => [...plans.value].reverse().find(p => p.status === 'proposed' && p.stale) || null)
const planDrafts = ref({}), planning = ref(false)
let planKey = null
// Drafts are created before render (pre-flush watcher), never during it.
watch(() => data.value?.plans, list => {
  for (const p of list || []) {
    if (p.status !== 'proposed' || p.stale || planDrafts.value[p.id]) continue
    const sel = p.plan.selections
    planDrafts.value[p.id] = { callouts: [...sel.callouts], narration: [...(sel.narration || [])], voice: sel.voice || '', style: styleKey(sel.style), choices: { ...sel.choices }, kept: [...sel.kept] }
  }
}, { immediate: true })
function draftFor(p) { return planDrafts.value[p.id] || p.plan.selections }
function planDirty(p) {
  const d = planDrafts.value[p.id]; if (!d) return false
  const sel = p.plan.selections
  return JSON.stringify([d.callouts.map(t => t.trim()).filter(Boolean), (d.narration || []).map(t => t.trim()).filter(Boolean), d.voice || '', d.style || '', d.choices, [...d.kept].sort()]) !== JSON.stringify([sel.callouts, sel.narration || [], sel.voice || '', styleKey(sel.style), sel.choices, [...sel.kept].sort()])
}
function optionCredits(p) {
  const d = planDrafts.value[p.id] || p.plan.selections
  const media = (p.plan.media || []).reduce((n, m) => n + (m.credits || 0), 0)
  return media + (p.plan.decisions || []).reduce((n, dec) => n + ((dec.options.find(o => o.id === d.choices[dec.id]) || {}).credits || 0), 0)
}
// How the voice says brand names; changes only what is spoken.
const pronOpen = ref(false), pronRows = ref([])
async function openPronunciations() {
  await guarded(async () => { pronRows.value = ((await api.get('/create/pronunciations')).data.data || []).map(r => ({ ...r })); if (!pronRows.value.length) pronRows.value.push({ written: '', spoken: '' }); pronOpen.value = true })
}
async function savePronunciations() {
  await guarded(async () => {
    const items = pronRows.value.map(r => ({ written: r.written.trim(), spoken: r.spoken.trim() })).filter(r => r.written && r.spoken)
    await api.put('/create/pronunciations', { items }); pronOpen.value = false
  })
}
// Voices for the script, described in plain words (see lib/voices.js).
function voiceOptions(current) {
  const keys = Object.keys(VOICE_DESCRIPTIONS)
  const list = keys.map(k => ({ key: k, label: `${voiceHeadline({ provider_voice_key: k })} · ${k}` }))
  if (current === 'clone') list.unshift({ key: 'clone', label: 'Your cloned voice' })
  else if (current && !keys.includes(current)) list.unshift({ key: current, label: current })
  return list
}
function toggleKept(p, item) { const d = draftFor(p); d.kept = d.kept.includes(item) ? d.kept.filter(k => k !== item) : [...d.kept, item] }
async function makePlan() {
  if (!id.value || planning.value) return
  planning.value = true
  try {
    await guarded(async () => {
      planKey ||= crypto.randomUUID()
      await api.post(`${base()}/plans`, { expected_version: conversation.value.version, idempotency_key: planKey })
      planKey = null; quote.value = null; await refresh()
      await nextTick(); end.value?.scrollIntoView({ behavior: 'smooth', block: 'end' })
    })
  } finally { planning.value = false }
}
async function savePlanEdits(p) {
  const d = draftFor(p)
  await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, callouts: d.callouts.map(t => t.trim()).filter(Boolean), ...(d.narration ? { narration: d.narration.map(t => t.trim()).filter(Boolean) } : {}), ...(d.voice ? { voice: d.voice } : {}), ...(d.style && d.style !== styleKey(p.plan.selections.style) ? { style: { route: d.style.split(':')[0], pack: d.style.split(':')[1] || null } } : {}), choices: d.choices, kept: d.kept })
  const next = { ...planDrafts.value }; delete next[p.id]; planDrafts.value = next; quote.value = null; await refresh()
}
async function reviewPlanCost(p) {
  if (planDirty(p)) { let ok = true; await guarded(async () => { try { await savePlanEdits(p) } catch (e) { ok = false; throw e } }); if (!ok) return }
  await plan()
}
// ---- free edits (text and colours) ----
const autoRan = ref(null), leversOpen = ref(false), leverDraft = ref({})
let editKey = null
const editableFields = computed(() => currentRevision.value?.variables || [])
function openLevers() { leverDraft.value = Object.fromEntries(editableFields.value.map(v => [v.id, v.default])); leversOpen.value = !leversOpen.value; editKey = null }
const leverChanges = computed(() => Object.fromEntries(Object.entries(leverDraft.value).filter(([k, v]) => v !== (editableFields.value.find(f => f.id === k) || {}).default)))
async function applyLevers() {
  if (!Object.keys(leverChanges.value).length) return
  await guarded(async () => {
    editKey ||= crypto.randomUUID()
    await api.post(`${base()}/revisions/${currentRevision.value.id}/edits`, { expected_version: conversation.value.version, idempotency_key: editKey, values: leverChanges.value })
    editKey = null; leversOpen.value = false; selectedRevision.value = null; await refresh()
  })
}
// A plan that only changes existing text and colours can be applied as a free edit.
let freePlanKey = null
async function applyPlanFreeEdit(values) {
  const head = conversation.value?.head_revision_id
  if (!head || !values || !Object.keys(values).length) return
  await guarded(async () => {
    freePlanKey ||= crypto.randomUUID()
    await api.post(`${base()}/revisions/${head}/edits`, { expected_version: conversation.value.version, idempotency_key: freePlanKey, values })
    freePlanKey = null; selectedRevision.value = null; await refresh()
  })
}
let timer, searchTimer, epoch = 0, mediaEpoch = 0, historyEpoch = 0, libraryEpoch = 0, compareEpoch = 0
let mediaKey = '', sendingKey = null, approvalKey = null, uploadRunning = false
const id = computed(() => route.params.conversationId)
const conversation = computed(() => data.value?.conversation)
const revisions = computed(() => data.value?.revisions ?? [])
const currentRevision = computed(() => revisions.value.find(r => r.id === (selectedRevision.value || conversation.value?.head_revision_id)))
const currentNumber = computed(() => revisions.value.find(r => r.id === conversation.value?.head_revision_id)?.number)
const isOldRevision = computed(() => currentRevision.value && currentRevision.value.id !== conversation.value?.head_revision_id)
const active = computed(() => data.value?.runs?.find(r => ['queued','running','cancel_requested','needs_attention'].includes(r.status)))
watch(() => active.value?.id, now => { if (!now) autoRan.value = null })
const canWrite = computed(() => ['owner','admin','editor','super_admin','platform_admin','client_admin','client_editor'].includes(auth.user?.role))
const hasUpload = computed(() => uploads.value.some(u => ['queued','uploading'].includes(u.state)))
const locked = computed(() => busy.value || hasUpload.value)
const expiredQuote = computed(() => quote.value && Date.parse(quote.value.expires_at) <= clock.value)
const kind = computed(() => {
  try { return JSON.parse(conversation.value?.settings_json || '{}').output_kind || outputKind.value } catch { return 'video' }
})
const filteredHistory = computed(() => history.value.filter(c => historyFilter.value === 'all' || state(c) === historyFilter.value))
const examples = [
  { title:'Package a take I already have', copy:'Keep the voice, add callouts and an offer. Usually no new footage.', prompt:'Turn this take into a launch video. Keep my voice, add three benefit callouts and end on our offer.', kind:'video' },
  { title:'Promo from product photos', copy:'Motion, headline and offer over stills. No footage needed.', prompt:'Make a 15-second promo from these product photos, keeping the product accurate and using only claims I provide.', kind:'video' },
  { title:'Kinetic-text explainer', copy:'Script-led, no media required.', prompt:'Explain my idea with big kinetic text on brand colours, no voice. Ask me for any facts you need.', kind:'video' },
  { title:'Product image from a photo', copy:'Same product, new setting. Quoted before it runs.', prompt:'Create a clean product image from my photo on a warm studio background, keeping its shape, label and colours unchanged.', kind:'image' },
]
function state(c) { return ['queued','running','cancel_requested'].includes(c.latest_run_status) ? 'working' : ['failed','needs_attention','needs_input'].includes(c.latest_run_status) ? 'needs' : c.head_revision_id ? 'done' : 'draft' }
function stateLabel(c) { return c.archived_at ? 'Archived' : ({working:'In progress',needs:'Needs attention',done:'Ready',draft:'Brief saved'})[state(c)] }
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
// Saved styles: the look of a finished version or a studied reference, kept only when asked.
const styles = ref([]), pendingStyleId = ref(''), stylesOpen = ref(false), styleSave = ref(null), styleName = ref(''), styleEdits = ref({})
// Built-in style packs: a craft to start from. The picker holds 'pack:<slug>', a saved style id, or '' to let WyvStudio choose.
const packs = ref([])
const currentStyleId = computed(() => { try { if(!conversation.value) return pendingStyleId.value; const s = JSON.parse(conversation.value.settings_json || '{}'); return s.style_pack ? 'pack:' + s.style_pack : (s.style_id || '') } catch { return '' } })
async function loadStyles() { try { const r = (await api.get('/create/styles')).data; styles.value = r.data || []; packs.value = r.packs || [] } catch { /* optional */ } }
function styleSettings(value) { return value.startsWith('pack:') ? { style_pack: value.slice(5), style_id: null } : { style_id: value || null, style_pack: null } }
async function chooseStyle(value) {
  if(!conversation.value) { pendingStyleId.value = value; return }
  await guarded(async () => { await api.patch(base(),{expected_version:conversation.value.version,settings:styleSettings(value)}); quote.value = null; await refresh() })
}
function styleKey(s) { return s ? `${s.route}:${s.pack || ''}` : '' }
// Routes the plan card offers: the planner's pick, every built-in pack, and free design.
function styleOptions(p) {
  const cur = p.plan.selections.style || p.plan.style
  const list = packs.value.map(k => ({ key: `pack:${k.slug}`, label: k.name }))
  if (cur && cur.route !== 'pack' && cur.route !== 'free') list.unshift({ key: styleKey(cur), label: cur.name })
  list.push({ key: 'free:', label: 'Free design' })
  return list
}
function askSaveStyle(target, suggested) { styleSave.value = target; styleName.value = suggested || ''; }
async function confirmSaveStyle() {
  if(!styleSave.value || !styleName.value.trim()) return
  await guarded(async () => { await api.post('/create/styles',{name:styleName.value.trim(),...styleSave.value}); styleSave.value = null; await loadStyles() })
}
async function renameStyle(s) { const name = (styleEdits.value[s.id] ?? s.name).trim(); if(!name || name === s.name) return; await guarded(async () => { await api.patch(`/create/styles/${s.id}`,{name}); await loadStyles() }) }
async function deleteStyle(s) { await guarded(async () => { await api.delete(`/create/styles/${s.id}`); if(currentStyleId.value === s.id) { if(conversation.value) await chooseStyle(''); else pendingStyleId.value = '' } await loadStyles() }) }
async function ensureConversation() {
  if(id.value) return id.value
  const draft = prompt.value
  const c = (await api.post('/create/conversations',{output_kind:outputKind.value,...(pendingStyleId.value ? Object.fromEntries(Object.entries(styleSettings(pendingStyleId.value)).filter(([,v]) => v)) : {})})).data.data
  persistDraft(c.id,draft); persistDraft(null,'')
  await router.replace({name:'create',params:{conversationId:c.id}}); persistDraft(null,''); await refresh()
  return c.id
}
const linkStudying = ref(''), claimPicks = ref({}), pendingText = ref('')
const VIDEO_HOSTS = ['x.com', 'twitter.com', 'mobile.twitter.com', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'tiktok.com', 'www.tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com']
const studySteps = computed(() => {
  let host = ''; try { host = new URL(linkStudying.value).hostname.toLowerCase() } catch {}
  return VIDEO_HOSTS.includes(host)
    ? ['Fetching the video', 'Finding the cuts', 'Listening for speech', 'Reading frames', 'Writing style notes']
    : ['Reading the page', 'Capturing the page', 'Looking at the design', 'Checking claims against the page', 'Writing brand notes']
})
// Keep the live thinking row in view as it appears.
watch([() => linkStudying.value, () => planning.value, () => pendingText.value], async () => { await nextTick(); end.value?.scrollIntoView({ behavior: 'smooth', block: 'end' }) })
const planSteps = ['Reading your brief', 'Looking at your files', 'Planning the scenes', 'Choosing the tools', 'Pricing the plan']
const linkKeys = {}
async function approveClaims(a) {
  const picked = (a.suggested_claims || []).filter((c, i) => claimPicks.value[a.asset_id + ':' + i]).map(c => c.text)
  if (!picked.length) return
  await guarded(async () => {
    let current = []; try { current = JSON.parse(conversation.value.settings_json || '{}').approved_facts || [] } catch {}
    const facts = [...new Set([...current, ...picked])].slice(0, 20)
    await api.patch(base(), { expected_version: conversation.value.version, settings: { approved_facts: facts } })
    claimPicks.value = {}; quote.value = null; await refresh()
  })
}
async function send() {
  if(!prompt.value.trim() || hasUpload.value) return
  const text = prompt.value.trim()
  await guarded(async () => {
    const target = await ensureConversation()
    // Links in the brief are studied first: video posts as style references, other pages as brand pages.
    const known = new Set((data.value?.attachments || []).map(a => a.source?.requested_url).filter(Boolean))
    const links = [...new Set((text.match(/https:\/\/[^\s<>"')]+/g) || []).map(u => u.replace(/[.,;:!?]+$/, '')))].filter(u => !known.has(u)).slice(0, 3)
    // Show the message right away while its links are studied.
    if (links.length) { pendingText.value = text; prompt.value = '' }
    for (const url of links) {
      linkStudying.value = url
      linkKeys[url] ||= crypto.randomUUID()
      const r = await api.post(`${base(target)}/references`, { url, idempotency_key: linkKeys[url], expected_version: conversation.value.version }, { timeout: 150000 })
      if (id.value === target) data.value = r.data.data
    }
    linkStudying.value = ''
    sendingKey ||= crypto.randomUUID()
    await api.post(`${base(target)}/messages`,{content:text,expected_version:conversation.value.version,idempotency_key:sendingKey})
    persistDraft(target,''); sendingKey = null; prompt.value = ''; pendingText.value = ''; quote.value = null; selectedRevision.value = null
    await refresh(); await loadHistory(); await nextTick(); end.value?.scrollIntoView({behavior:'smooth',block:'end'})
  })
  linkStudying.value = ''
  // A failed study leaves the message unsent: give the text back to the box.
  if (pendingText.value) { if (!prompt.value) prompt.value = pendingText.value; pendingText.value = '' }
  // The plan turn follows every brief. It is free; failure leaves the brief saved.
  if (!error.value && canWrite.value && !active.value) await makePlan()
}
async function plan(retryRunId = null) { await guarded(async () => {
  providerApproved.value=false
  quote.value = (await api.post(`${base()}/quotes`,{expected_version:conversation.value.version,variant_count:variantCount.value,...(typeof retryRunId === 'string' ? {retry_run_id:retryRunId} : {})})).data.data
  approvalKey = crypto.randomUUID(); clock.value = Date.now()
  // Owner decision: small jobs just run and show their cost. The server re-checks eligibility.
  if (quote.value.auto_run) {
    await api.post(`${base()}/runs`,{quote_id:quote.value.id,approved:true,auto:true,idempotency_key:approvalKey})
    autoRan.value = quote.value.credits_max; quote.value = null; await refresh(); await loadHistory()
  }
}) }
async function approve() { if(expiredQuote.value) return; await guarded(async () => { await api.post(`${base()}/runs`,{quote_id:quote.value.id,approved:true,provider_approved:providerApproved.value,idempotency_key:approvalKey}); quote.value = null; await refresh(); await loadHistory() }) }
async function saveOutput() { await guarded(async () => { await api.post(`${base()}/revisions/${currentRevision.value.id}/save-output`,{expected_version:conversation.value.version}); await refresh() }) }
async function cancel() { await guarded(async () => { await api.post(`${base()}/runs/${active.value.id}/cancel`); await refresh() }) }
const linkOpen = ref(false), linkUrl = ref(''), linkBusy = ref(false), linkError = ref('')
let linkKey = null
function openLink() { linkUrl.value = ''; linkError.value = ''; linkKey = null; linkOpen.value = true }
function closeLink() { if(!linkBusy.value) linkOpen.value = false }
async function addLink() {
  if(linkBusy.value || !linkUrl.value.trim()) return
  linkBusy.value = true; linkError.value = ''
  linkKey ??= crypto.randomUUID()
  try {
    const target = await ensureConversation()
    const result = await api.post(`${base(target)}/references`,{url:linkUrl.value.trim(),idempotency_key:linkKey,expected_version:conversation.value.version},{timeout:150000})
    if(id.value === target) { data.value = result.data.data; quote.value = null }
    linkOpen.value = false; await loadHistory()
  } catch(e) {
    linkError.value = message(e)
    // A different link needs a new request key; a retry of the same link replays safely.
    if(e.response?.status === 409) { linkKey = null; await refresh().catch(()=>{}) }
  } finally { linkBusy.value = false }
}
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
  loadStyles()
  window.addEventListener('keydown', onKey)
  if (!workspaceStore.usage && auth.user?.workspace_id) workspaceStore.load(auth.user.workspace_id).catch(() => {})
  prompt.value = readDraft(id.value)
  try {capabilities.value = (await api.get('/create/capabilities')).data.data; available.value = true; await loadHistory(); await refresh()}
  catch(e) {if(e.response?.status !== 404) error.value = message(e)}
  finally {loaded.value = true}
  timer = setInterval(async () => {clock.value = Date.now(); if(active.value && active.value.status !== 'needs_attention' && !locked.value) {try {await refresh()} catch(e) {error.value = message(e)}}},2000)
})
onBeforeUnmount(() => {window.removeEventListener('keydown', onKey);clearInterval(timer);clearTimeout(searchTimer);epoch++;mediaEpoch++;historyEpoch++;libraryEpoch++;compareEpoch++;for(const url of [media.value,compareMedia.value,...uploads.value.map(u=>u.preview_url)]) if(url) URL.revokeObjectURL(url)})
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="auth.user" active-page="create" @logout="auth.logout()" />
    <main class="agent-main">
      <header class="agent-header">
        <div><div class="crumb">Create</div><h1>{{ conversation?.title || 'New creation' }}</h1></div>
        <span v-if="headStatus" :class="['status', `status--${headStatus.cls}`]">{{ headStatus.text }}</span>
        <div class="header-actions">
          <span v-if="credits !== null && credits !== undefined" class="credits" :title="`${credits.toLocaleString()} credits available`">{{ credits.toLocaleString() }} cr</span>
          <button v-if="conversation" type="button" class="quiet" :disabled="locked" @click="router.push({name:'create'})">+ New creation</button>
          <button type="button" class="quiet" :disabled="locked" aria-haspopup="dialog" @click="showHistory = true">Recent conversations</button>
          <button v-if="conversation" type="button" class="quiet" :aria-expanded="details" aria-controls="details-panel" @click="togglePanel">Details &amp; versions</button>
        </div>
      </header>
      <p v-if="!loaded" class="loading">Opening your workspace…</p>
      <section v-else-if="!available" class="empty"><h2>Create is not enabled here yet.</h2><router-link to="/dashboard">Back to dashboard</router-link></section>
      <div v-else class="agent-content">
        <section class="conversation" aria-label="Conversation" @dragover.prevent="dragging = canWrite" @dragleave.self="dragging = false" @drop.prevent="canWrite && !conversation?.archived_at && chooseFiles($event.dataTransfer.files)">
          <div v-if="dragging" class="drop-overlay">Drop your footage, photos or audio here</div>
          <div class="messages">
            <div v-if="!data?.messages?.length && !currentRevision && !pendingText" class="empty">
              <h2>What are we making?</h2>
              <p>A video or an image. Describe the result and attach what you have; you see the cost before anything is spent.</p>
              <button v-if="canWrite && !conversation?.archived_at" type="button" class="dropzone" @click="fileInput.click()">Drop files here, or click to attach footage, photos or audio<small>PNG / JPG / WebP · MP4 · MP3 / WAV · up to 100 MB each</small></button>
              <div class="examples"><button v-for="item in examples.filter(item => !conversation || item.kind === kind)" :key="item.title" type="button" class="example" :disabled="!canWrite || !!conversation?.archived_at" @click="example(item)"><b>{{ item.title }}</b>{{ item.copy }}</button></div>
            </div>
            <div v-if="conversation?.archived_at" class="icard"><div class="icard__body"><p>This conversation is archived. Its briefs and versions are preserved.</p></div><div class="icard__foot"><span class="spacer" /><button v-if="canWrite" type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="updateConversation(false)">Restore conversation</button></div></div>

            <template v-for="m in data?.messages || []" :key="m.id">
              <div v-if="m.role === 'user'" class="user-message message">
                <div v-if="messageAttachments[m.id]?.length" class="attachments">
                  <span v-for="a in messageAttachments[m.id]" :key="a.asset_id" class="chip">
                    <img v-if="a.asset_type === 'image' && a.preview_url" :src="a.preview_url" alt="" class="chip__thumb" />
                    <span v-else :class="['chip__thumb', a.asset_type === 'video' ? 'thumb--video' : a.asset_type === 'audio' ? 'thumb--audio' : 'thumb--image']" />
                    <span><b :title="a.title">{{ a.title }}</b><small>{{ sizeLabel(a) }}</small></span>
                    <span :class="['chip__role', a.purpose === 'source' ? '' : 'chip__role--ref']">{{ a.purpose === 'source' ? 'REUSE' : 'REFERENCE' }}</span>
                  </span>
                </div>
                <p>{{ m.content }}</p>
              </div>
              <div v-else class="assistant-message message">
                <span class="speaker">WyvStudio <time>{{ time(m.created_at) }}</time></span>
                <template v-if="planByMessage[m.id]">
                  <details v-if="planByMessage[m.id].status !== 'proposed'" class="run-card"><summary>Earlier plan · {{ planByMessage[m.id].plan.summary }}</summary></details>
                  <div v-else :class="['icard', planByMessage[m.id].stale ? '' : 'icard--warn']">
                    <div class="icard__body">
                      <p class="icard__summary">{{ planByMessage[m.id].plan.summary }}</p>
                      <div v-if="planByMessage[m.id].plan.free_edit" class="free-plan" role="group" aria-label="Free change">
                        <b>This is a free change</b>
                        <span class="muted">It only edits text and colours already in your video. No model call, one render.</span>
                        <ul><li v-for="(v, k) in planByMessage[m.id].plan.free_edit" :key="k"><span class="muted">{{ (editableFields.find(f => f.id === k) || {}).label || k }}:</span> <span v-if="String(v).startsWith('#')" class="free-plan__swatch" :style="{ background: v }" /> {{ v }}</li></ul>
                        <button type="button" class="btn btn--primary btn--sm" :disabled="locked || !canWrite" @click="applyPlanFreeEdit(planByMessage[m.id].plan.free_edit)">Apply for free</button>
                      </div>
                      <p v-if="planByMessage[m.id].stale" class="notice">Your brief changed after this plan. Plan again to include it.</p>
                      <template v-else>
                        <div v-if="planByMessage[m.id].plan.callouts.length || draftFor(planByMessage[m.id]).callouts.length" class="claims">
                          <span class="claims__label">ON-SCREEN COPY · EDIT BEFORE APPROVING</span>
                          <div v-for="(t, i) in draftFor(planByMessage[m.id]).callouts" :key="i" class="claim"><span class="claim__n">{{ i + 1 }}</span><span v-if="(planByMessage[m.id].plan.new_wording || []).includes(t)" class="tier" title="Not in your brief, facts or page. Approving the plan approves this wording.">new wording</span><input v-model="draftFor(planByMessage[m.id]).callouts[i]" class="input" maxlength="120" :aria-label="`On-screen line ${i + 1}`" :disabled="!canWrite" /><button type="button" class="upload__x" :aria-label="`Remove line ${i + 1}`" :disabled="!canWrite" @click="draftFor(planByMessage[m.id]).callouts.splice(i, 1)">×</button></div>
                          <button v-if="canWrite && draftFor(planByMessage[m.id]).callouts.length < 6" type="button" class="quiet quiet--sm" @click="draftFor(planByMessage[m.id]).callouts.push('')">+ Add a line</button>
                          <span class="claims__note">Only the words here appear on screen.{{ planByMessage[m.id].plan.left_out ? ' ' + planByMessage[m.id].plan.left_out : '' }}</span>
                        </div>
                        <p v-else-if="planByMessage[m.id].plan.left_out" class="muted plan-note">{{ planByMessage[m.id].plan.left_out }}</p>
                        <div v-if="(planByMessage[m.id].plan.narration || []).length || (draftFor(planByMessage[m.id]).narration || []).length" class="claims">
                          <span class="claims__label">VOICEOVER SCRIPT · EDIT BEFORE APPROVING</span>
                          <div v-for="(t, i) in draftFor(planByMessage[m.id]).narration" :key="'n' + i" class="claim"><span class="claim__n">{{ i + 1 }}</span><span v-if="(planByMessage[m.id].plan.new_wording || []).includes(t)" class="tier" title="Not in your brief, facts or page. Approving the plan approves this wording.">new wording</span><input v-model="draftFor(planByMessage[m.id]).narration[i]" class="input" maxlength="160" :aria-label="`Spoken line ${i + 1}`" :disabled="!canWrite" /><button type="button" class="upload__x" :aria-label="`Remove spoken line ${i + 1}`" :disabled="!canWrite" @click="draftFor(planByMessage[m.id]).narration.splice(i, 1)">×</button></div>
                          <button v-if="canWrite && draftFor(planByMessage[m.id]).narration.length < 8" type="button" class="quiet quiet--sm" @click="draftFor(planByMessage[m.id]).narration.push('')">+ Add a spoken line</button>
                          <label class="voice-pick"><span class="muted">Voice</span>
                            <select v-model="draftFor(planByMessage[m.id]).voice" :disabled="!canWrite" aria-label="Voice for the script">
                              <option v-for="v in voiceOptions(draftFor(planByMessage[m.id]).voice)" :key="v.key" :value="v.key">{{ v.label }}</option>
                            </select>
                          </label>
                          <span class="claims__note">Only these words are spoken. Approving the plan approves this script. <button type="button" class="quiet quiet--sm" @click="openPronunciations">Pronunciations</button></span>
                        </div>
                        <label v-if="planByMessage[m.id].plan.style" class="voice-pick style-line"><span class="muted">Style</span>
                          <select :value="planDrafts[planByMessage[m.id].id]?.style ?? styleKey(planByMessage[m.id].plan.selections.style)" :disabled="!canWrite || !planDrafts[planByMessage[m.id].id]" aria-label="Style this video starts from" @change="planDrafts[planByMessage[m.id].id].style = $event.target.value">
                            <option v-for="o in styleOptions(planByMessage[m.id])" :key="o.key" :value="o.key">{{ o.label }}</option>
                          </select>
                          <span v-if="planByMessage[m.id].plan.style.why" class="muted">{{ planByMessage[m.id].plan.style.why }}</span>
                        </label>
                        <div v-for="dec in planByMessage[m.id].plan.decisions" :key="dec.id" class="decision">
                          <b>{{ dec.question }}</b>
                          <label v-for="o in dec.options" :key="o.id" class="choice"><input v-model="draftFor(planByMessage[m.id]).choices[dec.id]" type="radio" :name="`${planByMessage[m.id].id}-${dec.id}`" :value="o.id" :disabled="!canWrite" /><div><b>{{ o.label }} <span :class="['tier', o.kind === 'media' ? 'tier--media' : 'tier--free']">{{ o.kind === 'media' ? `~${o.credits} CREDITS` : 'INCLUDED' }}</span></b><p>{{ o.detail }}</p></div></label>
                        </div>
                        <div v-if="planByMessage[m.id].plan.kept_as_is.length" class="decision">
                          <b>Kept as-is</b>
                          <label v-for="k in planByMessage[m.id].plan.kept_as_is" :key="k" class="keep"><input type="checkbox" :checked="draftFor(planByMessage[m.id]).kept.includes(k)" :disabled="!canWrite" @change="toggleKept(planByMessage[m.id], k)" /> {{ k }}</label>
                        </div>
                      </template>
                    </div>
                    <details v-if="!planByMessage[m.id].stale" class="more">
                      <summary>View details</summary>
                      <div class="more__body">
                        <div class="plan-cols">
                          <div class="plan-col"><span class="legend ok">REUSED</span><ul><li v-for="r in planByMessage[m.id].plan.reused" :key="r.asset_id">{{ r.title }} <small>{{ r.use }}</small></li><li v-if="!planByMessage[m.id].plan.reused.length" class="muted">Nothing supplied</li></ul></div>
                          <div v-if="planByMessage[m.id].plan.scenes.length" class="plan-col"><span class="legend info">SCENES</span><ul><li v-for="sc in planByMessage[m.id].plan.scenes" :key="sc.label + sc.start">{{ sc.label }} <small>{{ sc.start }}–{{ sc.end }}s</small></li></ul></div>
                          <div class="plan-col"><span class="legend muted">OUTPUT</span><ul><li>{{ outputSummary }}</li></ul></div>
                        </div>
                        <div v-if="planByMessage[m.id].plan.media.length" class="quote">
                          <div v-for="md in planByMessage[m.id].plan.media" :key="md.kind + md.description" class="quote__line"><span>{{ md.description }}</span><b>{{ md.credits ? md.credits + ' cr' : 'included' }}</b></div>
                        </div>
                      </div>
                    </details>
                    <div class="icard__foot">
                      <template v-if="planByMessage[m.id].stale"><span class="spacer" /><button v-if="canWrite" type="button" class="btn btn--primary btn--sm" :disabled="locked || planning" @click="makePlan">{{ planning ? 'Planning…' : 'Plan again' }}</button></template>
                      <template v-else>
                        <div class="cost-line"><b>{{ optionCredits(planByMessage[m.id]) ? `+${optionCredits(planByMessage[m.id])} credits for new media` : 'Planning was free' }}</b><span>· building is priced on the next step</span></div>
                        <span class="spacer" />
                        <UiSelect v-if="paid && canWrite" v-model="variantCount" label="Results" :options="[{value:1,label:'One result'},{value:2,label:'Two variations'},{value:3,label:'Three variations'}]" />
                        <button v-if="canWrite && planDirty(planByMessage[m.id])" type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="guarded(() => savePlanEdits(planByMessage[m.id]))">Save changes</button>
                        <button v-if="canWrite && !active && !quote && !(kind === 'image' && !paid)" type="button" class="btn btn--primary btn--sm" :disabled="locked" @click="reviewPlanCost(planByMessage[m.id])">{{ paid ? 'Review cost' : 'Review local sample plan' }}</button>
                      </template>
                    </div>
                  </div>
                </template>
                <p v-else>{{ m.content }}</p>
              </div>
            </template>

            <div v-if="data?.runs?.some(r => ['failed','cancelled','needs_input'].includes(r.status))" class="assistant-message">
              <details class="run-card"><summary>Earlier attempts</summary><p v-for="run in data.runs.filter(r => ['failed','cancelled','needs_input'].includes(r.status))" :key="run.id" class="run-line">{{ run.status === 'needs_input' ? 'Your input is needed' : run.status === 'failed' ? 'Stopped' : 'Cancelled' }} · {{ run.error || run.stage }}<button v-if="run.status === 'failed' && canWrite" type="button" class="btn btn--ghost btn--sm" :disabled="locked || !!active" @click="plan(run.id)">Review cost to retry</button></p></details>
            </div>

            <div v-if="currentRevision" class="assistant-message">
              <span class="speaker">WyvStudio <time>{{ time(currentRevision.created_at) }}</time></span>
              <p>{{ currentRevision.summary }}</p>
              <p v-if="currentRevision.conflict" class="notice">{{ outputMeta.variant_group ? 'An alternative variation. Inspect it, then restore it as a new version to make it current.' : 'Your brief changed while this was being made. This draft is kept; your current version did not change.' }}</p>
              <p v-if="isOldRevision" class="notice">You are viewing version {{ currentRevision.number }}. Version {{ currentNumber }} is still current. Download uses the version shown here.</p>
              <div :class="['result', currentRevision.export_job_id || (imageOutput && currentRevision.output_asset_id) ? 'result--done' : '']">
                <div class="result__stage">
                  <p v-if="artifactLoading" class="muted">Loading your result…</p>
                  <img v-if="media && imageOutput" :src="media" class="created-image" alt="Generated image" />
                  <div v-else-if="media" class="player-wrap"><FinishedVideoPlayer ref="player" :src="media" /><div v-if="safeZones" class="safe-zones" aria-hidden="true" /></div>
                  <button v-if="!media && !artifactLoading" type="button" class="btn btn--ghost btn--sm" @click="loadArtifact">Retry preview</button>
                </div>
                <div class="result__meta">
                  <b>Version {{ currentRevision.number }}{{ imageOutput ? ' · image' : ' · video' }}</b>
                  <span class="muted">{{ outputMeta.fixture === false ? (currentRevision.export_job_id ? 'Saved to Videos' : currentRevision.output_asset_id ? 'Saved to Assets' : 'Preview') : 'Local sample preview' }}</span>
                  <span :class="['status', isOldRevision ? 'status--neutral' : 'status--ok']">{{ isOldRevision ? 'EARLIER' : 'CURRENT' }}</span>
                </div>
                <div v-if="delivery_checks" class="checks" role="status" aria-label="Before you post">
                  <b>{{ checkIssues.length ? 'Before you post' : 'Ready to post' }}</b>
                  <ul>
                    <li v-for="(t, i) in checkIssues" :key="i" class="checks__warn">{{ t }}</li>
                    <li v-if="delivery_checks.loudness?.status === 'levelled'">Sound levelled from {{ delivery_checks.loudness.from }} to {{ delivery_checks.loudness.lufs }} LUFS for social playback.</li>
                    <li v-else-if="delivery_checks.loudness?.status === 'ok'">Sound level is right for social ({{ delivery_checks.loudness.lufs }} LUFS).</li>
                    <li v-if="!checkIssues.length">Text clears the platform buttons and captions, stays inside the frame and is readable.</li>
                  </ul>
                </div>
                <div class="result__actions">
                  <button v-if="media" type="button" class="btn btn--primary" @click="download">Download {{ imageOutput ? 'image' : paid ? 'video' : 'sample' }}</button>
                  <button v-if="canWrite && !isOldRevision && !conversation.archived_at" type="button" class="btn btn--outline" :disabled="locked || !!currentRevision.output_asset_id" @click="saveOutput">{{ currentRevision.output_asset_id ? (imageOutput ? 'Saved to Assets' : 'Saved to videos') : (imageOutput ? 'Save to Assets' : paid ? 'Save to videos' : 'Save sample to videos') }}</button>
                  <button v-if="canWrite && !conversation.archived_at && currentRevision.output_asset_id" type="button" class="btn btn--outline" @click="requestDelivery('share')">Share link</button>
                  <button v-if="canWrite && !conversation.archived_at && currentRevision.share_enabled" type="button" class="btn btn--ghost" @click="requestDelivery('unshare')">Turn off share link</button>
                  <button v-if="canWrite && !conversation.archived_at && !imageOutput && currentRevision.export_job_id" type="button" class="btn btn--outline" @click="requestDelivery('schedule')">Schedule post</button>
                  <button v-if="paid && imageOutput && currentRevision.output_asset_id && canWrite && !conversation.archived_at" type="button" class="btn btn--ghost" :disabled="locked" @click="animateResult">Animate image</button>
                  <button v-if="canWrite && !conversation.archived_at && !active && editableFields.length" type="button" class="btn btn--ghost" :aria-expanded="leversOpen" @click="openLevers">Edit text and colours <span class="tier tier--free">FREE</span></button>
                  <button v-if="paid && !isOldRevision" type="button" class="btn btn--ghost" @click="editResult">Edit with a prompt</button>
                  <button v-if="isOldRevision" type="button" class="btn btn--ghost" @click="compare">Compare with current</button>
                  <button v-if="canWrite && isOldRevision && !conversation.archived_at" type="button" class="btn btn--ghost" :disabled="locked" @click="restore">Restore as a new version</button>
                  <button v-if="isOldRevision" type="button" class="btn btn--ghost" @click="selectedRevision = null">Back to current</button>
                  <button v-if="!imageOutput && media" type="button" class="btn btn--ghost btn--safe" :aria-pressed="safeZones" @click="safeZones = !safeZones">Show safe margins</button>
                </div>
                <div v-if="leversOpen" class="levers">
                  <div class="levers__grid">
                    <label v-for="f in editableFields" :key="f.id" class="field-label">{{ f.label.toUpperCase() }}
                      <span v-if="f.type === 'color'" class="colour"><input v-model="leverDraft[f.id]" type="color" :aria-label="f.label" /><input v-model="leverDraft[f.id]" class="input" maxlength="7" :aria-label="`${f.label} hex`" /></span>
                      <select v-else-if="f.type === 'enum'" v-model="leverDraft[f.id]" class="input"><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
                      <input v-else v-model="leverDraft[f.id]" class="input" :type="f.type === 'number' ? 'number' : 'text'" maxlength="200" />
                    </label>
                  </div>
                  <div class="levers__foot">
                    <span class="muted">{{ isOldRevision ? 'Makes a new version from this one; the current version stays as it is.' : 'Makes a new version. No model call, no credits.' }} Size changes need the assistant to re-lay the design; ask in the chat.</span>
                    <button type="button" class="btn btn--ok btn--sm" :disabled="locked || !Object.keys(leverChanges).length" @click="applyLevers">Apply as a new version <span class="sub">free</span></button>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="active" class="assistant-message" aria-live="polite">
              <div :class="['icard', active.status === 'needs_attention' ? 'icard--warn' : 'icard--info']">
                <div class="icard__body working">
                  <ThinkingLine v-if="active.status !== 'needs_attention'" :key="active.id" :label="active.stage" :started-at="active.created_at" :detail="active.status === 'needs_attention' ? 'This run needs a recovery check before it continues. Earlier versions are safe, and nothing retries on its own.' : 'You can leave this page. Earlier versions stay downloadable while this runs.'" /><div v-else class="working__row"><div><div class="working__label">{{ active.stage }}</div><div class="working__step">{{ active.status === 'needs_attention' ? 'This run needs a recovery check before it continues. Earlier versions are safe, and nothing retries on its own.' : 'You can leave this page. Earlier versions stay downloadable while this runs.' }}</div></div></div><div v-if="autoRan !== null" class="auto-tag-row"><span v-if="autoRan !== null" class="tier tier--quoted auto-tag">RAN AUTOMATICALLY · UP TO {{ autoRan }} CREDITS</span></div>
                </div>
                <div v-if="canWrite && active.status !== 'needs_attention'" class="icard__foot"><span class="spacer" /><button type="button" class="btn btn--ghost btn--sm" :disabled="locked || active.status === 'cancel_requested'" @click="cancel">{{ active.status === 'cancel_requested' ? 'Stopping…' : 'Stop · keeps what is done so far' }}</button></div>
              </div>
            </div>

            <div v-if="quote" class="assistant-message">
              <span class="speaker">WyvStudio</span>
              <div class="icard icard--warn">
                <div class="icard__body">
                  <p class="icard__summary">{{ quote.paid ? 'Here is what this will cost.' : 'This renders the fixed local sample.' }} {{ quote.description }}</p>
                  <p v-if="quote.settings?.video_mode === 'animate_image'" class="muted">{{ quote.settings.duration_seconds }} seconds · 480p image animation · silent. Generated motion may change details; check before use.</p>
                  <p v-if="quote.variants > 1" class="muted">{{ quote.variants }} variations, each with its own result. A finished variation is kept if another one fails.</p>
                  <label v-if="quote.paid" class="consent"><input v-model="providerApproved" type="checkbox" /> Send this brief and its approved media to our AI providers (Anthropic, Replicate). I have permission to use any people, products and claims in it.</label>
                  <p v-if="quote.paid" class="muted">After this approval, jobs under 15 credits in this conversation run without asking and show their cost.</p>
                  <small class="muted">{{ expiredQuote ? 'This approval expired. Review a fresh plan to continue.' : `Approval open until ${new Date(quote.expires_at).toLocaleTimeString()}` }}</small>
                </div>
                <div class="icard__foot">
                  <div v-if="quote.plan_media?.length" class="quote__items" aria-label="Bought for this plan">
                    <div v-for="(md, i) in quote.plan_media" :key="i" class="quote__line"><span>{{ md.description }}</span><b>{{ md.credits ? md.credits + ' cr' : 'included' }}</b></div>
                    <small class="muted">Each item is charged only if it is made. A retry of this plan reuses what was already made.</small>
                  </div>
                  <div class="cost-line"><b>{{ quote.paid ? `Up to ${quote.credits_max} credits` : 'No credits' }}</b><span>{{ quote.paid ? '· reserved when you approve, unused part returned' : '· no paid calls' }}</span></div>
                  <span class="spacer" />
                  <button type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="quote = null">Not now</button>
                  <button v-if="expiredQuote" type="button" class="btn btn--primary btn--sm" :disabled="locked" @click="plan">Refresh plan</button>
                  <button v-else type="button" class="btn btn--primary btn--sm" :disabled="locked || quote.paid && !providerApproved" @click="approve">{{ quote.paid ? 'Approve' : 'Approve sample render' }}</button>
                </div>
              </div>
            </div>
            <div v-if="pendingText" class="user-message message">{{ pendingText }}</div>
            <div v-if="linkStudying" class="assistant-message"><span class="speaker">WyvStudio</span><ThinkingLine :key="linkStudying" :steps="studySteps" :detail="linkStudying" :step-seconds="5" /></div>
            <div v-if="planning" class="assistant-message"><span class="speaker">WyvStudio</span><ThinkingLine :steps="planSteps" detail="Planning is free. You see the cost before anything is spent." :step-seconds="4" /></div>
            <div v-else-if="!quote && conversation && canWrite && !active && data?.messages?.length && !conversation.archived_at && !currentPlan" class="next-step">
              <p v-if="kind === 'image' && !paid" class="muted">Your image brief is saved. Image generation and editing are not enabled in this local preview yet.</p>
              <button v-if="!stalePlan" type="button" class="btn btn--primary btn--sm" :disabled="locked" @click="makePlan">Plan it</button>
            </div>
            <div ref="end" />
          </div>

          <div v-if="canWrite && !conversation?.archived_at" class="composer-dock">
            <div v-if="error" class="create-error" role="alert"><p>{{ error }}</p><p v-if="conflict">We refreshed the conversation. Your unsent text is still here; check the latest version before trying again.</p><button type="button" aria-label="Dismiss error" @click="error = ''; conflict = false">×</button></div>
            <div v-if="uploads.length || pendingAttachments.length" class="attached">
              <div v-for="a in pendingAttachments" :key="'a' + a.asset_id" class="upload">
                <img v-if="a.asset_type === 'image' && a.preview_url" :src="a.preview_url" alt="" class="upload__thumb" /><span v-else :class="['upload__thumb', a.asset_type === 'video' ? 'thumb--video' : 'thumb--audio']" />
                <div><b :title="a.title">{{ a.title }}</b><small>{{ sizeLabel(a) }} · {{ a.purpose === 'source' ? 'reuse' : 'reference' }}</small><small v-if="a.reference?.summary" class="ref-note">{{ a.reference.summary }}</small>
                  <div v-if="a.suggested_claims?.length && canWrite" class="claims">
                    <small class="muted">Claims on this page. Tick the ones that may appear on screen:</small>
                    <label v-for="(c, i) in a.suggested_claims" :key="i" class="claims__row" :title="'From the page: ' + c.quote"><input v-model="claimPicks[a.asset_id + ':' + i]" type="checkbox" /> {{ c.text }}</label>
                    <button type="button" class="quiet quiet--sm" :disabled="locked" @click="approveClaims(a)">Add to approved facts</button>
                  </div><button v-if="a.reference?.summary && canWrite" type="button" class="quiet quiet--sm" @click="askSaveStyle({asset_id:a.asset_id}, a.title)">Save as style</button></div>
                <button type="button" class="upload__x" :disabled="locked" :aria-label="`Remove ${a.title}`" @click="detach(a)">×</button>
              </div>
              <div v-for="u in uploads" :key="u.key" :class="['upload', u.error ? 'upload--error' : '']">
                <img v-if="u.file.type.startsWith('image/') && u.preview_url" :src="u.preview_url" alt="" class="upload__thumb" /><span v-else :class="['upload__thumb', u.file.type.startsWith('audio/') ? 'thumb--audio' : 'thumb--video']" />
                <div>
                  <b :title="u.file.name">{{ u.file.name }}</b>
                  <small>{{ u.error || (u.state === 'uploading' ? `uploading · ${u.progress}%` : `${(u.file.size / 1048576).toFixed(1)} MB · ready`) }}</small>
                  <div v-if="u.state === 'uploading'" class="upload__bar"><span :style="{ width: u.progress + '%' }" /></div>
                  <label v-if="u.purpose === 'source' && u.state !== 'uploading'" class="consent consent--sm"><input v-model="u.confirmed" type="checkbox" /> I own this or have permission to reuse it.</label>
                </div>
                <span v-if="u.state !== 'uploading'" class="role-toggle" role="group" :aria-label="`Use ${u.file.name} as`"><button type="button" :aria-pressed="u.purpose === 'source'" :disabled="u.state === 'failed'" @click="u.purpose = 'source'">REUSE</button><button type="button" :aria-pressed="u.purpose === 'reference'" :disabled="u.state === 'failed'" @click="u.purpose = 'reference'">REFERENCE</button></span>
                <button v-if="u.state !== 'uploading'" type="button" class="btn btn--ghost btn--sm" :disabled="locked || !!u.error && u.state === 'ready' || u.purpose === 'source' && !u.confirmed" @click="upload(u)">{{ u.state === 'failed' ? 'Retry upload' : 'Upload' }}</button>
                <button type="button" class="upload__x" :disabled="u.state === 'uploading'" :aria-label="`Remove pending ${u.file.name}`" @click="removeUpload(u)">×</button>
              </div>
            </div>
            <form class="prompt-form" @submit.prevent="send">
              <label for="create-prompt" class="sr-only">Describe what you want to create or change</label>
              <textarea ref="composer" id="create-prompt" v-model="prompt" rows="2" maxlength="10000" placeholder="Describe what you want to create or change…" @input="sendingKey = null" @keydown.meta.enter.prevent="send" @keydown.ctrl.enter.prevent="send" />
              <div class="composer-bottom">
                <button type="button" class="quiet" :disabled="locked" title="PNG, JPEG, WebP, MP4, MP3 or WAV. Up to 20 files, 100 MB each and 200 MB total." @click="fileInput.click()">+ Attach</button>
                <button type="button" class="quiet" :disabled="locked" @click="showLibrary">From library</button>
                <button type="button" class="quiet" :disabled="locked" title="A public post from X, YouTube or TikTok, used as a style reference" @click="openLink">From a link</button>
                <label v-if="styles.length || packs.length" class="style-pick"><span class="sr-only">Style</span><select :value="currentStyleId" :disabled="locked" aria-label="Style" @change="chooseStyle($event.target.value)"><option value="">Style: WyvStudio chooses</option><optgroup v-if="packs.length" label="WyvStudio styles"><option v-for="k in packs" :key="k.slug" :value="'pack:' + k.slug">Style: {{ k.name }}</option></optgroup><optgroup v-if="styles.length" label="Your styles"><option v-for="s in styles" :key="s.id" :value="s.id">Style: {{ s.name }}</option></optgroup></select></label>
                <button v-if="styles.length" type="button" class="quiet" @click="stylesOpen = true">Manage styles</button>
                <span v-if="!conversation" class="seg" role="group" aria-label="What to make"><button type="button" :aria-pressed="outputKind === 'video'" @click="outputKind = 'video'">Video</button><button type="button" :aria-pressed="outputKind === 'image'" @click="outputKind = 'image'">Image</button></span>
                <button class="send" type="submit" :disabled="locked || !prompt.trim()" aria-label="Send"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" /></svg></button>
              </div>
            </form>
            <p class="composer-note">Planning and text or colour changes are free. Small jobs under 15 credits just run and show their cost; anything more is quoted first. Uploads stay private.</p>
          </div>
          <p v-if="error && (!canWrite || conversation?.archived_at)" class="create-error" role="alert">{{ error }}</p>
        </section>

        <div v-if="details" class="panel-scrim" aria-hidden="true" @click="closePanel" />
        <aside v-if="details && conversation" id="details-panel" aria-label="Details and versions">
          <div class="panel-heading"><h2 ref="panelHeading" tabindex="-1">{{ kind === 'image' ? 'This image' : 'This video' }}</h2><button type="button" class="quiet" aria-label="Close details" @click="closePanel">×</button></div>
          <div class="detail-tabs" role="group" aria-label="Panel"><button type="button" :aria-pressed="panelTab === 'details'" @click="panelTab = 'details'">Details</button><button type="button" :aria-pressed="panelTab === 'versions'" @click="panelTab = 'versions'">Versions</button></div>
          <template v-if="panelTab === 'details'">
            <section>
              <h3>OUTPUT</h3>
              <p>{{ outputSummary }}</p>
              <p v-if="!paid" class="muted">{{ kind === 'image' ? 'Image generation is not enabled in this local preview. Your image brief and references are saved.' : 'The local sample is 15 seconds, portrait, 1080p. Other settings apply once generation is enabled.' }}</p>
              <details v-if="paid" class="panel-edit"><summary>Change output</summary>
                <UiSelect v-model="settingsDraft.aspect_ratio" label="Format" :options="[{value:'9:16',label:'Portrait · 9:16'},{value:'16:9',label:'Landscape · 16:9'},{value:'1:1',label:'Square'},{value:'4:5',label:'Feed · 4:5'}]" />
                <label v-if="kind === 'video'" class="field-label">Length in seconds<input v-model.number="settingsDraft.duration_seconds" type="number" min="5" max="30" class="input" /></label>
                <UiSelect v-model="settingsDraft.language" label="Language" :options="[{value:'en',label:'English'},{value:'fr',label:'French'},{value:'es',label:'Spanish'},{value:'de',label:'German'},{value:'pt',label:'Portuguese'}]" />
                <template v-if="kind === 'video'"><UiSelect v-model="settingsDraft.audio" label="Audio" :options="[{value:'original',label:'Keep supplied audio'},{value:'silent',label:'Silent'}]" /><UiSelect v-model="settingsDraft.captions" label="Captions" :options="[{value:'off',label:'Off'},{value:'provided',label:'Use my exact text'}]" /><textarea v-if="settingsDraft.captions === 'provided'" v-model="settingsDraft.caption_text" class="input" placeholder="Paste the exact words." /></template>
                <button type="button" class="btn btn--ghost btn--sm" :disabled="locked || !!active || !canWrite" @click="saveSettings">Apply</button>
              </details>
            </section>
            <section v-if="paid">
              <h3>APPROVED FACTS</h3>
              <textarea v-model="factsText" class="input" rows="3" placeholder="One verified fact per line. Only these claims can appear." />
              <p class="muted">Anything not listed here is left out rather than guessed.</p>
              <button type="button" class="btn btn--ghost btn--sm" :disabled="locked || !!active || !canWrite" @click="saveSettings">Save facts</button>
            </section>
            <section>
              <h3>FILES IN THIS {{ kind === 'image' ? 'IMAGE' : 'VIDEO' }}</h3>
              <div v-for="a in data?.attachments || []" :key="a.asset_id" class="asset">
                <img v-if="a.asset_type === 'image' && a.preview_url" :src="a.preview_url" alt="" class="asset__thumb" /><span v-else :class="['asset__thumb', a.asset_type === 'video' ? 'thumb--video' : 'thumb--audio']" />
                <span><b :title="a.title">{{ a.title }}</b><small>{{ sizeLabel(a) }} · {{ a.purpose === 'source' ? 'reuse' : 'reference' }}</small></span>
                <button v-if="canWrite && !conversation.archived_at" type="button" class="upload__x" :disabled="locked" :aria-label="`Remove ${a.title}`" @click="detach(a)">×</button>
              </div>
              <p v-if="!data?.attachments?.length" class="muted">No files yet.</p>
              <button v-if="canWrite && !conversation.archived_at" type="button" class="quiet" :disabled="locked" @click="showLibrary">+ Add from library</button>
            </section>
            <section>
              <h3>CONVERSATION</h3>
              <label for="conversation-title" class="field-label">Conversation name<input id="conversation-title" v-model="rename" class="input" maxlength="160" :disabled="!canWrite" /></label>
              <div class="row-actions"><button v-if="canWrite" type="button" class="btn btn--ghost btn--sm" :disabled="locked || !rename.trim()" @click="updateConversation()">Save name</button><button v-if="canWrite" type="button" class="btn btn--ghost btn--sm" :disabled="locked || !!active" @click="updateConversation(!conversation.archived_at)">{{ conversation.archived_at ? 'Restore conversation' : 'Archive conversation' }}</button></div>
              <p v-if="active" class="muted">Stop or recover the running creation before archiving.</p>
              <p v-if="settingsDraft.origin_conversation_id" class="muted"><router-link :to="{name:'create',params:{conversationId:settingsDraft.origin_conversation_id}}">Back to the source image conversation</router-link></p>
            </section>
          </template>
          <template v-else>
            <section>
              <p class="muted">Every change is a new version. Restoring an old one makes a new version; nothing is overwritten.</p>
              <div v-if="active" class="version"><div class="version__row"><span><b>Version {{ (currentNumber || 0) + 1 }}</b><small>{{ active.stage }}</small></span><span class="status status--info">BUILDING</span></div></div>
              <button v-for="r in [...revisions].reverse()" :key="r.id" type="button" :class="['version', r.id === conversation.head_revision_id ? 'is-current' : '', r.id === currentRevision?.id ? 'is-viewing' : '']" @click="selectedRevision = r.id === conversation.head_revision_id ? null : r.id">
                <span class="version__row"><span><b>Version {{ r.number }}</b><small>{{ date(r.created_at) }} · {{ r.restored_from_id ? 'restored' : r.conflict ? 'saved draft' : 'built' }}</small></span><span v-if="r.id === conversation.head_revision_id" class="status status--ok">CURRENT</span><span v-else-if="r.id === currentRevision?.id" class="status status--neutral">VIEWING</span></span>
              </button>
              <p v-if="!revisions.length" class="muted">Your first result will appear here.</p>
              <button v-if="currentRevision && canWrite" type="button" class="btn btn--ghost btn--sm" @click="askSaveStyle({conversation_id:id, revision_id:currentRevision.id}, (conversation.title || 'Style') + ' · v' + currentRevision.number)">Save this version's look as a style</button>
            </section>
            <section v-if="revisions.some(r => r.export_job_id || r.output_asset_id)">
              <h3>SAVED</h3>
              <div v-for="r in [...revisions].reverse().filter(r => r.export_job_id || r.output_asset_id)" :key="'s' + r.id" class="version"><span class="version__row"><span><b>Version {{ r.number }}</b><small>{{ r.export_job_id ? 'in All Videos' : 'in Assets' }}{{ r.share_enabled ? ' · shared' : '' }}</small></span><span v-if="r.has_newer_changes && r.id === conversation.head_revision_id" class="status status--warn">OLDER THAN BRIEF</span></span></div>
            </section>
          </template>
        </aside>
      </div>
      <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,audio/mpeg,audio/wav,audio/x-wav" multiple hidden @change="chooseFiles($event.target.files)" />
      <CreateDialog :open="!!delivery" :title="delivery?.action === 'unshare' ? 'Turn off this share link?' : 'Use this version?'" @close="delivery=null"><template v-if="delivery"><p>Version {{ delivery.revision.number }} is the exact file for this action.</p><p v-if="delivery.revision.has_newer_changes" class="notice">Newer changes are not in this file. Update your creation or continue with this version.</p><label v-if="delivery.revision.has_newer_changes" class="consent"><input v-model="delivery.allowOlder" type="checkbox" /> Continue with this earlier result.</label><p v-if="delivery.action === 'share'" class="muted">Anyone with the link can view this version until you turn it off. No other files or messages are shared.</p><p v-if="shareUrl"><a :href="shareUrl" target="_blank" rel="noopener">Open share page</a><input class="input" readonly :value="shareUrl" aria-label="Share link" @focus="$event.target.select()" /></p><div class="row-actions"><button v-if="delivery.revision.has_newer_changes" type="button" class="btn btn--ghost btn--sm" @click="updateForDelivery">Update creation</button><button type="button" class="btn btn--primary btn--sm" :disabled="locked || delivery.revision.has_newer_changes && !delivery.allowOlder" @click="performDelivery">{{ delivery.action === 'share' ? 'Create share link' : delivery.action === 'unshare' ? 'Turn off link' : delivery.action === 'schedule' ? 'Choose account and time' : 'Download this version' }}</button></div><p v-if="error" class="create-error">{{ error }}</p></template></CreateDialog>
      <SchedulePostModal v-if="scheduleTarget" :export-job-id="scheduleTarget.revision.export_job_id" :delivery-path="`${base()}/revisions/${scheduleTarget.revision.id}/delivery`" :delivery-context="{expected_version:scheduleTarget.version,allow_older:scheduleTarget.allowOlder}" :allow-ai-caption="false" @close="scheduleTarget=null" />
      <CreateDialog :open="showHistory" title="Recent conversations" drawer @close="showHistory = false">
        <div class="drawer-top"><input v-model="search" type="search" class="input" aria-label="Search conversations" placeholder="Search titles, briefs or file names…" /><router-link to="/videos" class="quiet" @click="showHistory = false">All Videos</router-link></div>
        <div class="drawer-filters" role="group" aria-label="Filter"><button v-for="f in [{value:'all',label:'All'},{value:'working',label:'In progress'},{value:'needs',label:'Needs you'},{value:'done',label:'Ready'}]" :key="f.value" type="button" :aria-pressed="historyFilter === f.value" @click="historyFilter = f.value">{{ f.label }}</button></div>
        <label class="consent"><input v-model="archivedHistory" type="checkbox" /> Show archived conversations</label>
        <p class="muted" v-if="historyLoading">Searching…</p><p class="muted" v-else-if="!filteredHistory.length">No matching conversations.</p>
        <template v-for="g in historyGroups" :key="g.label">
          <div class="drawer-group">{{ g.label }}</div>
          <router-link v-for="c in g.items" :key="c.id" :class="['session', c.id === id ? 'is-current' : '']" :to="{name:'create',params:{conversationId:c.id}}" @click="showHistory = false"><span class="session__thumb" /><div><b>{{ c.title }}</b><small>{{ c.last_message || 'Add your first brief or attachment' }}</small><span :class="['status', state(c) === 'working' ? 'status--info' : state(c) === 'needs' ? 'status--warn' : state(c) === 'done' ? 'status--ok' : 'status--neutral']">{{ stateLabel(c).toUpperCase() }}</span></div><time>{{ date(c.updated_at) }}</time></router-link>
        </template>
        <small class="muted">Showing up to 100 matching conversations.</small>
      </CreateDialog>
      <CreateDialog :open="pronOpen" title="Pronunciations" @close="pronOpen = false">
        <form class="link-form" @submit.prevent="savePronunciations">
          <p class="muted link-form__note">How the voice should say a word, for example WyvStudio as "Weave Studio". Only the voice changes; the screen keeps the written word. Applies to new voiceovers in this workspace.</p>
          <div v-for="(r, i) in pronRows" :key="i" class="pron-row">
            <input v-model="r.written" class="input" maxlength="60" placeholder="Written" :aria-label="`Written word ${i + 1}`" />
            <span class="muted" aria-hidden="true">said as</span>
            <input v-model="r.spoken" class="input" maxlength="80" placeholder="Spoken" :aria-label="`Spoken as ${i + 1}`" />
            <button type="button" class="upload__x" :aria-label="`Remove pronunciation ${i + 1}`" @click="pronRows.splice(i, 1)">×</button>
          </div>
          <button v-if="pronRows.length < 30" type="button" class="quiet quiet--sm" @click="pronRows.push({ written: '', spoken: '' })">+ Add a word</button>
          <div class="link-form__actions"><button type="button" class="btn btn--ghost btn--sm" @click="pronOpen = false">Cancel</button><button type="submit" class="btn btn--primary btn--sm" :disabled="busy">Save</button></div>
        </form>
      </CreateDialog>
      <CreateDialog :open="!!styleSave" title="Save as a style" @close="styleSave = null">
        <form class="link-form" @submit.prevent="confirmSaveStyle">
          <label for="style-name" class="link-form__label">Name</label>
          <input id="style-name" v-model="styleName" class="input" maxlength="80" autocomplete="off" />
          <p class="muted link-form__note">Saves the palette, type, motion and pacing so new creations in this workspace can use them. Nothing else is copied. You can rename or delete it any time.</p>
          <div class="link-form__actions"><button type="button" class="btn btn--ghost btn--sm" @click="styleSave = null">Cancel</button><button type="submit" class="btn btn--primary btn--sm" :disabled="busy || !styleName.trim()">Save style</button></div>
        </form>
      </CreateDialog>
      <CreateDialog :open="stylesOpen" title="Saved styles" @close="stylesOpen = false">
        <p v-if="!styles.length" class="muted">No saved styles yet.</p>
        <div v-for="s in styles" :key="s.id" class="style-row">
          <div class="style-row__swatches" aria-hidden="true"><span v-for="c in s.style.palette" :key="c" :style="{background:c}" /></div>
          <div class="style-row__body">
            <input v-model="styleEdits[s.id]" class="input" :placeholder="s.name" :aria-label="`Rename ${s.name}`" maxlength="80" @keydown.enter.prevent="renameStyle(s)" />
            <small class="muted">{{ s.style.summary || s.style.look || 'Saved look' }} · from a {{ s.source }} · v{{ s.version }}</small>
          </div>
          <button type="button" class="btn btn--ghost btn--sm" :disabled="busy || !(styleEdits[s.id] ?? '').trim()" @click="renameStyle(s)">Rename</button>
          <button type="button" class="btn btn--ghost btn--sm" :disabled="busy" :aria-label="`Delete ${s.name}`" @click="deleteStyle(s)">Delete</button>
        </div>
      </CreateDialog>
      <CreateDialog :open="linkOpen" title="Use a video as a style reference" @close="closeLink">
        <form class="link-form" @submit.prevent="addLink">
          <label for="ref-url" class="link-form__label">Link to a video post or a web page</label>
          <input id="ref-url" v-model="linkUrl" class="input" type="url" inputmode="url" autocomplete="off" placeholder="https://x.com/…/status/…" :disabled="linkBusy" />
          <p class="muted link-form__note">A video post (X, YouTube, TikTok) is studied for pacing, structure and look. A web page is read and captured for its brand look and the claims it makes, which you approve before any appear on screen. Neither is placed in your video. You can also paste links straight into your message.</p>
          <p v-if="linkError" class="create-error" role="alert">{{ linkError }}</p>
          <p v-if="linkBusy" class="muted" role="status">Fetching and studying the video. This can take up to a minute.</p>
          <div class="link-form__actions"><button type="button" class="btn btn--ghost btn--sm" :disabled="linkBusy" @click="closeLink">Cancel</button><button type="submit" class="btn btn--primary btn--sm" :disabled="linkBusy || !linkUrl.trim()">{{ linkBusy ? 'Studying…' : 'Add reference' }}</button></div>
        </form>
      </CreateDialog>
      <CreateDialog :open="libraryOpen" title="Add from your library" @close="libraryOpen = false">
        <form class="library-search" @submit.prevent="libraryPage = 1; loadLibrary()"><input v-model="librarySearch" class="input" type="search" aria-label="Search library" placeholder="Find a photo, video or audio file…" /><button type="submit" class="btn btn--ghost btn--sm">Search</button></form><UiSelect v-model="purpose" label="How to use this asset" :options="[{value:'reference',label:'Reference only'},{value:'source',label:'Reuse in my creation'}]" /><p class="muted">A reference helps describe a style. It does not give permission to copy footage, people or branding.</p><label v-if="purpose === 'source'" class="consent"><input v-model="reuseConfirmed" type="checkbox" /> I own this media or have permission to reuse it.</label>
        <div class="library-grid"><button v-for="a in library" :key="a.id" type="button" :disabled="locked || purpose === 'source' && !reuseConfirmed" @click="attach(a)"><img v-if="a.asset_type === 'image' && a.storage_url" :src="a.storage_url" alt="" /><span v-else class="file-symbol">{{ a.asset_type === 'video' ? '▷' : '♫' }}</span><strong>{{ a.title || a.asset_type }}</strong><small>{{ a.asset_type }}</small></button></div><p v-if="!library.length" class="muted">No matching media. Attach files directly in the composer.</p><div class="row-actions"><button type="button" class="btn btn--ghost btn--sm" :disabled="libraryPage <= 1" @click="libraryPage--; loadLibrary()">Previous</button><span>{{ libraryPage }} / {{ libraryLastPage }}</span><button type="button" class="btn btn--ghost btn--sm" :disabled="libraryPage >= libraryLastPage" @click="libraryPage++; loadLibrary()">Next</button></div><p v-if="error" class="create-error" role="alert">{{ error }}</p>
      </CreateDialog>
      <CreateDialog :open="compareOpen" title="Compare versions" @close="compareOpen = false"><div class="comparison"><section><h3>Version {{ currentRevision?.number }} · Earlier</h3><img v-if="media && imageOutput" :src="media" class="created-image" alt="Earlier image" /><FinishedVideoPlayer v-else-if="media" :src="media" /></section><section><h3>Version {{ currentNumber }} · Current</h3><img v-if="compareMedia && imageOutput" :src="compareMedia" class="created-image" alt="Current image" /><FinishedVideoPlayer v-else-if="compareMedia" :src="compareMedia" /><p v-else>Loading current version…</p></section></div><p class="muted">Inspect each version to compare. This does not change the current version.</p></CreateDialog>
    </main>
  </div>
</template>

<style scoped>
/* Tokens from the approved create-ui mockup, on the app's own accent. */
.link-form{display:flex;flex-direction:column;gap:10px}.link-form__label{font-size:13px;color:var(--text-2)}.link-form__note{font-size:12px;line-height:1.45;margin:0}.link-form__actions{display:flex;justify-content:flex-end;gap:8px}
.style-pick select{background:transparent;border:1px solid var(--line-2);color:var(--text-2);border-radius:8px;padding:4px 8px;font-size:12px;max-width:180px}
.quiet--sm{font-size:11px;padding:2px 0;margin-top:2px}
.style-row{display:grid;grid-template-columns:auto 1fr auto auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}
.style-row__swatches{display:flex;gap:3px}.style-row__swatches span{width:14px;height:14px;border-radius:4px;border:1px solid var(--line-2)}
.style-row__body{display:flex;flex-direction:column;gap:4px;min-width:0}
@media (max-width:560px){.style-row{grid-template-columns:1fr auto auto}.style-row__swatches{display:none}}
.checks{border:1px solid var(--line-2);border-radius:10px;padding:10px 12px;margin:8px 0;font-size:12px;color:var(--text-2)}.checks b{font-size:12px;color:var(--text)}.checks ul{margin:6px 0 0;padding-left:16px;display:flex;flex-direction:column;gap:3px}.checks__warn{color:#f5a524}
.free-plan{display:flex;flex-direction:column;gap:6px;border:1px solid var(--line-2);border-radius:10px;padding:10px 12px;margin:8px 0;font-size:12px}.free-plan ul{margin:0;padding-left:16px}.free-plan__swatch{display:inline-block;width:10px;height:10px;border-radius:3px;vertical-align:middle;border:1px solid var(--line-2)}
.claims{display:flex;flex-direction:column;gap:3px;margin-top:4px}.claims__row{font-size:11px;color:var(--text-2);display:flex;gap:6px;align-items:flex-start}
.voice-pick{display:flex;gap:8px;align-items:center;font-size:12px;margin-top:4px}.style-line{flex-wrap:wrap;margin:8px 0}.voice-pick select{background:transparent;border:1px solid var(--line-2);color:var(--text-2);border-radius:8px;padding:4px 8px;font-size:12px}
.pron-row{display:grid;grid-template-columns:1fr auto 1fr auto;gap:8px;align-items:center}
.ref-note{display:block;font-size:11px;color:var(--text-3);margin-top:2px;max-width:420px}
.fc-shell{--bg:#0b0d11;--bg-2:#0f1116;--bg-3:#14171d;--bg-4:#191d24;--bg-5:#111419;--line:#1f232b;--line-2:#262b34;--line-3:#2c313b;--text:#eceef1;--text-2:#b7bcc6;--text-3:#8f95a1;--text-4:#5d6472;--accent:var(--color-accent,#ff6b35);--accent-ink:#0b0d11;--accent-soft:rgba(255,107,53,.12);--accent-line:rgba(255,107,53,.35);--warn:#e3b64a;--warn-soft:rgba(227,182,74,.14);--warn-line:rgba(227,182,74,.35);--warn-bg:#16150f;--warn-edge:#3a3320;--ok:#4dc48a;--ok-soft:rgba(77,196,138,.10);--ok-line:rgba(77,196,138,.35);--info:#5b9dff;--info-soft:rgba(91,157,255,.12);--info-line:rgba(91,157,255,.35);--mono:"JetBrains Mono","Space Mono",ui-monospace,Menlo,monospace;--r:8px;--r-md:10px;--r-lg:12px;min-height:100vh;background:var(--bg);color:var(--text)}
.agent-main{margin-left:var(--sidebar-width,220px);height:100dvh;display:flex;flex-direction:column;min-width:0}
button{font:inherit;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.55}
button:focus-visible,a:focus-visible,textarea:focus-visible,input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.muted{color:var(--text-3)}
.agent-header{display:flex;align-items:center;gap:14px;padding:0 28px;min-height:64px;border-bottom:1px solid var(--line);flex-wrap:wrap;flex-shrink:0}
.agent-header h1{margin:0;font-size:16px;font-weight:700;max-width:52ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crumb{font-size:13px;color:var(--text-3)}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.quiet{border:1px solid var(--line-3);border-radius:var(--r);background:transparent;padding:8px 12px;color:var(--text-2);font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center}
.quiet:hover{background:var(--bg-4);color:var(--text)}
.quiet[aria-expanded="true"]{border-color:var(--accent-line);color:var(--accent);background:var(--accent-soft)}
.credits{display:flex;align-items:center;gap:8px;padding:8px 14px;border:1px solid var(--line-2);border-radius:var(--r);font:12px var(--mono);color:var(--warn)}
.credits::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.status{font:10px var(--mono);letter-spacing:1.5px;padding:4px 8px;border-radius:4px;border:1px solid;white-space:nowrap}
.status--warn{color:var(--warn);background:var(--warn-soft);border-color:var(--warn-line)}.status--ok{color:var(--ok);background:var(--ok-soft);border-color:var(--ok-line)}.status--info{color:var(--info);background:var(--info-soft);border-color:var(--info-line)}.status--neutral{color:var(--text-2);background:var(--line-2);border-color:var(--line-2)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:var(--r-md);border:1px solid transparent;font-weight:700;font-size:13px;text-decoration:none}
.btn--primary{background:var(--accent);color:var(--accent-ink)}.btn--primary:hover{filter:brightness(1.06)}
.btn--outline{background:var(--bg-4);border-color:var(--line-3);color:var(--text)}
.btn--ghost{background:transparent;border-color:var(--line-3);color:var(--text-2);font-weight:600}.btn--ghost:hover{color:var(--text);background:var(--bg-4)}
.btn--sm{padding:7px 11px;font-size:12px;border-radius:7px}
.btn--safe[aria-pressed="true"]{border-color:var(--info);background:var(--info-soft);color:var(--info)}
.input{width:100%;padding:9px 11px;border:1px solid var(--line-3);border-radius:var(--r);background:var(--bg-3);color:var(--text);font:inherit;font-size:13px}
.field-label{display:flex;flex-direction:column;gap:6px;font:10px var(--mono);letter-spacing:1.2px;color:var(--text-3)}
.field-label .input,#details-panel .input{font-family:var(--font-sans,"DM Sans",sans-serif);font-size:13px;letter-spacing:0}
.row-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.agent-content{display:flex;flex:1;min-height:0;position:relative}
.conversation{flex:1;min-width:0;display:flex;flex-direction:column;position:relative}
.messages{flex:1;overflow:auto;padding:32px max(24px,calc((100% - 760px)/2)) 16px;display:flex;flex-direction:column;gap:28px}
.user-message{align-self:flex-end;max-width:560px;background:var(--bg-4);border:1px solid var(--line-3);border-radius:16px 16px 4px 16px;padding:14px 18px;font-size:14px;line-height:1.55}
.user-message p{margin:0;white-space:pre-wrap}
.attachments{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.chip{display:flex;align-items:center;gap:10px;padding:5px 10px 5px 5px;border:1px solid var(--line-3);border-radius:var(--r);background:var(--bg-3);max-width:260px}
.chip>span:nth-child(2){min-width:0}
.chip b{font-size:13px;font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip small{font:10px var(--mono);color:var(--text-3)}
.chip__thumb{width:26px;height:26px;border-radius:5px;flex-shrink:0;object-fit:cover}
.thumb--video{background:#3a2f4a}.thumb--image{background:#2a3441}.thumb--audio{background:#243a33}
.chip__role{font:9px var(--mono);letter-spacing:1px;color:var(--ok)}.chip__role--ref{color:var(--info)}
.assistant-message{display:flex;flex-direction:column;gap:12px;max-width:760px;font-size:15px;line-height:1.6;color:#d5d9e0}
.assistant-message>p{margin:0}
.speaker{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--text-3)}
.speaker::before{content:"";width:8px;height:8px;border-radius:2px;background:var(--accent)}
.speaker time{font:11px var(--mono);color:var(--text-4);font-weight:400;margin-left:4px}
.icard{border:1px solid var(--line-2);border-radius:14px;background:var(--bg-3);overflow:hidden}
.icard__body{padding:14px 16px;display:flex;flex-direction:column;gap:10px;font-size:14px}
.icard__body p{margin:0}
.icard__summary{font-size:15px;line-height:1.55;color:var(--text)}
.icard__foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--line);background:var(--bg-5)}
.icard__foot .spacer,.spacer{flex:1}
.icard--warn{border-color:var(--warn-edge)}.icard--warn .icard__foot{background:var(--warn-bg);border-color:var(--warn-edge)}
.icard--info{border-color:var(--info-line)}
.claims{display:flex;flex-direction:column;gap:6px}
.claims__label{font:10px var(--mono);letter-spacing:1.5px;color:var(--text-3)}
.claim{display:flex;align-items:center;gap:10px}
.claim__n{width:20px;font:11px var(--mono);color:var(--text-4);text-align:right;flex-shrink:0}
.claim .input{padding:8px 10px;font-size:14px}
.claims__note{font-size:12px;color:var(--text-3)}
.quiet--sm{padding:5px 9px;font-size:12px;align-self:flex-start}
.plan-note{font-size:13px;margin:0}
.decision{display:flex;flex-direction:column;gap:8px}
.decision>b{font-size:13px}
.choice{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line-3);border-radius:var(--r-md);background:var(--bg-2);cursor:pointer}
.choice:has(input:checked){border-color:var(--ok-line);background:var(--ok-soft)}
.choice input{accent-color:var(--ok);margin-top:4px}
.choice b{font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.choice p{margin:2px 0 0;font-size:12px;color:var(--text-3)}
.keep{display:flex;align-items:center;gap:8px;font-size:13px}
.keep input{accent-color:var(--ok)}
.tier{font:500 11px var(--mono);letter-spacing:.5px}.tier--free{color:var(--ok)}.tier--media{color:var(--accent)}
.more{border-top:1px solid var(--line)}
.more summary{list-style:none;cursor:pointer;padding:10px 16px;font-size:13px;font-weight:600;color:var(--text-3);display:flex}
.more summary::-webkit-details-marker{display:none}
.more summary::after{content:"▾";margin-left:auto;color:var(--text-4)}
.more[open] summary::after{content:"▴"}
.more__body{padding:0 16px 14px;display:flex;flex-direction:column;gap:12px;font-size:13px;color:var(--text-2)}
.plan-cols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.plan-col{display:flex;flex-direction:column;gap:8px;padding:12px;border:1px solid var(--line);border-radius:var(--r-md);background:var(--bg-2)}
.plan-col ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px}
.plan-col li{display:flex;justify-content:space-between;gap:8px}
.plan-col li small{font:10px var(--mono);color:var(--text-3);text-align:right}
.legend{display:flex;align-items:center;gap:8px;font:10px var(--mono);letter-spacing:1.5px}
.legend::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
.legend.ok{color:var(--ok)}.legend.info{color:var(--info)}.legend.muted{color:var(--text-3)}
.quote{display:flex;flex-direction:column;gap:4px}
.quote__items{display:flex;flex-direction:column;gap:4px;margin-bottom:8px}
.quote__line{display:flex;justify-content:space-between;gap:12px;font-size:12px;color:var(--text-3)}
.quote__line b{font:500 12px var(--mono);color:var(--text)}
.levers{display:flex;flex-direction:column;gap:12px;padding:14px;border-top:1px solid var(--line);background:var(--bg-2)}
.levers__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.levers__foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px}
.levers__foot .muted{flex:1 1 260px}
.colour{display:flex;gap:6px;align-items:center}
.colour input[type=color]{width:36px;height:34px;padding:0;border:1px solid var(--line-3);border-radius:6px;background:none}
.btn--ok{background:var(--ok-soft);border-color:var(--ok);color:var(--ok)}
.btn .sub{font:500 11px var(--mono);opacity:.8}
.auto-tag{margin-left:10px;font-size:10px}
.cost-line{display:flex;align-items:baseline;gap:8px;font-size:13px;color:var(--text-2)}
.cost-line b{font:500 16px var(--mono);color:var(--warn)}
.working{gap:10px}
.working__row{display:flex;align-items:center;gap:12px}
.spinner{width:16px;height:16px;border-radius:50%;border:2px solid var(--line-3);border-top-color:var(--accent);animation:spin 1s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
.working__label{font-size:14px;font-weight:700}.working__step{font-size:12px;color:var(--text-3)}
.run-card{display:flex;flex-direction:column;gap:8px;padding:12px 14px;border:1px solid var(--line-2);border-radius:var(--r-md);background:var(--bg-3)}
.run-card summary{list-style:none;cursor:pointer;font-size:13px;font-weight:700;display:flex;gap:10px}
.run-card summary::-webkit-details-marker{display:none}
.run-card summary::after{content:"▾";margin-left:auto;color:var(--text-4)}
.run-card[open] summary::after{content:"▴"}
.run-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:8px 0 0;font-size:13px;color:var(--text-2)}
.result{border:1px solid var(--line-2);border-radius:14px;background:var(--bg-3);overflow:hidden}
.result--done{border-color:var(--ok-line)}
.result__stage{display:flex;justify-content:center;align-items:center;min-height:120px;padding:20px;background:var(--bg-2)}
.result__stage .player-wrap{width:100%;max-width:420px}
.result__meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px 0;font-size:13px}
.result__meta b{font-weight:700}.result__meta .status{margin-left:auto}
.result__actions{display:flex;flex-wrap:wrap;gap:8px;padding:12px 14px 14px}
.player-wrap{position:relative}
.safe-zones{position:absolute;inset:14% 6% 35%;border:1px dashed #fff9;pointer-events:none;box-shadow:0 0 0 1px #0005}
.player-wrap .safe-zones:after{content:"Keep essential content inside";position:absolute;top:4px;left:4px;font-size:10px;color:#fff;text-shadow:0 1px 2px #000}
.created-image{display:block;max-width:100%;max-height:60vh;object-fit:contain;border-radius:10px}
.notice{background:var(--warn-soft);color:#e8d29b!important;border:1px solid var(--warn-line);border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.55;margin:0}
.next-step{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.next-step p{margin:0;font-size:13px}
.composer-dock{padding:8px max(24px,calc((100% - 760px)/2)) 16px;background:linear-gradient(transparent,var(--bg) 24%);flex-shrink:0}
.attached{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px}
.upload{display:flex;align-items:center;gap:10px;padding:6px 8px 6px 6px;border:1px solid var(--line-3);border-radius:var(--r);background:var(--bg-4);font-size:13px;min-width:240px;max-width:100%}
.upload__thumb{width:30px;height:30px;border-radius:5px;flex-shrink:0;object-fit:cover;background:#2a3441}
.upload>div{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.upload b{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.upload small{font:10px var(--mono);color:var(--text-3)}
.upload__bar{height:3px;border-radius:2px;background:var(--line-2);overflow:hidden}.upload__bar span{display:block;height:100%;background:var(--accent)}
.upload--error{border-color:#4a2b2b}.upload--error small{color:#e07a7a}
.upload__x{width:22px;height:22px;border:0;background:transparent;color:var(--text-3);font-size:16px;flex-shrink:0}
.role-toggle{display:inline-flex;gap:2px;padding:2px;border-radius:6px;background:var(--bg-3);border:1px solid var(--line-2)}
.role-toggle button{border:0;background:transparent;color:var(--text-3);font:500 9px var(--mono);letter-spacing:.5px;padding:3px 6px;border-radius:4px}
.role-toggle button[aria-pressed="true"]{background:var(--line-2);color:var(--text)}
.consent{display:flex;align-items:flex-start;gap:8px;font-size:12px;line-height:1.55;color:var(--text-2)}
.consent input{accent-color:var(--accent);margin-top:3px}
.consent--sm{font-size:11px}
.prompt-form{background:var(--bg-3);border:1px solid var(--line-3);border-radius:16px;padding:12px 14px}
.prompt-form:focus-within{border-color:var(--text-4)}
.prompt-form textarea{width:100%;resize:none;max-height:160px;background:none;border:0;color:var(--text);font:inherit;font-size:15px;line-height:1.5;outline:none}
.composer-bottom{display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap}
.composer-bottom .quiet{border:0;padding:6px 8px;font-size:12px}
.seg{display:inline-flex;gap:2px;padding:2px;border-radius:7px;border:1px solid var(--line-2);background:var(--bg-4)}
.seg button{border:0;background:transparent;color:var(--text-3);font-size:12px;font-weight:600;padding:4px 10px;border-radius:5px}
.seg button[aria-pressed="true"]{background:var(--line-3);color:var(--text)}
.send{margin-left:auto;width:36px;height:36px;border:0;border-radius:var(--r-md);background:var(--accent);color:var(--accent-ink);display:grid;place-items:center}
.composer-note{font-size:11px;color:var(--text-4);margin:8px 4px 0}
.empty{margin:auto;max-width:560px;display:flex;flex-direction:column;gap:18px;text-align:center;align-items:center;padding:40px 0}
.empty h2{margin:0;font-size:28px;font-weight:800;letter-spacing:-.4px}
.empty p{margin:0;color:var(--text-3);line-height:1.55}
.examples{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;width:100%;text-align:left}
.example{border:1px solid var(--line-2);border-radius:var(--r-md);background:var(--bg-3);padding:12px 14px;font-size:13px;line-height:1.45;color:var(--text-2);text-align:center}
.example:hover{border-color:var(--text-4);color:var(--text)}
.example b{display:block;font-size:13px;color:var(--text);margin-bottom:3px}
.dropzone{width:100%;padding:22px;border:1px dashed var(--line-3);border-radius:var(--r-lg);color:var(--text-3);font-size:13px;background:transparent;display:flex;flex-direction:column;gap:6px;align-items:center}
.dropzone small{font:10px var(--mono);color:var(--text-4)}
.dropzone:hover{border-color:var(--accent);color:var(--text-2)}
#details-panel{width:320px;flex-shrink:0;border-left:1px solid var(--line);background:var(--bg-2);overflow:auto;display:flex;flex-direction:column}
.panel-scrim{display:none}
.panel-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:16px 18px 0}
.panel-heading h2{margin:0;font-size:15px;outline:none}
.panel-heading .quiet{padding:4px 10px;font-size:16px}
.detail-tabs{display:flex;gap:6px;padding:12px 18px 0;border-bottom:1px solid var(--line)}
.detail-tabs button{padding:10px 0;margin-right:12px;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--text-3);font-size:13px;font-weight:600}
.detail-tabs button[aria-pressed="true"]{color:var(--text);border-bottom-color:var(--accent);font-weight:700}
#details-panel section{border-bottom:1px solid var(--line);padding:14px 18px;display:flex;flex-direction:column;gap:8px}
#details-panel h3{margin:0;font:10px var(--mono);letter-spacing:1.5px;color:var(--text-3)}
#details-panel p{margin:0;font-size:13px;line-height:1.5}
.panel-edit summary{cursor:pointer;font-size:12px;color:var(--text-2);font-weight:600}
.panel-edit[open]{display:flex;flex-direction:column;gap:8px}
.asset{display:flex;align-items:center;gap:10px;font-size:13px}
.asset>span:nth-child(2){flex:1;min-width:0}
.asset__thumb{width:34px;height:34px;border-radius:6px;flex-shrink:0;object-fit:cover;background:#2a3441}
.asset b{display:block;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.asset small{font:10px var(--mono);color:var(--text-3)}
.version{display:flex;flex-direction:column;gap:8px;padding:10px;border:1px solid var(--line-2);border-radius:var(--r-md);background:var(--bg-3);text-align:left;color:inherit;width:100%}
.version__row{display:flex;align-items:center;gap:10px;width:100%}
.version b{font-size:13px}.version small{display:block;font:10px var(--mono);color:var(--text-3)}
.version .status{margin-left:auto}
.version.is-current{border-color:var(--accent-line)}.version.is-viewing{border-color:var(--info-line)}
.drawer-top{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.drawer-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.drawer-filters button{border:1px solid var(--line-3);border-radius:999px;background:transparent;color:var(--text-3);font-size:12px;padding:5px 10px}
.drawer-filters button[aria-pressed="true"]{color:var(--text);border-color:var(--text-4);background:var(--bg-4)}
.drawer-group{font:10px var(--mono);letter-spacing:1.5px;color:var(--text-4);padding:12px 6px 4px}
.session{display:flex;gap:12px;align-items:flex-start;padding:10px;border:1px solid transparent;border-radius:var(--r-md);color:inherit;text-decoration:none}
.session:hover{background:var(--bg-3);border-color:var(--line-2)}
.session.is-current{border-color:var(--accent-line);background:var(--bg-3)}
.session__thumb{width:30px;height:54px;border-radius:4px;background:#1a1f2b;border:1px solid var(--line-3);flex-shrink:0}
.session>div{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.session b{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.session small{font-size:12px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.session .status{font-size:9px;padding:3px 6px;align-self:flex-start}
.session time{font:11px var(--mono);color:var(--text-4);white-space:nowrap}
.create-error{position:relative;color:#f4bba9;padding:10px 40px 10px 14px;background:#352322;border-radius:10px;font-size:12px;line-height:1.6;margin:0 0 8px}
.create-error p{margin:0}
.create-error button{position:absolute;right:6px;top:6px;border:0;background:transparent;color:inherit;padding:4px 8px}
.file-symbol{display:grid;place-items:center;width:50px;height:50px;background:var(--bg-4);border-radius:7px;color:var(--text-3);font-size:24px}
.library-search{display:flex;gap:8px;align-items:center;margin-bottom:15px}
.library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}
.library-grid button{text-align:left;min-width:0;padding:10px;border:1px solid var(--line-2);border-radius:var(--r-md);background:var(--bg-3);color:inherit}
.library-grid img,.library-grid .file-symbol{height:85px;width:100%;object-fit:contain;background:var(--bg-2);border-radius:6px;margin-bottom:8px}
.library-grid strong{font-size:11px;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.library-grid small{font-size:10px;color:var(--text-3)}
.comparison{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.comparison h3{font-size:13px}
.drop-overlay{position:absolute;inset:16px;z-index:20;display:grid;place-items:center;border:2px dashed var(--accent);border-radius:20px;background:#1c1526ed;pointer-events:none}
.loading{text-align:center;padding:60px;color:var(--text-3)}
@media(max-width:1100px){#details-panel{width:290px}}
@media(max-width:860px){
  .agent-main{margin-left:0;padding-top:calc(52px + env(safe-area-inset-top));height:calc(100dvh - 66px)}
  .agent-header{padding:10px 14px;gap:8px}
  .agent-header h1{font-size:15px;max-width:70vw}
  .header-actions{margin-left:0;width:100%}
  .messages{padding:20px 14px}
  .composer-dock{padding:8px 12px 12px}
  .examples,.comparison,.plan-cols,.levers__grid{grid-template-columns:1fr}
  .library-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .result__meta .status{margin-left:0}
  .panel-scrim{display:block;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:24}
  #details-panel{position:fixed;right:0;top:0;bottom:0;z-index:25;width:min(340px,100%);box-shadow:-30px 0 100px rgba(0,0,0,.6)}
  .composer-note{display:none}
  .credits{display:none}
}
</style>
