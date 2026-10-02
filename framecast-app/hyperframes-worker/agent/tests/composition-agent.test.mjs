import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,readFile,rm,copyFile,access} from 'node:fs/promises';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {executeCompositionAgent,offlineContractProvider} from '../composition-agent.mjs';
import {digest} from '../workspace.mjs';
const root=fileURLToPath(new URL('../../',import.meta.url));
async function setup(){
 const directory=await mkdtemp(os.tmpdir()+'/composition-agent-');
 await mkdir(directory+'/project');await mkdir(directory+'/inputs/source',{recursive:true});await mkdir(directory+'/inputs/reference',{recursive:true});
 await copyFile(root+'fixtures/index.html',directory+'/project/index.html');await copyFile(root+'fixtures/product.svg',directory+'/project/product.svg');
 const files=[{asset_id:1,purpose:'source',name:'source.png',path:'source/source.png',sha256:digest('source')},{asset_id:2,purpose:'reference',name:'reference.png',path:'reference/reference.png',sha256:digest('reference')}];
 for(const file of files)await writeFile(directory+'/inputs/'+file.path,file.purpose);
 return {directory,files};
}
test('frozen conversation and roles reach agent; every call is accounted; base edit survives',async()=>{
 const {directory,files}=await setup();let began=0,settled=0;const contexts=[];
 try{
  const execute=async(base)=>{
   const p=offlineContractProvider(base);
   return executeCompositionAgent({directory,input:{messages:[{role:'user',content:'Keep the product unchanged'}],base_bundle:base,base_revision_id:base?'r1':null,execution_policy:{agent:{max_calls:5}}},manifest:files,provider:{...p,complete:args=>{contexts.push(JSON.parse(args.prompt).context);return p.complete(args);}},begin:async()=>({id:String(++began),may_execute:true}),settle:async()=>{settled++;},receipt:()=>({status:'succeeded',cost_microusd:0}),invoke:async()=>({ok:true}),guidanceDirectory:root+'agent/guidance'});
  };
  const first=await execute(null);assert.equal(first.state.status,'preview_ready');assert.equal(began,5);assert.equal(settled,5);
  assert.match(first.bundle['index.html'],/Local agent proof/);
  assert.equal(contexts[0].brief,'Keep the product unchanged');assert.equal(contexts[0].assets[1].renderable,false);
  await assert.rejects(access(directory+'/project/reference.png'));
  assert.equal(await readFile(directory+'/project/source.png','utf8'),'source');
  await rm(directory+'/agent-state.json');
  const second=await execute(first.bundle);assert.equal(second.state.status,'preview_ready');
  assert.equal(second.bundle['index.html'],first.bundle['index.html'].replace('Local agent proof','Local edit proof'));
 }finally{await rm(directory,{recursive:true,force:true});}
});
test('lost agent settlement stops before tools and retains uncertainty',async()=>{
 const {directory,files}=await setup();let tools=0;
 try{
  const result=await executeCompositionAgent({directory,input:{messages:[{role:'user',content:'A product sample'}],execution_policy:{agent:{max_calls:5}}},manifest:files,provider:offlineContractProvider(),begin:async()=>({id:'a',may_execute:true}),settle:async()=>{throw Error('lost receipt');},receipt:()=>({status:'succeeded',cost_microusd:0}),invoke:async()=>{tools++;return {ok:true};},guidanceDirectory:root+'agent/guidance'});
  assert.equal(result.state.status,'needs_attention');assert.equal(tools,0);assert.equal(result.state.calls,1);
 }finally{await rm(directory,{recursive:true,force:true});}
});
test('an exhausted allowance stops before any attempt is recorded',async()=>{
 const {directory,files}=await setup();let began=0;
 try{
  const provider={...offlineContractProvider(),reserve:async()=>{throw Object.assign(Error('Additional $6 pilot allowance exhausted'),{code:'BUDGET_EXHAUSTED'});}};
  const result=await executeCompositionAgent({directory,input:{messages:[{role:'user',content:'A product sample'}],execution_policy:{agent:{max_calls:5}}},manifest:files,provider,begin:async()=>{began++;return {id:'a',may_execute:true};},settle:async()=>({}),receipt:()=>({status:'succeeded',cost_microusd:0}),invoke:async()=>({ok:true}),guidanceDirectory:root+'agent/guidance'});
  assert.equal(result.state.status,'budget_exhausted');assert.equal(began,0,'no attempt is begun, so nothing is left held');assert.equal(result.state.pending,null);
 }finally{await rm(directory,{recursive:true,force:true});}
});

test('the instruction keeps only the sentences this run can use',async()=>{
 const {trimInstruction}=await import('../composition-agent.mjs');
 const text='Always true. When lookOnly is true do stills. When planMedia has a talking_shot clip, lip-sync. Sound mix: a music file is the bed. Use preview before finishing.';
 assert.equal(trimInstruction(text,{lookOnly:false,talking:true,music:false}),'Always true. When planMedia has a talking_shot clip, lip-sync. Use preview before finishing.');
 assert.equal(trimInstruction(text,{}),text,'unknown flags keep the sentence');
});
