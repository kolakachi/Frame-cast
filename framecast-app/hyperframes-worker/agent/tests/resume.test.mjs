import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import os from 'node:os';import path from 'node:path';
import {findResume,planHash} from '../resume.mjs';
const id=n=>'00000000-0000-0000-0000-'+String(n).padStart(12,'0');
const input={conversation_id:'c1',plan:{plan_id:'p1',narration:['Hi']},settings:{duration_seconds:15},build_stage:'full_video'};
async function run(live,n,{at,status,lastGood,files={'index.html':'<h1>draft</h1>'},media={},inp=input}){
 const dir=path.join(live,'app-'+id(n));await mkdir(dir+'/project',{recursive:true});
 await writeFile(dir+'/started.json',JSON.stringify({runId:id(n),startedAt:at,conversationId:inp.conversation_id,planId:inp.plan.plan_id,stage:inp.build_stage,planHash:planHash(inp)}));
 await writeFile(dir+'/agent-state.json',JSON.stringify({status,...(lastGood?{lastGood:{revision:3,files:lastGood}}:{})}));
 for(const [f,t] of Object.entries({...files,...media}))await writeFile(dir+'/project/'+f,t);
 return dir;
}
const next={id:id(99),input};
test('a stopped build of the same approved plan hands over its last checked draft with the media it uses',async t=>{
 const live=await mkdtemp(path.join(os.tmpdir(),'resume-'));t.after(()=>rm(live,{recursive:true,force:true}));
 const html='<audio src="derived-2-speed.wav"></audio><audio src="asset-12-ab.wav"></audio><video src="clip-1.mp4"></video>';
 const dir=await run(live,1,{at:'2026-10-04T10:00:00Z',status:'failed',lastGood:{'index.html':html,'notes.txt':'x'},files:{'index.html':'<h1>later broken</h1>'},media:{'derived-2-speed.wav':'w','clip-1.mp4':'v'}});
 const r=await findResume(next,live,['asset-12-ab.wav']);
 assert.deepEqual(r.files,{'index.html':html});assert.equal(r.checked,true);assert.equal(r.from,id(1));
 assert.deepEqual(r.media.map(m=>m.name).sort(),['clip-1.mp4','derived-2-speed.wav'],'inputs are staged again; what the build made comes along');
 assert.equal(r.media[0].path.startsWith(dir+'/project/'),true);
});
test('a draft missing its media, a changed selection, a finished run or another plan is not continued',async t=>{
 const live=await mkdtemp(path.join(os.tmpdir(),'resume-'));t.after(()=>rm(live,{recursive:true,force:true}));
 await run(live,1,{at:'2026-10-04T10:00:00Z',status:'failed',files:{'index.html':'<audio src="derived-1-speed.wav"></audio>'}});
 assert.equal(await findResume(next,live),null,'its derived audio is gone');
 await run(live,2,{at:'2026-10-04T11:00:00Z',status:'failed',inp:{...input,plan:{...input.plan,narration:['Hello']}}});
 assert.equal(await findResume(next,live),null,'an edited plan is a new build');
 await run(live,3,{at:'2026-10-04T12:00:00Z',status:'preview_ready'});
 assert.equal(await findResume(next,live),null,'the newest run finished: nothing to continue');
});
test('a finished build whose run failed afterwards (saving its files) is continued from its final files',async t=>{
 const live=await mkdtemp(path.join(os.tmpdir(),'resume-'));t.after(()=>rm(live,{recursive:true,force:true}));
 const saved='asset-1698-'+'d'.repeat(64)+'.wav';
 const dir=await run(live,1,{at:'2026-10-04T10:00:00Z',status:'preview_ready',lastGood:{'index.html':'<img src="x1.png"><audio src="derived-1-fade.wav">'},files:{'index.html':`<img src="x1.png"><audio src="${saved}"></audio>`},media:{'x1.png':'p',[saved]:'w'}});
 await writeFile(dir+'/failure.json','{"message":"Derived media upload failed (422)"}');
 const r=await findResume(next,live);
 assert.equal(r.from,id(1));assert.deepEqual(r.media.map(m=>m.name).sort(),[saved,'x1.png'].sort(),'the file it saved comes along under its saved name');
});
test('a draft\'s references to an input that was re-prepared since are pointed at its new name',async()=>{
 const {renameInputs}=await import('../resume.mjs');
 const old='asset-1676-'+'a'.repeat(64)+'.mp4',now='asset-1676-'+'b'.repeat(64)+'.mp4',other='asset-12-'+'c'.repeat(64)+'.png';
 assert.deepEqual(renameInputs({'index.html':`<video src="${old}"></video><img src="${other}">`},[now,other]),{'index.html':`<video src="${now}"></video><img src="${other}">`});
});
