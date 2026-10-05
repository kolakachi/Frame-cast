import test from 'node:test';import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';import {mkdtemp,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {buyPlanMedia} from '../plan-media.mjs';
const bytes=Buffer.from('png-bytes'),sha=createHash('sha256').update(bytes).digest('hex');
const file={asset_id:7,sha256:sha,bytes:bytes.length,name:`asset-7-${sha}.png`,purpose:'source',asset_type:'image',mime_type:'image/png'};
const items=[{kind:'ai_image',description:'glass'},{kind:'voiceover',description:'narrate'},{kind:'brand_kit',description:'brand'}];
const ok=()=>({ok:true,arrayBuffer:async()=>bytes});
test('buys each item, stages files as sources and reports failures without stopping',async()=>{
 const dir=await mkdtemp(tmpdir()+'/pm-'),manifest=[],stages=[];
 const replies=[{status:'succeeded',file,charged_credits:16,cues:[{name:'click',start:0,end:0.3}]},{status:'failed',error:'No lines',charged_credits:0},{status:'succeeded',file:null,brand:{colors:['#F26A1B']}}];
 const r=await buyPlanMedia({items,directory:dir,manifest,produce:async i=>replies[i],download:async()=>ok(),onStage:s=>stages.push(s)});
 assert.deepEqual(r.map(x=>[x.kind,x.status,x.file??null]),[['ai_image','succeeded',file.name],['voiceover','failed',null],['brand_kit','succeeded',null]]);
 assert.equal(r[2].brand.colors[0],'#F26A1B');assert.deepEqual(r[0].cues,[{name:'click',start:0,end:0.3}],'sound cues reach the agent');assert.equal(r[1].error,'No lines');
 assert.equal(manifest[0].path,'source/'+file.name);assert.equal(manifest[0].plan_media.kind,'ai_image');
 assert.deepEqual(await readFile(dir+'/source/'+file.name),bytes);
 assert.match(stages[0],/Getting an AI image \(1 of 3\)/);
});
test('a tampered or mislabelled file is refused',async()=>{
 const dir=await mkdtemp(tmpdir()+'/pm-');
 await assert.rejects(buyPlanMedia({items:[items[0]],directory:dir,manifest:[],produce:async()=>({status:'succeeded',file}),download:async()=>({ok:true,arrayBuffer:async()=>Buffer.from('other')})}),/hash or size/);
 await assert.rejects(buyPlanMedia({items:[items[0]],directory:dir,manifest:[],produce:async()=>({status:'succeeded',file:{...file,name:'../../x.png'}}),download:async()=>ok()}),/Invalid plan media record/);
});
test('a file already staged is not downloaded again',async()=>{
 const dir=await mkdtemp(tmpdir()+'/pm-');let n=0;
 await buyPlanMedia({items:[items[0]],directory:dir,manifest:[{asset_id:7}],produce:async()=>({status:'succeeded',file,reused:true}),download:async()=>{n++;return ok();}});
 assert.equal(n,0);
});

test('a pose sheet stages every pose file with its label',async()=>{
 const dir=await mkdtemp(tmpdir()+'/pm-'),manifest=[];
 const mk=(id,body)=>{const b=Buffer.from(body),h=createHash('sha256').update(b).digest('hex');return {f:{asset_id:id,sha256:h,bytes:b.length,name:`asset-${id}-${h}.png`,purpose:'source',asset_type:'image',mime_type:'image/png'},b};};
 const a=mk(11,'pose-a'),c=mk(12,'pose-b');
 const r=await buyPlanMedia({items:[{kind:'character_poses',description:'Mascot: talking, pointing'}],directory:dir,manifest,produce:async()=>({status:'succeeded',file:a.f,more_files:[c.f],poses:['talking','pointing']}),
  download:async id=>({ok:true,arrayBuffer:async()=>id===11?a.b:c.b})});
 assert.deepEqual(r[0].files,[{file:a.f.name,pose:'talking'},{file:c.f.name,pose:'pointing'}]);
 assert.equal(manifest.length,2);
});

test('frozen task and requirement identities survive successful and failed media handoff',async()=>{
 const dir=await mkdtemp(tmpdir()+'/pm-'),manifest=[];
 const approved=[{...items[0],id:'task-approved',requirement_ids:['req-00000000000000000001']},{...items[1],id:'task-voice',requirement_ids:['req-00000000000000000002']}];
 const result=await buyPlanMedia({items:approved,directory:dir,manifest,produce:async i=>i?{status:'failed',error:'Missing audio'}:{status:'succeeded',file,requirement_ids:['untrusted-return']},download:async()=>ok()});
 assert.equal(result[0].task_id,'task-approved');
 assert.deepEqual(result[0].requirement_ids,approved[0].requirement_ids);
 assert.deepEqual(result[1].requirement_ids,approved[1].requirement_ids);
 assert.deepEqual(manifest[0].plan_media.requirement_ids,approved[0].requirement_ids);
});

test('generated clips are all started first, then collected together', async () => {
 const items=[{kind:'generated_shot',description:'a'},{kind:'generated_shot',description:'b'},{kind:'music',description:'m'}];
 const calls=[];const left={0:2,1:1};
 const r=await buyPlanMedia({items,directory:'/tmp/unused',manifest:[],download:async()=>{throw Error('no files here');},pollMs:1,sleep:async()=>{},
  produce:async i=>{calls.push(i);if(i===2)return {status:'failed',error:'none'};return left[i]-->0?{status:'pending'}:{status:'failed',error:'declined'};}});
 assert.deepEqual(calls.slice(0,3),[0,1,2],'every item is started before any wait');
 assert.deepEqual(r.map(x=>x.status),['failed','failed','failed'],'results keep the plan order');
 await assert.rejects(buyPlanMedia({items:[items[0]],directory:'/tmp/unused',manifest:[],download:async()=>({}),pollMs:1,maxWaitMs:0,sleep:async()=>{},produce:async()=>({status:'pending'})}),/still rendering/);
});
