import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,rm,readFile} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {whenModelFree} from '../composition-agent.mjs';
import {PilotBudget} from '../pilot-budget.mjs';
const notSent=()=>Object.assign(Error('Anthropic could not be reached; nothing was sent or charged.'),{code:'NOT_SENT'});
test('a busy model is waited out and asked again; each wait gives the reservation back',async()=>{
 let tries=0,released=0;const waits=[];
 const out=await whenModelFree(async()=>{if(++tries<3)throw notSent();return 'answer';},{waits:[5,5,5],release:async()=>{released++;},onWait:(n,ms)=>waits.push([n,ms])});
 assert.equal(out,'answer');assert.equal(tries,3);assert.equal(released,2);assert.deepEqual(waits,[[1,5],[2,5]]);
});
test('after the last wait the not-sent error is passed on; other errors are never retried',async()=>{
 let tries=0;
 await assert.rejects(whenModelFree(async()=>{tries++;throw notSent();},{waits:[1,1]}),e=>e.code==='NOT_SENT');assert.equal(tries,3);
 tries=0;
 await assert.rejects(whenModelFree(async()=>{tries++;throw Object.assign(Error('uncertain'),{code:'ATTEMPT_NEEDS_ATTENTION'});},{waits:[1]}),/uncertain/);assert.equal(tries,1,'a call that may have been sent is never repeated');
});
test('Stop during a wait ends it at once',async()=>{
 const c=new AbortController();setTimeout(()=>c.abort(Error('stopped')),20);
 const t=Date.now();
 await assert.rejects(whenModelFree(async()=>{throw notSent();},{waits:[60000],signal:c.signal}),/stopped/);
 assert.ok(Date.now()-t<2000);
});
test('the pilot ledger frees the reservation of a call that was never sent',async()=>{
 const dir=await mkdtemp(path.join(os.tmpdir(),'pilot-'));
 try{
  const b=new PilotBudget(dir+'/ledger.json',1);
  const id=await b.reserve('claude-opus-5-5',.45);await b.reserve('claude-opus-5-5',.45);
  await assert.rejects(b.reserve('claude-opus-5-5',.45),/exhausted/);
  await b.release(id);
  await b.reserve('claude-opus-5-5',.45);
  const ledger=JSON.parse(await readFile(dir+'/ledger.json','utf8'));assert.equal(ledger.calls.find(c=>c.id===id).status,'not_sent');
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('Stop during a busy-model wait ends the wait with the not-sent error, so the runner keeps the last checked version',async()=>{
 let stop=false;setTimeout(()=>{stop=true;},30);
 const t=Date.now();
 await assert.rejects(whenModelFree(async()=>{throw notSent();},{waits:[60000],stop:()=>stop}),e=>e.code==='NOT_SENT');
 assert.ok(Date.now()-t<3000);
});
