import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';import {tmpdir} from 'node:os';import path from 'node:path';
import {PilotBudget} from '../pilot-budget.mjs';import {uploadProviderImage} from '../provider-files.mjs';
test('provider uploads fingerprint bytes and accept only the exact file API',async()=>{
 let form;
 const url=await uploadProviderImage({bytes:Buffer.from('image'),type:'image/png',token:'test',fetchImpl:async(u,o)=>{assert.equal(u,'https://api.replicate.com/v1/files');assert.equal(o.redirect,'error');form=o.body;return {ok:true,json:async()=>({urls:{get:'https://api.replicate.com/v1/files/abc.png'}})}}});
 assert.equal(url,'https://api.replicate.com/v1/files/abc.png');assert.equal(form.get('content').type,'image/png');
 await assert.rejects(uploadProviderImage({bytes:Buffer.from('image'),type:'image/png',token:'test',fetchImpl:async()=>({ok:true,json:async()=>({urls:{get:'https://attacker.test/image.png'}})})}),/Invalid provider/);
 await assert.rejects(uploadProviderImage({bytes:Buffer.alloc(11*1024*1024),type:'image/png',token:'test'}),/Unsupported/);
});
test('pilot budget is durable, bounded and never releases an unknown reservation',async()=>{
 const dir=await mkdtemp(path.join(tmpdir(),'e3-budget-'));try{
 const file=dir+'/budget.json',b=new PilotBudget(file),ids=[];
 for(let i=0;i<16;i++)ids.push(await b.reserve('anthropic/claude-4.5-sonnet',.3));
 await assert.rejects(new PilotBudget(file).reserve('anthropic/claude-4.5-sonnet',.3),/exhausted/);
 await assert.rejects(b.settle(ids[0],{status:'unknown',prediction_id:'p',cost_microusd:0}),/Verified/);
 await b.settle(ids[0],{status:'succeeded',prediction_id:'p',cost_microusd:10000});
 await b.settle(ids[0],{status:'succeeded',prediction_id:'p',cost_microusd:10000});
 await assert.rejects(b.settle(ids[1],{status:'succeeded',prediction_id:'p',cost_microusd:10000}),/already used/);
 await assert.rejects(b.settle(ids[0],{status:'succeeded',prediction_id:'other',cost_microusd:10000}),/changed/);
 await new PilotBudget(file).reserve('anthropic/claude-4.5-sonnet',.3);
 const l=JSON.parse(await readFile(file));assert.ok(l.calls.reduce((s,c)=>s+c.reservedUsd,0)<=5);assert.equal(l.calls[1].reservedUsd,.3);
 }finally{await rm(dir,{recursive:true,force:true})}
});

test('an unlimited run reserves its raised per-call ceiling; a normal run still accepts only the priced ones',async()=>{
 const {PilotBudget}=await import('../pilot-budget.mjs');
 const {mkdtemp}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const b=new PilotBudget((await mkdtemp(tmpdir()+'/pb-'))+'/ledger.json',null);
 await assert.rejects(()=>b.reserve('claude-opus-5-5',5),/Unpriced/);
 assert.ok(await b.reserve('claude-opus-5-5',5,{unlimited:true}));
 await assert.rejects(()=>b.reserve('claude-opus-5-5',6,{unlimited:true}),/Unpriced/);
 await assert.rejects(()=>b.reserve('some/other-model',1,{unlimited:true}),/Unpriced/);
 assert.ok(await b.reserve('claude-opus-5-5',.45));
});
