import {appendFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {safeDiagnostic} from './limitations.mjs';
const hash=x=>createHash('sha256').update(JSON.stringify(x??null)).digest('hex');
// No source bodies, prompts, provider responses or credentials in this journal.
export function actionEvent(action,result,{status,call,revision,durationMs=0}={}) {
 const parameters=Object.fromEntries(Object.entries(action.params??{}).filter(([k,v])=>['times','start','end','time','x','y','width','height','scale','frames'].includes(k)&&(typeof v==='number'||Array.isArray(v)&&v.every(Number.isFinite))));
 const findings=result?.critic?.directives??result?.diagnostics?.errors;
 const detail=status==='started' ? ['path','input','op','cmd','kind','category'].filter(k=>typeof action[k]==='string').map(k=>k+'='+safeDiagnostic(action[k])).join('; ')+(Object.keys(parameters).length?'; params='+JSON.stringify(parameters):'')
  : result?.error || (Array.isArray(findings)?findings:[]).slice(0,3).map(x=>typeof x==='string'?x:x?.message||x?.code||'finding').join('; ') || (action.type==='inspect_reference'&&result?.ok ? JSON.stringify({source_sha256:result.source_sha256,mode:result.mode,every_frame:result.every_frame===true,coverage:result.coverage,cache_hit:result.cache?.hit===true}) : action.type==='report_limitation' ? 'Unverified agent report: '+action.summary+'; impact: '+action.impact+'; requested change: '+action.requested_change : '');
 return {phase:action.type==='report_limitation'?'limitation':'tool',tool:action.type,status,call,revision,duration_ms:Math.max(0,Math.min(86400000,durationMs)),
  summary:safeDiagnostic(action.type+' · '+status),detail:safeDiagnostic(detail),input_hash:hash(action),...(status!=='started'?{output_hash:hash(result)}:{})};
}
export function createTrajectory({file,send}) {
 let sequence=0,queue=[],writing=Promise.resolve(),flushing=null;
 return {
  async record(event){
   if(sequence>=2000)return;
   const entry={...event,summary:safeDiagnostic(event.summary),detail:safeDiagnostic(event.detail),sequence:++sequence,at:new Date().toISOString()};
   queue.push(entry);
   writing=writing.catch(()=>{}).then(()=>appendFile(file,JSON.stringify(entry)+'\n',{mode:0o600}));
   await writing;
  },
  async flush(){
   if(flushing)return flushing;
   flushing=(async()=>{while(queue.length){const batch=queue.slice(0,50);await send(batch);queue.splice(0,batch.length);}})();
   try{await flushing;}finally{flushing=null;}
  },
  pending:()=>queue.length,
 };
}
