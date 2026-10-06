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
import StoryboardCarousel from '../components/create/StoryboardCarousel.vue'
import MascotPreview from '../components/create/MascotPreview.vue'
import ReferencePills from '../components/create/ReferencePills.vue'
import ComposerTray from '../components/create/ComposerTray.vue'
import TurnActivity from '../components/create/TurnActivity.vue'
import PlanningLive from '../components/create/PlanningLive.vue'
import SideDrawer from '../components/create/SideDrawer.vue'
import PlanGroup from '../components/create/PlanGroup.vue'
import PlanNote from '../components/create/PlanNote.vue'
import { conversationTimeline, acceptConversationResponse } from '../lib/createConversation.js'
import { VOICE_DESCRIPTIONS, voiceHeadline } from '../lib/voices.js'
import SchedulePostModal from '../components/SchedulePostModal.vue'
import { useWorkspaceStore } from '../stores/workspace'

const auth = useAuthStore(), route = useRoute(), router = useRouter()
const available = ref(false), loaded = ref(false), busy = ref(false), error = ref(''), conflict = ref(false)
const capabilities = ref(null), history = ref([]), search = ref(''), historyFilter = ref('all'), archivedHistory = ref(false)
const showHistory = ref(false), details = ref(false), libraryOpen = ref(false), compareOpen = ref(false)
const data = ref(null), prompt = ref(''), quote = ref(null), selectedRevision = ref(null), outputKind = ref('video')
const library = ref([]), librarySearch = ref(''), libraryPage = ref(1), libraryLastPage = ref(1)
const rename = ref(''), uploads = ref([])
const fileInput = ref(null), composer = ref(null), end = ref(null)
const player = ref(null)
const media = ref(''), compareMedia = ref(''), artifactLoading = ref(false), artifactGone = ref(''), historyLoading = ref(false), dragging = ref(false)
const clock = ref(Date.now()), providerApproved = ref(false), variantCount = ref(1)
const settingsDraft = ref({aspect_ratio:'9:16',duration_seconds:15,frame_rate:24,language:'en',audio:'original',captions:'off',caption_text:'',approved_facts:[],reference_effort:'',reference_match:''})
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
  // Plain words only: grouped by kind with the moments, never element selectors.
  const when = list => { const t = [...new Set(list.map(f => f.time).filter(v => v != null).map(v => Math.round(v * 10) / 10))].sort((a, b) => a - b).slice(0, 4); return t.length ? ` (around ${t.map(v => v + 's').join(', ')})` : '' }
  const quoted = f => String(f.message || '').match(/^"([^"]{1,60})"/)?.[1]
  const out = []
  if (c.safe_area?.length) out.push(`Some text sits where the app's buttons and captions will cover it${when(c.safe_area)}.`)
  if (c.edges?.length) out.push(`Something runs off the edge of the frame${when(c.edges)}.`)
  if (c.contrast?.length) out.push(`Some text may be hard to read against its background${when(c.contrast)}.`)
  const of = code => (c.pacing || []).filter(f => f.code === code)
  const blank = of('blank_frames'), fast = of('reading_time'), still = of('still_stretch'), small = of('small_text'), sparse = of('mostly_empty')
  if (blank.length) out.push(`The screen is empty for a moment${when(blank)}.`)
  if (still.length) out.push(`The picture stands still for a while${when(still)}.`)
  if (small.length) out.push('Some text may be too small to read on a phone.')
  if (sparse.length) out.push(`The frame looks mostly empty${when(sparse)}.`)
  if (fast.length) { const q = fast.map(quoted).filter(Boolean); out.push(q.length === 1 ? `"${q[0]}" leaves the screen before most people can read it.` : `Some text leaves the screen before most people can read it${when(fast)}.`) }
  if (c.loudness?.status === 'check_failed') out.push('The sound level could not be checked.')
  for (const p of c.audio?.problems || []) out.push(p)
  return out
})
const timeline = computed(() => conversationTimeline(data.value?.messages || [], currentRevision.value))
const imageOutput = computed(() => outputMeta.value.settings?.output_kind === 'image')
async function approveLook() {
  if (!currentPlan.value) { error.value = 'Review a current plan before building the full video.'; return }
  await reviewPlanCost(currentPlan.value, 'full_video', currentPlan.value.character_preview?.token)
}
function changeLook() { prompt.value = 'Keep the look, but change '; nextTick(() => composer.value?.focus()) }
async function editResult() {if(imageOutput.value && !currentRevision.value.output_asset_id){await saveOutput();if(!currentRevision.value.output_asset_id)return}prompt.value = imageOutput.value ? 'Keep this image, but change ' : 'Keep this video, but change '; nextTick(()=>composer.value?.focus())}
async function animateResult(){await guarded(async()=>{const rev=currentRevision.value;if(!rev.output_asset_id)throw Error('Save the image to Assets first.');const c=(await api.post('/create/conversations',{output_kind:'video',video_mode:'animate_image',duration_seconds:5,aspect_ratio:outputMeta.value.settings.aspect_ratio,audio:'silent',origin_conversation_id:id.value,origin_revision_id:rev.id})).data.data;await api.post(`/create/conversations/${c.id}/attachments`,{asset_id:rev.output_asset_id,purpose:'source',reuse_confirmed:true,expected_version:0});await router.push({name:'create',params:{conversationId:c.id}});await refresh();prompt.value='Animate this image with gentle motion. Keep the objects and composition consistent.';nextTick(()=>composer.value?.focus())})}
async function saveSettings() {await guarded(async()=>{await api.patch(base(),{expected_version:conversation.value.version,settings:{...settingsDraft.value,reference_effort:settingsDraft.value.reference_effort||null,reference_match:settingsDraft.value.reference_match||null,approved_facts:factsText.value.split('\n').map(s=>s.trim()).filter(Boolean)}});quote.value=null;await refresh()})}
function openSettings() {try{settingsDraft.value={...settingsDraft.value,...JSON.parse(conversation.value?.settings_json||'{}')};settingsDraft.value.reference_effort||='';settingsDraft.value.reference_match||='';factsText.value=(settingsDraft.value.approved_facts||[]).join('\n')}catch{}details.value=true}

const workspaceStore = useWorkspaceStore()
const credits = computed(() => workspaceStore.usage?.credits_balance)
const creditAvailability = computed(() => data.value?.credit_availability)
const quoteCreditAvailability = computed(() => quote.value?.credit_availability || creditAvailability.value)
// One number everywhere: the balance, as the dashboard shows it. Holds for unfinished work are explained, not subtracted.
const balance = computed(() => creditAvailability.value?.total ?? (credits.value ?? null))
const balanceTitle = computed(() => {
  const a = creditAvailability.value
  if (!a || !a.reserved) return 'Your credit balance'
  return `${a.reserved.toLocaleString()} held for unfinished work · ${a.available.toLocaleString()} free to start new work`
})
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
// The prompt box's pill row: files already attached for the next message, then files that upload when it is sent.
const fileKind = type => type.startsWith('image/') ? 'image' : type.startsWith('audio/') ? 'audio' : 'video'
const trayItems = computed(() => [
  ...pendingAttachments.value.map(a => ({ key: 'a' + a.asset_id, title: a.title, type: a.asset_type, url: a.preview_url, link: !!a.source?.requested_url, purpose: a.purpose, note: a.reference?.summary, asset: a })),
  ...uploads.value.map(u => ({ key: u.key, title: u.file.name, type: fileKind(u.file.type), url: u.preview_url, uploading: u.state === 'uploading', progress: u.progress, error: u.error, upload: u })),
])
const trayErrors = computed(() => trayItems.value.filter(i => i.error))
// What we read from a reference, shown once under the pills (a pill is too small to hold it).
const trayNotes = computed(() => pendingAttachments.value.flatMap(a => {
  const rig = a.rig ? (a.rig.ready ? 'Character rig ready: it can blink, look around, tilt its head and change between four mouth shapes.' : 'Not ready to animate: ' + (a.rig.problems || []).slice(0, 3).join(' ')) : ''
  const text = [a.reference?.summary, rig].filter(Boolean).join(' ')
  return text ? [{ asset_id: a.asset_id, title: a.title, text, style: !!a.reference?.summary && canWrite.value }] : []
}))
function removeTrayItem(i) { if (i.upload) removeUpload(i.upload); else detach(i.asset) }
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
const planDrafts = ref({}), planning = ref(false), characterReview = ref(null)
let planKey = null
// Drafts are created before render (pre-flush watcher), never during it.
watch(() => data.value?.plans, list => {
  for (const p of list || []) {
    if (p.status !== 'proposed' || p.stale || planDrafts.value[p.id]) continue
    const sel = p.plan.selections
    planDrafts.value[p.id] = { omitted_performance: [...(sel.omitted_performance || [])], callouts: [...sel.callouts], narration: [...(sel.narration || [])], voice: sel.voice || '', style: styleKey(sel.style), look_first: !!sel.look_first, choices: { ...sel.choices }, kept: [...sel.kept], agreement: agreementText(sel.agreement), colours: colourDraft(p.plan) }
  }
}, { immediate: true })
function draftFor(p) { return planDrafts.value[p.id] || p.plan.selections }
// The plan's colour roles as the user can change them: role -> hex.
function colourDraft(plan) { return Object.fromEntries(Object.entries(plan.colour_treatment?.roles || {}).map(([role, c]) => [role, c.hex])) }
function coloursDirty(p) { const d = planDrafts.value[p.id]; return !!d?.colours && JSON.stringify(d.colours) !== JSON.stringify(colourDraft(p.plan)) }
// The intent agreement (what stays, what changes, what must appear), edited as one line per item.
// What is being made and what sets its timing, as the plan card says it.
const VIDEO_TYPE_LABEL = { motion_graphics: 'Motion graphics', ugc: 'UGC / presenter', footage: 'Footage', ugc_motion: 'UGC with motion graphics', footage_motion: 'Footage with motion graphics' }
const TIMING_LABEL = { narration: 'the speech', source: 'your footage\'s speech', music: 'the music', visual: 'the action' }
const AGREEMENT = [['preserve', 'Keep from the reference'], ['replace', 'Swap in'], ['flexible', 'Free to change'], ['required', 'Must appear']]
const agreementEditing = ref({})
function agreementText(a) { return Object.fromEntries(AGREEMENT.map(([k]) => [k, ((a || {})[k] || []).join('\n')])) }
function agreementLists(t) { return Object.fromEntries(AGREEMENT.map(([k]) => [k, String((t || {})[k] || '').split('\n').map(x => x.trim()).filter(Boolean).slice(0, 6)])) }
function agreementShown(p) { return planDrafts.value[p.id]?.agreement ? agreementLists(planDrafts.value[p.id].agreement) : (p.plan.selections.agreement || p.plan.agreement || {}) }
function planDirty(p) {
  const d = planDrafts.value[p.id]; if (!d) return false
  const sel = p.plan.selections
  if (coloursDirty(p)) return true
  return JSON.stringify([(d.omitted_performance || []).slice().sort(), d.callouts.map(t => t.trim()).filter(Boolean), (d.narration || []).map(t => t.trim()).filter(Boolean), d.voice || '', d.style || '', !!d.look_first, d.choices, [...d.kept].sort(), d.agreement ? agreementLists(d.agreement) : null]) !== JSON.stringify([(sel.omitted_performance || []).slice().sort(), sel.callouts, sel.narration || [], sel.voice || '', styleKey(sel.style), !!sel.look_first, sel.choices, [...sel.kept].sort(), d.agreement ? agreementLists(agreementText(sel.agreement)) : null])
}
// Generated video is priced by its route (engine and length), which the server resolves for the saved selections.
const GENERATED = ['reference_sheet', 'generated_shot', 'ugc_take']
function routedMedia(p) { return p.media_routed?.length ? p.media_routed : (p.plan.media || []) }
function hasGenerated(p) { return routedMedia(p).some(m => GENERATED.includes(m.kind)) }
function mediaMeta(md) {
  if (md.kind === 'reference_sheet') return 'Cast and world sheet: ' + (md.subjects || []).map(x => x.name).join(', ')
  if (md.kind === 'generated_shot') return [md.engine_label, md.seconds ? md.seconds + ' s' : '', md.beat, md.audio === 'speech' ? 'speaks a line' : ''].filter(Boolean).join(' · ')
  if (md.kind === 'ugc_take') return ['UGC take', md.engine_label, md.seconds ? md.seconds + ' s' : '', (md.segments || []).length > 1 ? md.segments.length + ' parts joined' : ''].filter(Boolean).join(' · ')
  return ''
}
// Spend guard: a plan with costly generated media is confirmed explicitly before its cost is reviewed.
const SPEND_CONFIRM = 300
const spendOk = ref({})
function needsSpendOk(p) { return paid.value && optionCredits(p) > SPEND_CONFIRM && !spendOk.value[p.id] }
async function setVideoTier(p, tier) {
  if ((p.plan.selections.video_tier || 'standard') === tier) return
  await guarded(async () => { await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, video_tier: tier }); quote.value = null; await refresh() })
}
function optionCredits(p) {
  if (p.media_routed?.length && !planDirty(p)) return p.media_routed.reduce((n, m) => n + (m.credits || 0), 0)
  const d = planDrafts.value[p.id] || p.plan.selections
  const items = [...(p.plan.media || [])]
  for (const decision of p.plan.decisions || []) {
    const option = decision.options.find(o => o.id === d.choices[decision.id])
    if (option?.kind === 'media' && !items.some(m => m.kind === option.tool)) items.push({kind:option.tool, credits:option.credits || 0})
  }
  const nativeTake = d.voice !== 'clone' && items.some(m => m.kind === 'talking_take')
  return items.filter(m => !nativeTake || !['voiceover','cloned_voiceover'].includes(m.kind)).reduce((n, m) => n + (m.credits || 0), 0)
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
// While a plan is made, its steps are read from the server as they happen (PlanActivity).
// The reference question offers its three answers as cards; any wording in the composer also works.
const MATCH_ANSWERS = [
  { word: 'Exactly', label: 'Exactly', detail: 'Copy it moment for moment, with your brand, voice and content swapped in.' },
  { word: 'Similar', label: 'Similar', detail: 'Keep its format, look and pacing, with your own story and shots.' },
  { word: 'Inspired', label: 'Inspired', detail: 'Take just the idea and make something of your own.' },
]
const isMatchQuestion = m => /^How closely should I follow the reference video\?/.test(m.content || '')
async function answerWith(word) { prompt.value = word; await send() }
// A question asked before planning waits for its answer in the composer; it can be skipped.
const pendingQuestion = computed(() => { const list = data.value?.messages || []; const last = list[list.length - 1]; return last?.role === 'assistant' && last.kind === 'question' ? last : null })
const planLive = ref(null), planStarted = ref(0)
let planPoll
function watchPlanning(target) {
  planLive.value = null; planStarted.value = Date.now(); clearInterval(planPoll)
  planPoll = setInterval(async () => {
    try {
      const live = (await api.get(`${base(target)}/plan-activity`)).data.data
      // A finished record from an earlier plan is not this one.
      if (live && live.started_at >= planStarted.value - 5000 && id.value === target) planLive.value = live
    } catch {}
  }, 1500)
}
async function makePlan(skipQuestions = false) {
  if (!id.value || planning.value) return
  planning.value = true
  watchPlanning(id.value)
  try {
    await guarded(async () => {
      planKey ||= crypto.randomUUID()
      await api.post(`${base()}/plans`, { expected_version: conversation.value.version, idempotency_key: planKey, ...(skipQuestions === true ? { skip_questions: true } : {}) })
      planKey = null; quote.value = null; await refresh()
      await nextTick(); end.value?.scrollIntoView({ behavior: 'smooth', block: 'end' })
    })
    // A long planning request can lose its connection after the server has saved the plan: look before reporting a failure.
    if (error.value) { await refresh().catch(() => {}); if (currentPlan.value) { error.value = ''; planKey = null } }
  } finally { planning.value = false; clearInterval(planPoll) }
}
async function savePlanEdits(p) {
  const d = draftFor(p)
  await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, omitted_performance: d.omitted_performance || [], callouts: d.callouts.map(t => t.trim()).filter(Boolean), ...(d.narration ? { narration: d.narration.map(t => t.trim()).filter(Boolean) } : {}), ...(d.voice ? { voice: d.voice } : {}), ...(coloursDirty(p) ? { colours: d.colours } : {}), ...(d.style && d.style !== styleKey(p.plan.selections.style) ? { style: { route: d.style.split(':')[0], pack: d.style.split(':')[1] || null } } : {}), look_first: !!d.look_first, choices: d.choices, kept: d.kept, ...(d.agreement && agreementLists(d.agreement).required.length ? { agreement: agreementLists(d.agreement) } : {}) })
  const next = { ...planDrafts.value }; delete next[p.id]; planDrafts.value = next; quote.value = null; await refresh()
}
async function reviewPlanCost(p, buildStage = null, displayedCharacterToken = null) {
  if (planDirty(p)) { let ok = true; await guarded(async () => { try { await savePlanEdits(p) } catch (e) { ok = false; throw e } }); if (!ok) return }
  const latest = plans.value.find(row => row.id === p.id) || p
  const selection = latest.plan.selections
  const selectedKinds = [...(latest.plan.media || []).map(m => m.kind), ...(latest.plan.decisions || []).map(d => d.options.find(o => o.id === selection.choices[d.id])?.tool)]
  // An approved look (the cast and storyboard, or a character) is done: the next build is the full video.
  const stage = buildStage || (selection.look_first && !latest.character_preview?.approved ? 'storyboard' : 'full_video')
  if (paid.value && stage === 'full_video' && selectedKinds.some(k => ['character_poses', 'talking_take', 'talking_shot', 'reference_sheet'].includes(k)) && !latest.character_preview?.approved) {
    // The storyboard shows this exact master beside its approval button. A changed
    // plan/preview still needs a fresh review, never silent approval of new bytes.
    if (displayedCharacterToken && displayedCharacterToken === latest.character_preview?.token) {
      await approveCharacterPreview(latest)
      return
    }
    characterReview.value = latest
    return
  }
  await plan(null, buildStage)
}
// Storyboard panels: a check's finding under each, and a note that redraws only that panel.
const panelNoteDraft = ref({})
watch(() => currentPlan.value?.character_preview?.panel_notes, n => { panelNoteDraft.value = Object.fromEntries(Object.entries(n || {}).map(([k, v]) => ['Panel ' + k, v])) }, { immediate: true })
function isPanel(label) { return /^Panel \d+$/.test(label || '') }
function panelIssue(label) { const c = (currentPlan.value?.character_preview?.panel_checks?.panels || []).find(x => x.panel === label); return c && !c.ok ? c.issue : '' }
const panelNotesSaved = computed(() => Object.fromEntries(Object.entries(currentPlan.value?.character_preview?.panel_notes || {}).map(([k, v]) => ['Panel ' + k, v])))
const panelNotesChanged = computed(() => JSON.stringify(Object.fromEntries(Object.entries(panelNoteDraft.value).filter(([, v]) => (v || '').trim()))) !== JSON.stringify(panelNotesSaved.value))
const panelNotesCount = computed(() => Object.entries(panelNoteDraft.value).filter(([k, v]) => (v || '').trim() && v !== panelNotesSaved.value[k]).length || 1)
async function redrawPanels() {
  const p = currentPlan.value; if (!p) return
  const notes = Object.fromEntries(Object.entries(panelNoteDraft.value).filter(([, v]) => (v || '').trim()).map(([k, v]) => [k.replace('Panel ', ''), v.trim()]))
  let ok = false
  await guarded(async () => { await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, panel_notes: notes }); await refresh(); ok = true })
  if (ok) await plan(null, 'storyboard')
}
async function approveCharacter() {
  await approveCharacterPreview(characterReview.value)
}
async function approveCharacterPreview(p) {
  if (!p?.character_preview) return
  let ok = false
  await guarded(async () => {
    await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, character_approval: p.character_preview.token })
    await refresh(); characterReview.value = null; ok = true
  })
  if (ok) await plan(null, 'full_video')
}
async function prepareCharacterStoryboard() {
  characterReview.value = null
  await plan(null, 'storyboard')
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
let refreshRequest = 0, refreshApplied = 0
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
// A failed download's reason arrives as a file, not JSON; read it so the real message is shown.
// Versions saved before summaries were written for users may carry review internals; show them in plain words.
// A result's message: its first sentence stays in view, in bold; the rest (each moment, known gaps, what is not in
// this stage) folds behind a caret, with the agent's light markdown shown as bold, numbered and bulleted lines.
function summaryParts(text) {
  const t = plainSummary(text).trim()
  const m = t.match(/^(.*?[.!?])\s+(?=\*\*|\d+\.\s|-\s|[A-Z])([\s\S]*)$/)
  if (!m || m[2].length < 80) return { lead: t.replace(/\*\*/g, ''), rest: '' }
  return { lead: m[1].replace(/\*\*/g, ''), rest: m[2] }
}
function richText(text) {
  const esc = String(text || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
  return esc.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\s(<strong>[^<]{2,40}:<\/strong>)(?=\s)/g, '\n$1')
    .replace(/\s(\d+)\.\s/g, '\n$1. ')
    .replace(/(^|\s)-\s(?=<strong>|[A-Z0-9"“])/g, '\n• ')
    .split('\n').map(l => l.trim()).filter(Boolean).map(l => `<p>${l}</p>`).join('')
}
function plainSummary(text) {
  const t = String(text || '')
  if (/^Stopped at your request/.test(t)) return 'Stopped at your request. This is the last finished version.'
  if (/^Final creative review completed/.test(t)) return 'Your video is ready.'
  if (/Critic:|review stopped improving|review incomplete|Last review scores|critic/i.test(t)) return 'Here is the best version so far. You can keep improving it.'
  return t
}
// Upload requests on the plan card: an upload answers one; going without keeps its fallback.
const askUploading = ref('')
function askState(p, a) { const v = p.plan.selections?.asks?.[a.id]; return typeof v === 'number' ? 'uploaded' : v === 'skip' ? 'skipped' : 'open' }
async function answerAsk(p, a, file) {
  askUploading.value = a.id
  await guarded(async () => {
    const before = new Set((data.value?.attachments || []).map(x => x.asset_id))
    const form = new FormData(); form.append('asset_file', file); form.append('purpose', 'source'); form.append('idempotency_key', crypto.randomUUID()); form.append('expected_version', conversation.value.version)
    const result = await api.post(`${base()}/uploads`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
    data.value = result.data.data
    const added = (data.value.attachments || []).find(x => !before.has(x.asset_id))
    if (!added) throw Error('The upload did not attach. Please retry.')
    await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, asks: [{ id: a.id, asset_id: added.asset_id }] })
    if (ASK_ROLE[a.kind]) await api.post('/create/brand-library', { asset_id: added.asset_id, role: ASK_ROLE[a.kind] }).then(loadBrand).catch(() => {})
    quote.value = null; await refresh()
  })
  askUploading.value = ''
}
async function skipAsk(p, a) { await guarded(async () => { await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, asks: [{ id: a.id, skip: true }] }); quote.value = null; await refresh() }) }
async function blobMessage(e) {
  try { if (e?.response?.data instanceof Blob) { const j = JSON.parse(await e.response.data.text()); return j.message || j.error?.message || message(e) } } catch {}
  return e?.response ? message(e) : 'The video could not be loaded. Check your connection and retry.'
}
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
// Recent conversations load a page at a time; scrolling to the end of the list loads the next page.
const HISTORY_PAGE = 20, historyNext = ref(0), historySentinel = ref(null)
let historyObserver
async function loadHistory(more = false) {
  if (more && (historyNext.value === null || historyLoading.value)) return
  const ticket = more ? historyEpoch : ++historyEpoch; historyLoading.value = true
  try {
    const result = await api.get('/create/conversations',{params:{search:search.value,archived:archivedHistory.value,limit:HISTORY_PAGE,offset:more ? historyNext.value : 0}})
    if(ticket === historyEpoch) { history.value = more ? [...history.value, ...result.data.data] : result.data.data; historyNext.value = result.data.meta?.next_offset ?? null }
  }
  catch(e) { if(ticket === historyEpoch) error.value = message(e) }
  finally { if(ticket === historyEpoch) historyLoading.value = false }
}
async function refresh() {
  if (!id.value || !available.value) return
  const expected = id.value, ticket = epoch, request = ++refreshRequest
  const result = await api.get(base(expected))
  if(ticket !== epoch || id.value !== expected || !acceptConversationResponse(data.value?.conversation?.version, result.data.data.conversation.version, request, refreshApplied)) return
  refreshApplied = request
  const previousTitle = data.value?.conversation.title
  data.value = result.data.data
  if(previousTitle !== data.value.conversation.title) rename.value = data.value.conversation.title
}
// Saved styles: the look of a finished version or a studied reference, kept only when asked.
const styles = ref([]), pendingStyleId = ref(''), stylesOpen = ref(false), styleSave = ref(null), styleName = ref(''), styleEdits = ref({})
// Built-in style packs: a craft to start from. The picker holds 'pack:<slug>', a saved style id, or '' to let WyvStudio choose.
const packs = ref([])
const currentStyleId = computed(() => { try { if(!conversation.value) return pendingStyleId.value; const s = JSON.parse(conversation.value.settings_json || '{}'); return s.style_pack ? 'pack:' + s.style_pack : (s.style_id || '') } catch { return '' } })
const styleNotes = ref({}), noteText = ref(''), noteSaved = ref('')
async function loadStyles() { try { const r = (await api.get('/create/styles')).data; styles.value = r.data || []; packs.value = r.packs || []; styleNotes.value = r.notes || {} } catch { /* optional */ } }
// The style key a plan builds in, matching the API's keys for notes.
function planStyleKey(p) { const st = p?.plan?.selections?.style || p?.plan?.style; if (!st) return ''; try { const s = JSON.parse(conversation.value?.settings_json || '{}'); return st.route === 'pack' ? 'pack:' + st.pack : st.route === 'saved' ? 'saved:' + (s.style_id || '') : st.route } catch { return st.route } }
async function saveNote() {
  const note = noteText.value.trim(); if (!note || !currentRevision.value) return
  await guarded(async () => { const r = (await api.post(`${base()}/revisions/${currentRevision.value.id}/note`, { note })).data.data; noteText.value = ''; noteSaved.value = r.style_key; await loadStyles() })
}
const ceilingDraft = ref(0)
watch(() => quote.value?.media_ceiling, v => { if (typeof v === 'number') ceilingDraft.value = v }, { immediate: true })
async function setCeiling() {
  const v = Math.max(quote.value?.media_estimate || 0, Math.round(Number(ceilingDraft.value) || 0))
  await guarded(async () => { await api.patch(base(), { expected_version: conversation.value.version, settings: { media_ceiling_credits: v } }); quote.value = null; await refresh(); await plan() })
}
const reviewScores = computed(() => outputMeta.value?.review || [])
// The review's suggestions in plain words: no requirement bookkeeping, and for a storyboard nothing about audio, timing or export.
const reviewNotes = computed(() => (outputMeta.value.creative_review?.findings || [])
  .filter(f => !/^(Unmet requirement|Requirement ")/i.test(f))
  .filter(f => !outputMeta.value.look || !/\b(audio|narration|voice|music|sfx|sync|export|timing)\b/i.test(f))
  .slice(0, 3))
function mascotLine(m) {
  const s = m?.spec || {}, hair = { curls: 'curly hair', waves: 'wavy hair', bob: 'a bob', spikes: 'spiky hair', bun: 'a bun', none: 'no hair' }[s.hair?.style] || 'hair'
  const finish = { dither: 'black-and-white dithered 3D', toon: 'flat-shaded 3D', clay: 'clay 3D' }[s.finish] || '3D'
  return `A ${finish} character with ${hair}${s.body?.collar === 'turtleneck' ? ', a turtleneck' : ''}${m.why ? '. ' + m.why : ''}.`
}
function referenceTally(plan) {
  const d = plan.reference_decisions || [], n = k => d.filter(x => x.decision === k).length
  return `${d.length + (plan.reference_unaccounted || []).length} moments seen · ${n('keep')} kept · ${n('replace')} changed · ${n('drop')} left out`
}
// Offered, never automatic: when the checks did not pass, or when there are specific things to fix.
// The final checks on the delivered video: what failed (with times) and what could not be checked.
const finalFails = computed(() => (outputMeta.value.final_checks?.checks || []).filter(c => c.status === 'fail').sort((a, b) => Number(b.blocking) - Number(a.blocking)))
const finalUnverified = computed(() => (outputMeta.value.final_checks?.checks || []).filter(c => c.status === 'unverified'))
function checkTime(c) { return (c.times || []).length ? (c.times.length > 1 ? 'At ' + c.times.slice(0, 3).map(t => t.toFixed(1) + ' s').join(', ') : 'At ' + c.times[0].toFixed(1) + ' s') + ': ' : '' }
// A shot a model declined: the user picks the suggested engine for it, and a new version is quoted (C3).
async function useEngineFor(sg) {
  const p = currentPlan.value; if (!p) return
  const overrides = { ...(p.plan.selections.engine_overrides || {}), [sg.shot]: sg.engine }
  let ok = false
  await guarded(async () => { await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, engine_overrides: overrides }); await refresh(); ok = true })
  if (ok) await plan(null, 'full_video')
}
const needsAnotherRound = computed(() => ['incomplete', 'issues', 'blocked'].includes(outputMeta.value.creative_review?.status) || (reviewScores.value.length > 0 && reviewScores.value.some(s => s.score < 8)))
async function keepImproving() { const notes = reviewNotes.value; prompt.value = 'Keep this video and improve it' + (notes.length ? ': ' + notes.join('; ') : '.'); await send() }
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
  const c = (await api.post('/create/conversations',{output_kind:outputKind.value,...(pendingEffort.value !== 'standard' ? { effort: pendingEffort.value } : {}),...(pendingStyleId.value ? Object.fromEntries(Object.entries(styleSettings(pendingStyleId.value)).filter(([,v]) => v)) : {})})).data.data
  persistDraft(c.id,draft); persistDraft(null,'')
  await router.replace({name:'create',params:{conversationId:c.id}}); persistDraft(null,''); await refresh()
  return c.id
}
const linkStudying = ref(''), claimPicks = ref({}), pendingText = ref('')
// Page claims stay offered after the message is sent (a link in a message is captured and sent at once),
// until they are added to the approved facts or set aside for this conversation.
const claimsDone = ref({})
const claimsDoneKey = () => 'create-claims-done:' + (id.value || '')
function loadClaimsDone() { try { claimsDone.value = JSON.parse(localStorage.getItem(claimsDoneKey()) || '{}') } catch { claimsDone.value = {} } }
watch(id, loadClaimsDone, { immediate: true })
function markClaimsDone(a) { claimsDone.value = { ...claimsDone.value, [a.asset_id]: true }; try { localStorage.setItem(claimsDoneKey(), JSON.stringify(claimsDone.value)) } catch {} }
// A page's claims are settled once any of them is approved or the user chose Not now; both places that list them hide then.
const approvedFacts = computed(() => { try { return JSON.parse(conversation.value?.settings_json || '{}').approved_facts || [] } catch { return [] } })
const claimsSettled = a => !!claimsDone.value[a.asset_id] || (a.suggested_claims || []).some(c => approvedFacts.value.includes(c.text))
const approvedFrom = a => (a.suggested_claims || []).filter(c => approvedFacts.value.includes(c.text)).length
const openClaims = computed(() => {
  return (data.value?.attachments || []).filter(a => a.suggested_claims?.length && !claimsSettled(a))
})
const VIDEO_HOSTS = ['x.com', 'twitter.com', 'mobile.twitter.com', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'tiktok.com', 'www.tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com']
const studySteps = computed(() => {
  let host = ''; try { host = new URL(linkStudying.value).hostname.toLowerCase() } catch {}
  return VIDEO_HOSTS.includes(host)
    ? ['Fetching the video', 'Finding the cuts', 'Listening for speech', 'Reading frames', 'Writing style notes']
    : ['Reading the page', 'Capturing the page', 'Looking at the design', 'Checking claims against the page', 'Writing brand notes']
})
// Keep the live thinking row in view as it appears.
watch([() => linkStudying.value, () => planning.value, () => pendingText.value], async () => { await nextTick(); end.value?.scrollIntoView({ behavior: 'smooth', block: 'end' }) })
const linkKeys = {}
async function approveClaims(a) {
  const picked = (a.suggested_claims || []).filter((c, i) => claimPicks.value[a.asset_id + ':' + i]).map(c => c.text)
  if (!picked.length) return
  await guarded(async () => {
    let current = []; try { current = JSON.parse(conversation.value.settings_json || '{}').approved_facts || [] } catch {}
    const facts = [...new Set([...current, ...picked])].slice(0, 20)
    await api.patch(base(), { expected_version: conversation.value.version, settings: { approved_facts: facts } })
    // An open Details panel holds its own copy of the facts; keep it in step so its Apply doesn't drop these.
    const typed = factsText.value.split('\n').map(s => s.trim()).filter(Boolean)
    factsText.value = [...new Set([...typed, ...facts])].slice(0, 20).join('\n'); settingsDraft.value.approved_facts = facts
    claimPicks.value = {}; quote.value = null; markClaimsDone(a); await refresh()
  })
}
async function send() {
  if(!prompt.value.trim() || hasUpload.value) return
  const text = prompt.value.trim()
  await guarded(async () => {
    for (const u of [...uploads.value]) {
      if (u.error && u.state === 'ready') throw Error(u.error)
      if (!await upload(u, true)) throw Error(u.error || 'Upload did not finish. Your message has not been sent.')
    }
    const target = await ensureConversation()
    // Links in the brief are studied first: video posts as style references, other pages as brand pages.
    const known = new Set((data.value?.attachments || []).map(a => a.source?.requested_url).filter(Boolean))
    const links = [...new Set((text.match(/https:\/\/[^\s<>"')]+/g) || []).map(u => u.replace(/[.,;:!?]+$/, '')))].filter(u => !known.has(u)).slice(0, 3)
    // Show the message right away while its links are studied.
    if (links.length) { pendingText.value = text; prompt.value = '' }
    // A link that cannot be used never loses the brief: it is sent, and the link's problem is shown after.
    const linkProblems = []
    for (const url of links) {
      linkStudying.value = url
      // One key per link per conversation: a retry replays safely, and the same link in a new conversation is a new request.
      const linkId = `${target}|${url}`
      linkKeys[linkId] ||= crypto.randomUUID()
      try {
        const r = await api.post(`${base(target)}/references`, { url, idempotency_key: linkKeys[linkId], expected_version: conversation.value.version }, { timeout: 150000 })
        delete linkKeys[linkId]
        if (id.value === target) data.value = r.data.data
      } catch (e) {
        if (e.response?.status === 409) throw e
        delete linkKeys[linkId]
        linkProblems.push(`${url}: ${message(e)}`)
        await refresh().catch(() => {})
      }
    }
    linkStudying.value = ''
    sendingKey ||= crypto.randomUUID()
    await api.post(`${base(target)}/messages`,{content:text,expected_version:conversation.value.version,idempotency_key:sendingKey})
    persistDraft(target,''); sendingKey = null; prompt.value = ''; pendingText.value = ''; quote.value = null; selectedRevision.value = null
    await refresh(); await loadHistory(); await nextTick(); end.value?.scrollIntoView({behavior:'smooth',block:'end'})
    if (linkProblems.length) error.value = 'Your message was sent, but a link could not be used. ' + linkProblems.join(' ')
  })
  linkStudying.value = ''
  // A failed study leaves the message unsent: give the text back to the box.
  if (pendingText.value) { if (!prompt.value) prompt.value = pendingText.value; pendingText.value = '' }
  // The plan turn follows every brief. It is free; failure leaves the brief saved.
  if (!error.value && canWrite.value && !active.value) await makePlan()
}
async function plan(retryRunId = null, buildStage = null) { await guarded(async () => {
  providerApproved.value=false
  quote.value = (await api.post(`${base()}/quotes`,{expected_version:conversation.value.version,variant_count:variantCount.value,...(buildStage ? {build_stage:buildStage} : {}),...(typeof retryRunId === 'string' ? {retry_run_id:retryRunId} : {})})).data.data
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
  librarySearch.value = ''; libraryPage.value = 1
  libraryOpen.value = true; await loadLibrary()
}
async function loadLibrary() {
  const ticket = ++libraryEpoch
  try { const result = await api.get('/assets',{params:{per_page:24,page:libraryPage.value,q:librarySearch.value || undefined}}); if(ticket !== libraryEpoch) return; library.value = (result.data.data.assets ?? []).filter(a => ['image','video','audio'].includes(a.asset_type) && a.status !== 'archived'); libraryLastPage.value = result.data.meta?.pagination?.last_page || 1 }
  catch(e) { if(ticket === libraryEpoch) error.value = message(e) }
}
async function attach(asset) {
  await guarded(async () => {
    const target = await ensureConversation()
    await api.post(`${base(target)}/attachments`,{asset_id:asset.id,expected_version:conversation.value.version})
    quote.value = null; libraryOpen.value = false; await refresh(); await loadHistory()
  })
}
async function detach(asset) { await guarded(async () => { await api.delete(`${base()}/attachments/${asset.asset_id}`,{data:{expected_version:conversation.value.version}}); quote.value = null; await refresh() }) }
function chooseFiles(files) {
  const allowed = capabilities.value?.uploads?.mime_types || ['image/png','image/jpeg','image/webp','image/svg+xml','video/mp4','audio/mpeg','audio/wav','audio/x-wav']
  for(const file of Array.from(files || [])) {
    const validation = file.size > (capabilities.value?.uploads?.max_file_bytes || 104857600) ? 'This file is larger than 100 MB.' : !allowed.includes(file.type) ? 'Use PNG, JPEG, WebP, SVG, MP4, MP3 or WAV.' : null
    if(uploads.value.length + (data.value?.attachments?.length || 0) >= 20) { error.value = 'Use at most 20 attachments.'; break }
    uploads.value.push({key:crypto.randomUUID(),file,progress:0,state:'ready',error:validation,preview_url:validation ? '' : URL.createObjectURL(file)})
  }
  if(fileInput.value) fileInput.value.value = ''
  dragging.value = false
}
function removeUpload(u) { if(u.preview_url) URL.revokeObjectURL(u.preview_url); uploads.value = uploads.value.filter(x => x !== u) }
async function upload(u, fromSend = false) {
  if(uploadRunning || (busy.value && !fromSend) || u.error && u.state === 'ready') return
  uploadRunning = true; u.state = 'uploading'; u.error = ''; u.progress = 0
  let target
  try {
    target = await ensureConversation()
    const form = new FormData(); form.append('asset_file',u.file); form.append('idempotency_key',u.key); form.append('expected_version',conversation.value.version)
    const result = await api.post(`${base(target)}/uploads`,form,{headers:{'Content-Type':'multipart/form-data'},onUploadProgress:event => {u.progress = Math.min(99,Math.round(100*(event.loaded/(event.total || u.file.size))))}})
    if(id.value === target) { data.value = result.data.data; rename.value = data.value.conversation.title; quote.value = null }
    removeUpload(u); await loadHistory(); return true
  } catch(e) { u.state = 'failed'; u.error = message(e); if(e.response?.status === 409) { conflict.value = true; await refresh().catch(()=>{}) } return false }
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
  media.value = ''; artifactGone.value = ''; artifactLoading.value = Boolean(nextKey)
  if(!nextKey) return
  // The player streams the version from its signed link; the download below is the fallback.
  if(revision.preview_url) { media.value = revision.preview_url; artifactLoading.value = false; return }
  try { const result = await api.get(`${base(target)}/revisions/${revision.id}/artifact`,{responseType:'blob'}); if(ticket === mediaEpoch) media.value = URL.createObjectURL(result.data) }
  catch(e) { const text = await blobMessage(e); if(ticket === mediaEpoch) {artifactGone.value = e?.response?.status === 410 || e?.response?.status === 404 ? text : ''; if(!artifactGone.value) error.value = text; mediaKey = ''} }
  finally { if(ticket === mediaEpoch) artifactLoading.value = false }
}
async function compare() {
  player.value?.pause(); compareOpen.value = true; const ticket = ++compareEpoch
  const head = (data.value?.revisions || []).find(r => r.id === conversation.value.head_revision_id)
  if(head?.preview_url) { compareMedia.value = head.preview_url; return }
  try { const result = await api.get(`${base()}/revisions/${conversation.value.head_revision_id}/artifact`,{responseType:'blob'}); if(ticket === compareEpoch && compareOpen.value) compareMedia.value = URL.createObjectURL(result.data) }
  catch(e) { const text = await blobMessage(e); if(ticket === compareEpoch) error.value = text }
}
function example(item) { if(!id.value) outputKind.value = item.kind; prompt.value = item.prompt; nextTick(()=>composer.value?.focus()) }
watch(prompt, value => persistDraft(id.value,value))
watch([search,archivedHistory], () => {clearTimeout(searchTimer); searchTimer = setTimeout(loadHistory,250)})
watch(showHistory, open => {if(open) loadHistory()})
// The end of the list coming into view loads the next page.
watch(historySentinel, el => {
  historyObserver?.disconnect()
  if (el && window.IntersectionObserver) { historyObserver = new IntersectionObserver(e => { if (e[0].isIntersecting) loadHistory(true) }, { rootMargin: '0px 0px 300px 0px' }); historyObserver.observe(el) }
})
watch(compareOpen, open => {if(!open) {compareEpoch++; if(compareMedia.value) URL.revokeObjectURL(compareMedia.value); compareMedia.value = ''}})
watch(() => currentRevision.value?.id, loadArtifact)
watch(id, async (value, old) => {
  characterReview.value = null
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
// The plan card (create-ui chat mockup): what will be made as pills, Preview to change it, and one Approve that
// shows the price and starts the build. Older plans stay viewable but cannot be approved.
const planDrawerId = ref(null), approvingPlan = ref(false), planPrice = ref({})
const drawerPlan = computed(() => plans.value.find(p => p.id === planDrawerId.value) || null)
function openPlan(p) { planDrawerId.value = p.id }
const isLivePlan = p => p.status === 'proposed' && !p.stale && !p.plan.free_edit
const planEditable = p => isLivePlan(p) && !p.approved && canWrite.value && !active.value
function planPills(p) {
  const st = settingsNow.value, out = [], scenes = p.plan.scenes || []
  if (scenes.length) out.push(`${scenes.length} ${scenes.length === 1 ? 'shot' : 'shots'}`)
  const len = st.duration_seconds || Math.round(Math.max(0, ...scenes.map(x => Number(x.end) || 0)))
  if (len) out.push(`${len} s`)
  if (st.aspect_ratio) out.push(st.aspect_ratio)
  const kinds = routedMedia(p).map(x => x.kind)
  if (kinds.includes('reference_sheet') || kinds.includes('ugc_take')) out.push('AI creator')
  else if (kinds.includes('generated_shot')) out.push('Generated shots')
  if ((draftFor(p).narration || []).length) out.push('Voiceover')
  const style = styleOptions(p).find(o => o.key === (planDrafts.value[p.id]?.style ?? styleKey(p.plan.selections.style)))
  if (style) out.push(style.label)
  return out
}
function planEdited(p) {
  const sel = p.plan.selections
  return planDirty(p) || JSON.stringify(sel.callouts || []) !== JSON.stringify(p.plan.callouts || []) || JSON.stringify(sel.narration || []) !== JSON.stringify(p.plan.narration || []) || p.plan.colour_treatment?.source === 'user'
}
function planStatus(p) {
  if (p.status === 'superseded') return 'Replaced by a newer plan'
  if (p.status === 'proposed' && p.stale) return 'Out of date'
  if (p.built) return 'Approved · video made from it'
  if (p.approved || (isLivePlan(p) && (active.value || characterStep(p)))) return 'Approved'
  return ''
}
// Motion graphics may show its look first (silent stills of each beat) or go straight to the video; a plan with a
// generated person always shows its look first, so it has no choice.
const LOOK_CHOICES = [
  { id: 'video', label: 'Straight to video', detail: 'Fastest. Change text and colours for free afterwards.' },
  { id: 'look', label: 'Look first', detail: 'Silent stills of each beat to approve before motion and sound. Adds a step.' },
]
function lookRequired(p) { return p.plan.look_required ?? routedMedia(p).some(x => ['reference_sheet', 'character_poses', 'character_variants', 'talking_shot', 'talking_take'].includes(x.kind)) }
function lookChoice(p) { return (planDrafts.value[p.id]?.look_first ?? p.plan.selections.look_first) ? 'look' : 'video' }
function voiceName(key) { const v = voiceOptions(key).find(x => x.key === key); return v ? v.label.split(' · ')[0] : 'Default voice' }
function lookSummary(p) { return styleOptions(p).find(o => o.key === (planDrafts.value[p.id]?.style ?? styleKey(p.plan.selections.style)))?.label || '' }
function optionsSummary(p) {
  const d = draftFor(p), out = planDecisions(p).map(dec => dec.options.find(o => o.id === d.choices[dec.id])?.label).filter(Boolean)
  out.push(EFFORTS.find(e => e.id === currentEffort.value)?.label.toLowerCase() + ' effort')
  if (!lookRequired(p) && !p.plan.mascot3d) out.push(lookChoice(p) === 'look' ? 'look first' : 'straight to video')
  if (variantCount.value > 1) out.push(variantCount.value + ' variants')
  return out.join(' · ')
}
function toggleAction(p, actionId, keep) { const d = planDrafts.value[p.id]; const list = (d.omitted_performance || []).filter(x => x !== actionId); d.omitted_performance = keep ? list : [...list, actionId] }
// A voice question in the planner's options repeats the Voice picker, so it is not shown twice.
const planDecisions = p => (p.plan.decisions || []).filter(d => !/voice/i.test(`${d.id} ${d.question}`))
function planStage(p) { return p.plan.selections.look_first && !p.character_preview?.approved ? 'storyboard' : 'full_video' }
function needsCharacterCheck(p) {
  const sel = p.plan.selections
  const kinds = [...(p.plan.media || []).map(x => x.kind), ...(p.plan.decisions || []).map(d => d.options.find(o => o.id === sel.choices[d.id])?.tool)]
  return paid.value && planStage(p) === 'full_video' && kinds.some(k => ['character_poses', 'talking_take', 'talking_shot', 'reference_sheet'].includes(k)) && !p.character_preview?.approved
}
// The price is fetched with the plan, so Approve can say it and start the build in one click.
async function pricePlan(p) {
  if (!paid.value || !isLivePlan(p) || !canWrite.value || active.value || approvingPlan.value || needsCharacterCheck(p)) return
  const version = conversation.value.version, target = id.value, have = planPrice.value[p.id]
  if (have?.version === version && (have.quote || have.loading) && have.variants === variantCount.value) return
  planPrice.value = { ...planPrice.value, [p.id]: { version, variants: variantCount.value, loading: true } }
  try {
    const q = (await api.post(`${base(target)}/quotes`, { expected_version: version, variant_count: variantCount.value, build_stage: planStage(p) })).data.data
    if (id.value === target) planPrice.value = { ...planPrice.value, [p.id]: { version, variants: variantCount.value, quote: q } }
  } catch (e) { if (id.value === target) planPrice.value = { ...planPrice.value, [p.id]: { version, variants: variantCount.value, error: message(e) } } }
}
// What Approve starts. A plan with a generated person or generated shots makes still frames first (the server
// decides this from the media), so the expensive motion is only bought for a look the user has seen.
const storyboardFirst = p => !!(planDrafts.value[p.id]?.look_first ?? p.plan.selections.look_first) && !p.character_preview?.approved
const startsWithCharacter = p => routedMedia(p).some(x => x.kind === 'reference_sheet')
function nextStep(p) {
  if (startsWithCharacter(p)) return 'Approving draws the character first. You check them, then the storyboard drawn from them, then the video: each step has its own preview and its own price, and nothing moves on until you approve it.'
  return storyboardFirst(p)
    ? 'Approving makes still frames of each shot' + (routedMedia(p).some(x => x.kind === 'reference_sheet') ? ' and the character' : '') + ' first. You check them, then approve the full video with motion, voice and music, priced at that point.'
    : 'Approving makes the full video straight away.'
}
function approveLabel(p) {
  if (!paid.value) return 'Approve local sample'
  if (needsCharacterCheck(p)) return 'Approve'
  const pr = planPrice.value[p.id]
  if (planDirty(p)) return pr?.quote ? `Save and approve · ${priceText(pr.quote)}` : 'Save and approve'
  if (pr?.quote) return `${startsWithCharacter(p) ? 'Approve · draw the character' : storyboardFirst(p) ? 'Approve storyboard' : 'Approve'} · ${priceText(pr.quote)}`
  return pr?.error ? 'Approve' : 'Pricing…'
}
const approveBlocked = p => paid.value && !needsCharacterCheck(p) && !!planPrice.value[p.id]?.loading
async function approvePlan(p) {
  if (!paid.value || needsCharacterCheck(p)) { planDrawerId.value = null; return reviewPlanCost(p) }
  if (approvingPlan.value) return
  approvingPlan.value = true
  try {
    await guarded(async () => {
      const shown = planPrice.value[p.id]?.quote
      if (planDirty(p)) await savePlanEdits(p)
      let q = planPrice.value[p.id]?.quote
      const fresh = q && planPrice.value[p.id].version === conversation.value.version && planPrice.value[p.id].variants === variantCount.value && Date.parse(q.expires_at) > Date.now() + 15000
      if (!fresh) {
        q = (await api.post(`${base()}/quotes`, { expected_version: conversation.value.version, variant_count: variantCount.value, build_stage: planStage(p) })).data.data
        // A price the user has not seen, or a higher one, is shown on the button first, never charged silently.
        const raised = !shown || q.credits_max > shown.credits_max
        planPrice.value = { ...planPrice.value, [p.id]: { version: conversation.value.version, variants: variantCount.value, quote: q, raised: raised && !!shown } }
        if (raised) return
      }
      await api.post(`${base()}/runs`, { quote_id: q.id, approved: true, provider_approved: true, idempotency_key: crypto.randomUUID() })
      planDrawerId.value = null; quote.value = null; await refresh(); await loadHistory()
    })
  } finally { approvingPlan.value = false }
}
watch(() => [currentPlan.value?.id, conversation.value?.version, !!active.value, variantCount.value], () => { if (currentPlan.value) pricePlan(currentPlan.value) }, { immediate: true })
// Durable steps after the plan, for a plan with a generated person: the character, then the storyboard drawn from
// it, each with its own card, preview and approval. Approving one starts the next, at the price on its button.
const stepDrawer = ref(null), stepBusy = ref(false), stepPrice = ref({}), looksDraft = ref({}), framesDraft = ref({}), frameAt = ref(0), subjectAt = ref(0)
// A redraw runs behind the drawer: the changed people or places show "Redrawing" in place and swap when ready.
const redrawing = ref(null)
const isRedrawing = (p, name) => !!active.value && redrawing.value?.planId === p.id && redrawing.value.names.includes(name)
watch(() => !!active.value, on => { if (!on && redrawing.value) { redrawing.value = null; if (stepDrawer.value?.step === 'character') { const p = stepPlan.value; if (p) looksDraft.value = Object.fromEntries((characterStep(p)?.subjects || []).map(x => [x.name, characterStep(p).looks?.[x.name] ?? x.looks])) } } })
const stepPlan = computed(() => stepDrawer.value ? plans.value.find(p => p.id === stepDrawer.value.planId) || null : null)
const characterStep = p => p.character_preview?.kind === 'reference_sheet' ? p.character_preview : null
const storyboardStep = p => characterStep(p)?.approved ? p.storyboard_preview || null : null
const stepEditable = p => isLivePlan(p) && !p.built && canWrite.value && !active.value
function openStep(p, step) {
  stepDrawer.value = { planId: p.id, step }
  if (step === 'character') subjectAt.value = 0
  if (step === 'character') looksDraft.value = Object.fromEntries((characterStep(p)?.subjects || []).map(x => [x.name, characterStep(p).looks?.[x.name] ?? x.looks]))
  if (step === 'storyboard') { framesDraft.value = { ...(storyboardStep(p)?.panel_notes || {}) }; frameAt.value = 0 }
}
function subjectChanged(p, name) { const c = characterStep(p), x = c?.subjects.find(y => y.name === name); return !!x && (looksDraft.value[name] ?? '').trim() !== (c.looks?.[name] ?? x.looks).trim() }
function looksChanged(p) { return (characterStep(p)?.subjects || []).filter(x => subjectChanged(p, x.name)).length }
function framesChanged(p) { const saved = storyboardStep(p)?.panel_notes || {}; return Object.keys({ ...saved, ...framesDraft.value }).filter(k => (framesDraft.value[k] || '').trim() !== (saved[k] || '').trim()).length }
const sheetCredits = p => routedMedia(p).find(x => x.kind === 'reference_sheet')?.credits || 35 * (characterStep(p)?.subjects.length || 1)
function frameShot(p, k) { const shot = routedMedia(p).filter(x => x.kind === 'generated_shot')[k]; return shot ? shot.description : '' }
function frameIssue(p, k) { const c = (storyboardStep(p)?.panel_checks?.panels || []).find(x => x.panel === 'Panel ' + (k + 1)); return c && !c.ok ? c.issue : '' }
// The next step's price, before approving: a preview quote that assumes this step's approval.
const stepKey = (p, step) => p.id + ':' + step
function stepAssume(p, step) {
  const a = { character_approval: characterStep(p)?.token }
  if (step === 'storyboard') a.storyboard_approval = storyboardStep(p)?.token
  return a
}
async function priceStep(p, step) {
  if (!paid.value || !stepEditable(p) || stepBusy.value) return
  const version = conversation.value.version, target = id.value, key = stepKey(p, step), have = stepPrice.value[key]
  if (have?.version === version && (have.quote || have.loading)) return
  stepPrice.value = { ...stepPrice.value, [key]: { version, loading: true } }
  try {
    const q = (await api.post(`${base(target)}/quotes`, { expected_version: version, variant_count: step === 'storyboard' ? variantCount.value : 1, assume: stepAssume(p, step) })).data.data
    if (id.value === target) stepPrice.value = { ...stepPrice.value, [key]: { version, quote: q } }
  } catch (e) { if (id.value === target) stepPrice.value = { ...stepPrice.value, [key]: { version, error: message(e) } } }
}
const stepPriceLoading = (p, step) => !!stepPrice.value[stepKey(p, step)]?.loading
function stepPriceText(p, step) { const q = stepPrice.value[stepKey(p, step)]?.quote; return !q ? '' : q.credits_max ? `Next: ${priceText(q)} · ${ceilingText(q)}` : 'Next step is already paid for' }
function stepApproveLabel(p, step) {
  const q = stepPrice.value[stepKey(p, step)]?.quote, verb = step === 'character' ? 'Approve' : 'Approve · make video'
  if (!paid.value) return verb
  if (q && !q.credits_max) return verb
  return q ? `${verb} · ${priceText(q)}` : stepPrice.value[stepKey(p, step)]?.error ? verb : 'Pricing…'
}
async function approveStepNow(p, step) {
  const token = step === 'character' ? characterStep(p)?.token : storyboardStep(p)?.token
  if (!token || stepBusy.value) return
  stepBusy.value = true
  try {
    await guarded(async () => {
      const shown = stepPrice.value[stepKey(p, step)]?.quote
      if (!shown) { await priceStep(p, step); return }
      const r = (await api.post(`${base()}/plans/${p.id}/approve-step`, { step, token, expected_version: conversation.value.version, max_credits: shown.credits_max, idempotency_key: crypto.randomUUID(), variant_count: step === 'storyboard' ? variantCount.value : 1 })).data.data
      // A higher price than the one shown is shown, never charged: the approval is kept and the button updates.
      if (r.needs_confirm) { stepPrice.value = { ...stepPrice.value, [stepKey(p, step)]: { version: conversation.value.version + 1, quote: { credits_max: r.credits_max } } }; await refresh(); return }
      stepDrawer.value = null; await refresh(); await loadHistory()
    })
  } finally { stepBusy.value = false }
}
// A redraw is the same step again with the user's change, started at once at its price.
async function startQuoted(expectMax) {
  const q = (await api.post(`${base()}/quotes`, { expected_version: conversation.value.version })).data.data
  if (q.credits_max > expectMax) throw Error(`This redraw now costs up to ${q.credits_max} credits. Try again to go ahead.`)
  await api.post(`${base()}/runs`, { quote_id: q.id, approved: true, provider_approved: true, idempotency_key: crypto.randomUUID() })
}
async function redrawCharacter(p) {
  if (stepBusy.value) return
  stepBusy.value = true
  try {
    await guarded(async () => {
      const c = characterStep(p), looks = Object.fromEntries(c.subjects.map(x => [x.name, (looksDraft.value[x.name] || '').trim()]).filter(([n, v]) => v && v !== x0(c, n)))
      await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, character_looks: looks })
      const names = c.subjects.filter(x => subjectChanged(p, x.name)).map(x => x.name)
      await refresh(); await startQuoted(Math.max(sheetCredits(p), 35)); redrawing.value = { planId: p.id, names }; await refresh(); await loadHistory()
    })
  } finally { stepBusy.value = false }
}
const x0 = (c, name) => (c.subjects.find(x => x.name === name)?.looks || '').trim()
async function redrawFrames(p) {
  if (stepBusy.value) return
  stepBusy.value = true
  try {
    await guarded(async () => {
      const notes = Object.fromEntries(Object.entries(framesDraft.value).map(([k, v]) => [k, (v || '').trim()]).filter(([, v]) => v))
      const n = framesChanged(p)
      await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, panel_notes: notes })
      await refresh(); await startQuoted(Math.max(n, 1) * 35); stepDrawer.value = null; await refresh(); await loadHistory()
    })
  } finally { stepBusy.value = false }
}
watch(() => [currentPlan.value?.id, conversation.value?.version, !!active.value, variantCount.value], () => {
  const p = currentPlan.value; if (!p) return
  const c = characterStep(p), b = storyboardStep(p)
  if (c && !c.approved) priceStep(p, 'character')
  else if (b && !b.approved) priceStep(p, 'storyboard')
}, { immediate: true })
// The finished version (create-ui chat mockup): one bold line and a card in the chat; the agent's full account, the
// checks and the less common actions live in the "This version" drawer.
const versionOpen = ref(false)
const playerShape = computed(() => ({ '16:9': 'landscape', '1:1': 'square', '4:5': 'feed' })[settingsNow.value.aspect_ratio] || 'portrait')
const versionPills = computed(() => {
  const st = settingsNow.value, out = []
  if (st.aspect_ratio && !imageOutput.value) out.push(st.aspect_ratio)
  if (st.duration_seconds && !imageOutput.value && !outputMeta.value.look) out.push(st.duration_seconds + ' s')
  const run = (data.value?.runs || []).find(r => r.id === currentRevision.value?.run_id)
  if (run?.spent_credits) out.push(Number(run.spent_credits).toLocaleString() + ' cr')
  return out
})
function summarySections(text) {
  const { lead, rest } = summaryParts(text)
  if (!rest) return { lead, groups: [] }
  // "Things to know:", "**Known gap:**" and the like start a group when a list follows them.
  const parts = (' ' + rest).split(/\s(?:\*\*)?([A-Z][A-Za-z' ]{2,40}):(?:\*\*)?\s+(?=-\s|\d+\.\s|\*\*)/)
  const groups = []
  if (parts[0].trim()) groups.push({ title: "What's in it", html: richText(parts[0]) })
  for (let i = 1; i < parts.length; i += 2) groups.push({ title: parts[i], html: richText(parts[i + 1] || '') })
  return { lead, groups }
}
// Share the finished video the way the editor and UGC results do: copy a public link, schedule a post, or send it
// for approval. Each saves the version to Videos first when it is not saved yet (one click, no extra step).
const sharing = ref(''), shareNote = ref(''), approvalOpen = ref(false), approvalSending = ref(false), approvalResult = ref(''), approvalError = ref('')
const approvalForm = ref({ email: '', name: '', message: '' })
let shareNoteTimer
function noteShare(text) { shareNote.value = text; clearTimeout(shareNoteTimer); shareNoteTimer = setTimeout(() => { shareNote.value = '' }, 6000) }
async function ensureSaved() {
  const saved = (await api.post(`${base()}/revisions/${currentRevision.value.id}/save-output`, { expected_version: conversation.value.version })).data.data
  await refresh()
  return saved
}
async function copyShare() {
  // A version without your latest changes is confirmed first, as before.
  if (currentRevision.value?.has_newer_changes) return requestDelivery('share')
  sharing.value = 'share'
  try {
    await guarded(async () => {
      await ensureSaved()
      const url = (await api.post(`${base()}/revisions/${currentRevision.value.id}/delivery`, { action: 'share', expected_version: conversation.value.version, confirmed: true })).data.data.url
      await refresh()
      try { await navigator.clipboard.writeText(url); noteShare('Link copied. Anyone with it can watch this version.') } catch { noteShare(url) }
    })
  } finally { sharing.value = '' }
}
async function scheduleNow() {
  if (currentRevision.value?.has_newer_changes) return requestDelivery('schedule')
  sharing.value = 'schedule'
  try {
    await guarded(async () => {
      await ensureSaved()
      scheduleTarget.value = { action: 'schedule', revision: currentRevision.value, version: conversation.value.version, allowOlder: false }
    })
  } finally { sharing.value = '' }
}
async function sendApproval() {
  if (approvalSending.value || !approvalForm.value.email.trim()) return
  approvalSending.value = true; approvalError.value = ''
  try {
    const saved = await ensureSaved()
    const res = await api.post('/approvals', { project_id: saved.project_id, export_job_id: saved.export_job_id ?? null, reviewer_email: approvalForm.value.email.trim(),
      reviewer_name: approvalForm.value.name.trim() || null, comment: approvalForm.value.message.trim() || null, expires_in_days: 7 })
    approvalResult.value = res.data?.data?.public_url || 'sent'
  } catch (e) { approvalError.value = message(e) }
  finally { approvalSending.value = false }
}
// A failed step is retried in one click (create-ui chat mockup): the approval already given is used again, nothing
// is quoted or consented to twice, and the retry resumes from what the failed run finished.
const STEP_NAMES = { character: 'The character', storyboard: 'The storyboard', full_video: 'The video' }
const retrying = ref(false), dismissedFailures = ref([])
const lastFailure = computed(() => {
  const runs = data.value?.runs || [], last = runs[runs.length - 1]
  return last && last.status === 'failed' && !dismissedFailures.value.includes(last.id) ? last : null
})
const olderAttempts = computed(() => (data.value?.runs || []).filter(r => ['failed', 'cancelled', 'needs_input'].includes(r.status) && r.id !== lastFailure.value?.id))
async function retryRun(run) {
  if (retrying.value) return
  retrying.value = true
  try { await guarded(async () => { await api.post(`${base()}/runs/${run.id}/retry`, { idempotency_key: crypto.randomUUID() }); await refresh(); await loadHistory() }) }
  finally { retrying.value = false }
}
// Which files the plan puts in the video and which it follows; switching one re-plans (planning is free).
const planFiles = computed(() => (data.value?.attachments || []).filter(a => ['source', 'reference'].includes(a.purpose)))
async function switchRole(f) {
  await guarded(async () => {
    await api.post(`${base()}/attachments`, { asset_id: f.asset_id, purpose: f.purpose === 'source' ? 'reference' : 'source', expected_version: conversation.value.version })
    await refresh()
  })
  // Re-planned with questions allowed: a file now followed may need "how closely?".
  if (!error.value) await makePlan()
}
// A file the brief does not make clear is asked about with two answers.
const ROLE_ANSWERS = [
  { word: 'Use it in my video', label: 'Use it in my video', detail: 'It appears in the video, as your own footage or image.' },
  { word: 'Make mine like it', label: 'Make mine like it', detail: 'Follow its format, look and pacing; nothing from it is shown.' },
]
const isRoleQuestion = m => /^Should I put ".*" in your video, or make your video like it\?$/.test(m.content || '')
// The brand library: the workspace's logo, mascot, products and illustrations, kept once and offered every time.
const BRAND_ROLES = [{ id: 'logo', label: 'Logo' }, { id: 'mascot', label: 'Mascot' }, { id: 'product', label: 'Product' }, { id: 'illustration', label: 'Illustration' }]
const ASK_ROLE = { logo: 'logo', mascot: 'mascot', photo: 'product', illustration: 'illustration' }
const OURS_KINDS = ['mascot', 'illustration']
const brandItems = ref([])
async function loadBrand() { try { brandItems.value = (await api.get('/create/brand-library')).data.data } catch {} }
const brandRole = assetId => brandItems.value.find(b => b.asset_id === assetId)?.role || ''
async function setBrandRole(assetId, role) {
  await guarded(async () => {
    if (role) await api.post('/create/brand-library', { asset_id: assetId, role })
    else await api.delete(`/create/brand-library/${assetId}`)
    await loadBrand()
  })
}
// A requested brand visual offers the library's items of its kind (a logo request shows logos).
const brandFor = a => brandItems.value.filter(b => !ASK_ROLE[a.kind] || b.role === ASK_ROLE[a.kind])
async function pickBrandForAsk(p, a, b) {
  await guarded(async () => { await api.patch(`${base()}/plans/${p.id}`, { expected_version: conversation.value.version, asks: [{ id: a.id, asset_id: b.asset_id }] }); quote.value = null; await refresh() })
}
onMounted(loadBrand)
// Effort: how much care, and cost, goes into the video. Visible in the prompt box and on the plan, and switchable
// until the plan is approved; the price on Approve follows it.
const EFFORTS = [
  { id: 'quick', label: 'Quick', cost: 'about 0.4×', detail: 'Fastest and cheapest: a lighter plan and build, no review pass.' },
  { id: 'standard', label: 'Standard', cost: 'default', detail: 'Balanced care and cost, with two review passes.' },
  { id: 'thorough', label: 'Thorough', cost: 'about 2×', detail: 'Most care: deeper thinking, more calls and more review.' },
]
const pendingEffort = ref('standard')
const currentEffort = computed(() => settingsNow.value.effort || (conversation.value ? 'standard' : pendingEffort.value))
async function setEffort(v) {
  if (!conversation.value) { pendingEffort.value = v; return }
  await guarded(async () => { await api.patch(base(), { expected_version: conversation.value.version, settings: { effort: v } }); quote.value = null; await refresh() })
}
// A price: the likely cost; the ceiling ("never more than") only where it differs.
const priceText = q => q.estimate && q.estimate < q.credits_max ? `about ${q.estimate.toLocaleString()} cr` : `${(q.estimate || q.credits_max).toLocaleString()} cr`
const ceilingText = q => q.estimate && q.estimate < q.credits_max ? `never more than ${q.credits_max.toLocaleString()}` : 'exact'
// The bill, stage by stage: each plan's charge, then each step and the video at what they spent.
const spendRows = computed(() => {
  const rows = []
  for (const p of plans.value) { const c = p.plan.planning_charge; if (c) rows.push({ label: 'Planning', text: c.waived ? 'waived' : `${c.charged} cr (half price)`, cr: c.charged || 0, at: p.created_at }) }
  for (const r of data.value?.runs || []) if (Number(r.spent_credits) > 0) rows.push({ label: STEP_NAMES[r.build_stage] ? STEP_NAMES[r.build_stage].replace(/^The /, '').replace(/^./, c => c.toUpperCase()) : 'Build', text: `${Number(r.spent_credits).toLocaleString()} cr`, cr: Number(r.spent_credits), at: r.created_at })
  return rows.sort((a, b) => String(a.at).localeCompare(String(b.at)))
})
const spendTotal = computed(() => spendRows.value.reduce((n, r) => n + r.cr, 0))
const outOfCredits = run => /^Paused: this step used the credits/.test(run?.error || '')
onBeforeUnmount(() => {historyObserver?.disconnect();window.removeEventListener('keydown', onKey);clearInterval(timer);clearInterval(planPoll);clearTimeout(searchTimer);epoch++;mediaEpoch++;historyEpoch++;libraryEpoch++;compareEpoch++;for(const url of [media.value,compareMedia.value,...uploads.value.map(u=>u.preview_url)]) if(url) URL.revokeObjectURL(url)})
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="auth.user" active-page="create" @logout="auth.logout()" />
    <main class="agent-main">
      <header class="agent-header">
        <div class="agent-header__title"><div class="crumb">Create</div><h1 :title="conversation?.title || 'New creation'">{{ conversation?.title || 'New creation' }}</h1></div>
        <span v-if="headStatus" :class="['status', `status--${headStatus.cls}`]">{{ headStatus.text }}</span>
        <div class="header-actions">
          <span v-if="balance !== null" class="credits" :title="balanceTitle">{{ balance.toLocaleString() }} cr</span>
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

            <template v-for="m in timeline" :key="m.id">
              <div v-if="m.role === 'user'" :class="['user-message', 'message', { 'has-refs': messageAttachments[m.id]?.length }]">
                <ReferencePills v-if="messageAttachments[m.id]?.length" :assets="messageAttachments[m.id]" class="message-refs" />
                <p>{{ m.content }}</p>
              </div>
              <div v-else-if="m.eventType === 'message'" class="assistant-message message">
                <span class="speaker">WyvStudio <time>{{ time(m.created_at) }}</time></span>
                <template v-if="planByMessage[m.id]">
                  <TurnActivity v-if="planByMessage[m.id].status === 'proposed' && planByMessage[m.id].plan.activity" :activity="planByMessage[m.id].plan.activity" />
                  <div v-if="planByMessage[m.id].status === 'proposed' && planByMessage[m.id].plan.free_edit && !planByMessage[m.id].stale" class="icard icard--warn"><div class="icard__body">
                      <p class="icard__summary">{{ planByMessage[m.id].plan.summary }}</p>
                      <div v-if="planByMessage[m.id].plan.free_edit" class="free-plan" role="group" aria-label="Free change">
                        <b>This is a free change</b>
                        <span class="muted">It only edits text and colours already in your video. No model call, one render.</span>
                        <ul><li v-for="(v, k) in planByMessage[m.id].plan.free_edit" :key="k"><span class="muted">{{ (editableFields.find(f => f.id === k) || {}).label || k }}:</span> <span v-if="String(v).startsWith('#')" class="free-plan__swatch" :style="{ background: v }" /> {{ v }}</li></ul>
                        <button type="button" class="btn btn--primary btn--sm" :disabled="locked || !canWrite" @click="applyPlanFreeEdit(planByMessage[m.id].plan.free_edit)">Apply for free</button>
                      </div>
                  </div></div>
                  <template v-else>
                    <p v-if="planByMessage[m.id].status === 'proposed'" class="plan-lead">{{ planByMessage[m.id].plan.summary }}</p>
                    <PlanNote v-if="planByMessage[m.id].status === 'proposed' && planByMessage[m.id].plan.creative_intent?.reason" class="plan-approach" label="Creative approach" :text="planByMessage[m.id].plan.creative_intent.reason" />
                    <p v-if="planByMessage[m.id].status === 'proposed' && planByMessage[m.id].stale" class="plan-lead muted">Your brief changed after this plan. <button v-if="canWrite" type="button" class="quiet quiet--sm" :disabled="locked || planning" @click="makePlan">{{ planning ? 'Planning…' : 'Plan again' }}</button></p>
                    <p v-else-if="isLivePlan(planByMessage[m.id]) && !active" class="plan-lead">Here’s the plan. Open it to change the copy, voice or look, or approve and I’ll start.</p>
                    <div :class="['plan-card', { 'is-old': !isLivePlan(planByMessage[m.id]) }]">
                      <div class="plan-pills">
                        <span class="plan-title">Plan</span>
                        <span v-for="t in planPills(planByMessage[m.id])" :key="t" class="pill">{{ t }}</span>
                        <span v-if="planEdited(planByMessage[m.id])" class="pill is-edited">Edited</span>
                        <span class="pill pill--effort" :title="EFFORTS.find(e => e.id === currentEffort)?.detail">{{ EFFORTS.find(e => e.id === currentEffort)?.label }} effort</span>
                      </div>
                      <div v-if="planFiles.length && planByMessage[m.id].status === 'proposed'" class="plan-files">
                        <span v-for="f in planFiles" :key="f.asset_id" class="plan-file" :title="f.title">
                          <b>{{ f.purpose === 'source' ? 'Using' : 'Following' }}</b> {{ f.title }}<small v-if="brandRole(f.asset_id)" class="muted"> · brand {{ brandRole(f.asset_id) }}</small>
                          <button v-if="planEditable(planByMessage[m.id])" type="button" class="plan-file__switch" :disabled="locked || planning" @click="switchRole(f)">{{ f.purpose === 'source' ? 'Follow it instead' : 'Use it instead' }}</button>
                        </span>
                      </div>
                      <div class="plan-actions">
                        <button type="button" class="btn btn--ghost btn--sm" @click="openPlan(planByMessage[m.id])">Preview</button>
                        <button v-if="planEditable(planByMessage[m.id]) && !characterStep(planByMessage[m.id])" type="button" class="btn btn--primary btn--sm" :disabled="locked || approvingPlan || approveBlocked(planByMessage[m.id])" @click="approvePlan(planByMessage[m.id])">{{ approvingPlan ? 'Starting…' : approveLabel(planByMessage[m.id]) }}</button>
                        <span v-if="planStatus(planByMessage[m.id])" class="plan-status">{{ planStatus(planByMessage[m.id]) }}</span>
                      </div>
                    </div>
                    <PlanNote v-if="planEditable(planByMessage[m.id])" :label="startsWithCharacter(planByMessage[m.id]) ? 'Character, then storyboard, then the video' : storyboardFirst(planByMessage[m.id]) ? 'Storyboard first, then the video' : 'What happens next'" :text="nextStep(planByMessage[m.id])" />
                    <p v-if="planEditable(planByMessage[m.id]) && planPrice[planByMessage[m.id].id]?.raised" class="notice">Your changes raised the price to up to {{ planPrice[planByMessage[m.id].id].quote.credits_max.toLocaleString() }} credits. Approve again to go ahead.</p>
                    <p v-if="planEditable(planByMessage[m.id]) && planPrice[planByMessage[m.id].id]?.error" class="muted plan-note">Couldn’t price this plan: {{ planPrice[planByMessage[m.id].id].error }}</p>
                    <template v-if="planByMessage[m.id].status === 'proposed' && !planByMessage[m.id].stale">
                      <div v-if="characterStep(planByMessage[m.id])" class="step">
                        <p class="plan-lead">{{ characterStep(planByMessage[m.id]).approved ? 'The creator is approved. They stay the same in every shot.' : 'Here’s the creator for your video. Check them before the storyboard: they stay the same in every shot.' }}</p>
                        <div class="plan-card">
                          <div class="step-thumbs"><img v-for="img in characterStep(planByMessage[m.id]).images.slice(0, 4)" :key="img.asset_id" :src="img.preview_url" :alt="img.label || img.name" /></div>
                          <div class="plan-pills">
                            <span class="plan-title">Character</span>
                            <span v-for="n in characterStep(planByMessage[m.id]).subjects.map(x => x.name)" :key="n" class="pill">{{ n }}</span>
                            <span v-if="Object.keys(characterStep(planByMessage[m.id]).looks || {}).length" class="pill is-edited">Edited</span>
                          </div>
                          <div class="plan-actions">
                            <button type="button" class="btn btn--ghost btn--sm" @click="openStep(planByMessage[m.id], 'character')">Preview</button>
                            <button v-if="!characterStep(planByMessage[m.id]).approved && canWrite && !active" type="button" class="btn btn--primary btn--sm" :disabled="locked || stepBusy || stepPriceLoading(planByMessage[m.id], 'character')" @click="approveStepNow(planByMessage[m.id], 'character')">{{ stepBusy ? 'Starting…' : stepApproveLabel(planByMessage[m.id], 'character') }}</button>
                            <span v-if="characterStep(planByMessage[m.id]).approved" class="plan-status">Approved · used in every shot</span>
                          </div>
                        </div>
                      </div>
                      <div v-if="storyboardStep(planByMessage[m.id])" class="step">
                        <p class="plan-lead">{{ storyboardStep(planByMessage[m.id]).approved ? 'The storyboard is approved.' : 'The storyboard is ready. Change a frame in the preview, or approve it to make the video.' }}</p>
                        <div class="plan-card">
                          <div class="step-thumbs"><img v-for="img in storyboardStep(planByMessage[m.id]).images.slice(0, 5)" :key="img.asset_id" :src="img.preview_url" :alt="img.label" /></div>
                          <div class="plan-pills">
                            <span class="plan-title">Storyboard</span>
                            <span class="pill">{{ storyboardStep(planByMessage[m.id]).images.length }} frames</span>
                            <span v-if="Object.keys(storyboardStep(planByMessage[m.id]).panel_notes || {}).length" class="pill is-edited">Edited</span>
                          </div>
                          <div class="plan-actions">
                            <button type="button" class="btn btn--ghost btn--sm" @click="openStep(planByMessage[m.id], 'storyboard')">Preview</button>
                            <button v-if="!storyboardStep(planByMessage[m.id]).approved && canWrite && !active" type="button" class="btn btn--primary btn--sm" :disabled="locked || stepBusy || stepPriceLoading(planByMessage[m.id], 'storyboard')" @click="approveStepNow(planByMessage[m.id], 'storyboard')">{{ stepBusy ? 'Starting…' : stepApproveLabel(planByMessage[m.id], 'storyboard') }}</button>
                            <span v-if="storyboardStep(planByMessage[m.id]).approved" class="plan-status">Approved · making the video</span>
                          </div>
                        </div>
                      </div>
                    </template>
                  </template>
                </template>
                <p v-else>{{ m.content }}</p>
              </div>
            <div v-if="m.eventType === 'revision' && currentRevision" class="assistant-message">
              <span class="speaker">WyvStudio <time>{{ time(currentRevision.created_at) }}</time></span>
              <h3 v-if="outputMeta.look">Storyboard preview · no audio or motion</h3>
              <p class="result-lead"><strong>{{ summarySections(currentRevision.summary).lead }}</strong></p>
              <p v-if="currentRevision.conflict" class="notice">{{ outputMeta.variant_group ? 'An alternative variation. Inspect it, then restore it as a new version to make it current.' : 'Your brief changed while this was being made. This draft is kept; your current version did not change.' }}</p>
              <p v-if="isOldRevision" class="notice">You are viewing version {{ currentRevision.number }}. Version {{ currentNumber }} is still current. Download uses the version shown here.</p>
              <p v-if="outputMeta.final_checks?.status === 'blocked' && !outputMeta.look" class="notice">Not ready yet: this version misses something you approved. <button type="button" class="quiet quiet--sm" @click="versionOpen = true">See what</button></p>
              <div :class="['result-card', 'result-card--' + playerShape]">
                <div class="result__stage">
                  <p v-if="artifactLoading" class="muted">Loading your result…</p>
                  <StoryboardCarousel v-if="media && outputMeta.look" :src="media" />
                  <img v-else-if="media && imageOutput" :src="media" class="created-image" alt="Generated image" />
                  <div v-else-if="media" class="player-wrap"><FinishedVideoPlayer ref="player" :src="media" /><div v-if="safeZones" class="safe-zones" aria-hidden="true" /></div>
                  <p v-if="artifactGone && !artifactLoading" class="muted">{{ artifactGone }}</p>
                  <button v-if="!media && !artifactLoading && !artifactGone" type="button" class="btn btn--ghost btn--sm" @click="loadArtifact">Retry preview</button>
                </div>
                <div class="result-side">
                  <span class="result-title">{{ conversation?.title || 'Your video' }}</span>
                  <div class="result-pills">
                    <span class="pill">Version {{ currentRevision.number }}</span>
                    <span v-for="t in versionPills" :key="t" class="pill">{{ t }}</span>
                    <span :class="['pill', isOldRevision ? '' : 'pill--ok']">{{ isOldRevision ? 'Earlier' : 'Current' }}</span>
                  </div>
                  <div class="result-actions">
                    <button v-if="outputMeta.look && paid && canWrite && !isOldRevision && !conversation.archived_at" type="button" class="btn btn--primary" :disabled="locked" @click="approveLook">Approve design & create video · review cost</button>
                    <button v-if="outputMeta.look && paid && canWrite && !isOldRevision && !conversation.archived_at" type="button" class="btn btn--outline" :disabled="locked" @click="changeLook">Change the look</button>
                    <button v-if="media && !outputMeta.look" type="button" class="btn btn--primary" @click="download">Download {{ imageOutput ? 'image' : paid ? 'video' : 'sample' }}</button>
                    <button v-if="!outputMeta.look && needsAnotherRound && paid && canWrite && !isOldRevision && !conversation.archived_at" type="button" class="btn btn--outline" :disabled="locked" @click="keepImproving">Keep improving</button>
                    <button v-if="isOldRevision" type="button" class="btn btn--ghost" @click="compare">Compare with current</button>
                    <button v-if="canWrite && isOldRevision && !conversation.archived_at" type="button" class="btn btn--ghost" :disabled="locked" @click="restore">Restore as a new version</button>
                    <button v-if="isOldRevision" type="button" class="btn btn--ghost" @click="selectedRevision = null">Back to current</button>
                    <template v-if="!outputMeta.look && !imageOutput && media && canWrite && !conversation?.archived_at && paid">
                      <button type="button" class="btn btn--outline" :disabled="locked || !!sharing" @click="copyShare">{{ sharing === 'share' ? 'Getting link…' : 'Copy share link' }}</button>
                      <button type="button" class="btn btn--outline" :disabled="locked || !!sharing" @click="scheduleNow">{{ sharing === 'schedule' ? 'Saving…' : 'Schedule' }}</button>
                      <button type="button" class="btn btn--outline" :disabled="locked || !!sharing" @click="approvalOpen = true; approvalResult = ''; approvalError = ''">Send for approval</button>
                    </template>
                    <button type="button" class="btn btn--ghost" @click="versionOpen = true">Details</button>
                  </div>
                  <p v-if="shareNote" class="result-hint result-share" role="status">{{ shareNote }}</p>
                  <p v-if="!outputMeta.look && !isOldRevision && canWrite" class="result-hint">Want something different? Describe the change below, like “slower cuts” or “bigger captions”.</p>
                </div>
              </div>
                <p v-if="outputMeta.look && !isOldRevision" class="look-note">Storyboard preview — silent still frames, not your finished video. Request changes here, or review the cost to build the full video with motion and audio.</p>
                <section v-if="outputMeta.look && !isOldRevision && currentPlan?.character_preview" class="checks character-approval-inline" :aria-label="currentPlan.character_preview.kind === 'reference_sheet' ? 'Cast and world for the generated shots' : 'Character look for this video'">
                  <b>{{ currentPlan.character_preview.kind === 'reference_sheet' ? 'Cast and storyboard for the generated shots' : 'Character look for this video' }}</b>
                  <div class="character-review-grid">
                    <figure v-for="image in currentPlan.character_preview.images" :key="image.asset_id">
                      <img :src="image.preview_url" :alt="image.label || image.name || 'Character preview'" />
                      <figcaption v-if="image.label">{{ image.label }}<span v-if="panelIssue(image.label)" class="panel-issue"> · {{ panelIssue(image.label) }}</span></figcaption>
                      <textarea v-if="canWrite && isPanel(image.label) && !currentPlan.character_preview.approved" v-model="panelNoteDraft[image.label]" class="input input--sm grow" rows="2" maxlength="240" :placeholder="'Change ' + image.label + '…'" :aria-label="'Note to redraw ' + image.label" />
                    </figure>
                  </div>
                  <p v-if="currentPlan.character_preview.panel_checks?.status === 'unverified'" class="muted">The panels could not be checked automatically; look them over yourself.</p>
                  <button v-if="canWrite && panelNotesChanged" type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="redrawPanels">Redraw {{ panelNotesCount }} {{ panelNotesCount === 1 ? 'panel' : 'panels' }} · review cost</button>
                  <p v-if="currentPlan.character_preview.kind === 'reference_sheet'">{{ currentPlan.character_preview.approved ? 'Every generated shot is made from these images.' : 'Approving the storyboard approves these images: every generated shot is made from them, so the people, places and products stay the same. You’ll review the cost before any clip is made.' }}</p>
                  <p v-else>{{ currentPlan.character_preview.approved ? 'This approved look will guide the poses and talking clips.' : 'Approving the design also approves this character look for the poses and talking clips. You’ll review the cost before generation starts.' }}</p>
                </section>
            </div>
            </template>

            <div v-if="lastFailure && !active" class="assistant-message">
              <div class="fail" role="alert">
                <p class="fail-head"><span class="fail-glyph" aria-hidden="true">{{ outOfCredits(lastFailure) ? '⏸' : '✕' }}</span><b>{{ STEP_NAMES[lastFailure.build_stage] || 'The build' }} {{ outOfCredits(lastFailure) ? 'paused: it used the credits set aside for it.' : 'stopped before it finished.' }}</b></p>
                <p class="fail-sub">{{ outOfCredits(lastFailure) ? 'Everything it finished is kept. Top up if your balance is low, then Retry continues where it stopped.' : 'Everything it finished is kept, and you were charged only for the work it did. Retrying picks up from where it stopped, with the approval you already gave.' }}</p>
                <div class="result-actions">
                  <button v-if="outOfCredits(lastFailure)" type="button" class="btn btn--ghost btn--sm" @click="router.push({ name: 'settings', query: { section: 'billing' } })">Top up</button>
                  <button v-if="canWrite" type="button" class="btn btn--primary btn--sm" :disabled="locked || retrying" @click="retryRun(lastFailure)">{{ retrying ? 'Retrying…' : 'Retry' }}</button>
                  <button type="button" class="btn btn--ghost btn--sm" @click="dismissedFailures = [...dismissedFailures, lastFailure.id]">Stop here</button>
                </div>
                <PlanNote label="Details" :text="`Stopped ${time(lastFailure.created_at)}. ${lastFailure.error || lastFailure.stage || ''}`" />
              </div>
            </div>
            <div v-if="olderAttempts.length" class="assistant-message">
              <details class="run-card"><summary>Earlier attempts</summary><p v-for="run in olderAttempts" :key="run.id" class="run-line">{{ run.status === 'needs_input' ? 'Your input is needed' : run.status === 'failed' ? 'Stopped' : 'Cancelled' }} · {{ STEP_NAMES[run.build_stage] || 'Build' }} · {{ run.error || run.stage }}</p></details>
            </div>

            <div v-if="active" class="assistant-message" aria-live="polite">
              <div :class="['icard', active.status === 'needs_attention' ? 'icard--warn' : 'icard--info']">
                <div class="icard__body working">
                  <ThinkingLine v-if="active.status !== 'needs_attention'" :key="active.id" :label="active.stage" :started-at="active.created_at" :detail="active.status === 'needs_attention' ? 'This run needs a recovery check before it continues. Earlier versions are safe, and nothing retries on its own.' : 'You can leave this page. Earlier versions stay downloadable while this runs.'" /><div v-else class="working__row"><div><div class="working__label">{{ active.stage }}</div><div class="working__step">{{ active.status === 'needs_attention' ? 'This run needs a recovery check before it continues. Earlier versions are safe, and nothing retries on its own.' : 'You can leave this page. Earlier versions stay downloadable while this runs.' }}</div></div></div><div v-if="autoRan !== null" class="auto-tag-row"><span v-if="autoRan !== null" class="tier tier--quoted auto-tag">RAN AUTOMATICALLY · UP TO {{ autoRan }} CREDITS</span></div>
                </div>
                <div v-if="canWrite && active.status !== 'needs_attention'" class="icard__foot"><small v-if="active.held_credits" class="muted">{{ Number(active.held_credits).toLocaleString() }} credits held for this build · only what it uses is charged</small><span class="spacer" /><button type="button" class="btn btn--ghost btn--sm" :disabled="locked || active.status === 'cancel_requested'" @click="cancel">{{ active.status === 'cancel_requested' ? 'Stopping…' : 'Stop · keeps what is done so far' }}</button></div>
              </div>
            </div>

            <div v-if="quote" class="assistant-message">
              <span class="speaker">WyvStudio</span>
              <div class="icard icard--warn">
                <div class="icard__body">
                  <p class="icard__summary">{{ quote.paid ? 'Here is what this will cost.' : 'This renders the fixed local sample.' }} {{ quote.description }}</p>
                  <p v-if="quote.build_stage === 'storyboard'" class="notice">Storyboard only: silent still frames. Audio and animation are deferred to the full video build.</p>
                  <p v-else-if="quote.build_stage === 'full_video'" class="notice">Full video: build the motion and approved audio.</p>
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
                  <div v-if="quote.paid && quote.media_ceiling" class="ceiling-line">
                    <span class="muted">Media: estimated {{ quote.media_estimate }} credits · spend up to</span>
                    <input v-model.number="ceilingDraft" type="number" min="0" max="20000" step="10" class="input input--sm" aria-label="Media spending ceiling in credits" />
                    <span class="muted">credits</span>
                    <button v-if="ceilingDraft !== quote.media_ceiling" type="button" class="quiet quiet--sm" :disabled="busy" @click="setCeiling">Set</button>
                    <small class="muted">The agent may buy more media under this ceiling; anything over it waits for your approval.</small>
                  </div>
                  <div class="cost-line"><b>{{ quote.paid ? `Up to ${quote.credits_max} credits` : 'No credits' }}</b><span>{{ quote.paid ? '· reserved when you approve, unused part returned' : '· no paid calls' }}</span></div>
                  <p v-if="quoteCreditAvailability" class="muted">Balance {{ quoteCreditAvailability.total.toLocaleString() }}<template v-if="quoteCreditAvailability.reserved"> · {{ quoteCreditAvailability.reserved.toLocaleString() }} held for unfinished work · {{ quoteCreditAvailability.available.toLocaleString() }} free for this approval</template>. Checked again when you approve.</p>
                  <span class="spacer" />
                  <button type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="quote = null">Not now</button>
                  <button v-if="expiredQuote" type="button" class="btn btn--primary btn--sm" :disabled="locked" @click="plan">Refresh plan</button>
                  <button v-else type="button" class="btn btn--primary btn--sm" :disabled="locked || quote.paid && !providerApproved" @click="approve">{{ quote.paid ? 'Approve' : 'Approve sample render' }}</button>
                </div>
              </div>
            </div>
            <div v-if="pendingText" class="user-message message">{{ pendingText }}</div>
            <div v-if="linkStudying" class="assistant-message"><span class="speaker">WyvStudio</span><ThinkingLine :key="linkStudying" :steps="studySteps" :detail="linkStudying" :step-seconds="5" /></div>
            <div v-if="planning" class="assistant-message"><span class="speaker">WyvStudio</span><PlanningLive :live="planLive" :started-at="planStarted" /></div>
            <div v-else-if="!quote && conversation && canWrite && !active && data?.messages?.length && !conversation.archived_at && !currentPlan" class="next-step">
              <p v-if="kind === 'image' && !paid" class="muted">Your image brief is saved. Image generation and editing are not enabled in this local preview yet.</p>
              <div v-if="pendingQuestion && !error && isRoleQuestion(pendingQuestion)" class="answer-cards" role="group" aria-label="How to use this file">
                <button v-for="o in ROLE_ANSWERS" :key="o.word" type="button" class="pd-card answer-card" :disabled="locked" @click="answerWith(o.word)"><span class="pd-card-label">{{ o.label }}</span><span class="pd-card-detail">{{ o.detail }}</span></button>
              </div>
              <div v-if="pendingQuestion && !error && isMatchQuestion(pendingQuestion)" class="answer-cards" role="group" aria-label="How closely to follow the reference">
                <button v-for="o in MATCH_ANSWERS" :key="o.word" type="button" class="pd-card answer-card" :disabled="locked" @click="answerWith(o.word)"><span class="pd-card-label">{{ o.label }}</span><span class="pd-card-detail">{{ o.detail }}</span></button>
              </div>
              <p v-if="pendingQuestion && !error" class="question-hint">{{ isMatchQuestion(pendingQuestion) || isRoleQuestion(pendingQuestion) ? 'Or answer in your own words below, or' : 'Answer below, or' }} <button type="button" class="quiet quiet--sm" :disabled="locked" @click="makePlan(true)">skip and plan with your best guess</button></p>
              <template v-else-if="!stalePlan"><p v-if="error" class="muted">Planning didn't finish. Your brief is saved; try planning again.</p><button type="button" class="btn btn--primary btn--sm" :disabled="locked" @click="makePlan()">{{ error ? 'Plan again' : 'Plan it' }}</button></template>
            </div>
            <div ref="end" />
          </div>

          <div v-if="canWrite && !conversation?.archived_at" class="composer-dock">
            <div v-if="error" class="create-error" role="alert"><p>{{ error }}</p><p v-if="conflict">We refreshed the conversation. Your unsent text is still here; check the latest version before trying again.</p><button type="button" aria-label="Dismiss error" @click="error = ''; conflict = false">×</button></div>
            <div v-if="canWrite && openClaims.length" class="attached">
              <div v-for="a in openClaims" :key="'c' + a.asset_id" class="upload">
                <img v-if="a.asset_type === 'image' && a.preview_url" :src="a.preview_url" alt="" class="upload__thumb" /><span v-else class="upload__thumb" />
                <div><b :title="a.title">{{ a.title }}</b>
                  <div class="claims">
                    <small class="muted">Claims on this page. Tick the ones that may appear on screen:</small>
                    <label v-for="(c, i) in a.suggested_claims" :key="i" class="claims__row" :title="'From the page: ' + c.quote"><input v-model="claimPicks[a.asset_id + ':' + i]" type="checkbox" /> {{ c.text }}</label>
                    <span><button type="button" class="quiet quiet--sm" :disabled="locked" @click="approveClaims(a)">Add to approved facts</button> <button type="button" class="quiet quiet--sm" @click="markClaimsDone(a)">Not now</button></span>
                  </div></div>
              </div>
            </div>
            <form class="prompt-form" @submit.prevent="send">
              <ComposerTray v-if="trayItems.length" :items="trayItems" :disabled="locked" @remove="removeTrayItem" />
              <div v-if="trayItems.length && (trayErrors.length || trayNotes.length)" class="tray-notes">
                <p v-for="e in trayErrors" :key="'e' + e.key" class="tray-note tray-note--error">{{ e.title }}: {{ e.error }} <button v-if="e.upload?.state === 'failed'" type="button" class="quiet quiet--sm" :disabled="locked" @click="upload(e.upload)">Retry upload</button></p>
                <p v-for="n in trayNotes" :key="'n' + n.asset_id" class="tray-note" :title="n.text"><b>{{ n.title }}</b> · {{ n.text }} <button v-if="n.style" type="button" class="quiet quiet--sm" @click="askSaveStyle({asset_id:n.asset_id}, n.title)">Save as style</button></p>
              </div>
              <label for="create-prompt" class="sr-only">Describe what you want to create or change</label>
              <textarea ref="composer" id="create-prompt" v-model="prompt" rows="2" maxlength="10000" placeholder="Describe what you want to create or change…" @input="sendingKey = null" @keydown.meta.enter.prevent="send" @keydown.ctrl.enter.prevent="send" />
              <div class="composer-bottom">
                <button type="button" class="quiet" :disabled="locked" title="PNG, JPEG, WebP, MP4, MP3 or WAV. Up to 20 files, 100 MB each and 200 MB total." @click="fileInput.click()">+ Attach</button>
                <button type="button" class="quiet" :disabled="locked" @click="showLibrary">From library</button>
                <button type="button" class="quiet" :disabled="locked" title="A public post from X, YouTube or TikTok, used as a style reference" @click="openLink">From a link</button>
                <label v-if="styles.length || packs.length" class="style-pick"><span class="sr-only">Style</span><select :value="currentStyleId" :disabled="locked" aria-label="Style" @change="chooseStyle($event.target.value)"><option value="">Style: WyvStudio chooses</option><optgroup v-if="packs.length" label="WyvStudio styles"><option v-for="k in packs" :key="k.slug" :value="'pack:' + k.slug">Style: {{ k.name }}</option></optgroup><optgroup v-if="styles.length" label="Your styles"><option v-for="s in styles" :key="s.id" :value="s.id">Style: {{ s.name }}</option></optgroup></select></label>
                <button v-if="styles.length" type="button" class="quiet" @click="stylesOpen = true">Manage styles</button>
                <UiSelect v-if="outputKind === 'video' || conversation" :model-value="currentEffort" label="Effort: how much care, and cost, goes into the video" align="left" drop="up" :disabled="locked" :options="EFFORTS.map(e => ({ value: e.id, label: 'Effort · ' + e.label }))" @update:model-value="setEffort" />
                <span v-if="!conversation" class="seg" role="group" aria-label="What to make"><button type="button" :aria-pressed="outputKind === 'video'" @click="outputKind = 'video'">Video</button><button type="button" :aria-pressed="outputKind === 'image'" @click="outputKind = 'image'">Image</button></span>
                <button class="send" type="submit" :disabled="locked || !prompt.trim()" aria-label="Send"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" /></svg></button>
              </div>
            </form>
            <p class="composer-note">Planning and text or colour changes are free. Small jobs under 15 credits just run and show their cost; anything more is quoted first. Uploads stay private.</p>
          </div>
          <p v-if="error && (!canWrite || conversation?.archived_at)" class="create-error" role="alert">{{ error }}</p>
        </section>

        <div v-if="details" class="panel-scrim" aria-hidden="true" @click="closePanel" />
        <SideDrawer :open="details && !!conversation" :title="kind === 'image' ? 'This image' : 'This video'" meta="Settings and versions" @close="closePanel">
          <div v-if="conversation" id="details-panel" class="details-body">
          <div class="detail-tabs" role="group" aria-label="Panel"><button type="button" :aria-pressed="panelTab === 'details'" @click="panelTab = 'details'">Details</button><button type="button" :aria-pressed="panelTab === 'versions'" @click="panelTab = 'versions'">Versions</button></div>
          <template v-if="panelTab === 'details'">
            <section>
              <h3>OUTPUT</h3>
              <p>{{ outputSummary }}</p>
              <p v-if="!paid" class="muted">{{ kind === 'image' ? 'Image generation is not enabled in this local preview. Your image brief and references are saved.' : 'The local sample is 15 seconds, portrait, 1080p. Other settings apply once generation is enabled.' }}</p>
              <details v-if="paid" class="panel-edit"><summary>Change output</summary>
                <UiSelect v-model="settingsDraft.aspect_ratio" label="Format" :options="[{value:'9:16',label:'Portrait · 9:16'},{value:'16:9',label:'Landscape · 16:9'},{value:'1:1',label:'Square'},{value:'4:5',label:'Feed · 4:5'}]" />
                <label v-if="kind === 'video'" class="field-label">Length in seconds<input v-model.number="settingsDraft.duration_seconds" type="number" min="5" max="30" class="input" /></label>
                <UiSelect v-if="kind === 'video'" v-model="settingsDraft.reference_effort" label="How closely to study reference videos" :options="[{value:'',label:'Automatic'},{value:'standard',label:'Standard · quick'},{value:'high',label:'High · every clear change'},{value:'maximum',label:'Maximum · every frame that differs (slower)'}]" />
                <UiSelect v-if="kind === 'video'" v-model="settingsDraft.reference_match" label="Match the reference video" :options="[{value:'',label:'From my brief (I\'ll ask if unclear)'},{value:'exact',label:'Exactly · same timing, layout and moves, my brand and content'},{value:'similar',label:'Similar · its format, look and pacing, my own story and shots'},{value:'inspired',label:'Inspired · just the idea, my own execution'}]" />
                <UiSelect v-if="kind === 'video'" v-model="settingsDraft.frame_rate" label="Frame rate" :options="[{value:24,label:'24 fps · film'},{value:30,label:'30 fps'},{value:60,label:'60 fps · smoothest UI motion (the final render takes about 2.5 times as long)'}]" />
                <label v-if="kind === 'video'" class="field-label field-label--check"><input v-model="settingsDraft.motion_blur" type="checkbox" /> Motion blur on the final video <small class="muted">(smoother fast motion; the final render takes about twice as long)</small></label>
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
                <span><b :title="a.title">{{ a.title }}</b><small>{{ sizeLabel(a) }} · {{ a.purpose === 'source' ? 'used in the video' : a.purpose === 'reference' ? 'reference' : 'role set when you send' }}</small></span>
                <UiSelect v-if="canWrite && ['image', 'video'].includes(a.asset_type)" :model-value="brandRole(a.asset_id)" label="Keep in your brand library" align="right" :options="[{ value: '', label: 'Not a brand item' }, ...BRAND_ROLES.map(r => ({ value: r.id, label: 'Brand ' + r.label.toLowerCase() }))]" @update:model-value="v => setBrandRole(a.asset_id, v)" />
                <button v-if="canWrite && !conversation.archived_at" type="button" class="upload__x" :disabled="locked" :aria-label="`Remove ${a.title}`" @click="detach(a)">×</button>
              </div>
              <p v-if="!data?.attachments?.length" class="muted">No files yet.</p>
              <button v-if="canWrite && !conversation.archived_at" type="button" class="quiet" :disabled="locked" @click="showLibrary">+ Add from library</button>
            </section>
            <section v-if="spendRows.length">
              <h3>SPENT ON THIS CREATION</h3>
              <div v-for="(row, i) in spendRows" :key="i" class="spend-row"><span>{{ row.label }}</span><b>{{ row.text }}</b></div>
              <div class="spend-row spend-row--total"><span>Total</span><b>{{ spendTotal.toLocaleString() }} cr</b></div>
            </section>
            <section>
              <h3>YOUR BRAND LIBRARY</h3>
              <p class="muted">Kept for every creation: the planner uses these instead of asking again.</p>
              <div v-for="b in brandItems" :key="b.asset_id" class="asset">
                <img v-if="b.asset_type === 'image' && b.preview_url" :src="b.preview_url" alt="" class="asset__thumb" /><span v-else class="asset__thumb thumb--video" />
                <span><b :title="b.title">{{ b.title }}</b><small>{{ BRAND_ROLES.find(r => r.id === b.role)?.label }}</small></span>
                <button v-if="canWrite" type="button" class="upload__x" :aria-label="`Remove ${b.title} from your brand library`" @click="setBrandRole(b.asset_id, '')">×</button>
              </div>
              <p v-if="!brandItems.length" class="muted">Nothing yet. Keep a logo, mascot, product or illustration from the files above.</p>
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
          </div>
        </SideDrawer>
      </div>
      <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,video/mp4,audio/mpeg,audio/wav,audio/x-wav" multiple hidden @change="chooseFiles($event.target.files)" />
      <CreateDialog :open="!!characterReview" title="Approve your character’s look" @close="characterReview=null">
        <template v-if="characterReview">
          <p>This preview becomes the reference for every pose and talking clip. Check the face, proportions, outfit and visual treatment before continuing.</p>
          <div v-if="characterReview.character_preview" class="character-review-grid">
            <figure v-for="image in characterReview.character_preview.images" :key="image.asset_id">
              <img :src="image.preview_url" :alt="image.name" /><figcaption>{{ image.name }}</figcaption>
            </figure>
          </div>
          <p v-else>Start with a storyboard to create one character preview. Approve its look before we generate additional poses or the full video.</p>
          <p v-if="characterReview.plan.character_style"><strong>Requested style:</strong> {{ characterReview.plan.character_style }}</p>
          <p v-if="error" class="create-error">{{ error }}</p>
          <div class="row-actions">
            <button v-if="characterReview.character_preview" type="button" class="btn btn--primary" :disabled="locked" @click="approveCharacter">{{ characterReview.character_preview.kind === 'reference_sheet' ? 'Approve the cast and world · review video cost' : 'Approve character · review video cost' }}</button>
            <button v-else type="button" class="btn btn--primary" :disabled="locked" @click="prepareCharacterStoryboard">Review storyboard cost</button>
            <button type="button" class="btn btn--ghost" @click="characterReview=null; changeLook()">Request changes</button>
          </div>
        </template>
      </CreateDialog>
      <CreateDialog :open="!!delivery" :title="delivery?.action === 'unshare' ? 'Turn off this share link?' : 'Use this version?'" @close="delivery=null"><template v-if="delivery"><p>Version {{ delivery.revision.number }} is the exact file for this action.</p><p v-if="delivery.revision.has_newer_changes" class="notice">Newer changes are not in this file. Update your creation or continue with this version.</p><label v-if="delivery.revision.has_newer_changes" class="consent"><input v-model="delivery.allowOlder" type="checkbox" /> Continue with this earlier result.</label><p v-if="delivery.action === 'share'" class="muted">Anyone with the link can view this version until you turn it off. No other files or messages are shared.</p><p v-if="shareUrl"><a :href="shareUrl" target="_blank" rel="noopener">Open share page</a><input class="input" readonly :value="shareUrl" aria-label="Share link" @focus="$event.target.select()" /></p><div class="row-actions"><button v-if="delivery.revision.has_newer_changes" type="button" class="btn btn--ghost btn--sm" @click="updateForDelivery">Update creation</button><button type="button" class="btn btn--primary btn--sm" :disabled="locked || delivery.revision.has_newer_changes && !delivery.allowOlder" @click="performDelivery">{{ delivery.action === 'share' ? 'Create share link' : delivery.action === 'unshare' ? 'Turn off link' : delivery.action === 'schedule' ? 'Choose account and time' : 'Download this version' }}</button></div><p v-if="error" class="create-error">{{ error }}</p></template></CreateDialog>
      <SchedulePostModal v-if="scheduleTarget" :export-job-id="scheduleTarget.revision.export_job_id" :delivery-path="`${base()}/revisions/${scheduleTarget.revision.id}/delivery`" :delivery-context="{expected_version:scheduleTarget.version,allow_older:scheduleTarget.allowOlder}" :allow-ai-caption="false" @close="scheduleTarget=null" />
      <SideDrawer :open="showHistory" title="Recent conversations" meta="Newest first" @close="showHistory = false">
        <div class="history-body">
        <div class="drawer-top"><input v-model="search" type="search" class="input" aria-label="Search conversations" placeholder="Search titles, briefs or file names…" /></div>
        <div class="drawer-filters" role="group" aria-label="Filter"><button v-for="f in [{value:'all',label:'All'},{value:'working',label:'In progress'},{value:'needs',label:'Needs you'},{value:'done',label:'Ready'}]" :key="f.value" type="button" :aria-pressed="historyFilter === f.value" @click="historyFilter = f.value">{{ f.label }}</button></div>
        <label class="consent"><input v-model="archivedHistory" type="checkbox" /> Show archived conversations</label>
        <p class="muted" v-if="historyLoading && !history.length">Searching…</p><p class="muted" v-else-if="!filteredHistory.length && historyNext === null">No matching conversations.</p>
        <template v-for="g in historyGroups" :key="g.label">
          <div class="drawer-group">{{ g.label }}</div>
          <router-link v-for="c in g.items" :key="c.id" :class="['session', c.id === id ? 'is-current' : '']" :to="{name:'create',params:{conversationId:c.id}}" @click="showHistory = false"><span class="session__thumb" /><div><b>{{ c.title }}</b><small>{{ c.last_message || 'Add your first brief or attachment' }}</small><span :class="['status', state(c) === 'working' ? 'status--info' : state(c) === 'needs' ? 'status--warn' : state(c) === 'done' ? 'status--ok' : 'status--neutral']">{{ stateLabel(c).toUpperCase() }}</span></div><time>{{ date(c.updated_at) }}</time></router-link>
        </template>
        <div ref="historySentinel" class="history-sentinel" aria-hidden="true" />
        </div>
        <template #footer><small class="muted">{{ historyLoading ? 'Loading…' : historyNext === null ? 'That’s everything' : 'Scroll for more' }}</small><span class="spacer" /><router-link to="/videos" class="btn btn--ghost btn--sm" @click="showHistory = false">All videos →</router-link></template>
      </SideDrawer>
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
      <SideDrawer :open="!!drawerPlan" title="Plan" :meta="drawerPlan ? planPills(drawerPlan).join(' · ') + (isLivePlan(drawerPlan) ? '' : ' · ' + (planStatus(drawerPlan) || 'view only')) : ''" @close="planDrawerId = null">
        <template v-if="drawerPlan">
          <PlanGroup v-if="drawerPlan.plan.callouts.length || draftFor(drawerPlan).callouts.length" title="On-screen copy" :summary="draftFor(drawerPlan).callouts.filter(t => t.trim()).length + ' lines'" open>
            <div v-for="(t, i) in draftFor(drawerPlan).callouts" :key="i" class="pd-line"><div class="pd-line-head"><span class="pd-q">Line {{ i + 1 }}</span><span v-if="(drawerPlan.plan.new_wording || []).includes(t)" class="pd-flag" title="Not in your brief, facts or page. Approving the plan approves this wording.">new</span><button v-if="planEditable(drawerPlan)" type="button" class="pd-x" :aria-label="`Remove line ${i + 1}`" @click="draftFor(drawerPlan).callouts.splice(i, 1)">×</button></div><textarea v-model="draftFor(drawerPlan).callouts[i]" class="input pd-input grow" rows="1" maxlength="120" :aria-label="`On-screen line ${i + 1}`" :disabled="!planEditable(drawerPlan)" /></div>
            <button v-if="planEditable(drawerPlan) && draftFor(drawerPlan).callouts.length < 6" type="button" class="pd-add" @click="draftFor(drawerPlan).callouts.push('')">+ Add a line</button>
            <PlanNote label="What's left out" :text="'Only the words here appear on screen.' + (drawerPlan.plan.left_out ? ' ' + drawerPlan.plan.left_out : '')" />
          </PlanGroup>
          <PlanGroup v-if="(drawerPlan.plan.narration || []).length || (draftFor(drawerPlan).narration || []).length" title="Voiceover" :summary="(draftFor(drawerPlan).narration || []).filter(t => t.trim()).length + ' lines · ' + voiceName(draftFor(drawerPlan).voice)">
            <div v-for="(t, i) in draftFor(drawerPlan).narration" :key="'n' + i" class="pd-line"><div class="pd-line-head"><span class="pd-q">Line {{ i + 1 }}</span><span v-if="(drawerPlan.plan.new_wording || []).includes(t)" class="pd-flag" title="Not in your brief, facts or page. Approving the plan approves this wording.">new</span><button v-if="planEditable(drawerPlan)" type="button" class="pd-x" :aria-label="`Remove spoken line ${i + 1}`" @click="draftFor(drawerPlan).narration.splice(i, 1)">×</button></div><textarea v-model="draftFor(drawerPlan).narration[i]" class="input pd-input grow" rows="1" maxlength="160" :aria-label="`Spoken line ${i + 1}`" :disabled="!planEditable(drawerPlan)" /></div>
            <button v-if="planEditable(drawerPlan) && draftFor(drawerPlan).narration.length < 8" type="button" class="pd-add" @click="draftFor(drawerPlan).narration.push('')">+ Add a spoken line</button>
            <div class="pd-row">
              <div class="pd-field"><span>Voice</span><UiSelect v-model="draftFor(drawerPlan).voice" label="Voice for the script" :disabled="!planEditable(drawerPlan)" align="left" :options="voiceOptions(draftFor(drawerPlan).voice).map(v => ({ value: v.key, label: v.label }))" /></div>
              <button type="button" class="btn btn--ghost btn--sm pd-btn" @click="openPronunciations"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18M8 8v8M4 11v2M16 6v12M20 10v4" /></svg>Pronunciations</button>
            </div>
            <PlanNote label="Presenters" text="Talking presenters speak in their own generated voice unless you choose your cloned voice." />
          </PlanGroup>
          <PlanGroup v-if="drawerPlan.plan.style || drawerPlan.plan.colour_treatment || drawerPlan.plan.mascot3d" title="Look" :summary="lookSummary(drawerPlan)">
            <template #aside><span v-if="drawerPlan.plan.colour_treatment" class="pd-dots" aria-hidden="true"><i v-for="(c, role) in drawerPlan.plan.colour_treatment.roles" :key="role" :style="{ background: planDrafts[drawerPlan.id]?.colours?.[role] || c.hex }" /></span></template>
            <div v-if="drawerPlan.plan.style" class="pd-field"><span>Style</span>
              <UiSelect :model-value="planDrafts[drawerPlan.id]?.style ?? styleKey(drawerPlan.plan.selections.style)" label="Style this video starts from" :disabled="!planEditable(drawerPlan) || !planDrafts[drawerPlan.id]" align="left" :options="styleOptions(drawerPlan).map(o => ({ value: o.key, label: o.label }))" @update:model-value="v => { planDrafts[drawerPlan.id].style = v }" />
            </div>
            <PlanNote v-if="drawerPlan.plan.style?.why || (styleNotes[planStyleKey(drawerPlan)] || []).length" label="Why this style" :text="[drawerPlan.plan.style?.why, ...(styleNotes[planStyleKey(drawerPlan)] || [])].filter(Boolean).join(' ')" />
            <div v-if="drawerPlan.plan.colour_treatment" class="pd-colours" role="group" aria-label="Colours">
              <label v-for="(colour, role) in drawerPlan.plan.colour_treatment.roles" :key="role" class="pd-colour" :title="(planDrafts[drawerPlan.id]?.colours?.[role] || colour.hex).toUpperCase()">
                <input v-if="planEditable(drawerPlan) && planDrafts[drawerPlan.id]?.colours" v-model="planDrafts[drawerPlan.id].colours[role]" type="color" :aria-label="`${role} colour`" />
                <span v-else class="pd-swatch" :style="{ backgroundColor: colour.hex }" aria-hidden="true" />
                <span>{{ role }}</span>
              </label>
            </div>
            <PlanNote v-if="drawerPlan.plan.colour_treatment" label="About these colours" :text="[drawerPlan.plan.colour_treatment.source_note, drawerPlan.plan.colour_treatment.usage].filter(Boolean).join(' ')" />
            <PlanNote v-if="drawerPlan.plan.character_style" label="Character appearance" :text="drawerPlan.plan.character_style" />
            <div v-if="drawerPlan.plan.mascot3d" class="pd-mascot"><MascotPreview :spec="drawerPlan.plan.mascot3d.spec" /><PlanNote label="Your mascot" :text="mascotLine(drawerPlan.plan.mascot3d) + (drawerPlan.plan.mascot3d.missing ? ' Not possible yet: ' + drawerPlan.plan.mascot3d.missing : '')" /></div>
          </PlanGroup>
          <PlanGroup v-if="drawerPlan.plan.character_performance?.length" title="Character actions" :summary="drawerPlan.plan.character_performance.length + ' actions'">
            <label v-for="action in drawerPlan.plan.character_performance" :key="action.id" class="pd-check"><input v-if="planDrafts[drawerPlan.id]" type="checkbox" :checked="!(planDrafts[drawerPlan.id].omitted_performance || []).includes(action.id)" :disabled="!planEditable(drawerPlan)" @change="toggleAction(drawerPlan, action.id, $event.target.checked)" /><span>{{ action.action }} <small>{{ action.start ?? '?' }}–{{ action.end ?? '?' }} s</small></span></label>
            <p v-for="issue in drawerPlan.performance_issues || []" :key="issue.id" class="notice">{{ issue.action }}: {{ issue.message }}</p>
          </PlanGroup>
          <PlanGroup title="Options" :summary="optionsSummary(drawerPlan)">
            <div v-for="dec in planDecisions(drawerPlan)" :key="dec.id" class="pd-choice-group" role="radiogroup" :aria-label="dec.question">
              <span class="pd-q">{{ dec.question }}</span>
              <label v-for="o in dec.options" :key="o.id" :class="['pd-card', { 'is-on': draftFor(drawerPlan).choices[dec.id] === o.id, 'is-off': !planEditable(drawerPlan) }]"><input v-model="draftFor(drawerPlan).choices[dec.id]" class="sr-only" type="radio" :name="`${drawerPlan.id}-${dec.id}`" :value="o.id" :disabled="!planEditable(drawerPlan)" /><span class="pd-card-top"><span class="pd-card-label">{{ o.label }}</span><span :class="['pd-cost-tag', { 'is-paid': o.kind === 'media' }]">{{ o.kind === 'media' ? `~${o.credits} cr` : 'Included' }}</span></span><span v-if="o.detail" class="pd-card-detail">{{ o.detail }}</span></label>
            </div>
            <div v-if="drawerPlan.plan.kept_as_is.length" class="pd-choice-group"><span class="pd-q">Kept as-is</span>
              <label v-for="k in drawerPlan.plan.kept_as_is" :key="k" class="pd-check"><input type="checkbox" :checked="draftFor(drawerPlan).kept.includes(k)" :disabled="!planEditable(drawerPlan)" @change="toggleKept(drawerPlan, k)" /> <span>{{ k }}</span></label>
            </div>
            <div class="pd-choice-group" role="radiogroup" aria-label="Effort">
              <span class="pd-q">Effort</span>
              <label v-for="e in EFFORTS" :key="e.id" :class="['pd-card', { 'is-on': currentEffort === e.id, 'is-off': !planEditable(drawerPlan) }]"><input class="sr-only" type="radio" name="plan-effort" :value="e.id" :checked="currentEffort === e.id" :disabled="!planEditable(drawerPlan)" @change="setEffort(e.id)" /><span class="pd-card-top"><span class="pd-card-label">{{ e.label }}</span><span class="pd-cost-tag">{{ e.cost }}</span></span><span class="pd-card-detail">{{ e.detail }}</span></label>
            </div>
            <div v-if="!lookRequired(drawerPlan) && !drawerPlan.plan.mascot3d" class="pd-choice-group" role="radiogroup" aria-label="How to build it">
              <span class="pd-q">How to build it</span>
              <label v-for="o in LOOK_CHOICES" :key="o.id" :class="['pd-card', { 'is-on': lookChoice(drawerPlan) === o.id, 'is-off': !planEditable(drawerPlan) || !planDrafts[drawerPlan.id] }]"><input class="sr-only" type="radio" :name="`${drawerPlan.id}-look`" :value="o.id" :checked="lookChoice(drawerPlan) === o.id" :disabled="!planEditable(drawerPlan) || !planDrafts[drawerPlan.id]" @change="planDrafts[drawerPlan.id].look_first = o.id === 'look'" /><span class="pd-card-top"><span class="pd-card-label">{{ o.label }}</span></span><span class="pd-card-detail">{{ o.detail }}</span></label>
            </div>
            <div v-if="hasGenerated(drawerPlan)" class="pd-row" role="group" aria-label="Video quality"><span class="pd-q">Video quality</span>
              <span class="pd-seg"><button v-for="t in ['standard', 'premium']" :key="t" type="button" :aria-pressed="(drawerPlan.plan.selections.video_tier || 'standard') === t" :disabled="locked || !planEditable(drawerPlan)" @click="setVideoTier(drawerPlan, t)">{{ t === 'standard' ? 'Standard' : 'Premium' }}</button></span>
            </div>
            <div v-if="paid && planEditable(drawerPlan)" class="pd-row"><span class="pd-q">Variants</span>
              <span class="pd-seg"><button v-for="n in [1, 2, 3]" :key="n" type="button" :aria-pressed="variantCount === n" @click="variantCount = n">{{ n }}</button></span>
            </div>
            <PlanNote label="About these options" text="Premium uses Veo 3.1 where it fits. Each extra variant is a separate take on the same plan, with its own cost." />
          </PlanGroup>
          <PlanGroup v-if="drawerPlan.plan.asks?.length" title="Files we'd like" :summary="drawerPlan.plan.asks.length + ((drawerPlan.plan.asks.length === 1) ? ' file' : ' files')" :open="drawerPlan.plan.asks.some(a => askState(drawerPlan, a) === 'open')">
            <div v-for="a in drawerPlan.plan.asks" :key="a.id" class="pd-question">
              <p>{{ a.what }}</p>
              <span v-if="askState(drawerPlan, a) === 'open' && planEditable(drawerPlan)" class="pd-actions"><label class="btn btn--ghost btn--sm pd-upload" tabindex="0" role="button" :aria-disabled="!!askUploading" @keydown.enter.prevent="$event.currentTarget.querySelector('input').click()" @keydown.space.prevent="$event.currentTarget.querySelector('input').click()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6M4 20h16" /></svg>{{ askUploading === a.id ? 'Uploading…' : 'Upload' }}<input type="file" :accept="a.kind === 'recording' ? 'video/*' : 'image/*,video/*'" hidden :disabled="!!askUploading" @change="e => e.target.files[0] && answerAsk(drawerPlan, a, e.target.files[0])" /></label><button type="button" class="btn btn--ghost btn--sm" :disabled="!!askUploading" @click="skipAsk(drawerPlan, a)">{{ OURS_KINDS.includes(a.kind) ? 'Use ours' : 'Go without' }}</button></span>
              <div v-if="askState(drawerPlan, a) === 'open' && planEditable(drawerPlan) && brandFor(a).length" class="ask-brand">
                <span class="pd-q">Or pick from your brand library</span>
                <div class="ask-brand__items"><button v-for="b in brandFor(a)" :key="b.asset_id" type="button" class="ask-brand__item" :disabled="locked || !!askUploading" :title="b.title" @click="pickBrandForAsk(drawerPlan, a, b)"><img v-if="b.preview_url && b.asset_type === 'image'" :src="b.preview_url" alt="" /><span>{{ b.title }}</span></button></div>
              </div>
              <small v-if="askState(drawerPlan, a) !== 'open'" class="muted">{{ askState(drawerPlan, a) === 'uploaded' ? 'Added' : askState(drawerPlan, a) === 'skipped' ? (OURS_KINDS.includes(a.kind) ? 'Using ours' : 'Going without it') : '' }}</small>
              <PlanNote label="Why" :text="a.why + ' Without it: ' + a.fallback" />
            </div>
          </PlanGroup>
        </template>
        <template #footer>
          <template v-if="drawerPlan">
            <PlanNote v-if="planEditable(drawerPlan)" class="pd-next" :label="startsWithCharacter(drawerPlan) ? 'Character, then storyboard, then the video' : storyboardFirst(drawerPlan) ? 'Storyboard first, then the video' : 'What happens next'" :text="nextStep(drawerPlan)" />
            <span class="pd-cost">{{ !isLivePlan(drawerPlan) ? 'View only' : planDirty(drawerPlan) ? 'Unsaved changes' : planPrice[drawerPlan.id]?.quote ? `${priceText(planPrice[drawerPlan.id].quote)} · ${ceilingText(planPrice[drawerPlan.id].quote)}` : '' }}</span>
            <span class="spacer" />
            <button v-if="planEditable(drawerPlan) && planDirty(drawerPlan)" type="button" class="btn btn--ghost btn--sm" :disabled="locked" @click="guarded(() => savePlanEdits(drawerPlan))">Save</button>
            <button type="button" class="btn btn--ghost btn--sm" @click="planDrawerId = null">Close</button>
            <button v-if="planEditable(drawerPlan)" type="button" class="btn btn--primary btn--sm" :disabled="locked || approvingPlan || approveBlocked(drawerPlan)" @click="approvePlan(drawerPlan)">{{ approvingPlan ? 'Starting…' : approveLabel(drawerPlan) }}</button>
          </template>
        </template>
      </SideDrawer>
      <SideDrawer :open="stepDrawer?.step === 'character' && !!stepPlan && !!characterStep(stepPlan)" title="Character" meta="The people and places in your video" @close="stepDrawer = null">
        <template v-if="stepPlan && characterStep(stepPlan)">
          <template v-for="(sub, k) in characterStep(stepPlan).subjects" :key="sub.name">
            <div v-if="k === subjectAt" class="sb-frame">
              <div class="sb-stage">
                <img v-for="img in characterStep(stepPlan).images.filter(i => i.label === sub.name)" :key="img.asset_id" :src="img.preview_url" :alt="sub.name" :class="{ 'is-redrawing': isRedrawing(stepPlan, sub.name) }" />
                <div v-if="isRedrawing(stepPlan, sub.name)" class="sb-busy" role="status"><span class="sb-spin" aria-hidden="true" />Redrawing {{ sub.name }}… about half a minute</div>
                <button v-if="characterStep(stepPlan).subjects.length > 1" type="button" class="sb-nav sb-nav--prev" aria-label="Previous" :disabled="subjectAt === 0" @click="subjectAt--"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg></button>
                <button v-if="characterStep(stepPlan).subjects.length > 1" type="button" class="sb-nav sb-nav--next" aria-label="Next" :disabled="subjectAt >= characterStep(stepPlan).subjects.length - 1" @click="subjectAt++"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg></button>
              </div>
              <div class="cs-head"><b>{{ sub.name }}</b><span class="muted">{{ sub.kind === 'place' ? 'Place' : sub.kind === 'product' ? 'Product' : 'Person' }} · {{ k + 1 }} of {{ characterStep(stepPlan).subjects.length }}</span></div>
              <label class="cs-look"><span class="pd-q">Look</span><textarea v-model="looksDraft[sub.name]" class="input pd-input" rows="3" maxlength="240" :disabled="!stepEditable(stepPlan) || isRedrawing(stepPlan, sub.name)" :aria-label="`How ${sub.name} looks`" /></label>
            </div>
          </template>
          <div v-if="characterStep(stepPlan).subjects.length > 1" class="sb-thumbs"><button v-for="(sub, k) in characterStep(stepPlan).subjects" :key="sub.name" type="button" :class="{ 'is-on': k === subjectAt, 'has-note': subjectChanged(stepPlan, sub.name) }" :aria-label="sub.name" @click="subjectAt = k"><img v-for="img in characterStep(stepPlan).images.filter(i => i.label === sub.name).slice(0, 1)" :key="img.asset_id" :src="img.preview_url" alt="" /></button></div>
          <PlanNote label="How changes work" text="Each person and place is drawn on its own, in the video's style. Change one and redraw: only that one is drawn again, and the storyboard frames it appears in are redrawn after you approve it." />
        </template>
        <template #footer>
          <template v-if="stepPlan && characterStep(stepPlan)">
            <span class="pd-cost">{{ looksChanged(stepPlan) ? `Redraw ${looksChanged(stepPlan)} · about ${looksChanged(stepPlan) * 35} cr` : characterStep(stepPlan).approved ? 'Approved' : stepPriceText(stepPlan, 'character') }}</span>
            <span class="spacer" />
            <button v-if="stepEditable(stepPlan) && looksChanged(stepPlan)" type="button" class="btn btn--ghost btn--sm" :disabled="locked || stepBusy || !!active" @click="redrawCharacter(stepPlan)">Redraw</button>
            <button type="button" class="btn btn--ghost btn--sm" @click="stepDrawer = null">Close</button>
            <button v-if="stepEditable(stepPlan) && !characterStep(stepPlan).approved && !looksChanged(stepPlan)" type="button" class="btn btn--primary btn--sm" :disabled="locked || stepBusy || stepPriceLoading(stepPlan, 'character')" @click="approveStepNow(stepPlan, 'character')">{{ stepBusy ? 'Starting…' : stepApproveLabel(stepPlan, 'character') }}</button>
          </template>
        </template>
      </SideDrawer>
      <SideDrawer :open="stepDrawer?.step === 'storyboard' && !!stepPlan && !!storyboardStep(stepPlan)" title="Storyboard" meta="Still frames, no motion or sound yet" @close="stepDrawer = null">
        <template v-if="stepPlan && storyboardStep(stepPlan)">
          <div class="sb-frame">
            <div class="sb-stage">
              <img :src="storyboardStep(stepPlan).images[frameAt]?.preview_url" :alt="storyboardStep(stepPlan).images[frameAt]?.label" />
              <button type="button" class="sb-nav sb-nav--prev" aria-label="Previous frame" :disabled="frameAt === 0" @click="frameAt--"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg></button>
              <button type="button" class="sb-nav sb-nav--next" aria-label="Next frame" :disabled="frameAt >= storyboardStep(stepPlan).images.length - 1" @click="frameAt++"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg></button>
            </div>
            <span class="pd-q">Frame {{ frameAt + 1 }} of {{ storyboardStep(stepPlan).images.length }}</span>
          </div>
          <p class="sb-shot">{{ frameShot(stepPlan, frameAt) }}</p>
          <p v-if="frameIssue(stepPlan, frameAt)" class="notice">{{ frameIssue(stepPlan, frameAt) }}</p>
          <label class="cs-look"><span class="pd-q">Change this frame</span><textarea v-model="framesDraft[frameAt + 1]" class="input pd-input grow" rows="2" maxlength="240" placeholder="e.g. phone in her left hand" :disabled="!stepEditable(stepPlan)" :aria-label="`Change frame ${frameAt + 1}`" /></label>
          <div class="sb-thumbs"><button v-for="(img, k) in storyboardStep(stepPlan).images" :key="img.asset_id" type="button" :class="{ 'is-on': k === frameAt, 'has-note': (framesDraft[k + 1] || '').trim() }" :aria-label="`Frame ${k + 1}`" @click="frameAt = k"><img :src="img.preview_url" alt="" /></button></div>
          <PlanNote label="How changes work" text="Only the frames you change are redrawn. The video is made from these frames: each shot starts from its frame." />
        </template>
        <template #footer>
          <template v-if="stepPlan && storyboardStep(stepPlan)">
            <span class="pd-cost">{{ framesChanged(stepPlan) ? `Redraw ${framesChanged(stepPlan)} · about ${framesChanged(stepPlan) * 35} cr` : storyboardStep(stepPlan).approved ? 'Approved' : stepPriceText(stepPlan, 'storyboard') }}</span>
            <span class="spacer" />
            <button v-if="stepEditable(stepPlan) && framesChanged(stepPlan)" type="button" class="btn btn--ghost btn--sm" :disabled="locked || stepBusy" @click="redrawFrames(stepPlan)">Redraw</button>
            <button type="button" class="btn btn--ghost btn--sm" @click="stepDrawer = null">Close</button>
            <button v-if="stepEditable(stepPlan) && !storyboardStep(stepPlan).approved && !framesChanged(stepPlan)" type="button" class="btn btn--primary btn--sm" :disabled="locked || stepBusy || stepPriceLoading(stepPlan, 'storyboard')" @click="approveStepNow(stepPlan, 'storyboard')">{{ stepBusy ? 'Starting…' : stepApproveLabel(stepPlan, 'storyboard') }}</button>
          </template>
        </template>
      </SideDrawer>
      <SideDrawer :open="versionOpen && !!currentRevision" title="This version" :meta="currentRevision ? 'Version ' + currentRevision.number + (versionPills.length ? ' · ' + versionPills.join(' · ') : '') : ''" @close="versionOpen = false">
        <template v-if="currentRevision">
          <PlanGroup v-for="(g, i) in summarySections(currentRevision.summary).groups" :key="g.title" :title="g.title" :open="i < 2"><div class="result-more" v-html="g.html" /></PlanGroup>
          <PlanGroup v-if="!outputMeta.look && (delivery_checks || outputMeta.final_checks || reviewNotes.length || (outputMeta.media_suggestions || []).length || outputMeta.creative_review?.status === 'ready')" title="Checks" :summary="checkIssues.length || finalFails.length ? 'needs a look' : 'all clear'" :open="!!(checkIssues.length || finalFails.length)">
                <div v-if="delivery_checks && !outputMeta.look" class="checks" role="status" aria-label="Before you post">
                  <b>{{ checkIssues.length ? 'Before you post' : 'Delivery checks complete' }}</b>
                  <ul>
                    <li v-for="(t, i) in checkIssues" :key="i" class="checks__warn">{{ t }}</li>
                    <li v-if="delivery_checks.loudness?.status === 'levelled'">Sound level adjusted for social playback.</li>
                    <li v-else-if="delivery_checks.loudness?.status === 'ok'">Sound level is right for social.</li>
                    <li v-if="!checkIssues.length">Text clears the platform buttons and captions, stays inside the frame and is readable.</li>
                  </ul>
                </div>
                <p v-if="outputMeta.creative_review?.status === 'ready' && !outputMeta.look" class="muted" role="status">Checks passed. It's ready for your review: tell us anything you'd like changed.</p>
                <div v-if="outputMeta.final_checks && !outputMeta.look && (finalFails.length || finalUnverified.length)" :class="['checks', outputMeta.final_checks.status === 'blocked' ? 'checks--blocked' : '']" role="status">
                  <b>{{ outputMeta.final_checks.status === 'blocked' ? 'Not ready yet: this version misses something you approved' : 'Checked against your plan' }}</b>
                  <ul>
                    <li v-for="c in finalFails" :key="c.id + c.label" :class="c.blocking ? 'checks__warn' : ''">{{ checkTime(c) }}{{ c.label }}<template v-if="c.message">: {{ c.message }}</template></li>
                    <li v-for="c in finalUnverified" :key="'u' + c.id + c.label" class="muted">Not checked automatically: {{ c.label }}<template v-if="c.message"> ({{ c.message }})</template></li>
                  </ul>
                  <p v-if="outputMeta.final_checks.status === 'blocked'" class="muted">Use “Keep improving” to fix these in a new version; this one stays saved.</p>
                </div>
                <div v-if="(outputMeta.media_suggestions || []).length && !outputMeta.look && !isOldRevision && canWrite && paid" class="checks" role="status">
                  <b>A video model declined some shots</b>
                  <p v-for="sg in outputMeta.media_suggestions" :key="sg.shot" class="suggestion-line">
                    Shot {{ sg.shot }} was declined by {{ sg.declined_by || 'the video model' }}.
                    <button type="button" class="btn btn--ghost btn--sm" :disabled="locked || active" @click="useEngineFor(sg)">Make it on {{ sg.label }} · {{ sg.credits }} cr</button>
                  </p>
                </div>
                <div v-if="outputMeta.creative_review && !['passed', 'ready', 'blocked'].includes(outputMeta.creative_review.status) && (reviewNotes.length || outputMeta.look)" class="checks" role="status">
                  <b>{{ outputMeta.look ? 'Notes for the next round' : outputMeta.creative_review.status === 'issues' ? 'Things to check in this version' : 'Ideas for the next version' }}</b>
                  <ul v-if="reviewNotes.length"><li v-for="(note, i) in reviewNotes" :key="i">{{ note }}</li></ul>
                  <p v-else>Look over the stills and tell us what to change, or build the full video.</p>
                </div>
          </PlanGroup>
          <PlanGroup v-if="!outputMeta.look && canWrite && !conversation?.archived_at" title="Edit" summary="text, colours, or a prompt">
            <div class="result-actions">
              <button v-if="canWrite && !conversation.archived_at && !active && editableFields.length" type="button" class="btn btn--ghost" :aria-expanded="leversOpen" @click="openLevers">Edit text and colours <span class="tier tier--free">FREE</span></button>
              <button v-if="paid && !isOldRevision" type="button" class="btn btn--ghost" @click="editResult">Edit with a prompt</button>
            </div>
                <div v-if="leversOpen" class="levers">
                  <div class="levers__grid">
                    <label v-for="f in editableFields" :key="f.id" :class="['field-label', { 'levers__wide': !['color', 'enum', 'number'].includes(f.type) }]">{{ f.label.toUpperCase() }}
                      <span v-if="f.type === 'color'" class="colour"><input v-model="leverDraft[f.id]" type="color" :aria-label="f.label" /><input v-model="leverDraft[f.id]" class="input" maxlength="7" :aria-label="`${f.label} hex`" /></span>
                      <select v-else-if="f.type === 'enum'" v-model="leverDraft[f.id]" class="input"><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
                      <input v-else-if="f.type === 'number'" v-model="leverDraft[f.id]" class="input" type="number" />
                      <textarea v-else v-model="leverDraft[f.id]" class="input grow" rows="2" maxlength="200" />
                    </label>
                  </div>
                  <div class="levers__foot">
                    <span class="muted">{{ isOldRevision ? 'Makes a new version from this one; the current version stays as it is.' : 'Makes a new version. No model call, no credits.' }} Size changes need the assistant to re-lay the design; ask in the chat.</span>
                    <button type="button" class="btn btn--ok btn--sm" :disabled="locked || !Object.keys(leverChanges).length" @click="applyLevers">Apply as a new version <span class="sub">free</span></button>
                  </div>
                </div>
                <form v-if="paid && canWrite && !conversation.archived_at" class="note-form" @submit.prevent="saveNote">
                  <textarea v-model="noteText" class="input grow" rows="2" maxlength="400" placeholder="What worked, what to change next time (kept for this style)" aria-label="Note for this style" />
                  <button type="submit" class="btn btn--ghost btn--sm" :disabled="!noteText.trim()">Save note</button>
                  <small v-if="noteSaved" class="muted">Saved for {{ noteSaved }}</small>
                </form>
          </PlanGroup>
          <PlanGroup v-if="!outputMeta.look" title="Share and save">
            <div class="result-actions">
              <button v-if="!outputMeta.look && canWrite && !isOldRevision && !conversation.archived_at" type="button" class="btn btn--outline" :disabled="locked || !!currentRevision.output_asset_id" @click="saveOutput">{{ currentRevision.output_asset_id ? (imageOutput ? 'Saved to Assets' : 'Saved to videos') : (imageOutput ? 'Save to Assets' : paid ? 'Save to videos' : 'Save sample to videos') }}</button>
              <button v-if="!outputMeta.look && canWrite && !conversation.archived_at && currentRevision.output_asset_id" type="button" class="btn btn--outline" @click="requestDelivery('share')">Share link</button>
              <button v-if="canWrite && !conversation.archived_at && currentRevision.share_enabled" type="button" class="btn btn--ghost" @click="requestDelivery('unshare')">Turn off share link</button>
              <button v-if="!outputMeta.look && canWrite && !conversation.archived_at && !imageOutput && currentRevision.export_job_id" type="button" class="btn btn--outline" @click="requestDelivery('schedule')">Schedule post</button>
              <button v-if="paid && imageOutput && currentRevision.output_asset_id && canWrite && !conversation.archived_at" type="button" class="btn btn--ghost" :disabled="locked" @click="animateResult">Animate image</button>
              <button v-if="!outputMeta.look && !imageOutput && media" type="button" class="btn btn--ghost btn--safe" :aria-pressed="safeZones" @click="safeZones = !safeZones">Show safe margins</button>
            </div>
          </PlanGroup>
        </template>
        <template #footer>
          <span class="spacer" />
          <button type="button" class="btn btn--ghost btn--sm" @click="versionOpen = false">Close</button>
          <button v-if="media && !outputMeta.look" type="button" class="btn btn--primary btn--sm" @click="download">Download</button>
        </template>
      </SideDrawer>
      <CreateDialog :open="approvalOpen" title="Send for approval" @close="approvalOpen = false">
        <form v-if="!approvalResult" class="link-form" @submit.prevent="sendApproval">
          <label class="link-form__label">Reviewer's email<input v-model="approvalForm.email" class="input" type="email" required maxlength="190" autocomplete="email" /></label>
          <label class="link-form__label">Name (optional)<input v-model="approvalForm.name" class="input" maxlength="120" /></label>
          <label class="link-form__label">Message (optional)<textarea v-model="approvalForm.message" class="input" rows="3" maxlength="1000" /></label>
          <p class="muted link-form__note">They get a link to watch version {{ currentRevision?.number }} and approve it or ask for changes. The link works for 7 days.</p>
          <p v-if="approvalError" class="create-error" role="alert">{{ approvalError }}</p>
          <div class="link-form__actions"><button type="button" class="btn btn--ghost btn--sm" @click="approvalOpen = false">Cancel</button><button type="submit" class="btn btn--primary btn--sm" :disabled="approvalSending || !approvalForm.email.trim()">{{ approvalSending ? 'Sending…' : 'Send' }}</button></div>
        </form>
        <div v-else class="link-form">
          <p>Sent to {{ approvalForm.email }}. You can also share this review link:</p>
          <input class="input" :value="approvalResult" readonly aria-label="Review link" @focus="$event.target.select()" />
          <div class="link-form__actions"><button type="button" class="btn btn--primary btn--sm" @click="approvalOpen = false">Done</button></div>
        </div>
      </CreateDialog>
      <CreateDialog :open="libraryOpen" title="Add from your library" @close="libraryOpen = false">
        <form class="library-search" @submit.prevent="libraryPage = 1; loadLibrary()"><input v-model="librarySearch" class="input" type="search" aria-label="Search library" placeholder="Find a photo, video or audio file…" /><button type="submit" class="btn btn--ghost btn--sm">Search</button></form>
        <div class="library-grid"><button v-for="a in library" :key="a.id" type="button" :disabled="locked" @click="attach(a)"><img v-if="a.asset_type === 'image' && a.storage_url" :src="a.storage_url" alt="" /><span v-else class="file-symbol">{{ a.asset_type === 'video' ? '▷' : '♫' }}</span><strong>{{ a.title || a.asset_type }}</strong><small>{{ a.asset_type }}</small></button></div><p v-if="!library.length" class="muted">No matching media. Attach files directly in the composer.</p><div class="row-actions"><button type="button" class="btn btn--ghost btn--sm" :disabled="libraryPage <= 1" @click="libraryPage--; loadLibrary()">Previous</button><span>{{ libraryPage }} / {{ libraryLastPage }}</span><button type="button" class="btn btn--ghost btn--sm" :disabled="libraryPage >= libraryLastPage" @click="libraryPage++; loadLibrary()">Next</button></div><p v-if="error" class="create-error" role="alert">{{ error }}</p>
      </CreateDialog>
      <CreateDialog :open="compareOpen" title="Compare versions" @close="compareOpen = false"><div class="comparison"><section><h3>Version {{ currentRevision?.number }} · Earlier</h3><StoryboardCarousel v-if="media && outputMeta.look" :src="media" />
                  <img v-else-if="media && imageOutput" :src="media" class="created-image" alt="Earlier image" /><FinishedVideoPlayer v-else-if="media" :src="media" /></section><section><h3>Version {{ currentNumber }} · Current</h3><img v-if="compareMedia && imageOutput" :src="compareMedia" class="created-image" alt="Current image" /><FinishedVideoPlayer v-else-if="compareMedia" :src="compareMedia" /><p v-else>Loading current version…</p></section></div><p class="muted">Inspect each version to compare. This does not change the current version.</p></CreateDialog>
    </main>
  </div>
</template>

<style scoped>
.character-review-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}.character-review-grid figure{margin:0}.character-review-grid img{width:100%;aspect-ratio:1;object-fit:contain;border-radius:12px;background:#111}.character-review-grid figcaption{font-size:12px;color:var(--color-text-secondary);margin-top:6px}
.character-approval-inline .character-review-grid{grid-template-columns:repeat(auto-fit,minmax(110px,160px));margin:10px 0}
.character-review-grid figure .input{width:100%;margin-top:4px;font-size:12px}
.panel-issue{color:var(--color-warning, #b45309)}
.suggestion-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:4px 0}
.checks--blocked{border-color:var(--color-warning, #b45309)}

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
.ceiling-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;margin:6px 0}.ceiling-line .input--sm{width:96px;padding:4px 8px}
.review-line{margin:10px 14px 0;font-size:12px}.review-line b{margin-left:8px;font-weight:600}.review-line b.low{color:#e07b39}
.note-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px 0}.note-form .input{flex:1;min-width:220px}
.style-notes{margin:4px 0 8px;padding-left:18px;font-size:12px;color:var(--text-2)}.style-notes li{margin:2px 0}
.look-note{margin:10px 14px 0;padding:10px 12px;border-radius:10px;background:var(--bg-2);font-size:13px}.look-first{margin:6px 0}.look-first input{width:auto}
.field-label--check{flex-direction:row;align-items:center;gap:8px}.field-label--check input{width:auto}
.beat__state,.beat__reads{display:block;font-size:11px;color:var(--text-3);line-height:1.35}.beat__reads{color:var(--text-2)}.plan-col--beats li{margin-bottom:4px}
.voice-pick{display:flex;gap:8px;align-items:center;font-size:12px;margin-top:4px}.style-line{flex-wrap:wrap;margin:8px 0}.voice-pick select{background:transparent;border:1px solid var(--line-2);color:var(--text-2);border-radius:8px;padding:4px 8px;font-size:12px}
.pron-row{display:grid;grid-template-columns:1fr auto 1fr auto;gap:8px;align-items:center}
.ref-note{display:block;font-size:11px;color:var(--text-3);margin-top:2px;max-width:420px}
.fc-shell{--surface:#17171f;--bg:#0b0d11;--bg-2:#0f1116;--bg-3:#14171d;--bg-4:#191d24;--bg-5:#111419;--line:#1f232b;--line-2:#262b34;--line-3:#2c313b;--text:#eceef1;--text-2:#b7bcc6;--text-3:#8f95a1;--text-4:#5d6472;--accent:var(--color-accent,#ff6b35);--accent-ink:#0b0d11;--accent-soft:rgba(255,107,53,.12);--accent-line:rgba(255,107,53,.35);--warn:#e3b64a;--warn-soft:rgba(227,182,74,.14);--warn-line:rgba(227,182,74,.35);--warn-bg:#16150f;--warn-edge:#3a3320;--ok:#4dc48a;--ok-soft:rgba(77,196,138,.10);--ok-line:rgba(77,196,138,.35);--info:#5b9dff;--info-soft:rgba(91,157,255,.12);--info-line:rgba(91,157,255,.35);--mono:"JetBrains Mono","Space Mono",ui-monospace,Menlo,monospace;--r:8px;--r-md:10px;--r-lg:12px;min-height:100vh;background:var(--bg);color:var(--text)}
.agent-main{margin-left:var(--sidebar-width,220px);height:100dvh;display:flex;flex-direction:column;min-width:0}
button{font:inherit;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.55}
button:focus-visible,a:focus-visible,textarea:focus-visible,input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.muted{color:var(--text-3)}
.agent-header{display:flex;align-items:center;gap:14px;padding:0 28px;min-height:64px;border-bottom:1px solid var(--line);flex-wrap:nowrap;flex-shrink:0}
.agent-header__title{flex:1 1 auto;min-width:0}
.agent-header .status{flex:0 0 auto;white-space:nowrap}
.agent-header h1{margin:0;font-size:16px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crumb{font-size:13px;color:var(--text-3)}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:nowrap;flex:0 0 auto}.header-actions>*{white-space:nowrap}
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
/* The user's message: the create-ui chat bubble (flat, no border); with files, a pill row sits above the text. */
.user-message{align-self:flex-end;max-width:75%;min-width:0;background:var(--surface);border-radius:12px;padding:10px 16px;font-size:14px;line-height:1.55;color:#ececec;overflow-wrap:anywhere}
.user-message.has-refs{padding:14px 20px 16px;display:flex;flex-direction:column;gap:10px}
.user-message p{margin:0;white-space:pre-wrap}
.thumb--video{background:#3a2f4a}.thumb--image{background:#2a3441}.thumb--audio{background:#243a33}
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
.quote__line > span{display:flex;flex-direction:column;gap:2px;min-width:0}
.agreement{border:1px solid var(--line, rgba(127,127,127,.25));border-radius:10px;padding:10px 12px;margin:8px 0;font-size:13px}
.agreement__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.agreement__cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.agreement__col ul{margin:4px 0 0;padding-left:16px}
.agreement__col textarea{width:100%;margin-top:4px;font-size:12px}
.quote__meta{font-size:11px;color:var(--text-3);opacity:.85}
.tier-pick{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px}
.tier-pick .is-on{color:var(--text);font-weight:600;text-decoration:underline}
.spend-guard{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text);width:100%}
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
.prompt-form{display:flex;flex-direction:column;gap:10px}
.prompt-form .composer-bottom{margin-top:0}
.tray-notes{display:flex;flex-direction:column;gap:4px}
.tray-note{margin:0;font-size:12px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tray-note b{font-weight:600;color:var(--text-2)}
.tray-note--error{color:var(--danger,#f0857a);white-space:normal}
label.tray-note{white-space:normal}
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
#details-panel{display:flex;flex-direction:column;margin:-6px -18px -18px}
.panel-scrim{display:none}
.panel-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:16px 18px 0}
.panel-heading h2{margin:0;font-size:15px;outline:none}
.panel-heading .quiet{padding:4px 10px;font-size:16px}
.detail-tabs{display:flex;gap:6px;padding:4px 18px 0;border-bottom:1px solid var(--line);position:sticky;top:-6px;background:var(--surface);z-index:1}
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

@media(max-width:860px){
  .agent-main{margin-left:0;padding-top:calc(52px + env(safe-area-inset-top));height:calc(100dvh - 66px)}
  .agent-header{padding:10px 14px;gap:8px;flex-wrap:wrap}
  .agent-header h1{font-size:15px;max-width:70vw}
  .header-actions{margin-left:0;width:100%}
  .messages{padding:20px 14px}
  .composer-dock{padding:8px 12px 12px}
  .examples,.comparison,.plan-cols,.levers__grid{grid-template-columns:1fr}
  .library-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .result__meta .status{margin-left:0}
  .panel-scrim{display:block;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:24}
  .composer-note{display:none}
  .credits{display:none}
}

/* The plan card and its drawer (create-ui chat mockup). */
.plan-lead{margin:0;color:#ececec}
.plan-approach{margin-top:-4px}
.plan-card{border:1px solid var(--line-3);border-radius:12px;padding:10px 12px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;background:var(--surface)}
.plan-card.is-old{opacity:.7}
.plan-pills{display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-width:0}
.plan-title{font-weight:600;margin-right:4px}
.pill{font-size:12px;color:var(--text-2);background:rgba(255,255,255,.06);border-radius:999px;padding:2px 10px}
.pill.is-edited{color:#f26b3a;background:#3a2a22}
.plan-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.plan-status{font-size:12px;color:#9b9b9b}
.pd-question{display:flex;flex-direction:column;gap:6px}.pd-question p{margin:0}
.pd-answer{display:flex;gap:6px}
.pd-input{flex:1;min-width:0;padding:6px 10px;font-size:13px;border-radius:8px}
.pd-line{display:flex;flex-direction:column;gap:4px}
.pd-line .grow{width:100%}
.pd-line-head{display:flex;align-items:center;gap:6px}.pd-line-head .pd-x{margin-left:auto}
.pd-flag{font:10px var(--mono);color:#f5a524;flex:0 0 auto}
.pd-x{flex:0 0 auto;width:24px;height:24px;border:0;border-radius:5px;background:transparent;color:#8e8e8e;cursor:pointer;font-size:15px;line-height:1}
.pd-x:hover{background:#2e2e2e;color:#ececec}
.pd-add{align-self:flex-start;border:0;background:transparent;color:#a7abb2;font-size:12px;padding:2px 0;cursor:pointer}
.pd-add:hover{color:#ececec}
.pd-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.pd-field{display:flex;align-items:center;gap:8px;color:#8e8e8e;font-size:12px;min-width:0}
.pd-select{background:#262626;border:1px solid #383838;color:#ececec;border-radius:7px;padding:4px 8px;font-size:12px;max-width:220px}
.pd-dots{display:inline-flex;gap:3px;flex:0 0 auto}.pd-dots i{width:10px;height:10px;border-radius:50%;border:1px solid #ffffff30;display:block}
.pd-colours{display:flex;flex-wrap:wrap;gap:10px 14px}
.pd-colour{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#c9cbcf;text-transform:capitalize}
.pd-colour input[type=color]{width:22px;height:22px;border:0;padding:0;background:none;cursor:pointer}
.pd-swatch{width:18px;height:18px;border-radius:50%;border:1px solid #ffffff30;display:inline-block}
.pd-mascot{display:flex;flex-direction:column;gap:6px}
.pd-check{display:flex;align-items:center;gap:8px;font-size:13px}.pd-check small{color:#8e8e8e}
.pd-choice-group{display:flex;flex-direction:column;gap:6px}
.pd-q{font-size:12px;color:#8e8e8e}
.pd-card{display:flex;flex-direction:column;gap:2px;padding:9px 11px;border:1px solid var(--line-3);border-radius:10px;background:var(--bg-3);cursor:pointer;transition:border-color .12s,background .12s}
.pd-card:hover{border-color:var(--text-4)}
.pd-card.is-on{border-color:var(--accent-line);background:var(--accent-soft)}
.pd-card.is-off{cursor:default}
.pd-card:has(input:focus-visible){outline:2px solid var(--accent);outline-offset:2px}
.pd-card-top{display:flex;align-items:center;gap:8px}
.pd-card-label{flex:1;min-width:0;font-weight:600}
.pd-card-detail{font-size:12px;color:var(--text-3);line-height:1.4}
.pd-cost-tag.is-paid{color:var(--accent)}
.pd-btn{gap:6px}
.pd-cost-tag{font:11px var(--mono);color:#8e8e8e;flex:0 0 auto}
.pd-seg{display:inline-flex;border:1px solid #383838;border-radius:7px;overflow:hidden}
.pd-seg button{border:0;background:transparent;color:#a7abb2;font-size:12px;padding:4px 10px;cursor:pointer}
.pd-seg button[aria-pressed=true]{background:#2e2e2e;color:#ececec}
.pd-cost{font-size:12px;color:#8e8e8e}
/* Anything the user writes in their own words is a text area that grows with what they write. */
.levers__wide{grid-column:1/-1}
.note-form{flex-direction:column;align-items:stretch}.note-form .btn{align-self:flex-start}
.grow{width:100%;box-sizing:border-box;field-sizing:content;min-height:2.3em;max-height:14em;resize:vertical;font:inherit;font-size:13px;line-height:1.45}
.sb-stage{position:relative;border-radius:10px;overflow:hidden;background:var(--bg-3)}
.sb-stage img{display:block}
.sb-stage img.is-redrawing{opacity:.35;filter:blur(2px)}
.sb-nav{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.25);background:rgba(10,12,16,.72);color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.45);transition:background .12s,transform .12s}
.sb-nav:hover:not(:disabled){background:rgba(10,12,16,.92);transform:translateY(-50%) scale(1.06)}
.sb-nav:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sb-nav:disabled{opacity:.3;cursor:default}
.sb-nav--prev{left:10px}.sb-nav--next{right:10px}
.sb-busy{position:absolute;inset:auto 0 0 0;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;font-size:13px;color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.75))}
.sb-spin{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;animation:sbspin .8s linear infinite}
@keyframes sbspin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.sb-spin{animation:none}.sb-nav{transition:none}}
/* The player gets the room its shape needs; the details sit beside it, or under it when there is no room. */
.result-card{--player-w:340px;display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;border:1px solid var(--line-3);border-radius:14px;padding:14px;background:var(--surface)}
.result-card--square{--player-w:420px}.result-card--feed{--player-w:380px}.result-card--landscape{--player-w:100%}
.result-card .result__stage{flex:0 0 auto;width:min(100%,var(--player-w));padding:0;min-height:0;background:none}
.result-card .result__stage .player-wrap{max-width:none;width:100%}
.result-side{flex:1 1 240px;display:flex;flex-direction:column;gap:10px;min-width:240px}
.result-title{font-weight:600;font-size:15px}
.result-pills{display:flex;flex-wrap:wrap;gap:6px}
.pill--ok{color:var(--ok);background:var(--ok-soft)}
.result-actions{display:flex;flex-wrap:wrap;gap:8px}
.result-hint{margin:0;font-size:12px;color:var(--text-3)}
.result-share{color:var(--ok);overflow-wrap:anywhere}

.fail{border:1px solid rgba(242,107,58,.35);border-radius:12px;padding:12px 14px;background:rgba(242,107,58,.06);display:flex;flex-direction:column;gap:8px}
.fail-head{margin:0;display:flex;align-items:baseline;gap:8px}.fail-glyph{color:var(--accent)}
.fail-sub{margin:0;font-size:13px;color:var(--text-2)}
.plan-files{flex:1 0 100%;display:flex;flex-wrap:wrap;gap:6px 12px;font-size:12px;color:var(--text-3)}
.plan-file{display:inline-flex;align-items:center;gap:6px;max-width:100%;min-width:0}
.plan-file b{color:var(--text-2);font-weight:600}
.plan-file__switch{border:0;background:none;color:var(--accent);font-size:12px;padding:0;cursor:pointer}
.plan-file__switch:hover{text-decoration:underline}
.ask-brand{display:flex;flex-direction:column;gap:6px}
.ask-brand__items{display:flex;gap:6px;flex-wrap:wrap}
.ask-brand__item{display:inline-flex;align-items:center;gap:6px;max-width:180px;padding:4px 8px 4px 4px;border:1px solid var(--line-3);border-radius:8px;background:var(--bg-3);color:var(--text-2);font-size:12px;cursor:pointer}
.ask-brand__item:hover{border-color:var(--accent-line)}
.ask-brand__item img{width:28px;height:28px;object-fit:cover;border-radius:5px}
.ask-brand__item span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pill--effort{color:var(--text-2);border:1px solid var(--line-3);background:transparent}
.spend-row{display:flex;justify-content:space-between;gap:10px;font-size:13px}.spend-row b{font-variant-numeric:tabular-nums}
.spend-row--total{border-top:1px solid var(--line-2);padding-top:6px;margin-top:2px}
.step{display:flex;flex-direction:column;gap:12px;margin-top:6px}
.step-thumbs{display:flex;gap:4px;flex:0 0 auto}.step-thumbs img{width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid var(--line-3)}
.cs-subject{display:flex;flex-direction:column;gap:8px;padding:12px 0;border-bottom:1px solid var(--line-2)}
.cs-image{width:100%;max-height:300px;object-fit:contain;border-radius:10px;background:var(--bg-3)}
.cs-head{display:flex;align-items:baseline;gap:8px}.cs-head .muted{font-size:12px}
.cs-look{display:flex;flex-direction:column;gap:4px}
.cs-look textarea{resize:vertical;font:inherit;font-size:13px}
.sb-frame{display:flex;flex-direction:column;gap:6px;padding-top:10px}
.sb-frame img{width:100%;max-height:52vh;object-fit:contain;border-radius:10px;background:var(--bg-3)}
.sb-bar{display:flex;align-items:center;justify-content:space-between}
.sb-shot{margin:2px 0 0;font-size:13px;color:var(--text-2)}
.sb-thumbs{display:flex;gap:6px;overflow-x:auto;padding:4px 0}
.sb-thumbs button{flex:0 0 auto;padding:0;border:2px solid transparent;border-radius:7px;background:none;cursor:pointer;position:relative}
.sb-thumbs button.is-on{border-color:var(--accent)}
.sb-thumbs button.has-note::after{content:"";position:absolute;top:3px;right:3px;width:7px;height:7px;border-radius:50%;background:var(--accent)}
.sb-thumbs img{width:44px;height:64px;object-fit:cover;border-radius:5px;display:block}

.result-lead{margin:0}
.result-more{margin:6px 0 0 15px;display:flex;flex-direction:column;gap:6px;font-size:13px;line-height:1.55;color:var(--text-2)}
.result-more :deep(p){margin:0}
.result-more :deep(strong){color:var(--text)}
.pd-next{flex:1 0 100%}
.history-body{display:flex;flex-direction:column;gap:10px;padding-top:10px}
.history-body .drawer-top{display:block}.history-body .drawer-top .input{width:100%}
.history-sentinel{height:1px}
.question-hint{margin:0;font-size:12px;color:var(--text-3)}
.answer-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px}
.answer-card{text-align:left;font:inherit;color:inherit}
.pd-actions{display:flex;gap:8px;flex-wrap:wrap}
.pd-upload{cursor:pointer}
.pd-upload:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
</style>
