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
  <header class="tr-head">
    <div><h2>Create trajectories</h2><p class="tr-sub">Follow a request through its plan, approved run, tools, provider receipts and result. Admin only.</p></div>
    <form class="tr-search" @submit.prevent="load(1)"><input v-model="query" aria-label="Search trajectories" placeholder="Conversation ID, run ID or title"/><button :disabled="loading">Search</button></form>
  </header>
  <p v-if="error" class="tr-error" role="alert">{{ error }}</p>
  <div class="trace-layout">
    <aside class="tr-list" aria-label="Conversations">
      <button v-for="row in rows" :key="row.id" class="conversation" :class="{selected:detail?.conversation.id===row.id}" @click="open(row.id)">
        <strong class="tr-title">{{row.title || 'Untitled'}}</strong>
        <small class="tr-id">{{row.id}}</small>
        <small>Workspace {{row.workspace_id}} · {{row.updated_at}}</small>
      </button>
      <p v-if="!rows.length&&!loading" class="note">No conversations found.</p>
      <div class="tr-pager"><button :disabled="loading||page<=1" @click="load(page-1)">Previous</button><span>{{page}} / {{lastPage}}</span><button :disabled="loading||page>=lastPage" @click="load(page+1)">Next</button></div>
    </aside>
    <div class="trace-detail">
      <p v-if="loading" class="note" role="status">Loading trajectory…</p>
      <template v-if="detail">
        <div class="tr-detail-head">
          <h3>{{detail.conversation.title || 'Untitled'}}</h3>
          <div class="tr-actions"><a :href="'/create/'+detail.conversation.id" target="_blank" rel="noopener">Open conversation</a><button @click="open(detail.conversation.id)" :disabled="loading">Refresh</button><button @click="download">Download JSON</button></div>
        </div>
        <p class="note">{{detail.coverage.note}}</p>
        <p v-if="detail.coverage.older_runs_omitted" class="note">Showing the latest {{detail.coverage.run_limit}} runs. Older runs are omitted.</p>
        <div class="tr-filters"><select v-model="selectedRun" aria-label="Filter by run"><option value="">All included runs</option><option v-for="run in detail.runs" :key="run.id" :value="run.id">{{run.id.slice(0,8)}} · {{run.build_stage}} · {{run.status}}</option></select><label class="tr-check"><input v-model="failuresOnly" type="checkbox"/> Failures and unresolved outcomes</label></div>
        <div class="tr-runs">
          <article v-for="run in detail.runs.filter(r=>!selectedRun||r.id===selectedRun)" :key="run.id" class="run-card">
            <div class="run-top"><strong>{{run.build_stage}}</strong><span :class="['tr-badge', 'tr-'+run.status]">{{run.status}}</span></div>
            <small class="tr-id">{{run.id}}</small>
            <p>{{run.charged_credits}} credits charged · ${{(run.known_cost_microusd/1000000).toFixed(4)}} recorded cost · {{run.unknown_attempts}} unresolved calls</p>
            <p class="note">{{run.trace_coverage}}</p>
            <p v-if="run.trace_truncated" class="note">Event limit reached; this tool trace may be incomplete.</p>
          </article>
        </div>
        <ol class="timeline"><li v-for="event in detail.timeline.filter(visible)" :key="event.id+event.kind"><small>{{event.at}} · {{event.kind}}<template v-if="event.data.sequence"> · #{{event.data.sequence}}</template></small><strong>{{event.summary}}</strong><details><summary>Details</summary><pre>{{JSON.stringify(event.data,null,2)}}</pre></details></li></ol>
      </template>
      <p v-else-if="!loading" class="note tr-empty">Select a conversation to inspect its requests.</p>
    </div>
  </div>
</section>
</template>
<style scoped>
/* Every column can shrink and every long value wraps, so nothing pushes the admin page wider (it overflowed). */
.trajectories{color:var(--text,#eee);max-width:100%;min-width:0;overflow-x:hidden}
h2,h3{margin:0}h2{font-size:20px}h3{font-size:16px;overflow-wrap:anywhere}
.tr-head{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-end;justify-content:space-between;margin-bottom:16px}
.tr-head>div{min-width:0;flex:1 1 320px}
.tr-sub{margin:6px 0 0;color:#a7a7b5;font-size:13px;max-width:70ch}
.tr-search{display:flex;gap:8px;flex:1 1 360px;min-width:0;max-width:520px}
.tr-search input{flex:1;min-width:0}
input,select,button{font:inherit;color:inherit;background:#191923;border:1px solid #343443;border-radius:8px;padding:8px 12px;min-width:0}
button{cursor:pointer}button:disabled{opacity:.45;cursor:default}button:focus-visible,select:focus-visible,input:focus-visible{outline:2px solid #ff6b35;outline-offset:1px}
a{color:#ff8c56}
.tr-error{color:#ff8c78}
.trace-layout{display:grid;grid-template-columns:minmax(0,280px) minmax(0,1fr);gap:20px;align-items:start}
.tr-list{min-width:0;display:flex;flex-direction:column;gap:8px;max-height:calc(100vh - 220px);overflow-y:auto;padding-right:4px;position:sticky;top:16px}
.conversation{display:flex;flex-direction:column;gap:3px;width:100%;text-align:left;padding:10px 12px}
.conversation:hover{border-color:#4a4a5c}
.selected{border-color:#ff6b35;background:#1f1a1a}
.tr-title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
small{display:block;color:#a7a7b5;font-size:12px;overflow-wrap:anywhere}
.tr-id{font-family:ui-monospace,Menlo,monospace;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tr-pager{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:4px;font-size:13px}
.trace-detail{min-width:0}
.tr-detail-head{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;justify-content:space-between;margin-bottom:8px}
.tr-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.note{color:#a7a7b5;font-size:13px;margin:6px 0;overflow-wrap:anywhere}
.tr-empty{padding:40px 0;text-align:center}
.tr-filters{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;margin:12px 0}
.tr-filters select{width:100%;max-width:360px}
.tr-check{display:inline-flex;gap:6px;align-items:center;font-size:13px;color:#cfcfd8}
.tr-runs{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.run-card{min-width:0;border:1px solid #3c3c4b;border-radius:10px;padding:12px;background:#15151d}
.run-card p{margin:6px 0 0;font-size:13px;overflow-wrap:anywhere}
.run-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.tr-badge{font-size:11px;border-radius:999px;padding:2px 8px;border:1px solid #3c3c4b;color:#cfcfd8;white-space:nowrap}
.tr-preview_ready,.tr-succeeded{border-color:#2f6b4f;color:#7fd3a6}
.tr-failed,.tr-needs_attention{border-color:#7a3b31;color:#ff9a86}
.tr-running,.tr-queued{border-color:#2f4f7a;color:#8fb7ff}
.timeline{list-style:none;border-left:2px solid #ff6b35;margin:20px 0;padding-left:16px;min-width:0}
.timeline li{margin-bottom:16px;min-width:0}
.timeline strong{display:block;white-space:pre-wrap;overflow-wrap:anywhere;font-weight:500;margin-top:2px}
pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#111118;border-radius:8px;padding:12px;font-size:12px;max-height:420px;overflow:auto;max-width:100%}
summary{cursor:pointer;color:#a7a7b5;margin-top:6px;font-size:12px}
@media(max-width:950px){.trace-layout{grid-template-columns:minmax(0,1fr)}.tr-list{position:static;max-height:320px}}
</style>
