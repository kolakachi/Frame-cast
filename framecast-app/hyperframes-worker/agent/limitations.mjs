import {createHash} from 'node:crypto';

export const limitationCategories=['missing_capability','execution_limit','budget','quality','input','provider','safeguard','other'];
export const reportFields=['category','summary','evidence','impact','workaround','requested_change'];
export function validateLimitation(report){
 if(!limitationCategories.includes(report.category))throw Error('Invalid limitation category');
 for(const key of reportFields.slice(1))if(typeof report[key]!=='string'||!report[key].trim()||report[key].length>800)throw Error('Limitation '+key+' must be 1 to 800 characters');
}
// Diagnostics only: never send prompts, files, credentials or signed URLs to a
// separate telemetry service. This redaction is an extra defence, not a promise
// that model-authored prose is safe to publish without review.
export function safeDiagnostic(value){
 return String(value??'')
  .replace(/data:[^\s]+/gi,'[media omitted]')
  .replace(/https?:\/\/[^\s<>"']+/gi,'[url omitted]')
  .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi,'[email omitted]')
  .replace(/\b(?:Bearer\s+\S+|sk-[A-Za-z0-9_-]+|r8_[A-Za-z0-9_-]+)/gi,'[credential omitted]')
  .replace(/\b(api[_-]?key|token|secret|password|authorization)\s*[:=]\s*["']?[^\s,"'}]+/gi,'$1=[redacted]')
  .replace(/\/(?:Users|home|output|private|tmp)\/[^\s"']+/g,'[local path omitted]')
  .replace(/[\u0000-\u0008\u000b-\u001f]/g,'').slice(0,800);
}
export function limitationAssessment(category){
 return ({
  missing_capability:'candidate_for_scoped_tool: reproduce the task offline, add one bounded capability, then compare quality and cost',
  execution_limit:'measure_before_tuning: check useful progress and repeat counts; change one limit within the approved budget and compare',
  budget:'owner_approval_required: improve reuse/context efficiency first; the agent cannot raise spend or approve purchases',
  quality:'fix_workflow_or_model_first: poor output is not evidence that more permissions would help',
  input:'fix_input_or_invocation_first: supply the missing asset/fact or correct tool arguments',
  provider:'recover_provider_first: verify receipts and outcomes; never replay an uncertain paid request',
  safeguard:'retain_boundary: preserve credential isolation, app-controlled providers, consent, source protection and approved spending',
  other:'needs_investigation: a self-report is a hypothesis, not proof that a restriction caused the problem',
 })[category];
}
export function recordLimitation(state,entry){
 const category=limitationCategories.includes(entry.category)?entry.category:'other';
 const source=entry.source==='runtime'?'runtime':'agent_report';
 const report={source,category,code:safeDiagnostic(entry.code||category),tool:safeDiagnostic(entry.tool||''),
  ...Object.fromEntries(reportFields.slice(1).map(k=>[k,safeDiagnostic(entry[k])])),
  assessment:limitationAssessment(category),authorization_changed:false,
  evidence_status:source==='runtime'?'observed_event_cause_unverified':'unverified_agent_claim'};
 const key=createHash('sha256').update(JSON.stringify([source,category,report.code,report.tool,source==='agent_report'?report.summary:''])).digest('hex').slice(0,16);
 const now=new Date().toISOString(),records=state.limitations??=[];
 const prior=records.find(x=>x.id===key);
 if(prior){prior.occurrences++;prior.last_seen=now;prior.last_call=state.calls;prior.last_revision=state.revision;return prior;}
 if(records.length>=60){state.limitation_overflow=(state.limitation_overflow??0)+1;return {recorded:false,reason:'Per-run diagnostic record limit reached',authorization_changed:false};}
 const item={id:key,...report,occurrences:1,first_seen:now,last_seen:now,first_call:state.calls,last_call:state.calls,first_revision:state.revision,last_revision:state.revision};
 records.push(item);return item;
}
export function classifyLimitation(message){
 const text=String(message);
 if(/reconcil|uncertain|unknown.*outcome|provider|429|rate.limit|503|502/i.test(text))return 'provider';
 if(/budget|credit|ceiling|spend/i.test(text))return 'budget';
 if(/limit reached|exhausted|deadline|time.?out|time limit/i.test(text))return 'execution_limit';
 if(/not installed|not available|unsupported.*(?:tool|engine|operation)/i.test(text))return 'missing_capability';
 if(/permission|not allowed|protected|no URLs|no absolute|traversal|consent/i.test(text))return 'safeguard';
 if(/invalid|requires|missing|must be|out.of.range|unexpected|no match/i.test(text))return 'input';
 return 'other';
}
export function observeToolResult(state,action,result){
 const errors=result?.diagnostics?.errors??(result?.critic?.directives?.length?result.critic.directives.map(message=>({code:'critic_revision',message})):null);
 const message=result?.error??(result?.ok===false?'Tool returned an unsuccessful result':'');
 if(!message&&!errors?.length)return;
 const detail=message||'Validation reported findings';
 const category=errors?.length?'quality':classifyLimitation(detail);
 recordLimitation(state,{source:'runtime',category,code:category+'_'+action.type,tool:action.type,
  summary:detail,evidence:errors?.length?errors.slice(0,3).map(e=>safeDiagnostic(e.code||'finding')+': '+safeDiagnostic(e.message||'')).join('; '):detail,
  impact:'This tool did not establish the requested result. Check the subsequent revision or terminal outcome to see whether it was resolved.',
  workaround:'Use the returned diagnostic and existing tools; do not infer permission to bypass the constraint.',requested_change:''});
}
export function summarizeLimitations(runs){
 const groups=new Map();
 for(const {run,state} of runs){
  for(const record of state.limitations??[]){
   const key=[record.source,record.category,record.code,record.tool,record.source==='agent_report'?record.summary:''].join('|');
   if(!groups.has(key))groups.set(key,{category:record.category,code:record.code,source:record.source,evidence_status:record.evidence_status,
    tool:record.tool,summary:safeDiagnostic(record.summary),assessment:limitationAssessment(record.category),occurrences:0,runs:[],incomplete_runs:0,examples:[]});
   const group=groups.get(key);group.occurrences+=record.occurrences;
   if(!group.runs.includes(run)){group.runs.push(run);if(state.status!=='preview_ready'||state.recoveredDraft||state.reviewedRevision!==state.revision||state.critic?.verdict!=='pass')group.incomplete_runs++;}
   if(group.examples.length<3)group.examples.push({run,evidence:safeDiagnostic(record.evidence),impact:safeDiagnostic(record.impact),workaround:safeDiagnostic(record.workaround),requested_change:safeDiagnostic(record.requested_change)});
  }
 }
 return {schema:1,runs_scanned:runs.length,runs_with_records:runs.filter(r=>r.state.limitations?.length).length,
  note:'Observed errors and unverified agent claims are separate. Frequency and incomplete outcomes are signals to investigate, not proof that a restriction caused poor results. No permissions changed.',
  limitations:[...groups.values()].sort((a,b)=>b.runs.length-a.runs.length||b.occurrences-a.occurrences)};
}
export function observeStop(state,detail){
 const category=state.status==='needs_attention'?'provider':classifyLimitation(detail);
 recordLimitation(state,{source:'runtime',category,code:'run_'+category,tool:state.pending?.action?.type||state.pending?.kind||'runner',
  summary:detail,evidence:'status='+state.status+'; call='+state.calls+'; revision='+state.revision,
  impact:state.recoveredDraft?'A prior draft was delivered without confirming all later changes.':'The requested work could not continue or finish normally.',
  workaround:category==='provider'?'Reconcile the existing attempt before any retry.':'Review saved work and diagnostics before another approved run.',requested_change:''});
}
