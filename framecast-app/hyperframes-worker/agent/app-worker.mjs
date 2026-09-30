// Local app bridge. Paid calls require both app and host opt-in plus durable limits.
// Credentials stay on the host; no shell text or Docker socket enters the sandbox.
import {readFile,writeFile,mkdir,copyFile,access,readdir,rename,unlink} from 'node:fs/promises';
import path from 'node:path';
import {PilotBudget} from './pilot-budget.mjs';
import {ReplicateProvider} from './replicate.mjs';
import {AnthropicGatewayProvider} from './anthropic-gateway.mjs';
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
 let seq=0,cancelled=false,lost=false,heartbeatBusy=false,stage='Preparing the local sample';
 const aborter=new AbortController();
 async function beat(){
  if(heartbeatBusy)return;heartbeatBusy=true;
  try{const state=await request('runs/'+run.id+'/heartbeat',{lease_token:run.lease_token,sequence:++seq,stage});cancelled||=state.cancel_requested;}
  catch{lost=true;}finally{heartbeatBusy=false;}
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
  let providerToken;
  const viaGateway=paid&&run.input.execution_policy?.agent?.provider==='anthropic'&&!run.input.execution_policy?.media;
  if(paid){
   if(process.env.CREATE_AGENT_LIVE!=='1')throw Error('Live local host is not enabled');
  }
  if(paid&&!viaGateway){
   const env=await readFile(root+'/../api/.env','utf8');
   providerToken=env.split('\n').find(l=>l.startsWith('REPLICATE_API_TOKEN='))?.split('=').slice(1).join('=').trim().replace(/^['"]|['"]$/g,'');
   if(!providerToken)throw Error('Missing local provider credential');
  }
  // Claude API calls keep their own local $5 test ledger so they never draw on the Replicate pilot's.
  const pilotBudget=new PilotBudget(root+'/artifacts/live/'+(viaGateway?'e3-opus-budget.json':'e3-2026-09-29-budget.json'));
  const begin=payload=>request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token});
  let reservation=null;
  const settle=async(attemptId,result)=>{const confirmed=await request('runs/'+run.id+'/attempts/'+attemptId+'/settle',{...result,lease_token:run.lease_token});if(reservation && confirmed.status==='succeeded'){await pilotBudget.settle(reservation,confirmed);reservation=null;}return confirmed;};
  const bindPrediction=(attemptId,predictionId)=>request('runs/'+run.id+'/attempts/'+attemptId+'/prediction',{lease_token:run.lease_token,prediction_id:predictionId});
  if(paid&&run.input.execution_policy?.media){
   stage=run.input.settings.output_kind==='image'?'Creating your image':'Animating your image';
   const image=await executeImage({directory:dir,input:run.input,manifest,token:providerToken,begin,settle,bindPrediction,signal:aborter.signal,fetchImpl:async(url,options)=>{if(options?.method==='POST' && String(url).endsWith('/predictions'))reservation=await pilotBudget.reserve(run.input.execution_policy.media.model,run.input.execution_policy.media.cost_limit_microusd/1e6);return fetch(url,options);}});
   if(image.type==='video/mp4'){await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/prepare-animation.mjs',id],{timeout:120000,maxBuffer:1000000});image.artifact=dir+'/animation-silent.mp4';}
   await beat();if(cancelled||lost||stopping)throw Error('Stopped before delivering image');
   await finish(run,{status:'preview_ready',summary:image.summary,bundle:image.bundle},image.artifact,image.type);
   return;
  }
  const agentModel=run.input.execution_policy?.agent?.model;
  const provider=viaGateway?new AnthropicGatewayProvider({model:agentModel,maxCallUsd:.3,
    call:(attemptId,body)=>request('runs/'+run.id+'/attempts/'+attemptId+'/anthropic',{...body,lease_token:run.lease_token},false,300000)})
   :paid?new ReplicateProvider({contract:JSON.parse(await readFile(root+'/agent/contracts/sonnet.json','utf8')),token:providerToken,enabled:true,maxCallUsd:.3}):offlineContractProvider(run.input.base_bundle,manifest);
  if(paid){const complete=provider.complete.bind(provider);provider.complete=async args=>{reservation=await pilotBudget.reserve(viaGateway?agentModel:'anthropic/claude-4.5-sonnet',.3);return complete(args);};}
  let agentResult;
  if(run.input.execution_policy?.agent){
   stage=paid?'Designing your video':'Running the offline agent contract check';
   const assetIds=new Map(manifest.map(f=>[f.name,f.asset_id]));
   agentResult=await executeCompositionAgent({directory:dir,input:run.input,manifest,
    transcribe:async({input})=>{const assetId=assetIds.get(input);if(!assetId)throw Error('Only supplied audio or video can be transcribed');return request('runs/'+run.id+'/transcripts',{lease_token:run.lease_token,asset_id:assetId},false,150000);},
    provider,guidanceDirectory:root+'/agent/guidance',signal:aborter.signal,
    bindPrediction:(attemptId,predictionId)=>request('runs/'+run.id+'/attempts/'+attemptId+'/prediction',{lease_token:run.lease_token,prediction_id:predictionId}),
    begin:payload=>request('runs/'+run.id+'/attempts',{...payload,lease_token:run.lease_token}),
    settle,receipt:output=>paid?({status:'succeeded',prediction_id:output.predictionId}):({status:'succeeded',cost_microusd:0}),
    invoke:async(operation,{times=[],signal,op,input,params}={})=>{if(operation==='media')await writeFile(dir+'/media-request.json',JSON.stringify({op,input,params:params??{}}),{mode:0o600});await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,operation,...(times.length?[times.join(',')]:[])],{signal,timeout:180000,maxBuffer:2000000});const result=JSON.parse(await readFile(dir+'/'+operation+'/result.json','utf8'));if(operation==='media')await unlink(dir+'/media-request.json').catch(()=>{});if(paid&&operation==='snapshot'&&result.ok)result.providerImage='data:image/jpeg;base64,'+(await readFile(dir+'/snapshot/contact-sheet.jpg')).toString('base64');return result;}});
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
    const from=ids.get(d.derivedFrom);if(!from)throw Error('Derived media has no known source');
    const form=new FormData();form.append('lease_token',run.lease_token);form.append('derived_from_asset_id',String(from));form.append('operation',d.operation);form.append('params',JSON.stringify(d.params??{}));
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
   execute:async()=>{await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,'render'],{timeout:180000,maxBuffer:2000000});const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));if(report.status!=='ready')throw Error('Render did not validate');return report;},
   receipt:()=>({status:'succeeded',cost_microusd:0})});
  clearInterval(timer);
  while(heartbeatBusy)await new Promise(resolve=>setTimeout(resolve,25));
  await beat();
  if(lost)throw Error('Worker lease lost; keep output for reconciliation');
  if(cancelled||stopping){await finish(run,{status:'cancelled',summary:'Local render stopped'});return;}
  const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
  if(report.status!=='ready')throw Error('Render did not produce a verified output');
  const bundleFiles=async()=>Object.fromEntries(await Promise.all((await readdir(dir+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)&&n!=='gsap.min.js').sort().map(async n=>[n,await readFile(dir+'/project/'+n,'utf8')])));
  const result=freeEdit?{status:'preview_ready',summary:'Updated '+Object.keys(run.input.edit_values??{}).length+' field(s). Free: no model call, one render.',bundle:await bundleFiles()}:{status:'preview_ready',summary:paid?agentResult.state.summary:'Local integration sample ready. This fixed sample does not represent your prompt.',bundle:agentResult?.bundle??{'index.html':await readFile(dir+'/project/index.html','utf8')}};
  // Persist completion before sending: a callback failure must not trigger rendering again.
  await writeFile(dir+'/completion.json',JSON.stringify({result,report}),{mode:0o600});
  if(!report.directory.startsWith('/output/live/'+id+'/render/') || report.artifact !== 'video.mp4')throw Error('Invalid artifact path');
  await finish(run,result,path.join(root,'artifacts',report.directory.slice('/output/'.length),report.artifact));
  console.log(JSON.stringify({run:run.id,status:'preview_ready'}));
 }catch(e){
  clearInterval(timer);
  let stopped=false;
  try{await exec(docker,['rm','-f',container]);stopped=true;}catch{
   try{const {stdout}=await exec(docker,['ps','-a','--filter','name=^/'+container+'$','--format','{{.Names}}']);stopped=!stdout.trim();}catch{/* Docker unreachable: unknown */}
  }
  const uncertain=e.code==='ATTEMPT_NEEDS_ATTENTION';
  const result={status:stopped&&!uncertain?(cancelled||stopping?'cancelled':'failed'):'needs_attention',summary:uncertain?'Attempt outcome needs reconciliation; do not repeat it.':stopped?'Local render stopped without a usable result. See the local worker journal.':'Worker state is unknown. Reconcile before retrying.'};
  await writeFile(dir+'/failure.json',JSON.stringify({message:e.message,...result}),{mode:0o600});
  // If completion may already be accepted, the server rejects a conflicting result.
  if(!lost)try{await finish(run,result);}catch{}
  console.error(JSON.stringify({run:run.id,status:lost?'needs_attention':result.status}));
 }finally{clearInterval(timer);}
}
while(!stopping){
 try{const run=await request('claim',{});if(run)await execute(run);else if(process.argv.includes('--once'))break;}
 catch(e){console.error(e.message);if(process.argv.includes('--once'))process.exitCode=1;}
 if(process.argv.includes('--once'))break;
 await new Promise(resolve=>setTimeout(resolve,2000));
}
