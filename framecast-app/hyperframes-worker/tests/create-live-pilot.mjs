// Explicit, paid local pilot. Uses the additional $5 host ledger and API budget.
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import assert from 'node:assert/strict';import path from 'node:path';import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
if(process.env.CREATE_AGENT_LIVE!=='1')throw Error('Explicit paid pilot flag required');
const [kind='video',existing]=process.argv.slice(2);if(!['video','image','edit','video-edit','animate'].includes(kind))throw Error('Invalid pilot');
const api='http://localhost:8018/api/v1/create/';
async function call(endpoint,body,method='POST'){
 const r=await fetch(api+endpoint,{method,headers:{Authorization:'Bearer local-create-fixture','Content-Type':'application/json',Accept:'application/json'},body:method==='GET'?undefined:JSON.stringify(body??{})});
 const result=await r.json();if(!r.ok)throw Error(JSON.stringify(result));return result.data;
}
const out=root+'/artifacts/e3-pilot';await mkdir(out,{recursive:true});
let c=existing?(await call('conversations/'+existing,null,'GET')).conversation:await call('conversations',{output_kind:kind,aspect_ratio:'1:1',duration_seconds:10});
if(kind==='animate'){const parent=c,state=await call('conversations/'+c.id,null,'GET'),revision=state.revisions.find(r=>r.id===parent.head_revision_id);assert.ok(revision.output_asset_id);c=await call('conversations',{output_kind:'video',video_mode:'animate_image',duration_seconds:5,aspect_ratio:'1:1',audio:'silent',origin_conversation_id:parent.id,origin_revision_id:revision.id});await call('conversations/'+c.id+'/attachments',{asset_id:revision.output_asset_id,purpose:'source',reuse_confirmed:true,expected_version:0});c.version=1;}
const base='conversations/'+c.id;
const content=kind==='video-edit'?'Change only the first heading from One idea. to A fresh idea. Preserve every other word, colour, timing, composition and animation. Read the current source, patch only that heading, then preview at 1,5,9 seconds and visually review the attached contact sheet.':kind==='animate'?'Keep the orange geometric objects intact and in the same arrangement. A very gentle camera push in, subtle studio shadows. No new objects, people or text.':kind==='image'?'Create a square editorial still life of three matte orange geometric objects on a dark purple background, soft studio lighting, no people, no brands, no text.':kind==='edit'?'Keep the exact composition and objects from the latest image. Change only the background from dark purple to deep teal. No extra objects or text.':'Create a 10-second square typography video with three clear beats. Exact approved on-screen copy: One idea. Three clear beats. Your next story. Dark warm background with orange graphic accents, large legible typography, purposeful motion and reading pauses. No photos, products or audio. Completely replace the sample. Keep root 1080x1080 and duration 10. Use font.ttf and gsap.min.js. Write a complete compact composition, then use preview at 1,5,9 seconds, visually review, and finish.';
await call(base+'/messages',{content,expected_version:c.version,idempotency_key:crypto.randomUUID()});
const quote=await call(base+'/quotes',{expected_version:c.version+1});
const run=await call(base+'/runs',{quote_id:quote.id,approved:true,provider_approved:true,idempotency_key:crypto.randomUUID()});
console.log(JSON.stringify({kind,conversation:c.id,run:run.id,creditCeiling:quote.credits_max}));
await writeFile(out+'/'+run.id+'.json',JSON.stringify({kind,conversation:c.id,run:run.id}),{flag:'wx'});
const env=Object.fromEntries((await readFile('/tmp/create-e3-pilot.env','utf8')).split('\n').filter(l=>l.includes('=')).map(l=>[l.slice(0,l.indexOf('=')),l.slice(l.indexOf('=')+1)]));
const r=await promisify(execFile)(process.execPath,[root+'/agent/app-worker.mjs','--once'],{env:{...process.env,CREATE_API_URL:'http://localhost:8018',CREATE_WORKER_TOKEN:env.CREATE_WORKER_TOKEN,DOCKER_BIN:'/Applications/Docker.app/Contents/Resources/bin/docker'},timeout:720000,maxBuffer:1000000});
console.log(r.stdout);if(r.stderr)console.log(r.stderr);
const state=await call(base,null,'GET');await writeFile(out+'/'+run.id+'-result.json',JSON.stringify(state,null,2));
const current=state.runs.find(r=>r.id===run.id);console.log(JSON.stringify(current));
assert.equal(current.status,'preview_ready');
const revision=state.revisions.find(r=>r.run_id===run.id);assert.ok(revision);
const asset=await call(base+'/revisions/'+revision.id+'/save-output',{expected_version:state.conversation.version});
await writeFile(out+'/'+run.id+'-saved.json',JSON.stringify(asset));
console.log(JSON.stringify({saved:asset,conversation:c.id}));
