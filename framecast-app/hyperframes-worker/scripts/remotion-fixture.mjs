// Offline real-runner integration: native React -> still/clip -> Hyperframes -> edit.
import {mkdir,copyFile,readFile,writeFile,rm} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import assert from 'node:assert/strict';
import {executeCompositionAgent} from '../agent/composition-agent.mjs';
import {digest} from '../agent/workspace.mjs';
const exec=promisify(execFile),id='remotion-proof',directory='/output/live/'+id;
await rm(directory,{recursive:true,force:true});await mkdir(directory+'/project',{recursive:true});await mkdir(directory+'/inputs/source',{recursive:true});
await copyFile('/opt/worker/fixtures/product.svg',directory+'/inputs/source/product.svg');
const hash=digest(await readFile(directory+'/inputs/source/product.svg'));
await writeFile(directory+'/output-settings.json',JSON.stringify({aspect_ratio:'16:9',duration_seconds:6}));
const source=await readFile('/opt/worker/fixtures/remotion/remotion-demo.js','utf8');
const trace=[];let clip,still,calls=0;
const invoke=async(op,args={})=>{
 if(op==='run')await writeFile(directory+'/run-request.json',JSON.stringify(args));
 await exec(process.execPath,['/opt/worker/agent/live-tool.mjs',id,op,(args.times??[1,3,5]).join(',')],{timeout:180000,maxBuffer:16000000});
 const result=JSON.parse(await readFile(directory+'/'+op+'/result.json','utf8'));trace.push({op,request:op==='run'?args:null,result});
 assert.ok(result.ok===true||result.status==='ready',JSON.stringify(result));
 if(op==='run')for(const f of result.outputs||[]){if(f.path.endsWith('.mp4'))clip=f.path;if(f.path.endsWith('.png'))still=f.path;}
 if(op==='snapshot')result.providerImage='data:image/jpeg;base64,'+(await readFile(directory+'/snapshot/contact-sheet.jpg')).toString('base64');
 return result;
};
const html=()=>`<!doctype html><html><head><script src="gsap.min.js"></script><style>body{margin:0}#main,video{width:1920px;height:1080px}</style></head><body><div id="main" data-composition-id="main" data-width="1920" data-height="1080" data-duration="6"><video id="remotion-clip" src="${clip}" data-start="0" data-duration="6" muted></video></div><script>window.__timelines=window.__timelines||{};window.__timelines.main=gsap.timeline({paused:true}).to({}, {duration:6});</script></body></html>`;
const run=async base=>{
 await rm(directory+'/agent-state.json',{force:true});let i=0;
 const actions=[()=>({type:'read',path:'kit/remotion.md'}),()=>({type:'read',path:'skills/iart-product/product-demo-video/SKILL.md'}),()=>base?{type:'patch',path:'remotion-demo.js',before:'A new perspective.',after:'Your next campaign.'}:{type:'write',path:'remotion-demo.js',content:source},()=>({type:'run',cmd:'remotion',args:['still','project/remotion-demo.js','6','72']}),()=>({type:'run',cmd:'remotion',args:['render','project/remotion-demo.js','6']}),()=>base?{type:'patch',path:'index.html',before:base['index.html'].match(/src="(remotion-[^"]+mp4)"/)[1],after:clip}:{type:'write',path:'index.html',content:html()},()=>({type:'preview',times:[1,3,5]}),()=>({type:'finish',summary:'Offline Remotion integration proof, not model creative assessment.'})];
 const result=await executeCompositionAgent({directory,input:{messages:[{role:'user',content:'Use a native Remotion product card; preserve the supplied product.'}],settings:{aspect_ratio:'16:9',duration_seconds:6},execution_policy:{agent:{max_calls:10}},base_bundle:base,base_revision_id:base?'original':null},manifest:[{purpose:'source',name:'product.svg',path:'source/product.svg',sha256:hash}],provider:{id:'offline-remotion',maxCallUsd:0,complete:async args=>{
  const context=JSON.parse(args.prompt).context;assert.ok(context.renderAdapters.some(a=>a.engine==='remotion'));
  if(i===1)assert.match(args.prompt,/Remotion clip adapter/);
  assert.ok(actions[i],'Unexpected repair request');calls++;return {text:JSON.stringify(actions[i++]())};
 }},begin:async()=>({id:'offline-'+calls,may_execute:true}),settle:async()=>{},receipt:()=>({status:'succeeded',cost_microusd:0}),invoke,guidanceDirectory:'/opt/worker/agent/guidance'});
 assert.equal(result.state.status,'preview_ready',result.state.reason);return result;
};
const first=await run();const oldClip=clip,oldClipHash=digest(await readFile(directory+'/project/'+clip));
const render1=await invoke('render');await copyFile(render1.directory+'/'+render1.artifact,'/output/remotion-original.mp4');await copyFile(directory+'/project/'+still,'/output/remotion-original.png');
const second=await run(first.bundle);
assert.notEqual(clip,oldClip);assert.equal(digest(await readFile(directory+'/project/'+oldClip)),oldClipHash);
assert.equal(digest(await readFile(directory+'/project/product.svg')),hash);
assert.equal(second.bundle['remotion-demo.js'],first.bundle['remotion-demo.js'].replace('A new perspective.','Your next campaign.'));
const render2=await invoke('render');await copyFile(render2.directory+'/'+render2.artifact,'/output/remotion-edited.mp4');await copyFile(directory+'/project/'+still,'/output/remotion-edited.png');
// An unsupported source import must fail without adding a partial media asset.
await writeFile(directory+'/project/invalid.js',"import fs from 'node:fs';export default ()=>null;");
await writeFile(directory+'/run-request.json',JSON.stringify({cmd:'remotion',args:['render','project/invalid.js','6']}));
await exec(process.execPath,['/opt/worker/agent/live-tool.mjs',id,'run'],{timeout:120000});
const rejected=JSON.parse(await readFile(directory+'/run/result.json','utf8'));assert.equal(rejected.ok,false);assert.deepEqual(rejected.outputs,[]);assert.match(rejected.stderr,/Unsupported Remotion import/);
await writeFile('/output/remotion-report.json',JSON.stringify({passed:true,kind:'offline-scripted-agent',calls,paidUsd:0,sourcePreserved:true,oldClipPreserved:true,nativeSourceInBundle:true,unsupportedImportRejected:true,trace},null,2));
console.log(JSON.stringify({passed:true,calls,paidUsd:0}));
