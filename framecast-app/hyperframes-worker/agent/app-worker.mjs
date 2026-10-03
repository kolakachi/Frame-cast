import {createTrajectory} from './trajectory.mjs';
import {reviewStatus} from './review-status.mjs';
// Local app bridge. Paid calls require both app and host opt-in plus durable limits.
// Credentials stay on the host; no shell text or Docker socket enters the sandbox.
import {readFile,writeFile,mkdir,copyFile,access,readdir,rename,unlink} from 'node:fs/promises';
import path from 'node:path';
import {PilotBudget} from './pilot-budget.mjs';
import {ReplicateGatewayProvider} from './replicate-gateway.mjs';
import {AnthropicGatewayProvider} from './anthropic-gateway.mjs';
import {buyPlanMedia,stageFile} from './plan-media.mjs';
import {levelIfNeeded,summary as deliverySummary} from './delivery-checks.mjs';
import {executeImage} from './media-provider.mjs';
import {stageInputs} from './stage-inputs.mjs';
import {executeCompositionAgent,offlineContractProvider} from './composition-agent.mjs';
import {accountedCall} from './accounted-call.mjs';
import {fileURLToPath} from 'node:url';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
const exec=promisify(execFile),root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const base=process.env.CREATE_API_URL??'http://localhost:8000';
const url=new URL(base);
if(!['localhost','127.0.0.1','[::1]'].includes(url.hostname)||url.username||url.password||url.pathname!=='/')throw Error('Local API origin required');
const token=process.env.CREATE_WORKER_TOKEN;
if(!token||token.length<32)throw Error('Set the matching local CREATE_WORKER_TOKEN (32+ characters)');
const docker=process.env.DOCKER_BIN??'docker';
let stopping=false;
process.on('SIGINT',()=>{stopping=true;});process.on('SIGTERM',()=>{stopping=true;});
// The app accepts 2,000 characters; the cut note is kept whole and the review is shortened first.
function fitSummary(review,note){const room=2000-note.length;return (review.length>room?review.slice(0,Math.max(0,room-1)).replace(/\s+\S*$/,'')+'…':review)+note;}
// Words cut from the user's own speech are always listed, so meaning never changes silently.
function cutNote(edits){
 if(!Array.isArray(edits)||!edits.length)return '';
 const removed=edits.flatMap(e=>e.removed),content=edits.flatMap(e=>e.content_removed);
 const q=w=>'\u201c'+w.slice(0,12).join(' ')+'\u201d'+(w.length>12?' and more':'');
 return '\n\nRemoved from your footage: '+q(removed)+'.'+(content.length?' This includes words that were not filler or repeats: '+q(content)+'.':'');
}
async function request(endpoint,body,form=false,timeoutMs=15000){
 const response=await fetch(new URL('/api/internal/create/'+endpoint,base),{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json',...(!form?{'Content-Type':'application/json'}:{})},body:form?body:JSON.stringify(body),signal:AbortSignal.timeout(timeoutMs)});
 if(!response.ok){const body=await response.json().catch(()=>({}));throw Error('Coordinator returned HTTP '+response.status+(body?.error?.message||body?.message?': '+String(body.error?.message||body.message).slice(0,200):''));}return (await response.json()).data;
}
async function finish(run,result,artifact,type='video/mp4'){
 const form=new FormData();form.set('lease_token',run.lease_token);form.set('result',JSON.stringify(result));
 if(artifact)form.set('artifact',new Blob([await readFile(artifact)],{type}),type==='video/mp4'?'preview.mp4':artifact.split('/').at(-1));
 return request('runs/'+run.id+'/finish',form,true);
}
async function execute(run){
 if(!/^[a-f0-9-]{36}$/.test(run.id)||!['fixture','agent'].includes(run.input.mode))throw Error('Unsupported run contract');
 const id='app-'+run.id,dir=root+'/artifacts/live/'+id,container='wyv-create-'+run.id;
 await mkdir(dir,{recursive:true});
 // A prior process may have spent/rendered. Never replay an interrupted run.
 try{await access(dir+'/started.json');throw Error('Run journal exists; reconcile instead of replaying');}catch(e){if(e.code!=='ENOENT')throw e;}
 await writeFile(dir+'/started.json',JSON.stringify({runId:run.id,startedAt:new Date().toISOString()}),{flag:'wx',mode:0o600});
 await mkdir(dir+'/project');
 for(const name of ['index.html','product.svg'])await copyFile(root+'/fixtures/'+name,dir+'/project/'+name);
 await writeFile(dir+'/output-settings.json',JSON.stringify(run.input.mode==='fixture'?{aspect_ratio:'9:16',duration_seconds:15}:run.input.settings),{flag:'wx'});
 const trajectory=createTrajectory({file:dir+'/trajectory.jsonl',send:events=>request('runs/'+run.id+'/trajectory',{lease_token:run.lease_token,events},false,10000)});
 const trace=async event=>{try{await trajectory.record(event);}catch{console.error('Local trajectory write failed for '+run.id);}};
 const flushTrace=async()=>{try{await trajectory.flush();}catch{console.error('Trajectory sync pending for '+run.id);}};
 await trace({phase:'run',status:'started',summary:'Worker claimed run'});
 let seq=0,cancelled=false,lost=false,heartbeatBusy=false,stage='Preparing the local sample';
 const aborter=new AbortController();
 // One slow heartbeat is not a lost lease (a busy single-threaded dev server
 // queues it behind a long model call). The lease is lost when the app rejects
 // it, or when no heartbeat has succeeded for 60 s of the 90 s lease.
 let lastBeat=Date.now();
 async function beat(){
  if(heartbeatBusy)return;heartbeatBusy=true;
  try{const state=await request('runs/'+run.id+'/heartbeat',{lease_token:run.lease_token,sequence:++seq,stage},false,45000);cancelled||=state.cancel_requested;lastBeat=Date.now();}
  catch(e){if(/HTTP (403|404|409)\b/.test(String(e.message))||Date.now()-lastBeat>60000)lost=true;}finally{heartbeatBusy=false;}
  void flushTrace();
  if(cancelled||lost||stopping){aborter.abort();try{await exec(docker,['rm','-f',container]);}catch{/* final inspection below decides whether cancellation is safe */}}
 }
 await beat();
 const timer=setInterval(beat,20000);
 try{
  if(lost)throw Error('Coordinator unavailable before render');
  if(cancelled||stopping){await finish(run,{status:'cancelled',summary:'Cancelled before rendering'});return;}
  const manifest=await stageInputs({directory:dir+'/inputs',files:run.input.input_files??[],baseBundle:run.input.base_bundle,
   download:(assetId)=>fetch(new URL('/api/internal/create/runs/'+run.id+'/inputs/'+assetId,base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({lease_token:run.lease_token}),signal:AbortSignal.timeout(60000)})});
  if(lost||cancelled||stopping)throw Error('Stopped while preparing inputs');
  const freeEdit=run.input.free_edit===true;
  // A free edit is a re-render of an existing bundle with new variable values:
  // no model, no provider credential, no spend.
  const paid=run.input.mode==='agent'&&!freeEdit;
  if(freeEdit){
   const policy=run.input.execution_policy??{};
   if(Object.keys(policy).join()!=='render'||policy.render.credits!==0||!run.input.base_bundle)throw Error('Invalid free edit contract');
   for(const [name,text] of Object.entries(run.input.base_bundle)){if(!/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(name))throw Error('Invalid bundle file');await writeFile(dir+'/project/'+name,text,{mode:0o600});}
   for(const file of manifest)if(file.purpose==='source')await copyFile(dir+'/inputs/'+file.path,dir+'/project/'+file.name);
   stage='Applying your changes';
  }
  const viaGateway=paid&&run.input.execution_policy?.agent?.provider==='anthropic'&&!run.input.execution_policy?.media;
  if(paid){
   if(process.env.CREATE_AGENT_LIVE!=='1')throw Error('Live local host is not enabled');
  }
  // Claude API calls keep their own local $5 test ledger so they never draw on the Replicate pilot's.
  // Opus test ledger: uncapped by owner decision on 2026-10-01 (was $5, then $6, $6.50, $7.10). Each run is
  // still limited to its approved calls at $0.30 each and to the app-side pilot ceiling.
  const pilotBudget=new PilotBudget(root+'/artifacts/live/'+(viaGateway?'e3-opus-budget.json':'e3-2026-09-29-budget.json'),viaGateway?null:5);
  const begin=payload=>request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token});
  let reservation=null;
  const settle=async(attemptId,result)=>{const confirmed=await request('runs/'+run.id+'/attempts/'+attemptId+'/settle',{...result,lease_token:run.lease_token});if(reservation && confirmed.status==='succeeded'){await pilotBudget.settle(reservation,confirmed);reservation=null;}return confirmed;};
  const bindPrediction=(attemptId,predictionId)=>request('runs/'+run.id+'/attempts/'+attemptId+'/prediction',{lease_token:run.lease_token,prediction_id:predictionId});
  if(paid&&run.input.execution_policy?.media){
   stage=run.input.settings.output_kind==='image'?'Creating your image':'Animating your image';
   reservation=await pilotBudget.reserve(run.input.execution_policy.media.model,run.input.execution_policy.media.cost_limit_microusd/1e6);
   const gateway={prepare:()=>request('runs/'+run.id+'/replicate/prepare',{lease_token:run.lease_token},false,300000),call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/replicate',{...body,lease_token:run.lease_token},false,120000)};
   const image=await executeImage({directory:dir,input:run.input,manifest,gateway,begin,settle,bindPrediction,signal:aborter.signal});
   if(image.type==='video/mp4'){await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/prepare-animation.mjs',id],{timeout:120000,maxBuffer:1000000});image.artifact=dir+'/animation-silent.mp4';}
   await beat();if(cancelled||lost||stopping)throw Error('Stopped before delivering image');
   await finish(run,{status:'preview_ready',summary:image.summary,bundle:image.bundle},image.artifact,image.type);
   return;
  }
  const agentModel=run.input.execution_policy?.agent?.model;
  // The per-call cap approved with the run (deeper effort gets a higher one).
  const unlimited=run.input.execution_policy?.agent?.unlimited===true;
  const callCapUsd=(run.input.execution_policy?.agent?.cost_limit_microusd??300000)/1e6;
  const provider=viaGateway?new AnthropicGatewayProvider({model:agentModel,maxCallUsd:callCapUsd,
    call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/anthropic',{...body,lease_token:run.lease_token},false,unlimited?960000:300000)})
   :paid?new ReplicateGatewayProvider({maxCallUsd:callCapUsd,upload:image=>request('runs/'+run.id+'/replicate/prepare',{lease_token:run.lease_token,image},false,120000),call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/replicate',{...body,lease_token:run.lease_token},false,120000)}):offlineContractProvider(run.input.base_bundle,manifest);
  // Reserve local allowance before the app records the attempt, so running out never leaves a held call.
  if(paid)provider.reserve=async()=>{reservation=await pilotBudget.reserve(viaGateway?agentModel:'anthropic/claude-4.5-sonnet',callCapUsd,{unlimited});};
  let agentResult;
  if(run.input.execution_policy?.agent){
   // Buy the approved plan items first, so the design can use them.
   let planMedia=[];
   if(paid&&Array.isArray(run.input.plan_media)&&run.input.plan_media.length){
    const download=(assetId,signal)=>fetch(new URL('/api/internal/create/runs/'+run.id+'/inputs/'+assetId,base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({lease_token:run.lease_token}),signal:AbortSignal.any([signal??aborter.signal,AbortSignal.timeout(120000)])});
    planMedia=await buyPlanMedia({items:run.input.plan_media,directory:dir+'/inputs',manifest,signal:aborter.signal,onStage:s=>{stage=s;},
     produce:i=>request('runs/'+run.id+'/plan-media/'+i,{lease_token:run.lease_token},false,900000),
     download});
    if(lost||cancelled||stopping)throw Error('Stopped while getting plan media');
   }
   stage=paid?'Designing your video':'Running the offline agent contract check';
   const assetIds=new Map(manifest.map(f=>[f.name,f.asset_id]));
   // The activity line: what the agent is doing, the call count and the credits so far (model calls plus purchases).
   const mediaCredits=planMedia.reduce((n,m)=>n+(Number(m.charged_credits)||0),0);
   const onProgress=p=>{const credits=Math.round(p.spentUsd/0.004)+mediaCredits;stage=(p.doing+' · '+credits+' credits so far').slice(0,250);};
   // A purchase the agent decides on, within the approved ceiling; the API refuses anything over it (402).
   const buy=async({kind,description,requirement_ids=[],signal})=>{
    let r;
    try{r=await request('runs/'+run.id+'/plan-media/adhoc',{lease_token:run.lease_token,kind,description,requirement_ids},false,900000);}
    catch(e){const m=String(e.message);return {ok:false,over_ceiling:/HTTP 402/.test(m),error:m.replace(/^Coordinator returned HTTP \d+: ?/,'').slice(0,300)};}
    if(r.status!=='succeeded')return {ok:false,error:r.error||'The item could not be made.'};
    const files=[];
    for(const f of [r.file,...(r.more_files||[])].filter(Boolean)){const name=await stageFile(f,{manifest,directory:dir+'/inputs',download,signal,kind,description,taskId:r.task_id??null,requirementIds:r.requirement_ids??[]});files.push({name,sha256:f.sha256,asset_id:f.asset_id});}
    planMedia.push({task_id:r.task_id??null,requirement_ids:r.requirement_ids??[],kind,description,status:'succeeded',file:files[0]?.name,charged_credits:r.charged_credits});
    return {ok:true,task_id:r.task_id??null,requirement_ids:r.requirement_ids??[],kind,description,charged_credits:r.charged_credits,reused:!!r.reused,files,...(Array.isArray(r.cues)?{cues:r.cues.slice(0,6)}:{}),...(Array.isArray(r.poses)?{poses:r.poses}:{}),...(typeof r.line==='string'?{line:r.line}:{})};
   };
   agentResult=await executeCompositionAgent({directory:dir,input:run.input,manifest,planMedia,onProgress,onTrace:trace,buy,
    transcribe:async({input})=>{const assetId=assetIds.get(input);if(!assetId)throw Error('Only supplied audio or video can be transcribed');return request('runs/'+run.id+'/transcripts',{lease_token:run.lease_token,asset_id:assetId},false,150000);},
    provider,guidanceDirectory:root+'/agent/guidance',signal:aborter.signal,
    bindPrediction:(attemptId,predictionId)=>request('runs/'+run.id+'/attempts/'+attemptId+'/prediction',{lease_token:run.lease_token,prediction_id:predictionId}),
    begin:payload=>request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token}),
    settle,receipt:output=>paid?({status:'succeeded',prediction_id:output.predictionId}):({status:'succeeded',cost_microusd:0}),
    invoke:async(operation,{times=[],signal,op,input,params,cmd,args}={})=>{
     const payload=operation==='media'?{op,input,params:params??{}}:
      operation==='run'?{cmd,args}:operation==='inspect_reference'?{input,params}:null;
     const requestFile=dir+'/'+operation+'-request.json';
     if(payload)await writeFile(requestFile,JSON.stringify(operation==='run'&&unlimited?{...payload,timeout_ms:600000}:payload),{mode:0o600});
     await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,operation,...(times.length?[times.join(',')]:[])],{signal,timeout:unlimited?900000:180000,maxBuffer:8000000});
     const result=JSON.parse(await readFile(dir+'/'+operation+'/result.json','utf8'));
     if(payload)await unlink(requestFile).catch(()=>{});
     const imageFile=({snapshot:'snapshot/contact-sheet.jpg',strip:'strip/strip.jpg',inspect_reference:'inspect_reference/contact-sheet.jpg'})[operation];
     if(paid&&imageFile&&result.ok)result.providerImage='data:image/jpeg;base64,'+(await readFile(dir+'/'+imageFile)).toString('base64');
     return result;
    }});
   if(['needs_input','awaiting_media_approval'].includes(agentResult.state.status)){
    await finish(run,{status:'needs_input',summary:(agentResult.state.question??agentResult.state.proposal??'Please clarify your brief.').slice(0,2000)});return;
   }
   if(agentResult.state.status!=='preview_ready')throw Object.assign(Error(agentResult.state.reason??'Agent needs input before rendering'),{code:agentResult.state.status==='needs_attention'?'ATTEMPT_NEEDS_ATTENTION':'AGENT_STOPPED'});
  }
  // Derived media becomes a permanent source before rendering: upload it, give
  // it its stored name, and point the composition at that name, so later
  // versions and free edits inherit exactly these bytes.
  if(agentResult?.derived?.length){
   stage='Saving your edited footage';await beat();
   const ids=new Map(manifest.map(f=>[f.name,f.asset_id]));
   for(const d of agentResult.derived){
    // A run-made file may have no source (generated from scratch); a media edit always has one.
    const from=ids.get(d.derivedFrom??d.origin);if(!from&&d.operation!=='run')throw Error('Derived media has no known source');
    const form=new FormData();form.append('lease_token',run.lease_token);if(from)form.append('derived_from_asset_id',String(from));form.append('operation',d.operation);form.append('params',JSON.stringify(d.params??{}));
    form.append('file',new Blob([await readFile(dir+'/project/'+d.path)]),d.path);
    const response=await fetch(new URL('/api/internal/create/runs/'+run.id+'/derived',base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,Accept:'application/json'},body:form,signal:aborter.signal});
    if(!response.ok)throw Error('Derived media upload failed ('+response.status+')');
    const record=(await response.json()).data;
    if(record.sha256!==d.sha256)throw Error('Derived media hash mismatch');
    await rename(dir+'/project/'+d.path,dir+'/project/'+record.name);ids.set(d.path,record.asset_id);ids.set(record.name,record.asset_id);
    for(const name of (await readdir(dir+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n))){
     const text=await readFile(dir+'/project/'+name,'utf8');if(text.includes(d.path))await writeFile(dir+'/project/'+name,text.split(d.path).join(record.name),{mode:0o600});
    }
    for(const [k,v] of Object.entries(agentResult.bundle))agentResult.bundle[k]=v.split(d.path).join(record.name);
   }
  }
  stage=paid?'Rendering your video':'Rendering the local sample';
  await accountedCall({key:'render-1',kind:'render',input:{runId:run.id,mode:run.input.mode},
   begin:async payload=>{const attempt=await request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token});await writeFile(dir+'/render-attempt.json',JSON.stringify(attempt),{flag:'wx',mode:0o600});return attempt;},
   settle:(attemptId,result)=>request('runs/'+run.id+'/attempts/'+attemptId+'/settle',{...result,lease_token:run.lease_token}),
   execute:async()=>{await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,'render'],{timeout:unlimited?1800000:180000,maxBuffer:2000000});const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));if(report.status==='failed' && report.artifact===null)throw Object.assign(Error('The layout did not pass render checks. Correct the saved draft before rendering again.'),{code:'LOCAL_RENDER_FAILED'});if(report.status!=='ready')throw Error('Render outcome could not be verified');return report;},
   receipt:()=>({status:'succeeded',cost_microusd:0})});
  // Delivery checks on the final file: platform safe area, frame edges,
  // contrast and loudness. Reported with the version; loudness is levelled.
  let deliveryChecks=null;
  if(!(cancelled||stopping||lost)){
   stage='Checking the final video';
   try{
    await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container+'-delivery','smoke','node','agent/live-tool.mjs',id,'delivery'],{timeout:unlimited?900000:200000,maxBuffer:2000000});
    const sandbox=JSON.parse(await readFile(dir+'/delivery/result.json','utf8'));
    const rendered=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
    const loudness=await levelIfNeeded(path.join(root,'artifacts',rendered.directory.slice('/output/'.length),rendered.artifact),{silent:run.input.settings?.audio==='silent'});
    deliveryChecks=deliverySummary(sandbox,loudness);
   }catch{deliveryChecks=null;}
  }
  clearInterval(timer);
  while(heartbeatBusy)await new Promise(resolve=>setTimeout(resolve,25));
  await beat();
  if(lost)throw Error('Worker lease lost; keep output for reconciliation');
  if(cancelled||stopping){await finish(run,{status:'cancelled',summary:'Local render stopped'});return;}
  const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
  if(report.status!=='ready')throw Error('Render did not produce a verified output');
  const bundleFiles=async()=>Object.fromEntries(await Promise.all((await readdir(dir+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)&&n!=='gsap.min.js'&&n!=='wyv-motion.js').sort().map(async n=>[n,await readFile(dir+'/project/'+n,'utf8')])));
  const result=freeEdit?{status:'preview_ready',summary:'Updated '+Object.keys(run.input.edit_values??{}).length+' field(s). Free: no model call, one render.',bundle:await bundleFiles()}:{status:'preview_ready',summary:paid?fitSummary(agentResult.state.summary??'',cutNote(agentResult.state.edits)):'Local integration sample ready. This fixed sample does not represent your prompt.',bundle:agentResult?.bundle??{'index.html':await readFile(dir+'/project/index.html','utf8')}};
  if(deliveryChecks)result.delivery_checks=deliveryChecks;
  result.creative_review=agentResult?.state?reviewStatus(agentResult.state):{status:'incomplete',findings:['This output has not received an independent creative review.']};
  // The agent's last review scores travel with the version, so the card can offer another round.
  if(Array.isArray(agentResult?.state?.scores))result.review=agentResult.state.scores.slice(0,5);
  // Persist completion before sending: a callback failure must not trigger rendering again.
  await writeFile(dir+'/completion.json',JSON.stringify({result,report}),{mode:0o600});
  if(!report.directory.startsWith('/output/live/'+id+'/render/') || report.artifact !== 'video.mp4')throw Error('Invalid artifact path');
  await finish(run,result,path.join(root,'artifacts',report.directory.slice('/output/'.length),report.artifact));
  console.log(JSON.stringify({run:run.id,status:'preview_ready'}));
 }catch(e){
  clearInterval(timer);
  await trace({phase:'run',status:'failed',summary:'Worker encountered an error',detail:e.message});
  let stopped=false;
  try{await exec(docker,['rm','-f',container]);stopped=true;}catch{
   try{const {stdout}=await exec(docker,['ps','-a','--filter','name=^/'+container+'$','--format','{{.Names}}']);stopped=!stdout.trim();}catch{/* Docker unreachable: unknown */}
  }
  const uncertain=e.code==='ATTEMPT_NEEDS_ATTENTION';
  const result={status:stopped&&!uncertain?(cancelled||stopping?'cancelled':'failed'):'needs_attention',summary:uncertain?'Attempt outcome needs reconciliation; do not repeat it.':stopped?'Local render stopped without a usable result. See the local worker journal.':'Worker state is unknown. Reconcile before retrying.'};
  await writeFile(dir+'/failure.json',JSON.stringify({message:e.message,...result}),{mode:0o600});
  // If completion may already be accepted, the server rejects a conflicting result.
  if(!lost)try{await finish(run,result);}catch{}
  await flushTrace();
  if(stopped)try{await request('runs/'+run.id+'/stopped',{lease_token:run.lease_token,sandbox_stopped:true});}catch{/* Terminal runs reject this; uncertain holds remain if acknowledgement is lost. */}
  console.error(JSON.stringify({run:run.id,status:lost?'needs_attention':result.status}));
 }finally{clearInterval(timer);await trace({phase:'run',status:'finished',summary:'Worker execution ended; see authoritative run status and receipts'});await flushTrace();}
}
while(!stopping){
 try{const run=await request('claim',{});if(run)await execute(run);else if(process.argv.includes('--once'))break;}
 catch(e){console.error(e.message);if(process.argv.includes('--once'))process.exitCode=1;}
 if(process.argv.includes('--once'))break;
 await new Promise(resolve=>setTimeout(resolve,2000));
}
