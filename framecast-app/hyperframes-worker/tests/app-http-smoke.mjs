// Use the disposable create-http-fixture.php harness, never a real account.
import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {mkdir,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const api=process.env.CREATE_API_URL??'http://localhost:8018';
if(!['localhost','127.0.0.1'].includes(new URL(api).hostname))throw Error('Local fixture required');
async function call(endpoint,body,method='POST'){
 const response=await fetch(api+'/api/v1/create/'+endpoint,{method,headers:{Authorization:'Bearer local-create-fixture','Content-Type':'application/json',Accept:'application/json'},...(method!=='GET'?{body:JSON.stringify(body??{})}:{})});
 const json=await response.json();assert.equal(response.status>=200&&response.status<300,true,JSON.stringify(json));return json.data;
}
const c=await call('conversations',{}),base='conversations/'+c.id;
await call(base+'/messages',{content:'Local HTTP integration test: preserve previous outputs.',idempotency_key:crypto.randomUUID(),expected_version:0});
const q=await call(base+'/quotes',{expected_version:1});assert.equal(q.credits_max,0);
const key=crypto.randomUUID(),run=await call(base+'/runs',{quote_id:q.id,idempotency_key:key,approved:true});
const replay=await call(base+'/runs',{quote_id:q.id,idempotency_key:key,approved:true});assert.equal(run.id,replay.id);
const {stdout}=await promisify(execFile)(process.execPath,[root+'/agent/app-worker.mjs','--once'],{env:{...process.env,CREATE_API_URL:api},timeout:240000,maxBuffer:1000000});
const state=await call(base,null,'GET');
assert.equal(state.runs.at(-1).status,'preview_ready',JSON.stringify(state.runs));
const revision=state.revisions[0];assert.ok(revision.artifact_hash);
const output=await fetch(api+'/api/v1/create/'+base+'/revisions/'+revision.id+'/artifact',{headers:{Authorization:'Bearer local-create-fixture'}});
assert.equal(output.status,200);assert.equal(output.headers.get('content-type'),'video/mp4');
const bytes=Buffer.from(await output.arrayBuffer());assert.ok(bytes.length>1000);
await mkdir(root+'/artifacts/app-integration',{recursive:true});await writeFile(root+'/artifacts/app-integration/preview.mp4',bytes);
const restored=await call(base+'/revisions/'+revision.id+'/restore',{expected_version:2});assert.notEqual(restored.revision_id,revision.id);
const final=await call(base,null,'GET');assert.equal(final.revisions.length,2);
const evidence={conversation:c.id,run:run.id,artifactBytes:bytes.length,revision:revision.id,restored:restored.revision_id,worker:stdout.trim(),paidCalls:0};
await writeFile(root+'/artifacts/app-integration/evidence.json',JSON.stringify(evidence,null,2));console.log(JSON.stringify(evidence));
