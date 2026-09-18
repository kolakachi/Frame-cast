<script setup>
import { computed, reactive, ref, watch } from 'vue'
import api from '../services/api'
import { useWorkspaceStore } from '../stores/workspace'
import { useAuthStore } from '../stores/auth'
import ConfirmDialog from './ConfirmDialog.vue'
const props = defineProps({ clientId: { type: Number, default: null } })
const emit = defineEmits(['changed'])
const store = useWorkspaceStore(), auth = useAuthStore()
const data = ref(null), loading = ref(true), error = ref(''), notice = ref(''), busy = ref(false), tab = ref('Overview')
const base = computed(() => props.clientId ? `/workspaces/clients/${props.clientId}` : '/client-work')
const tabs = computed(() => ['Overview', 'Client brief', 'Requests', 'Reviews', 'Delivery', ...(props.clientId ? ['Spending','Access & budget'] : [])])
const brief = reactive({}), request = reactive({title:'',brief:'',due_at:'',asset_ids:[]})
const delivery = reactive({title:'',message:'',export_ids:[],asset_ids:[],expires_in_days:30})
const fields = {audience:'Audience',goals:'Goals',products:'Products and offers',approved_claims:'Approved messaging and claims',restrictions:'Brand restrictions',preferences:'Creative preferences',pronunciations:'Pronunciation notes'}
let version = 0
async function load() {
  const v = ++version; loading.value = true; error.value = ''
  try {
    const r = await api.get(props.clientId ? `${base.value}/hub` : base.value)
    if (v !== version) return
    data.value = r.data.data
    for (const k of Object.keys(brief)) delete brief[k]
    Object.assign(brief, data.value.profile, { asset_ids: data.value.profile.asset_ids ?? [] })
  } catch(e) { if(v===version) error.value=e.response?.data?.message ?? e.response?.data?.error?.message ?? 'Could not load this client. Please retry.' }
  finally { if(v===version) loading.value=false }
}
async function action(fn, message) {
  if(busy.value) return
  busy.value=true; error.value=''; notice.value=''
  try { await fn(); notice.value=message; await load(); emit('changed') }
  catch(e) { error.value=e.response?.data?.message ?? e.response?.data?.error?.message ?? 'That change could not be saved. Please retry.' }
  finally { busy.value=false }
}
const pending = computed(() => (data.value?.approvals ?? []).filter(a=>a.status==='pending' && (!a.expires_at || new Date(a.expires_at)>new Date())))
const openRequests = computed(() => (data.value?.requests ?? []).filter(r=>!['delivered','cancelled'].includes(r.status)))
const approved = computed(() => (data.value?.exports ?? []).filter(e=>data.value.approvals.some(a=>a.export_job_id===e.id && a.status==='approved')))
const editors = computed(() => (data.value?.members ?? []).filter(m=>m.role!=='client'))
async function upload(event, target) {
  const file=event.target.files?.[0]; if(!file) return
  if(file.size>100*1024*1024){error.value='Choose a file under 100 MB.';return}
  busy.value=true;error.value=''
  try {
    const form=new FormData();form.append('asset_file',file);form.append('title',file.name);form.append('asset_type',file.type.startsWith('video/')?'video':file.type.startsWith('image/')?'image':file.type.startsWith('audio/')?'audio':'document')
    const asset=(await api.post(`${base.value}/attachments`,form)).data.data.asset
    data.value.assets.unshift({id:asset.id,name:asset.title || file.name,asset_type:asset.asset_type})
    target.asset_ids=[...new Set([...(target.asset_ids || []),asset.id])]
    notice.value='File uploaded and selected. Save the brief or submit the request to attach it.'
  } catch(e){error.value=e.response?.data?.message || 'Upload failed. Please retry.'} finally{busy.value=false;event.target.value=''}
}
const title = id => data.value?.projects.find(p=>p.id===id)?.title ?? `Project #${id}`
const due = d => d && new Date(`${d}T23:59:59`) < new Date()
function saveBrief() { return action(()=>api.put(`${base.value}/profile`,brief),'Client brief saved. It will inform new scripts.') }
function submitRequest() { return action(async()=>{await api.post(`${base.value}/requests`,{...request,due_at:request.due_at||null});Object.assign(request,{title:'',brief:'',due_at:'',asset_ids:[]})},'Request added.') }
function startRequest(row){return action(()=>api.post(`${base.value}/requests/${row.id}/start`),'Draft created. Open the project to produce the video; no generation credits were spent.')}
function updateRequest(row,field,value) { return action(()=>api.patch(`${base.value}/requests/${row.id}`,{[field]:value||null}),'Request updated.') }
async function open(path) {
  error.value=''
  try { await store.switchTo(data.value.workspace.id,path) } catch(e) {error.value=e.response?.data?.message ?? 'Could not open the workspace.'}
}
function deliver() { return action(async()=>{await api.post(`${base.value}/deliveries`,delivery);Object.assign(delivery,{title:'',message:'',export_ids:[],asset_ids:[],expires_in_days:30})},'Delivery link created. Copy it below to share.') }
const confirmation = ref(null)
function lifecycle(status) {
  confirmation.value={title:`${status==='active'?'Restore':status==='paused'?'Pause':'Archive'} client?`,message:status==='active'?'Members will regain access.':'Members will lose access while this client is inactive. Projects and allocated credits stay with the client. Use Access & budget first if you want to reclaim credits.',run:()=>action(()=>api.patch(base.value,{status}),'Client status updated.')}
}
async function confirm() { await confirmation.value.run(); confirmation.value=null }
async function copy(url) {try {await navigator.clipboard.writeText(url); notice.value='Link copied.'} catch {error.value='Could not copy. Open the link and copy its address.'} }
watch(()=>props.clientId,()=>{tab.value='Overview';notice.value='';load()},{immediate:true})
</script>
<template>
  <section class="hub" aria-label="Client workspace management">
    <div v-if="error" class="message error" role="alert">{{error}} <button @click="load">Retry</button></div>
    <div v-if="notice" class="message" role="status">{{notice}}</div>
    <p v-if="loading">Loading client workspace…</p>
    <template v-else-if="data">
      <nav class="tabs" aria-label="Client sections"><button v-for="t in tabs" :key="t" :class="{selected:tab===t}" :aria-current="tab===t?'page':undefined" @click="tab=t">{{t}}</button></nav>
      <div v-if="tab==='Overview'" class="panel">
        <div class="heading"><div><h2>{{data.workspace.name}}</h2><p>Work, feedback and delivery in one place.</p></div><span class="badge">{{data.workspace.status}}</span></div>
        <div class="stats"><div><strong>{{openRequests.length}}</strong>Open requests</div><div><strong>{{pending.length}}</strong>Awaiting approval</div><div><strong>{{data.projects.length}}</strong>Recent projects</div><div><strong>{{data.spending.credits.toLocaleString()}}</strong>Credits this month</div></div>
        <p v-if="data.spending.alert" class="message">This client has used at least 80% of its monthly credit cap.</p>
        <div v-if="data.can_produce && data.workspace.status==='active'" class="actions"><button @click="open('/dashboard')">Create or continue a video</button><button @click="open('/assets')">Open asset library</button><template v-if="auth.user?.is_internal"><button @click="open('/ugc-ads')">Create UGC</button><button @click="open('/from-my-footage')">Use my footage</button></template></div>
        <h3>Upcoming work</h3><p v-if="!openRequests.length" class="muted">No open requests. Add a brief in Requests to start work.</p>
        <div v-for="r in openRequests.slice(0,8)" :key="r.id" class="row"><strong>{{r.title}}</strong><span :class="{overdue:due(r.due_at)}">{{r.due_at || 'No due date'}} · {{r.status.replaceAll('_',' ')}}</span></div>
        <h3>Recent projects</h3><p v-if="!data.projects.length" class="muted">No projects yet.</p><button v-for="p in data.projects.slice(0,8)" :key="p.id" class="row full" @click="open(`/projects/${p.id}/editor`)"><strong>{{p.title}}</strong><span>{{p.status.replaceAll('_',' ')}} →</span></button>
        <h3>Activity</h3><p v-if="!data.activity.length" class="muted">New requests, brief changes and deliveries will appear here.</p><div v-for="(a,i) in data.activity" :key="i" class="row"><span>{{a.description}}</span><small>{{new Date(a.created_at).toLocaleString()}}</small></div>
        <div v-if="clientId" class="lifecycle"><h3>Client lifecycle</h3><p>Pausing or archiving preserves projects and credits. Archived clients no longer use an active client slot.</p><div class="actions"><button v-if="data.workspace.status!=='active'" @click="lifecycle('active')">Restore access</button><button v-if="data.workspace.status==='active'" @click="lifecycle('paused')">Pause access</button><button v-if="data.workspace.status!=='archived'" @click="lifecycle('archived')">Archive client</button><button @click="confirmation={title:'Offboard client?',message:'Return all unspent allocated credits to the agency, revoke every invited member and delivery link, cancel pending reviews, and archive this client. Projects stay available to the agency. Restoring the client will not restore revoked memberships.',run:()=>action(()=>api.post(`${base}/offboard`,{reclaim_credits:true}),'Client offboarded.')}">Offboard and return credits</button></div></div>
      </div>
      <form v-else-if="tab==='Client brief'" class="panel" @submit.prevent="saveBrief">
        <h2>Set the context once</h2><p>Save your client's audience, products and creative boundaries. Their existing brand kit and assets stay in this workspace.</p>
        <div class="fields"><label v-for="(label,key) in fields" :key="key">{{label}}<textarea v-model="brief[key]" :disabled="!data.can_manage" :maxlength="['products','approved_claims','restrictions'].includes(key)?5000:3000" rows="3" /></label></div>
        <label v-if="data.can_manage">Upload reference<input type="file" :disabled="busy" @change="upload($event,brief)" /></label><label>Reference assets<select v-model="brief.asset_ids" multiple :disabled="!data.can_manage"><option v-for="a in data.assets" :key="a.id" :value="a.id">{{a.name}} · {{a.asset_type}}</option></select></label><p class="muted">Use Ctrl/Cmd to select several. Upload logos, product images, footage or reference material in the asset library.</p>
        <div class="actions"><button v-if="data.can_manage" :disabled="busy">{{busy?'Saving…':'Save client brief'}}</button><button v-if="data.can_produce" type="button" @click="open('/workspace')">Brand kit settings</button><button v-if="data.can_produce" type="button" @click="open('/assets')">Manage assets</button></div>
      </form>
      <div v-else-if="tab==='Requests'" class="panel">
        <h2>Creative requests</h2><form @submit.prevent="submitRequest"><div class="fields"><label>Video title<input v-model="request.title" required maxlength="160" placeholder="Summer product launch" /></label><label>Due date<input v-model="request.due_at" type="date" /></label></div><label>Brief<textarea v-model="request.brief" required maxlength="10000" rows="4" placeholder="What should this video communicate? Include audience, format and call to action." /></label><label>Upload attachment<input type="file" :disabled="busy" @change="upload($event,request)" /></label><label>Attachments<select v-model="request.asset_ids" multiple><option v-for="a in data.assets" :key="a.id" :value="a.id">{{a.name}}</option></select></label><button :disabled="busy || data.workspace.status!=='active'">Submit request</button></form>
        <p v-if="!data.requests.length" class="muted">No requests yet.</p><article v-for="r in data.requests" :key="r.id" class="request"><h3>{{r.title}}</h3><p class="preserve">{{r.brief}}</p><p :class="{overdue:due(r.due_at)}">{{r.due_at || 'No due date'}} · {{r.status.replaceAll('_',' ')}}</p><p v-if="r.asset_ids.length" class="muted">Attachments: {{r.asset_ids.map(id=>data.assets.find(a=>a.id===id)?.name || `Asset #${id}`).join(', ')}}</p><div v-if="clientId" class="fields"><label>Status<select :value="r.status" :disabled="busy" @change="updateRequest(r,'status',$event.target.value)"><option v-for="s in ['requested','in_progress','in_review','delivered','cancelled']" :key="s" :value="s">{{s.replaceAll('_',' ')}}</option></select></label><label>Assigned editor<select :value="r.assigned_to_user_id || ''" :disabled="busy" @change="updateRequest(r,'assigned_to_user_id',$event.target.value)"><option value="">Unassigned</option><option v-for="m in editors" :key="m.id" :value="m.id">{{m.name || m.email}}</option></select></label><label>Project<select :value="r.project_id || ''" :disabled="busy" @change="updateRequest(r,'project_id',$event.target.value)"><option value="">Not linked yet</option><option v-for="p in data.projects" :key="p.id" :value="p.id">{{p.title}}</option></select></label><label>Due date<input type="date" :value="r.due_at" :disabled="busy" @change="updateRequest(r,'due_at',$event.target.value)" /></label></div><button v-if="clientId && !r.project_id" :disabled="busy || ['cancelled','delivered'].includes(r.status)" @click="startRequest(r)">Create draft from request</button><button v-if="r.project_id" @click="open(`/projects/${r.project_id}/editor`)">Open project</button></article>
      </div>
      <div v-else-if="tab==='Reviews'" class="panel"><h2>Reviews and feedback</h2><p>Request approval from the video editor after exporting. Each review link identifies the exported version; reviewers can add timed comments.</p><p v-if="!data.approvals.length" class="muted">No reviews requested yet.</p><article v-for="a in data.approvals" :key="a.id" class="request"><h3>{{title(a.project_id)}}</h3><p>Version #{{a.export_job_id || 'not exported'}} · {{a.status}}</p><p v-if="a.comment">{{a.comment}}</p><a :href="a.url" target="_blank" rel="noopener">Open review and comments ↗</a></article></div>
      <div v-else-if="tab==='Delivery'" class="panel"><h2>Delivery and handoff</h2><p>Share a package of approved exports and supporting files. Each selected video must have approval for that exact export.</p><form v-if="clientId" @submit.prevent="deliver"><label>Package title<input v-model="delivery.title" required maxlength="160" /></label><label>Handoff note<textarea v-model="delivery.message" maxlength="4000" rows="3" /></label><fieldset><legend>Approved video versions</legend><p v-if="!approved.length">No approved exports yet. Request review from the editor first.</p><label v-for="e in approved" :key="e.id" class="check"><input type="checkbox" v-model="delivery.export_ids" :value="e.id" />{{title(e.project_id)}} · {{e.aspect_ratio}} · #{{e.id}}</label></fieldset><label>Upload supporting file<input type="file" :disabled="busy" @change="upload($event,delivery)" /></label><label>Supporting files (captions, thumbnails, source files)<select v-model="delivery.asset_ids" multiple><option v-for="a in data.assets" :key="a.id" :value="a.id">{{a.name}}</option></select></label><label>Link expires in days<input type="number" v-model.number="delivery.expires_in_days" required min="1" max="90" /></label><button :disabled="busy || !delivery.export_ids.length">Create delivery link</button></form><article v-for="d in data.deliveries" :key="d.id" class="row"><div><strong>{{d.title}}</strong><p>{{d.revoked_at?'Revoked':`Expires ${new Date(d.expires_at).toLocaleDateString()}`}}</p></div><div v-if="!d.revoked_at" class="actions"><a :href="d.url" target="_blank" rel="noopener">Open</a><button @click="copy(d.url)">Copy link</button><button v-if="clientId" :disabled="busy" @click="confirmation={title:'Revoke delivery?',message:'The delivery page will stop working. Download links already issued expire within ten minutes.',run:()=>action(()=>api.delete(`${base}/deliveries/${d.id}`),'Delivery revoked.')}">Revoke</button></div></article></div>
      <div v-else-if="tab==='Spending'" class="panel"><h2>Credits used · {{data.spending.month}}</h2><p>Actual consumption excludes grants and transfers. This measures credits, not your client's invoice or profit.</p><div class="stats"><div><strong>{{data.spending.credits.toLocaleString()}}</strong>Credits consumed</div><div><strong>{{data.spending.cap?.toLocaleString() || 'No cap'}}</strong>Monthly spending limit</div></div><div v-for="p in data.spending.by_project" :key="p.project_id ?? 'other'" class="row"><span>{{p.project_id?title(p.project_id):'Other workspace activity'}}</span><strong>{{Number(p.credits).toLocaleString()}} credits</strong></div><p v-if="!data.spending.by_project.length">No credit consumption this month.</p></div>
      <div v-else-if="tab==='Access & budget'"><slot name="access" /></div>
    </template>
    <ConfirmDialog :open="!!confirmation" :title="confirmation?.title || ''" :message="confirmation?.message || ''" confirm-label="Confirm" :pending="busy" :destructive="true" @close="confirmation=null" @confirm="confirm" />
  </section>
</template>
<style scoped>
.hub{width:100%;color:var(--color-text-primary,#ececf2)}.tabs{display:flex;gap:4px;overflow:auto;border-bottom:1px solid var(--color-border,#30303b);margin-bottom:20px;padding-bottom:8px}.tabs button{white-space:nowrap;background:transparent;border-color:transparent}.tabs .selected{background:var(--color-bg-panel,#24242e);color:var(--color-accent,#ff6b35);border-color:var(--color-accent,#ff6b35)}.panel{padding:24px;border:1px solid var(--color-border,#30303b);border-radius:14px;background:var(--color-bg-card,#17171f)}h2{font-size:21px;margin:0 0 8px}h3{font-size:15px;margin:24px 0 10px}p{line-height:1.65;font-size:13px;color:var(--color-text-secondary,#b4b4c2)}.heading,.row{display:flex;justify-content:space-between;gap:16px;align-items:center}.row{padding:14px 0;border-bottom:1px solid var(--color-border,#30303b);font-size:13px}.row span,small,.muted{color:var(--color-text-muted,#9696a7)}.stats,.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0}.stats{grid-template-columns:repeat(4,minmax(0,1fr))}.stats>div{padding:18px;border:1px solid var(--color-border,#30303b);border-radius:10px;font-size:12px;color:var(--color-text-secondary,#b4b4c2)}.stats strong{display:block;font-size:25px;color:var(--color-text-primary,#ececf2);margin-bottom:6px}label{display:block;font-size:13px;margin-bottom:16px}input,textarea,select{display:block;width:100%;box-sizing:border-box;padding:11px;margin-top:7px;border:1px solid var(--color-border,#30303b);background:var(--color-bg-sunken,#101017);color:inherit;border-radius:8px;font:inherit}select[multiple]{min-height:110px}textarea{resize:vertical}button{cursor:pointer;padding:9px 13px;border:1px solid var(--color-border,#41414e);border-radius:8px;background:var(--color-bg-panel,#252531);color:inherit;font:inherit;font-size:13px}button:disabled{opacity:.5;cursor:not-allowed}button:focus-visible,a:focus-visible{outline:2px solid var(--color-accent,#ff6b35);outline-offset:3px}.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}a{color:var(--color-accent,#ff6b35)}.badge{padding:7px 12px;border-radius:20px;background:var(--color-bg-panel,#252531);font-size:12px}.message{padding:12px;margin:12px 0;border:1px solid var(--color-accent,#ff6b35);border-radius:8px;font-size:13px}.error,.overdue{color:#ff9c91!important}.error{border-color:#d85c50}.request,.lifecycle{border-top:1px solid var(--color-border,#30303b);margin-top:24px;padding-top:8px}.preserve{white-space:pre-wrap}.full{width:100%;text-align:left;background:transparent;border-radius:0}.check{display:flex;gap:10px;align-items:center}.check input{width:auto;margin:0}fieldset{border:1px solid var(--color-border,#30303b);border-radius:8px;margin:15px 0;padding:15px}@media(max-width:760px){.panel{padding:16px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.fields{grid-template-columns:1fr}.row{align-items:flex-start;flex-wrap:wrap}}
</style>
