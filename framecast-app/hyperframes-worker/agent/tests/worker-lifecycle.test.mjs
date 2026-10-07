import test from 'node:test';
import assert from 'node:assert/strict';
import {createWorkerShutdown,runWorkerLoop} from '../worker-lifecycle.mjs';

test('deployment shutdown during a claim completes that lease without claiming another',async()=>{
 const shutdown=createWorkerShutdown();const events=[];
 const code=await runWorkerLoop({shutdown,
  claim:async()=>{events.push('claim');shutdown.signal('SIGTERM');return {run:{id:'accepted'},blocked:false};},
  execute:async run=>{assert.equal(run.id,'accepted');assert.equal(shutdown.stopRequested,false);events.push('settled');},
 });
 assert.equal(code,0);assert.deepEqual(events,['claim','settled']);
});

test('SIGTERM during execution waits for completion; repeating it never silently cancels',async()=>{
 const shutdown=createWorkerShutdown();let claims=0,completed=false;
 await runWorkerLoop({shutdown,claim:async()=>{claims++;return {run:{id:'one'}};},execute:async()=>{
  shutdown.signal('SIGTERM');shutdown.signal('SIGTERM');
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(shutdown.stopRequested,false);completed=true;
 }});
 assert.equal(claims,1);assert.equal(completed,true);
});

test('SIGINT requests immediate stop and lets execution report before loop exits',async()=>{
 const shutdown=createWorkerShutdown();let reported=false;
 await runWorkerLoop({shutdown,claim:async()=>({run:{id:'one'}}),execute:async()=>{
  shutdown.signal('SIGINT');assert.equal(shutdown.stopRequested,true);
  await new Promise(resolve=>setImmediate(resolve));reported=true;
 }});
 assert.equal(reported,true);
});

test('shutdown wakes a blocked disk poll and never claims after an idle drain',async()=>{
 const shutdown=createWorkerShutdown();let claims=0;
 await runWorkerLoop({shutdown,claim:async()=>{
  claims++;setImmediate(()=>shutdown.signal('SIGTERM'));return {run:null,blocked:true};
 },execute:async()=>assert.fail('No work was admitted')});
 assert.equal(claims,1);
 await runWorkerLoop({shutdown,claim:async()=>assert.fail('Already drained'),execute:async()=>assert.fail()});
});

test('one-shot mode preserves capacity and error exit codes',async()=>{
 assert.equal(await runWorkerLoop({shutdown:createWorkerShutdown(),once:true,claim:async()=>({blocked:true}),execute:async()=>assert.fail()}),75);
 const errors=[];
 assert.equal(await runWorkerLoop({shutdown:createWorkerShutdown(),once:true,claim:async()=>{throw Error('offline');},execute:async()=>assert.fail(),onError:e=>errors.push(e.message)}),1);
 assert.deepEqual(errors,['offline']);
});
