import {workerIdentity,verifyClaimAssignment} from './worker-identity.mjs';
import {verifyRelease} from './release-manifest.mjs';
import {createWorkerShutdown,runWorkerLoop} from './worker-lifecycle.mjs';
import {createSandboxSupervisor,confirmSandboxStopped} from './sandbox-exec.mjs';
import {agentFailure,sandboxStopUnconfirmed} from './sandbox-failure.mjs';
import {createTrajectory} from './trajectory.mjs';
import {reviewStatus} from './review-status.mjs';
import {finalChecks,repairable,repairBrief,audioForVerdict} from './final-checks.mjs';
import {moveFindings} from './move-check.mjs';
import {createDiskGate,diskPolicy} from './disk-capacity.mjs';
// Local app bridge. Paid calls require both app and host opt-in plus durable limits.
// Credentials stay on the host; no shell text or Docker socket enters the sandbox.
import {readFile,writeFile,mkdir,copyFile,access,readdir,rename,unlink} from 'node:fs/promises';
import path from 'node:path';
import {PilotBudget} from './pilot-budget.mjs';
import {ReplicateGatewayProvider} from './replicate-gateway.mjs';
import {AnthropicGatewayProvider} from './anthropic-gateway.mjs';
import {buyPlanMedia,stageFile} from './plan-media.mjs';
import {levelIfNeeded,summary as deliverySummary} from './delivery-checks.mjs';
import {listenToExport} from './audio-review.mjs';
import {cutTimes,outputPace,paceNotes} from './pace-review.mjs';
import {executeImage} from './media-provider.mjs';
import {stageInputs} from './stage-inputs.mjs';
import {executeCompositionAgent,offlineContractProvider,editBaseSources} from './composition-agent.mjs';
import {findResume,planHash} from './resume.mjs';
import {renderFailure,renderTransient} from './render-failure.mjs';
import {accountedCall} from './accounted-call.mjs';
import {apiOriginAllowed} from './api-origin.mjs';
import {fileURLToPath} from 'node:url';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
const exec=promisify(execFile),root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const base=process.env.CREATE_API_URL??'http://localhost:8000';
const url=new URL(base);
if(!apiOriginAllowed(url))throw Error('The API origin must be HTTPS, this machine, or a private network address');
const token=process.env.CREATE_WORKER_TOKEN;
if(!token||token.length<32)throw Error('Set the matching local CREATE_WORKER_TOKEN (32+ characters)');
const docker=process.env.DOCKER_BIN??'docker';
let sandboxCompose=root+'/compose.local.yml';
if(process.env.CREATE_RELEASE_REQUIRED==='1'){
 const manifest=JSON.parse(await readFile(root+'/RELEASE.json','utf8'));
 const inspection=await exec(docker,['image','inspect',manifest.sandbox_image],{timeout:30000,maxBuffer:1000000});
 await verifyRelease(root,manifest,JSON.parse(inspection.stdout)[0]);
 process.env.CREATE_SANDBOX_IMAGE=manifest.sandbox_image;
 sandboxCompose=root+'/compose.release.yml';
 console.error(JSON.stringify({event:'create.release_verified',revision:manifest.revision,sandbox_image:manifest.sandbox_image,art:manifest.art}));
}
const identity=workerIdentity();
const diskGate=createDiskGate(diskPolicy(root),{notify:event=>console.error(JSON.stringify(event))});
let stopping=false;
const shutdown=createWorkerShutdown({notify:event=>console.error(JSON.stringify(event))});
process.on('SIGINT',()=>{stopping=true;shutdown.signal('SIGINT');});
process.on('SIGTERM',()=>shutdown.signal('SIGTERM'));
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
 await writeFile(dir+'/started.json',JSON.stringify({runId:run.id,assignment:run.assignment??null,coordinatorPid:process.pid,startedAt:new Date().toISOString(),conversationId:run.input.conversation_id??null,planId:run.input.plan?.plan_id??null,stage:run.input.build_stage??null,planHash:planHash(run.input)}),{flag:'wx',mode:0o600});
 // The app listens to a sound file the build made (its export, or narration it edited) and returns the words.
 const listen=async file=>{const form=new FormData();form.set('lease_token',run.lease_token);form.set('file',new Blob([await readFile(file)]),path.basename(file));return request('runs/'+run.id+'/listen',form,true,180000);};
 await mkdir(dir+'/project');
 for(const name of ['index.html','product.svg'])await copyFile(root+'/fixtures/'+name,dir+'/project/'+name);
 // The reference's sound effects ride along for the sound pass (it runs in the sandbox, which sees only these files).
 await writeFile(dir+'/output-settings.json',JSON.stringify(run.input.mode==='fixture'?{aspect_ratio:'9:16',duration_seconds:15}:{...run.input.settings,...(run.input.plan?.reference_sound?{reference_sound:run.input.plan.reference_sound}:{})}),{flag:'wx'});
 const trajectory=createTrajectory({file:dir+'/trajectory.jsonl',send:events=>request('runs/'+run.id+'/trajectory',{lease_token:run.lease_token,events},false,10000)});
 const trace=async event=>{try{await trajectory.record(event);}catch{console.error('Local trajectory write failed for '+run.id);}};
 const flushTrace=async()=>{try{await trajectory.flush();}catch{console.error('Trajectory sync pending for '+run.id);}};
 await trace({phase:'run',status:'started',summary:'Worker claimed run'});
 let seq=0,cancelled=false,lost=false,heartbeatBusy=false,stage='Preparing the local sample';
 // Where the build is, for Stop: while designing, Stop lets the current step finish and keeps the last checked
 // version; once rendering, the render finishes and is delivered. Before that, and on shutdown, it stops at once.
 let sandboxWaiting=false;
 let phase='prepare',stopSeenAt=null;const STOP_GRACE_MS=360000;
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
  if(cancelled&&!lost&&!stopping&&!sandboxWaiting&&(phase==='agent'||phase==='render')){
   stopSeenAt??=Date.now();stage=phase==='agent'?'Stopping: finishing this step and keeping your last checked version':'Stopping: finishing the render of your last version';
   if(phase==='render'||Date.now()-stopSeenAt<STOP_GRACE_MS)return;
  }
  if(cancelled||lost||stopping){aborter.abort();await confirmSandboxStopped(docker,[container,container+'-delivery']);}
 }
 const sandboxSupervisor=createSandboxSupervisor();
 const runSandbox=async(command,args,options={})=>{
  const previousStage=stage;
  await diskGate.require();
  try{return await sandboxSupervisor.run(command,args,{...options,signal:options.signal??aborter.signal,onQueue:state=>{
   sandboxWaiting=state==='waiting';
   stage=sandboxWaiting?'Waiting for render capacity':previousStage;
   void trace({phase:'tool',tool:'sandbox_queue',status:sandboxWaiting?'started':'succeeded',summary:sandboxWaiting?'Waiting for render capacity':'Render capacity acquired'});
   void beat();
  }});}finally{sandboxWaiting=false;}
 };
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
   if(image.type==='video/mp4'){await runSandbox(docker,['compose','-f',sandboxCompose,'run','--rm','--name',container,'smoke','node','agent/prepare-animation.mjs',id],{timeout:120000,maxBuffer:1000000});image.artifact=dir+'/animation-silent.mp4';}
   await beat();if(cancelled||lost||stopping)throw Error('Stopped before delivering image');
   await finish(run,{status:'preview_ready',summary:image.summary,bundle:image.bundle},image.artifact,image.type);
   return;
  }
  const agentModel=run.input.execution_policy?.agent?.model;
  // The per-call cap approved with the run (deeper effort gets a higher one).
  const unlimited=run.input.execution_policy?.agent?.unlimited===true;
  const callCapUsd=(run.input.execution_policy?.agent?.cost_limit_microusd??300000)/1e6;
  const provider=viaGateway?new AnthropicGatewayProvider({model:agentModel,maxCallUsd:callCapUsd,
    call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/anthropic',{...body,lease_token:run.lease_token},false,960000)})
   :paid?new ReplicateGatewayProvider({maxCallUsd:callCapUsd,upload:image=>request('runs/'+run.id+'/replicate/prepare',{lease_token:run.lease_token,image},false,120000),call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/replicate',{...body,lease_token:run.lease_token},false,120000)}):offlineContractProvider(run.input.base_bundle,manifest);
  // Reserve local allowance before the app records the attempt, so running out never leaves a held call.
  if(paid)provider.reserve=async()=>{reservation=await pilotBudget.reserve(viaGateway?agentModel:'anthropic/claude-4.5-sonnet',callCapUsd,{unlimited});};
  if(paid)provider.release=async()=>{await pilotBudget.release(reservation);reservation=null;};
  // The bought plan items, kept at run scope: the final checks and the refusal report read them after the build.
  let agentResult,planMedia=[],agentArgs=null;
  // A character or storyboard step only buys its images: the user checks and approves them before anything is built.
  if(run.input.media_only===true){
   if(paid&&Array.isArray(run.input.plan_media)&&run.input.plan_media.length){
    const download=(assetId,signal)=>fetch(new URL('/api/internal/create/runs/'+run.id+'/inputs/'+assetId,base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({lease_token:run.lease_token}),signal:AbortSignal.any([signal??aborter.signal,AbortSignal.timeout(120000)])});
    planMedia=await buyPlanMedia({items:run.input.plan_media,directory:dir+'/inputs',manifest,signal:aborter.signal,onStage:s=>{stage=s;},
     produce:i=>request('runs/'+run.id+'/plan-media/'+i,{lease_token:run.lease_token},false,900000),
     download});
    if(lost||cancelled||stopping)throw Error('Stopped while getting plan media');
   }
   // A step is ready only when every image was made; otherwise it fails (the made ones are kept) and Retry redraws the rest.
   const missing=planMedia.find(m=>m.status!=='succeeded');
   if(missing)throw Object.assign(Error((missing.error||'An image could not be made').slice(0,300)),{code:'STEP_MEDIA_FAILED'});
   await finish(run,{status:'step_ready',summary:run.input.step==='character'?'The character is ready for you to check.':'The storyboard is ready for you to check.'});
   console.log(JSON.stringify({run:run.id,status:'step_ready',step:run.input.step}));
   return;
  }
  if(run.input.execution_policy?.agent){
   // One download for the plan's purchases and the builder's own (the buy tool below uses it too).
   const download=(assetId,signal)=>fetch(new URL('/api/internal/create/runs/'+run.id+'/inputs/'+assetId,base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({lease_token:run.lease_token}),signal:AbortSignal.any([signal??aborter.signal,AbortSignal.timeout(120000)])});
   // Buy the approved plan items first, so the design can use them.
   if(paid&&Array.isArray(run.input.plan_media)&&run.input.plan_media.length){
    planMedia=await buyPlanMedia({items:run.input.plan_media,directory:dir+'/inputs',manifest,signal:aborter.signal,onStage:s=>{stage=s;},
     produce:i=>request('runs/'+run.id+'/plan-media/'+i,{lease_token:run.lease_token},false,900000),
     download});
    if(lost||cancelled||stopping)throw Error('Stopped while getting plan media');
   }
   stage=paid?'Designing your video':'Running the offline agent contract check';
   const assetIds=new Map(manifest.map(f=>[f.name,f.asset_id]));
   // The activity line: what the agent is doing, the call count and the credits so far (model calls plus purchases).
   const mediaCredits=planMedia.reduce((n,m)=>n+(Number(m.charged_credits)||0),0);
   const onProgress=p=>{if(stopSeenAt)return;const credits=Math.round(p.spentUsd/0.004)+mediaCredits;stage=(p.doing+' · '+credits+' credits so far').slice(0,250);};
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
   phase='agent';
   // A full build from an approved look continues too; a small edit of a finished version starts from that version.
   const resume=run.input.mode==='agent'&&(!run.input.base_bundle||run.input.from_look)?await findResume(run,root+'/artifacts/live',manifest.map(f=>f.name)):null;
   if(resume)await trace({phase:'run',status:'started',summary:'Continuing from the build that stopped',detail:resume.from});
   agentArgs={directory:dir,manifest,planMedia,stopRequested:()=>cancelled&&!stopping&&!lost,onProgress,onTrace:trace,buy,
    transcribe:async({input})=>{const assetId=assetIds.get(input);
     // A file the build made itself (edited narration) is listened to directly.
     if(!assetId){if(!/^[a-zA-Z0-9_.-]+\.(wav|mp3|mp4)$/.test(input))throw Error('Only audio or video can be transcribed');const r=await listen(dir+'/project/'+input);return {text:r.text,words:r.words,segments:r.segments||[],provider:r.provider};}return request('runs/'+run.id+'/transcripts',{lease_token:run.lease_token,asset_id:assetId},false,150000);},
    provider,guidanceDirectory:root+'/agent/guidance',signal:aborter.signal,
    bindPrediction:(attemptId,predictionId)=>request('runs/'+run.id+'/attempts/'+attemptId+'/prediction',{lease_token:run.lease_token,prediction_id:predictionId}),
    begin:payload=>request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token}),
    settle,receipt:output=>paid?({status:'succeeded',prediction_id:output.predictionId}):({status:'succeeded',cost_microusd:0}),
    invoke:async(operation,{times=[],signal,op,input,params,cmd,args}={})=>{
     const payload=operation==='media'?{op,input,params:params??{}}:
      operation==='run'?{cmd,args}:operation==='inspect_reference'?{input,params}:operation==='detail'?{times:params?.times??[],sequences:params?.sequences??[]}:operation==='layout'?{times:params?.times??[]}:operation==='compare'?{reference:params?.reference,moments:params?.moments??[]}:null;
     const requestFile=dir+'/'+operation+'-request.json';
     // A command that runs past its limit is stuck, not slow: a 3D clip render gets 5 minutes, anything else 90 s.
     if(payload)await writeFile(requestFile,JSON.stringify(operation==='run'?{...payload,timeout_ms:cmd==='remotion'&&args?.[0]==='render'?300000:90000}:payload),{mode:0o600});
     await runSandbox(docker,['compose','-f',sandboxCompose,'run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,operation,...(times.length?[times.join(',')]:[])],{signal,timeout:900000,maxBuffer:8000000});
     const result=JSON.parse(await readFile(dir+'/'+operation+'/result.json','utf8'));
     if(payload)await unlink(requestFile).catch(()=>{});
     const imageFile=({snapshot:'snapshot/contact-sheet.jpg',strip:'strip/strip.jpg',inspect_reference:'inspect_reference/contact-sheet.jpg',detail:'detail/detail.jpg',compare:'compare/compare.jpg'})[operation];
     if(paid&&imageFile&&result.ok)result.providerImage='data:image/jpeg;base64,'+(await readFile(dir+'/'+imageFile)).toString('base64');
     return result;
    }};
   agentResult=await executeCompositionAgent({...agentArgs,input:resume?{...run.input,resume}:run.input});
   await sandboxSupervisor.drain();
   if(['needs_input','awaiting_media_approval'].includes(agentResult.state.status)){
    await finish(run,{status:'needs_input',summary:(agentResult.state.question??agentResult.state.proposal??'Please clarify your brief.').slice(0,2000)});return;
   }
   if(agentResult.state.status!=='preview_ready')throw agentFailure(agentResult.state);
   // From here a finished version exists: Stop lets it be saved and rendered.
   phase='render';
   // The review detail users no longer see stays in the trajectory.
   if(agentResult.state.internalNote)await trace({phase:'run',status:'delivered',summary:'Version delivered',detail:String(agentResult.state.internalNote).slice(0,1900)});
  }
  // Render and check, and when the final check blocks on something the build can fix, fix it and do it again: at most
  // two repair rounds (todo D), within the calls already approved, never charged (a repair corrects our own work).
  let deliveryChecks=null,audioReview=null,paceReview=null,finalReview=null;const uploaded=new Set();
  let lastFixKey=null;
  let renderAttempts=0;
  for(let round=0;;round++){
  // Derived media becomes a permanent source before rendering: upload it, give
  // it its stored name, and point the composition at that name, so later
  // versions and free edits inherit exactly these bytes.
  if(agentResult?.derived?.some(d=>!uploaded.has(d.path))){
   stage='Saving your edited footage';await beat();
   const ids=new Map(manifest.map(f=>[f.name,f.asset_id]));
   // Only what the finished composition uses: a builder that tried several frame grabs leaves the discarded ones behind.
   const used=Object.values(agentResult.bundle??{}).join('\n');
   // A used file keeps its lineage: the files it was made from are saved too (music ducked, then faded, keeps the
   // ducked file as the faded one's source), even though the composition only names the last one.
   const byPath=new Map(agentResult.derived.map(d=>[d.path,d])),needed=new Set();
   for(const d of agentResult.derived)if(!agentResult.bundle||used.includes(d.path))for(let x=d,i=0;x&&i<12&&!needed.has(x.path);i++){needed.add(x.path);x=byPath.get(x.derivedFrom??x.origin);}
   for(const d of agentResult.derived){
    if(uploaded.has(d.path))continue;
    if(!needed.has(d.path))continue;
    uploaded.add(d.path);
    // A run-made file may have no source (generated from scratch); a media edit always has one.
    const from=ids.get(d.derivedFrom??d.origin);if(!from&&d.operation!=='run'&&d.operation!=='library')throw Error('Derived media has no known source');
    const form=new FormData();form.append('lease_token',run.lease_token);if(from)form.append('derived_from_asset_id',String(from));form.append('operation',d.operation);form.append('params',JSON.stringify(d.params??{}));
    form.append('file',new Blob([await readFile(dir+'/project/'+d.path)]),d.path);
    const response=await fetch(new URL('/api/internal/create/runs/'+run.id+'/derived',base),{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token,Accept:'application/json'},body:form,signal:aborter.signal});
    if(!response.ok)throw Error('Derived media upload failed ('+response.status+')');
    const record=(await response.json()).data;
    if(record.sha256!==d.sha256)throw Error('Derived media hash mismatch');
    await rename(dir+'/project/'+d.path,dir+'/project/'+record.name);uploaded.add(record.name);ids.set(d.path,record.asset_id);ids.set(record.name,record.asset_id);
    for(const name of (await readdir(dir+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n))){
     const text=await readFile(dir+'/project/'+name,'utf8');if(text.includes(d.path))await writeFile(dir+'/project/'+name,text.split(d.path).join(record.name),{mode:0o600});
    }
    for(const [k,v] of Object.entries(agentResult.bundle))agentResult.bundle[k]=v.split(d.path).join(record.name);
   }
  }
  phase='render';if(!stopSeenAt)stage=paid?'Rendering your video':'Rendering the local sample';
  // One render; a page that only timed out loading (a busy host) is rendered once more, within the approved renders.
  const renderOnce=suffix=>accountedCall({key:'render-'+(round+1)+suffix,kind:'render',input:{runId:run.id,mode:run.input.mode},
   begin:async payload=>{const attempt=await request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token});await writeFile(dir+'/render-attempt'+(round?'-'+round:'')+suffix+'.json',JSON.stringify(attempt),{flag:'wx',mode:0o600});return attempt;},
   settle:(attemptId,result)=>request('runs/'+run.id+'/attempts/'+attemptId+'/settle',{...result,lease_token:run.lease_token}),
   execute:async()=>{await runSandbox(docker,['compose','-f',sandboxCompose,'run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,'render'],{timeout:Math.max(1800000,180000*renderLoad(run.input.settings)),maxBuffer:2000000});const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));if(report.status==='failed' && report.artifact===null)throw Object.assign(Error(await renderFailure(report,root)),{code:'LOCAL_RENDER_FAILED'});if(report.status!=='ready')throw Error('Render outcome could not be verified');return report;},
   receipt:()=>({status:'succeeded',cost_microusd:0})});
  renderAttempts++;
  try{await renderOnce('');}
  catch(e){
   const report=e.code==='LOCAL_RENDER_FAILED'?JSON.parse(await readFile(dir+'/render/result.json','utf8').catch(()=>'{}')):{};
   if(e.code!=='LOCAL_RENDER_FAILED'||renderAttempts>=3||stopping||lost||!await renderTransient(report,root))throw e;
   await trace({phase:'render',status:'failed',summary:'The render page timed out loading; rendering again',detail:e.message.slice(0,300)});
   renderAttempts++;await renderOnce('-again');
  }
  // Delivery checks on the final file: platform safe area, frame edges,
  // contrast and loudness. Reported with the version; loudness is levelled.
  deliveryChecks=null;audioReview=null;paceReview=null;finalReview=null;
  if(!(stopping||lost)){
   stage='Checking the final video';
   try{
    await runSandbox(docker,['compose','-f',sandboxCompose,'run','--rm','--name',container+'-delivery','smoke','node','agent/live-tool.mjs',id,'delivery'],{timeout:900000,maxBuffer:2000000});
    const sandbox=JSON.parse(await readFile(dir+'/delivery/result.json','utf8'));
    const rendered=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
    const loudness=await levelIfNeeded(path.join(root,'artifacts',rendered.directory.slice('/output/'.length),rendered.artifact),{silent:run.input.settings?.audio==='silent'});
    deliveryChecks=deliverySummary(run.input.look_first===true?{...sandbox,pacing:(sandbox.pacing||[]).filter(f=>f.code!=='still_stretch')}:sandbox,loudness);
   }catch(error){if(sandboxStopUnconfirmed(error))throw error;deliveryChecks=null;await trace({phase:'review',status:'failed',summary:'Delivery checks unavailable',detail:error.message});}
   // Listen to the export: narration against the script, text against speech, voice over music, the ending.
   const plan=run.input.plan??{};
   if(paid&&run.input.look_first!==true&&run.input.settings?.audio!=='silent'&&((plan.narration||[]).length||(plan.requirements||[]).length)){
    stage='Listening to the final video';
    try{
     const rendered=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
     audioReview=await listenToExport({file:path.join(root,'artifacts',rendered.directory.slice('/output/'.length),rendered.artifact),html:await readFile(dir+'/project/index.html','utf8').catch(()=>''),requirements:plan.requirements||[],listen});
     await trace({phase:'review',status:audioReview.summary.ok?'succeeded':'failed',summary:'Listened to the final video',detail:JSON.stringify(audioReview.summary).slice(0,1900)});
    }catch(e){audioReview=null;await trace({phase:'review',status:'failed',summary:'Listening check unavailable',detail:String(e.message).slice(0,300)});}
   }
   // The finished video's rhythm against the reference's: suggestions only.
   if(paid&&run.input.look_first!==true&&plan.reference_pacing)try{
    const rendered=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
    const file=path.join(root,'artifacts',rendered.directory.slice('/output/'.length),rendered.artifact);
    const pace=outputPace({cuts:await cutTimes(file),duration:audioReview?.duration||Number(run.input.settings?.duration_seconds)||0,words:audioReview?.words||[],sync:audioReview?.summary?.sync||[]});
    paceReview={pace,notes:paceNotes(plan.reference_pacing,pace)};
    await trace({phase:'review',status:'succeeded',summary:'Compared the rhythm with the reference',detail:JSON.stringify({reference:plan.reference_pacing,...paceReview}).slice(0,1900)});
   }catch{paceReview=null;}
   // The finished video against what was approved (todo D): words, required items, identity, blank frames, planned moves.
   if(paid&&run.input.look_first!==true)try{
    stage='Checking the final video against your plan';
    const rendered=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
    const file=path.join(root,'artifacts',rendered.directory.slice('/output/'.length),rendered.artifact);
    const look=async frames=>{const form=new FormData();form.set('lease_token',run.lease_token);for(const [k,f] of frames.entries()){form.append('times[]',String(f.time));form.append('labels[]',f.label||'');form.append('frames[]',new Blob([f.jpeg],{type:'image/jpeg'}),'f'+k+'.jpg');}return request('runs/'+run.id+'/look',form,true,180000);};
    const sources=Object.entries(agentResult?.bundle??{}).filter(([n])=>!/^(gsap|wyv-|barty-)/.test(n)).map(([,t])=>t).join('\n');
    finalReview=await finalChecks({file,duration:audioReview?.duration||Number(run.input.settings?.duration_seconds)||15,plan:{...plan,settings_audio:run.input.settings?.audio},planMedia,
     audioSummary:audioForVerdict(audioReview?.summary),reading:(deliveryChecks?.pacing||[]).filter(f=>f.code==='reading_time'),moves:moveFindings({plan,sources,base:editBaseSources(run.input),requireRuntime:true,ran:await readFile(dir+'/render/moves.json','utf8').then(t=>{const m=JSON.parse(t);return Array.isArray(m)?m:null;}).catch(()=>null)}),look,html:agentResult?.bundle?.['index.html']||''});
    await trace({phase:'review',status:finalReview.status==='blocked'?'failed':'succeeded',summary:'Checked the final video against the plan',detail:JSON.stringify(finalReview).slice(0,1900)});
   }catch(e){
    // Checks that could not run are unverified, never absent: the version is marked as not checked.
    finalReview={status:'unverified',checks:[{id:'final',label:'The final video was checked',status:'unverified',blocking:true,message:'The final checks could not run: '+String(e.message).slice(0,160),times:[]}],findings:[]};
    await trace({phase:'review',status:'failed',summary:'Final checks unavailable',detail:String(e.message).slice(0,300)});}
  }
  const fixable=repairable(finalReview,{takeUsed:planMedia.some(m=>m.kind==='ugc_take'&&m.status==='succeeded')});
  if(!paid||!agentArgs||finalReview?.status!=='blocked'||!fixable.length||round>=2||renderAttempts>=3||stopping||lost)break;
  // A round that ends with exactly the same problems did not help: another would not either.
  const fixKey=JSON.stringify(fixable.map(c=>[c.id,c.label,c.message]));
  if(fixKey===lastFixKey){await trace({phase:'review',status:'failed',summary:'Repair stopped: the same problems remained after a round',detail:fixKey.slice(0,600)});break;}
  lastFixKey=fixKey;
  stage='Fixing what the final check found';await beat();
  const brief=repairBrief(fixable);
  await trace({phase:'review',status:'started',summary:'Repair round '+(round+1),detail:brief.slice(0,1900)});
  const repaired=await executeCompositionAgent({...agentArgs,callPrefix:'repair'+(round+1)+'-agent',input:{...run.input,resume:{files:agentResult.bundle},messages:[...(run.input.messages||[]),{role:'user',content:brief}]}}).catch(e=>({state:{status:'failed',reason:e.message}}));
  await sandboxSupervisor.drain();
  if(repaired?.state?.failureCode==='SANDBOX_STOP_UNCONFIRMED')throw agentFailure(repaired.state);
  if(repaired?.state?.status!=='preview_ready'){await trace({phase:'review',status:'failed',summary:'Repair round '+(round+1)+' did not finish; the checked version stands',detail:String(repaired?.state?.reason||'').slice(0,300)});break;}
  agentResult=repaired;
  }
  clearInterval(timer);
  while(heartbeatBusy)await new Promise(resolve=>setTimeout(resolve,25));
  await beat();
  if(lost)throw Error('Worker lease lost; keep output for reconciliation');
  if(stopping){await finish(run,{status:'cancelled',summary:'Stopped. Earlier versions are safe.'});return;}
  // A user Stop after a checked version exists still delivers that version (the app accepts it while stopping).
  const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
  if(report.status!=='ready')throw Error('Render did not produce a verified output');
  const bundleFiles=async()=>Object.fromEntries(await Promise.all((await readdir(dir+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)&&n!=='gsap.min.js'&&n!=='wyv-motion.js').sort().map(async n=>[n,await readFile(dir+'/project/'+n,'utf8')])));
  const result=freeEdit?{status:'preview_ready',summary:'Updated '+Object.keys(run.input.edit_values??{}).length+' field(s). Free: no model call, one render.',bundle:await bundleFiles()}:{status:'preview_ready',summary:paid?fitSummary(agentResult.state.summary??'',cutNote(agentResult.state.edits)):'Local integration sample ready. This fixed sample does not represent your prompt.',bundle:agentResult?.bundle??{'index.html':await readFile(dir+'/project/index.html','utf8')}};
  if(deliveryChecks)result.delivery_checks=deliveryChecks;
  // What was heard settles the requirements that wait for listening, and its problems join Before you post.
  if(audioReview){
   result.delivery_checks={...(result.delivery_checks||{}),audio:{ok:audioReview.summary.ok,problems:audioReview.summary.problems.slice(0,6),script_coverage:audioReview.summary.script_coverage??null}};
  }
  result.creative_review=agentResult?.state?reviewStatus(agentResult.state):{status:'incomplete',findings:['The build ended before its checks finished.']};
  // Pacing measured on the delivered video reaches the user too; a version ready for review with notes has issues.
  if(paceReview?.notes?.length){result.creative_review.findings=[...(result.creative_review.findings||[]),...paceReview.notes].slice(0,8);if(result.creative_review.status==='ready')result.creative_review.status='issues';}
  if(audioReview){const heardIds=new Set(audioReview.checks.map(c=>c.id));result.creative_review.requirement_checks=[...(result.creative_review.requirement_checks||[]).filter(c=>!heardIds.has(c.id)),...audioReview.checks].slice(0,24);}
  // The final checks decide what the user is told: a blocking failure makes the version "not ready" with its evidence;
  // checks that could not run are shown as unverified, never as passed.
  if(finalReview){
   result.final_checks=finalReview;
   if(finalReview.status==='blocked'){result.creative_review.status='blocked';result.creative_review.findings=[...finalReview.findings,...(result.creative_review.findings||[])].slice(0,8);}
   else if(finalReview.status==='issues'){result.creative_review.findings=[...(result.creative_review.findings||[]),...finalReview.findings].slice(0,8);if(result.creative_review.status==='ready'||result.creative_review.status==='passed')result.creative_review.status='issues';}
   else if(finalReview.status==='unverified'&&result.creative_review.status==='passed')result.creative_review.status='ready';
  }
  // Shots a model refused: the user chooses whether to make them on the next-best engine, at its price (C3).
  {const items=run.input.plan_media??[];let n=0;const sugg=[];
   for(const [k,it] of items.entries()){if(it.kind!=='generated_shot')continue;n++;const m=planMedia[k];if(m?.suggest)sugg.push({shot:n,engine:m.suggest.engine,label:m.suggest.label,credits:m.suggest.credits,declined_by:it.engine_label||it.engine||''});}
   if(sugg.length){result.media_suggestions=sugg;result.creative_review.findings=[...sugg.map(x=>'Shot '+x.shot+' was declined by '+(x.declined_by||'the video model')+'; it can be made on '+x.label+' for '+x.credits+' credits.'),...(result.creative_review.findings||[])].slice(0,8);if(result.creative_review.status==='ready'||result.creative_review.status==='passed')result.creative_review.status='issues';}}
  // The agent's last review scores travel with the version, so the card can offer another round.
  if(Array.isArray(agentResult?.state?.scores))result.review=agentResult.state.scores.slice(0,5);
  // Persist completion before sending: a callback failure must not trigger rendering again.
  await writeFile(dir+'/completion.json',JSON.stringify({result,report}),{mode:0o600});
  if(!report.directory.startsWith('/output/live/'+id+'/render/') || report.artifact !== 'video.mp4')throw Error('Invalid artifact path');
  await finish(run,result,path.join(root,'artifacts',report.directory.slice('/output/'.length),report.artifact));
  console.log(JSON.stringify({run:run.id,status:'preview_ready'}));
 }catch(e){
  clearInterval(timer);
  try{await sandboxSupervisor.drain();}catch(cleanupError){e=cleanupError;}
  await trace({phase:'run',status:'failed',summary:'Worker encountered an error',detail:e.message+(e.sandboxDiagnostic?' '+JSON.stringify(e.sandboxDiagnostic):'')});
  const stopped=await confirmSandboxStopped(docker,[container,container+'-delivery']);
  const uncertain=e.code==='ATTEMPT_NEEDS_ATTENTION'||sandboxStopUnconfirmed(e);
  const result={status:stopped&&!uncertain?(cancelled||stopping?'cancelled':'failed'):'needs_attention',summary:uncertain?'This build stopped while a step was in progress. We are checking it before anything runs again; earlier versions are safe.':stopped?'This build stopped before it finished. You are only charged for the work it did, and earlier versions are safe. Try again, or change the brief.':'This build lost contact before it finished. We are checking it before anything runs again; earlier versions are safe.'};
  if(e.code==='SANDBOX_QUEUE_TIMEOUT'&&result.status==='failed')result.summary='The render queue stayed busy too long. Your saved assets are safe. Please try again later.';
  if(e.code==='CREATE_DISK_CAPACITY'&&result.status==='failed')result.summary='This build stopped because the worker ran short of disk space. Existing assets and drafts are retained. Please try again after capacity is restored.';
  await writeFile(dir+'/failure.json',JSON.stringify({message:e.message,diagnostic:e.sandboxDiagnostic??null,...result}),{mode:0o600});
  // If completion may already be accepted, the server rejects a conflicting result.
  if(!lost)try{await finish(run,result);}catch{}
  await flushTrace();
  if(stopped)try{await request('runs/'+run.id+'/stopped',{lease_token:run.lease_token,sandbox_stopped:true});}catch{/* Terminal runs reject this; uncertain holds remain if acknowledgement is lost. */}
  console.error(JSON.stringify({run:run.id,status:lost?'needs_attention':result.status}));
 }finally{clearInterval(timer);await trace({phase:'run',status:'finished',summary:'Worker execution ended; see authoritative run status and receipts'});await flushTrace();}
}
// How much longer than a plain 24 fps render the final render takes (60 fps, motion blur).
function renderLoad(settings){const fps=[30,60].includes(settings?.frame_rate)?settings.frame_rate:24;return Math.max(1,fps*(settings?.motion_blur===true?(fps>=60?2:4):1)/24);}
process.exitCode=await runWorkerLoop({shutdown,
 claim:()=>diskGate.claim(async()=>verifyClaimAssignment(await request('claim',identity??{}),identity)),execute,
 once:process.argv.includes('--once'),onError:e=>console.error(e.message),
});
