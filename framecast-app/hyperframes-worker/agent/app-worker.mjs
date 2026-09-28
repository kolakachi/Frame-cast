// Local app bridge. Fixed fixture only until paid settlement and model acceptance pass.
// No model credential is read, no shell text is executed, and no Docker socket enters the sandbox.
import {readFile,writeFile,mkdir,copyFile,access} from 'node:fs/promises';
import path from 'node:path';
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
async function request(endpoint,body,form=false){
 const response=await fetch(new URL('/api/internal/create/'+endpoint,base),{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json',...(!form?{'Content-Type':'application/json'}:{})},body:form?body:JSON.stringify(body),signal:AbortSignal.timeout(15000)});
 if(!response.ok)throw Error('Coordinator returned HTTP '+response.status);return (await response.json()).data;
}
async function finish(run,result,artifact){
 const form=new FormData();form.set('lease_token',run.lease_token);form.set('result',JSON.stringify(result));
 if(artifact)form.set('artifact',new Blob([await readFile(artifact)],{type:'video/mp4'}),'preview.mp4');
 return request('runs/'+run.id+'/finish',form,true);
}
async function execute(run){
 if(!/^[a-f0-9-]{36}$/.test(run.id)||run.input.mode!=='fixture')throw Error('Unsupported run contract');
 const id='app-'+run.id,dir=root+'/artifacts/live/'+id,container='wyv-create-'+run.id;
 await mkdir(dir,{recursive:true});
 // A prior process may have spent/rendered. Never replay an interrupted run.
 try{await access(dir+'/started.json');throw Error('Run journal exists; reconcile instead of replaying');}catch(e){if(e.code!=='ENOENT')throw e;}
 await writeFile(dir+'/started.json',JSON.stringify({runId:run.id,startedAt:new Date().toISOString()}),{flag:'wx',mode:0o600});
 await mkdir(dir+'/project');
 for(const name of ['index.html','product.svg'])await copyFile(root+'/fixtures/'+name,dir+'/project/'+name);
 let seq=0,cancelled=false,lost=false,heartbeatBusy=false;
 async function beat(){
  if(heartbeatBusy)return;heartbeatBusy=true;
  try{const state=await request('runs/'+run.id+'/heartbeat',{lease_token:run.lease_token,sequence:++seq,stage:'Rendering the local sample'});cancelled||=state.cancel_requested;}
  catch{lost=true;}finally{heartbeatBusy=false;}
  if(cancelled||lost||stopping){try{await exec(docker,['rm','-f',container]);}catch{/* final inspection below decides whether cancellation is safe */}}
 }
 await beat();
 const timer=setInterval(beat,20000);
 try{
  if(lost)throw Error('Coordinator unavailable before render');
  if(cancelled||stopping){await finish(run,{status:'cancelled',summary:'Cancelled before rendering'});return;}
  await exec(docker,['compose','-f',root+'/compose.local.yml','run','--rm','--name',container,'smoke','node','agent/live-tool.mjs',id,'render'],{timeout:180000,maxBuffer:2000000});
  clearInterval(timer);
  while(heartbeatBusy)await new Promise(resolve=>setTimeout(resolve,25));
  await beat();
  if(lost)throw Error('Worker lease lost; keep output for reconciliation');
  if(cancelled||stopping){await finish(run,{status:'cancelled',summary:'Local render stopped'});return;}
  const report=JSON.parse(await readFile(dir+'/render/result.json','utf8'));
  if(report.status!=='ready')throw Error('Render did not produce a verified output');
  const result={status:'preview_ready',summary:'Local integration sample ready. This fixed sample does not represent your prompt.',bundle:{'index.html':await readFile(dir+'/project/index.html','utf8')}};
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
  const result={status:stopped?(cancelled||stopping?'cancelled':'failed'):'needs_attention',summary:stopped?'Local render stopped without a usable result. See the local worker journal.':'Worker state is unknown. Reconcile before retrying.'};
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
