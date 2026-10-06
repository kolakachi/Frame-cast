import test from 'node:test';
import assert from 'node:assert/strict';
import {sandboxExec} from '../sandbox-exec.mjs';

test('reports queue acquisition and runs once',async()=>{
 const states=[];
 const r=await sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_WAITING\\nWYV_SANDBOX_ACQUIRED\\n");console.log("once")'],{onQueue:s=>states.push(s)});
 assert.deepEqual(states,['waiting','acquired']);assert.equal(r.stdout.trim(),'once');
});
test('queue exhaustion has a distinct reason and exit evidence',async()=>{
 await assert.rejects(sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_WAITING\\n");process.exit(75)']),e=>e.code==='SANDBOX_QUEUE_TIMEOUT'&&e.sandboxDiagnostic.exit_code===75);
});
test('command exit 75 is not mislabeled as queue exhaustion',async()=>{
 await assert.rejects(sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_ACQUIRED\\n");process.exit(75)']),e=>e.code===75);
});
test('abort stops a waiting process',async()=>{
 const abort=new AbortController();
 await assert.rejects(sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_WAITING\\n");setInterval(()=>{},1000)'],{signal:abort.signal,onQueue:()=>abort.abort()}),e=>e.name==='AbortError');
});

test('execution deadline starts at acquisition without borrowing queue time',async()=>{
 const start=Date.now();
 await assert.rejects(sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_ACQUIRED\\n");setTimeout(()=>{},10000)'],{timeout:60}),e=>e.code==='SANDBOX_EXECUTION_TIMEOUT'&&e.sandboxDiagnostic.acquired);
 assert.ok(Date.now()-start<3000);
});
test('waiting does not consume the command execution allowance',async()=>{
 const result=await sandboxExec(process.execPath,['-e','process.stderr.write("WYV_SANDBOX_WAITING\\n");setTimeout(()=>{process.stderr.write("WYV_SANDBOX_ACQUIRED\\n");setTimeout(()=>console.log("done"),30)},250)'],{timeout:150});
 assert.equal(result.stdout.trim(),'done');
});
test('missing acquisition confirmation is uncertain, not confirmed unstarted',async()=>{
 await assert.rejects(sandboxExec(process.execPath,['-e','setInterval(()=>{},1000)'],{queueTimeout:80}),e=>e.code==='SANDBOX_START_TIMEOUT'&&!e.sandboxNotStarted);
});
test('a repeated acquisition marker cannot reset the execution deadline',async()=>{
 await assert.rejects(sandboxExec(process.execPath,['-e','setInterval(()=>process.stderr.write("WYV_SANDBOX_ACQUIRED\\n"),20)'],{timeout:80}),e=>e.code==='SANDBOX_EXECUTION_TIMEOUT');
});
