<script setup>
import {ref,onMounted} from 'vue'
import api from '../../services/api'
const query=ref(''), rows=ref([]), detail=ref(null), loading=ref(false), error=ref(''), page=ref(1), lastPage=ref(1), selectedRun=ref(''), failuresOnly=ref(false)
let serial=0
async function load(n=1){
  loading.value=true;error.value=''
  try {const {data}=await api.get('/admin/create-trajectories',{params:{q:query.value,page:n}});rows.value=data.data;page.value=data.current_page;lastPage.value=data.last_page}
  catch(e){error.value=e?.response?.data?.error?.message||'Could not load trajectories.'}finally{loading.value=false}
}
async function open(id){
  const ticket=++serial;loading.value=true;error.value=''
  try {const {data}=await api.get('/admin/create-trajectories/'+id);if(ticket===serial){detail.value=data.data;selectedRun.value=''}}
  catch(e){if(ticket===serial)error.value=e?.response?.data?.error?.message||'Could not load this trajectory.'}finally{if(ticket===serial)loading.value=false}
}
function visible(e){return (!selectedRun.value||e.run_id===selectedRun.value||!e.run_id)&&(!failuresOnly.value||/fail|unknown|attention|revise|exhausted/.test(e.data?.status||e.data?.review_status||'')||e.data?.error)}
function download(){const url=URL.createObjectURL(new Blob([JSON.stringify(detail.value,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='create-trajectory-'+detail.value.conversation.id+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
onMounted(()=>load())
</script>
<template>
<section class="trajectories">
  <h2>Create trajectories</h2>
  <p>Follow a request through its plan, approved run, tools, provider receipts and result. Admin only.</p>
  <form class="toolbar" @submit.prevent="load(1)"><input v-model="query" aria-label="Search trajectories" placeholder="Conversation ID, run ID or title"/><button :disabled="loading">Search</button></form>
  <p v-if="error" role="alert">{{ error }}</p>
  <p v-if="loading" role="status">Loading trajectory…</p>
  <div class="trace-layout">
    <aside><button v-for="row in rows" :key="row.id" class="conversation" :class="{selected:detail?.conversation.id===row.id}" @click="open(row.id)"><strong>{{row.title}}</strong><small>{{row.id}}</small><small>Workspace {{row.workspace_id}} · {{row.updated_at}}</small></button>
    <p v-if="!rows.length&&!loading">No conversations found.</p>
    <div class="toolbar"><button :disabled="loading||page<=1" @click="load(page-1)">Previous</button><span>{{page}} / {{lastPage}}</span><button :disabled="loading||page>=lastPage" @click="load(page+1)">Next</button></div></aside>
    <div v-if="detail" class="trace-detail">
      <h3>{{detail.conversation.title}}</h3>
      <div class="toolbar"><a :href="'/create/'+detail.conversation.id" target="_blank" rel="noopener">Open conversation</a><button @click="open(detail.conversation.id)" :disabled="loading">Refresh</button><button @click="download">Download JSON</button></div>
      <p class="note">{{detail.coverage.note}}</p>
      <p v-if="detail.coverage.older_runs_omitted" class="note">Showing the latest {{detail.coverage.run_limit}} runs. Older runs are omitted.</p>
      <div class="toolbar"><select v-model="selectedRun" aria-label="Filter by run"><option value="">All included runs</option><option v-for="run in detail.runs" :key="run.id" :value="run.id">{{run.id}} · {{run.status}}</option></select><label><input v-model="failuresOnly" type="checkbox"/> Failures and unresolved outcomes</label></div>
      <article v-for="run in detail.runs.filter(r=>!selectedRun||r.id===selectedRun)" :key="run.id" class="run-card"><strong>{{run.build_stage}} · {{run.status}}</strong><small>{{run.id}}</small><p>{{run.charged_credits}} credits charged · ${{(run.known_cost_microusd/1000000).toFixed(4)}} recorded run cost · {{run.unknown_attempts}} unresolved calls</p><p>{{run.trace_coverage}}</p><p v-if="run.trace_truncated">Event limit reached; this tool trace may be incomplete.</p></article>
      <ol class="timeline"><li v-for="event in detail.timeline.filter(visible)" :key="event.id+event.kind"><small>{{event.at}} · {{event.kind}}<template v-if="event.data.sequence"> · #{{event.data.sequence}}</template></small><strong>{{event.summary}}</strong><details><summary>Details</summary><pre>{{JSON.stringify(event.data,null,2)}}</pre></details></li></ol>
    </div><p v-else>Select a conversation to inspect its requests.</p>
  </div>
</section>
</template>
<style scoped>
.trajectories{color:var(--text,#eee)}h2,h3{margin:0 0 12px}.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0}.toolbar input:not([type=checkbox]){flex:1;min-width:220px}input,select,button{font:inherit;color:inherit;background:#191923;border:1px solid #343443;border-radius:8px;padding:9px 12px}button{cursor:pointer}button:disabled{opacity:.45;cursor:default}a{color:#ff8c56}.trace-layout{display:grid;grid-template-columns:minmax(220px,300px) minmax(0,1fr);gap:24px}.conversation{display:block;width:100%;text-align:left;margin-bottom:8px}.selected{border-color:#ff6b35}small{display:block;color:#a7a7b5;font-size:12px;overflow-wrap:anywhere;margin:5px 0}.note{color:#aaa;font-size:13px}.run-card{border:1px solid #3c3c4b;border-radius:10px;padding:12px;margin:12px 0}.run-card p{margin:6px 0;font-size:13px}.timeline{list-style:none;border-left:2px solid #ff6b35;margin:20px 0;padding-left:18px}.timeline li{margin-bottom:18px}.timeline strong{display:block;white-space:pre-wrap;overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#111118;border-radius:8px;padding:12px;font-size:12px;max-height:420px;overflow:auto}summary{cursor:pointer;color:#aaa;margin-top:6px}select{max-width:100%}@media(max-width:950px){.trace-layout{grid-template-columns:1fr}}
</style>
