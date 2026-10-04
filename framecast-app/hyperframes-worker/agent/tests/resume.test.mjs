import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import os from 'node:os';import path from 'node:path';
import {findResume} from '../resume.mjs';
const id=n=>'00000000-0000-0000-0000-'+String(n).padStart(12,'0');
async function run(live,n,{conv='c1',plan='p1',stage='storyboard',at,status,lastGood,files={'index.html':'<h1>draft</h1>'}}){
 const dir=path.join(live,'app-'+id(n));await mkdir(dir+'/project',{recursive:true});
 await writeFile(dir+'/started.json',JSON.stringify({runId:id(n),startedAt:at,conversationId:conv,planId:plan,stage}));
 await writeFile(dir+'/agent-state.json',JSON.stringify({status,...(lastGood?{lastGood:{revision:3,files:lastGood}}:{})}));
 for(const [f,t] of Object.entries(files))await writeFile(dir+'/project/'+f,t);
}
const next={id:id(99),input:{conversation_id:'c1',plan:{plan_id:'p1'},build_stage:'storyboard'}};
test('a stopped build of the same plan and stage hands over its last checked draft, else its latest files',async t=>{
 const live=await mkdtemp(path.join(os.tmpdir(),'resume-'));t.after(()=>rm(live,{recursive:true,force:true}));
 await run(live,1,{at:'2026-10-04T10:00:00Z',status:'failed',lastGood:{'index.html':'<h1>checked</h1>','notes.txt':'x'},files:{'index.html':'<h1>later broken</h1>'}});
 let r=await findResume(next,live);assert.deepEqual(r,{files:{'index.html':'<h1>checked</h1>'},checked:true,from:id(1)});
 await run(live,2,{at:'2026-10-04T11:00:00Z',status:'cancelled',files:{'index.html':'<h1>half</h1>','main.js':'x'}});
 r=await findResume(next,live);assert.deepEqual(r,{files:{'index.html':'<h1>half</h1>','main.js':'x'},checked:false,from:id(2)});
 await run(live,3,{at:'2026-10-04T12:00:00Z',status:'preview_ready'});
 assert.equal(await findResume(next,live),null,'the newest run finished: nothing to continue');
 await run(live,4,{at:'2026-10-04T13:00:00Z',status:'failed',plan:'p2'});
 assert.equal(await findResume(next,live),null,'another plan is not continued');
});
