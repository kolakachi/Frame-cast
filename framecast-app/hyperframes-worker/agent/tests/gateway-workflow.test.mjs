import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile,mkdtemp,mkdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {ReplicateGatewayProvider} from '../replicate-gateway.mjs';
import {executeImage} from '../media-provider.mjs';
import {recordCapability} from '../capability-evidence.mjs';

test('app worker has no provider credential or direct Replicate adapter',async()=>{
  const source=await readFile(new URL('../app-worker.mjs',import.meta.url),'utf8');
  assert.doesNotMatch(source,/REPLICATE_API_TOKEN|api\/\.env|new ReplicateProvider|token:providerToken/);
  assert.match(source,/new ReplicateGatewayProvider/);
});
test('Replicate agent sends only the recorded app call; lost dispatch never starts another',async()=>{
  let calls=0;
  const p=new ReplicateGatewayProvider({maxCallUsd:.3,upload:async()=>({url:'https://api.replicate.com/v1/files/review'}),call:async(id,payload)=>{
    calls++;assert.equal(id,'attempt');assert.equal(payload.input.prompt,'scene');return {id:'prediction',status:'succeeded',output:['one','two']};
  }});
  assert.equal((await p.complete({prompt:'scene',system:'rules',maxTokens:1024,attemptId:'attempt'})).text,'onetwo');
  p.call=async()=>{calls++;throw Error('connection lost');};
  await assert.rejects(p.complete({prompt:'scene',system:'rules',maxTokens:1024,attemptId:'attempt'}),/connection lost/);
  assert.equal(calls,2);
});
test('media uses frozen app input; no worker upload or provider API request',async()=>{
  const dir=await mkdtemp(tmpdir()+'/gateway-media-');await mkdir(dir+'/inputs');
  const requests=[];
  try{
    const out=await executeImage({directory:dir,input:{execution_policy:{media:{model:'google/nano-banana'}},media_input:{prompt:'ignored'}},manifest:[],
      gateway:{prepare:async()=>({prompt:'frozen'}),call:async(id,body)=>{assert.equal(id,'attempt');assert.deepEqual(body,{input:{prompt:'frozen'}});return {id:'p',status:'succeeded',output:'https://replicate.delivery/x/image.png'};}},
      begin:async()=>({id:'attempt',may_execute:true}),settle:async()=>({status:'succeeded',cost_microusd:39000}),bindPrediction:async()=>{},
      fetchImpl:async url=>{requests.push(String(url));return new Response(new Uint8Array([1,2]),{headers:{'content-type':'image/png'}});}});
    assert.equal(out.type,'image/png');assert.deepEqual(requests,['https://replicate.delivery/x/image.png']);
  }finally{await rm(dir,{recursive:true,force:true});}
});
test('capability evidence distinguishes failed tools and redacts diagnostics',()=>{
  const state={calls:2,revision:1};
  recordCapability(state,{type:'read',path:'skills/hyperframes/creative.md'},{text:'rules'});
  recordCapability(state,{type:'read',path:'skills/hyperframes/creative.md'},{text:'rules'});
  recordCapability(state,{type:'run',cmd:'remotion'},{error:'failed'});
  recordCapability(state,{type:'catalog',query:'secret https://private.test/a'},{ok:true});
  assert.equal(state.capability_evidence[0].count,2);
  assert.equal(state.capability_evidence[1].ok,false);
  assert.doesNotMatch(JSON.stringify(state.capability_evidence),/private.test/);
});
