import test from 'node:test';
import assert from 'node:assert/strict';
import {confirmSandboxStopped,managedSandboxExec,createSandboxSupervisor} from '../sandbox-exec.mjs';
import {agentFailure,sandboxStopUnconfirmed} from '../sandbox-failure.mjs';
import {accountedCall} from '../accounted-call.mjs';
import {runAgent} from '../runner.mjs';
import {Workspace} from '../workspace.mjs';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';

const args=['compose','run','--name','wyv-create-audit','smoke','node'];
const expired=()=>Object.assign(Error('Queue wait expired'),{code:'SANDBOX_QUEUE_TIMEOUT',sandboxNotStarted:true,sandboxDiagnostic:{exit_code:75,acquired:false,stderr:'queue expired'}});
const fail=async()=>{throw Error('Docker unavailable');};

test('failed removal is safe only if absence can be confirmed',async()=>{
 assert.equal(await confirmSandboxStopped('docker',['wyv-create-audit'],{execute:async(c,a)=>{if(a[0]==='rm')throw Error('already removed');return {stdout:''};}}),true);
 assert.equal(await confirmSandboxStopped('docker',['wyv-create-audit'],{execute:async(c,a)=>{if(a[0]==='rm')throw Error('failed');return {stdout:'wyv-create-audit\n'};}}),false);
 assert.equal(await confirmSandboxStopped('docker',['wyv-create-audit'],{execute:fail}),false);
});
test('unconfirmed cleanup overrides a previously known queue failure',async()=>{
 await assert.rejects(managedSandboxExec('docker',args,{}, {execute:async()=>{throw expired();},stop:async()=>false}),e=>sandboxStopUnconfirmed(e)&&!e.sandboxNotStarted&&e.sandboxDiagnostic.cleanup_confirmed===false);
});
test('confirmed queue timeout settles a render once at zero cost without replay',async()=>{
 const settled=[];let executions=0;
 await assert.rejects(accountedCall({key:'audit',kind:'render',input:{},begin:async()=>({id:'audit',may_execute:true}),execute:()=>managedSandboxExec('docker',args,{}, {execute:async()=>{executions++;throw expired();},stop:async()=>true}),settle:async(id,r)=>settled.push(r),receipt:()=>({})}),e=>e.code==='SANDBOX_QUEUE_TIMEOUT');
 assert.equal(executions,1);assert.deepEqual(settled,[{status:'failed',cost_microusd:0}]);
});
test('lost zero-cost settlement acknowledgement still requires reconciliation',async()=>{
 await assert.rejects(accountedCall({key:'audit',kind:'render',input:{},begin:async()=>({id:'audit',may_execute:true}),execute:async()=>{throw expired();},settle:fail,receipt:()=>({})}),e=>e.code==='ATTEMPT_NEEDS_ATTENTION');
});
test('an unproven queue code or a provider failure never releases uncertain spend',async()=>{
 for(const [kind,proof] of [['render',false],['agent',true]]){
  const settled=[];
  await assert.rejects(accountedCall({key:'audit',kind,input:{},begin:async()=>({id:'audit',may_execute:true}),execute:async()=>{throw Object.assign(expired(),{sandboxNotStarted:proof});},settle:async(id,r)=>settled.push(r),receipt:()=>({})}),e=>e.code==='ATTEMPT_NEEDS_ATTENTION');
  assert.deepEqual(settled,[{status:'unknown'}]);
 }
});

async function agent(t,tools,actions){
 const dir=await mkdtemp('/tmp/wyv-sandbox-recovery-');t.after(()=>rm(dir,{recursive:true,force:true}));await writeFile(dir+'/index.html','<h1>Audit</h1>');
 let i=0;
 const state=await runAgent({stateFile:dir+'/state.json',context:{brief:'Audit'},workspace:new Workspace(dir,[]),provider:{id:'offline-audit',maxCallUsd:0,complete:async()=>({text:JSON.stringify(actions[i++])})},tools});
 return state;
}
test('queue reason and exit evidence survive the agent-to-host boundary',async t=>{
 const state=await agent(t,{check:async()=>{throw expired();}},[{type:'check'}]);const error=agentFailure(state);
 assert.equal(state.status,'failed');assert.equal(error.code,'SANDBOX_QUEUE_TIMEOUT');assert.equal(error.sandboxDiagnostic.exit_code,75);assert.equal(error.sandboxNotStarted,true);
});
test('unconfirmed cleanup cannot fall back to an earlier checked draft',async t=>{
 let checks=0;
 const state=await agent(t,{check:async()=>{if(++checks===1)return {ok:true};return managedSandboxExec('docker',args,{}, {execute:fail,stop:async()=>false});},snapshot:async()=>({ok:true,paths:['frame.png']})},[
  {type:'write',path:'index.html',content:'<h1>First draft</h1>'},{type:'check'},{type:'snapshot',times:[1]},
  {type:'write',path:'index.html',content:'<h1>Second draft</h1>'},{type:'check'},
 ]);
 assert.ok(state.lastGood,'the earlier checked draft exists');assert.equal(state.status,'needs_attention');assert.equal(agentFailure(state).code,'SANDBOX_STOP_UNCONFIRMED');
});
test('drain waits for late cleanup and blocks delivery after an agent deadline',async()=>{
 let finish;
 const supervisor=createSandboxSupervisor(()=>new Promise((resolve,reject)=>{finish=()=>reject(Object.assign(Error('not stopped'),{code:'SANDBOX_STOP_UNCONFIRMED'}));}));
 const task=supervisor.run();task.catch(()=>{});await Promise.resolve();
 let drained=false;const drain=supervisor.drain().finally(()=>{drained=true;});
 await Promise.resolve();assert.equal(drained,false);finish();await assert.rejects(drain,sandboxStopUnconfirmed);
});
