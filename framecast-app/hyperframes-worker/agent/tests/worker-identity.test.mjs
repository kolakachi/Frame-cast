import test from 'node:test';
import assert from 'node:assert/strict';
import {workerIdentity,verifyClaimAssignment} from '../worker-identity.mjs';

test('worker label stays stable but every process incarnation gets a new id',()=>{
 const a=workerIdentity({CREATE_WORKER_ID:'create-host-1'}),b=workerIdentity({CREATE_WORKER_ID:'create-host-1'});
 assert.equal(a.worker_id,b.worker_id);assert.equal(a.slot,'render-1');assert.notEqual(a.instance_id,b.instance_id);
 assert.equal(workerIdentity({}),null);
});

test('invalid host and slot labels fail before claiming',()=>{
 for(const env of [{CREATE_WORKER_SLOT:'render-1'},{CREATE_WORKER_ID:'../host'},{CREATE_WORKER_ID:'host\n'},{CREATE_WORKER_ID:'host',CREATE_WORKER_SLOT:'../../render'}])assert.throws(()=>workerIdentity(env));
});

test('a configured worker never executes a claim for a different process or missing assignment',()=>{
 const owner=workerIdentity({CREATE_WORKER_ID:'host-1'});
 const run={id:'run',assignment:{...owner,id:'11111111-1111-4111-8111-111111111111'}};
 assert.equal(verifyClaimAssignment(run,owner),run);
 assert.equal(verifyClaimAssignment(null,owner),null);
 for(const assignment of [undefined,{...run.assignment,instance_id:workerIdentity({CREATE_WORKER_ID:'host-1'}).instance_id},{...run.assignment,slot:'render-2'},{...run.assignment,worker_id:'other'},{...run.assignment,id:'invalid'}])
  assert.throws(()=>verifyClaimAssignment({...run,assignment},owner));
 assert.equal(verifyClaimAssignment({id:'legacy'},null).id,'legacy');
});
