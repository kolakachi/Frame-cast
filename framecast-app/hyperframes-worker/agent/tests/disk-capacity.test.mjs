import test from 'node:test';
import assert from 'node:assert/strict';
import {diskCapacity,diskPolicy,createDiskGate} from '../disk-capacity.mjs';

const policy={paths:['/work'],minFreeBytes:200,minFreeRatio:.1};
test('checks available bytes, percentage reserve and every mount',async()=>{
 const state=await diskCapacity({...policy,paths:['/work','/docker']},{read:async p=>({bsize:10,blocks:1000,bavail:p==='/work'?150:90})});
 assert.equal(state.ok,false);assert.equal(state.volumes[0].ok,true);
 assert.equal(state.volumes[1].required_bytes,1000);
});
test('uses the nearest existing directory, and fails closed on unknown capacity',async()=>{
 const visited=[];
 const result=await diskCapacity({...policy,paths:['/work/new']},{read:async p=>{visited.push(p);if(p.endsWith('/new'))throw Object.assign(Error(),{code:'ENOENT'});return {bsize:1,blocks:1000,bavail:201};}});
 assert.equal(result.ok,true);assert.deepEqual(visited,['/work/new','/work']);
 assert.equal((await diskCapacity(policy,{read:async()=>{throw Object.assign(Error(),{code:'EACCES'});}})).ok,false);
 assert.equal((await diskCapacity({...policy,strictPaths:['/work']},{read:async()=>{throw Object.assign(Error(),{code:'ENOENT'});}})).ok,false);
});
test('low disk never claims a job; recovery resumes claiming without a paid retry',async()=>{
 let free=100,claims=0;const events=[];
 const gate=createDiskGate(policy,{read:async()=>({bsize:1,blocks:1000,bavail:free}),notify:e=>events.push(e)});
 const claim=async()=>{claims++;return {id:'queued-job'};};
 assert.deepEqual(await gate.claim(claim),{blocked:true,run:null});
 await gate.claim(claim);assert.equal(claims,0);assert.equal(events.length,1);
 await assert.rejects(gate.require(),{code:'CREATE_DISK_CAPACITY'});
 free=300;assert.equal((await gate.claim(claim)).run.id,'queued-job');
 assert.equal(claims,1);assert.deepEqual(events.map(e=>e.state),['waiting','available']);
});
test('configuration rejects invalid reserves and relative extra mount paths',()=>{
 assert.throws(()=>diskPolicy('/worker',{CREATE_WORKER_MIN_FREE_BYTES:'wat'}));
 assert.throws(()=>diskPolicy('/worker',{CREATE_WORKER_MIN_FREE_RATIO:'2'}));
 assert.throws(()=>diskPolicy('/worker',{CREATE_WORKER_EXTRA_DISK_PATHS:'["relative"]'}));
 assert.ok(diskPolicy('/worker',{CREATE_WORKER_EXTRA_DISK_PATHS:'["/var/lib/docker"]'}).paths.includes('/var/lib/docker'));
});
