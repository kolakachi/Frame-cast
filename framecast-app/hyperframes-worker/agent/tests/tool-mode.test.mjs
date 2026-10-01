import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {runAgent} from '../runner.mjs';import {Workspace} from '../workspace.mjs';
const use=(id,name,input)=>({type:'tool_use',id,name,input});
async function harness(turns,{limits={},requireVisualReview=true,tools}={}){
 const dir=await mkdtemp(tmpdir()+'/tool-');await writeFile(dir+'/index.html','<html></html>');
 let i=0;const seen=[];
 const provider={id:'t',maxCallUsd:0,complete:async args=>{seen.push(args);const t=turns[i++]??turns.at(-1);return {content:t,text:'',predictionId:'p'+i,metrics:{}};}};
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[],dir+'-work'),provider,context:{brief:'x',toolMode:true},limits:{calls:6,repairs:3,budgetUsd:0,...limits},requireVisualReview,
  tools:(typeof tools==='function'?tools(dir):tools)??{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 return {state,seen,dir};
}
test('one model call can write three files and preview; the next reviews and finishes',async()=>{
 const {state,seen,dir}=await harness([
  [use('a','write',{path:'index.html',content:'<html><h1>Hi</h1></html>'}),use('b','write',{path:'style.css',content:'h1{}'}),use('c','write',{path:'main.js',content:'1;'}),use('d','preview',{times:[1,7]})],
  [use('e','visual_review',{decision:'pass',findings:'Good',scores:[{time:1,score:9,problems:[]},{time:7,score:8,problems:[]}]}),use('f','finish',{summary:'Done'})],
 ]);
 assert.equal(state.status,'preview_ready');assert.equal(state.calls,2);assert.equal(state.revision,3);
 assert.match(await readFile(dir+'/index.html','utf8'),/Hi/);
 // the second call carried the history: tool results for all four calls, with the frames as an image
 const history=seen[1].messages;assert.equal(history.length,3);
 const results=history[2].content.filter(b=>b.type==='tool_result');assert.equal(results.length,4);
 assert.ok(results[3].content.some(b=>b.type==='image'),'the preview frames travel back as an image');
 assert.ok(Array.isArray(seen[1].tools)&&seen[1].tools.some(t=>t.name==='preview'));
});
test('a misused tool is an error result, not a failed run; a turn without tools is nudged',async()=>{
 const {state,seen}=await harness([
  [{type:'text',text:'Thinking out loud'}],
  [use('a','visual_review',{decision:'pass',findings:'x',scores:[{time:1,score:9,problems:[]}]}),use('b','write',{path:'index.html',content:'<html>ok</html>'}),use('c','preview',{times:[1]})],
  [use('d','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('e','finish',{summary:'Done'})],
 ]);
 assert.equal(state.status,'preview_ready');
 assert.match(seen[1].messages.at(-1).content[0].text,/Use the tools/);
 const r=seen[2].messages.at(-1).content.filter(b=>b.type==='tool_result');
 assert.equal(r[0].is_error,true);assert.match(r[0].content,/requires current host-provided snapshot/);
 assert.equal(state.repairs,2);
});
test('old frames leave the history and only the latest image stays',async()=>{
 const {seen}=await harness([
  [use('a','write',{path:'index.html',content:'<html>1</html>'}),use('b','preview',{times:[1]})],
  [use('c','visual_review',{decision:'repair',findings:'x',scores:[{time:1,score:5,problems:['small']}]}),use('d','patch',{path:'index.html',before:'1',after:'2'}),use('e','preview',{times:[1]})],
  [use('f','visual_review',{decision:'pass',findings:'ok',scores:[{time:1,score:9,problems:[]}]}),use('g','finish',{summary:'Done'})],
 ]);
 const images=seen[2].messages.flatMap(m=>m.content).flatMap(b=>b.type==='tool_result'&&Array.isArray(b.content)?b.content:[]).filter(b=>b.type==='image');
 assert.equal(images.length,1,'exactly one image in the history sent to the model');
});
test('run executes through the host tool: scratch writes do not bump the revision, outputs become protected assets, misuse is an error result',async()=>{
 const calls=[];
 const {state,seen,dir}=await harness([
  [use('a','write',{path:'work/gen.mjs',content:'1;'}),use('b','run',{cmd:'node',args:['gen.mjs']}),use('c','run',{cmd:'ffmpeg',args:['-i','/etc/passwd']}),use('d','write',{path:'index.html',content:'<html><img src="sprite.png"></html>'}),use('e','preview',{times:[1]})],
  [use('f','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('g','finish',{summary:'Done'})],
 ],{tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
  run:async a=>{calls.push(a);await writeFile(dir+'/sprite.png','p');return {ok:true,exit:0,stdout:'',stderr:'',outputs:[{path:'sprite.png',sha256:'148de9c5a7a44d19e56cd9ae1a554bf67847afb0c58f6e12fa29ac7ddfca9940',bytes:1}],scratch:['gen.mjs']};}})});
 assert.equal(state.status,'preview_ready');assert.equal(state.revision,1,'the scratch write did not count as a source revision');
 assert.deepEqual(calls.map(c=>c.cmd),['node']);
 assert.equal(await readFile(dir+'-work/gen.mjs','utf8'),'1;','the scratch file lives beside the project, not in it');
 assert.equal(await readFile(dir+'/gen.mjs','utf8').catch(()=>null),null);
 const r=seen[1].messages.at(-1).content.filter(b=>b.type==='tool_result');
 assert.equal(r[2].is_error,true);assert.match(r[2].content,/absolute paths/);
 assert.equal(state.runs,1);
});
