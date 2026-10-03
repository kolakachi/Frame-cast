import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import os from 'node:os';
import {actionEvent,createTrajectory} from '../trajectory.mjs';
test('reference trajectory records inspected pages and source identity, not image bytes',()=>{
 const coverage={start_seconds:.15,end_seconds:.65,decoded_frames:12,page:1,pages:2,shown_frames:8,next_page:2};
 const event=actionEvent({type:'inspect_reference'}, {ok:true,source_sha256:'a'.repeat(64),mode:'sequence',every_frame:true,coverage,cache:{hit:true},providerImage:'SECRET_IMAGE_BYTES'}, {status:'succeeded'});
 const detail=JSON.parse(event.detail);assert.deepEqual(detail.coverage,coverage);assert.equal(detail.cache_hit,true);assert.equal(detail.every_frame,true);assert.equal(detail.source_sha256,'a'.repeat(64));
 assert.ok(!JSON.stringify(event).includes('SECRET_IMAGE_BYTES'));
});
test('trajectory retries only telemetry, preserves sequence, and excludes source/credentials',async t=>{
 const dir=await mkdtemp(os.tmpdir()+'/trajectory-');t.after(()=>rm(dir,{recursive:true,force:true}));let requests=0;const delivered=[];
 const journal=createTrajectory({file:dir+'/trace.jsonl',send:async events=>{requests++;if(requests===1)throw Error('offline');delivered.push(...events);}});
 await journal.record(actionEvent({type:'write',path:'index.html',content:'PRIVATE SOURCE'},null,{status:'started',call:1,revision:0}));
 await journal.record(actionEvent({type:'write'},{error:'Bearer supersecret https://private.test/?token=secret /Users/me/key'},{status:'failed',call:1,revision:0}));
 await assert.rejects(journal.flush());assert.equal(journal.pending(),2);
 await Promise.all([journal.flush(),journal.flush()]);assert.equal(requests,2);assert.equal(journal.pending(),0);
 assert.deepEqual(delivered.map(e=>e.sequence),[1,2]);
 const text=await readFile(dir+'/trace.jsonl','utf8');for(const secret of ['PRIVATE SOURCE','supersecret','private.test','/Users/me/key'])assert.ok(!text.includes(secret));
 assert.match(delivered[0].input_hash,/^[a-f0-9]{64}$/);
});
test('trace is bounded at 2000 records',async t=>{
 const dir=await mkdtemp(os.tmpdir()+'/trajectory-');t.after(()=>rm(dir,{recursive:true,force:true}));let count=0;
 const journal=createTrajectory({file:dir+'/trace.jsonl',send:async events=>{assert.ok(events.length<=50);count+=events.length;}});
 for(let i=0;i<2002;i++)await journal.record({phase:'run',status:'reported',summary:'event'});
 await journal.flush();assert.equal(count,2000);
});
