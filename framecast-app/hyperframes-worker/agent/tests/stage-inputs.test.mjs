import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import os from 'node:os';import path from 'node:path';
import {stageInputs} from '../stage-inputs.mjs';
import {digest} from '../workspace.mjs';
const data=Buffer.from('fixture media bytes'),sha256=digest(data);
const file={asset_id:1,purpose:'reference',asset_type:'image',bytes:data.length,sha256,name:'asset-1-'+sha256+'.png'};
async function args(t){const root=await mkdtemp(path.join(os.tmpdir(),'hf-stage-'));t.after(()=>rm(root,{recursive:true,force:true}));return {directory:root+'/inputs',files:[file],download:async()=>new Response(data)};}
test('stages verified reference separately and preserves base revision source',async t=>{
 const a=await args(t);a.baseBundle={'index.html':'<h1>Saved draft</h1>'};
 const result=await stageInputs(a);assert.equal(result[0].path,'reference/'+file.name);
 assert.deepEqual(await readFile(a.directory+'/'+result[0].path),data);
 assert.equal(await readFile(a.directory+'/base/index.html','utf8'),a.baseBundle['index.html']);
 await assert.rejects(stageInputs(a),/EEXIST/);
});
test('invalid manifest and traversal stop before any download',async t=>{
 for(const change of [{name:'../escape.png'},{purpose:'source/../reference'},{bytes:-1},{sha256:'invalid'}]){
  const a=await args(t);let calls=0;a.files=[{...file,...change}];a.download=async()=>{calls++;};
  await assert.rejects(stageInputs(a),/Invalid input manifest/);assert.equal(calls,0);
 }
});
test('rejects duplicate IDs, aggregate size and invalid base filenames',async t=>{
 const a=await args(t);await assert.rejects(stageInputs({...a,files:[file,file]}),/Invalid input manifest/);
 await assert.rejects(stageInputs({...a,maxBytes:1}),/byte limit/);
 await assert.rejects(stageInputs({...a,baseBundle:{'../unsafe.js':'x'}}),/Invalid base revision/);
});
test('changed, truncated or oversized downloads never become staged inputs',async t=>{
 for(const body of [Buffer.alloc(data.length),data.subarray(1),Buffer.concat([data,data])]){
  const a=await args(t);a.download=async()=>new Response(body);await assert.rejects(stageInputs(a),/mismatch/);
  await assert.rejects(readFile(a.directory+'/reference/'+file.name),/ENOENT/);
 }
});
test('cancellation and failed download cannot stage a usable manifest',async t=>{
 const a=await args(t);await assert.rejects(stageInputs({...a,signal:AbortSignal.abort()}));
 const b=await args(t);b.download=async()=>new Response('denied',{status:403});
 await assert.rejects(stageInputs(b),/download failed/);await assert.rejects(readFile(b.directory+'/manifest.json'),/ENOENT/);
});
