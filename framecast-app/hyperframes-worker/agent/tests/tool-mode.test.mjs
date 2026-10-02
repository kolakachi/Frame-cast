import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {runAgent} from '../runner.mjs';import {Workspace} from '../workspace.mjs';
const use=(id,name,input)=>({type:'tool_use',id,name,input});
async function harness(turns,{limits={},requireVisualReview=true,tools,context={}}={}){
 const dir=await mkdtemp(tmpdir()+'/tool-');await writeFile(dir+'/index.html','<html></html>');
 let i=0;const seen=[];
 const provider={id:'t',maxCallUsd:0,complete:async args=>{seen.push(args);const t=turns[i++]??turns.at(-1);return {content:t,text:'',predictionId:'p'+i,metrics:{}};}};
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[],dir+'-work'),provider,context:{brief:'x',toolMode:true,...context},limits:{calls:6,repairs:3,budgetUsd:0,...limits},requireVisualReview,
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
test('an initial page image does not count toward the text context limit',async()=>{
 const dir=await mkdtemp(tmpdir()+'/tool-');await writeFile(dir+'/index.html','<html></html>');
 const turns=[[use('a','write',{path:'index.html',content:'<html>ok</html>'}),use('b','preview',{times:[1]})],[use('c','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('d','finish',{summary:'Done'})]];
 let i=0;const provider={id:'t',maxCallUsd:0,complete:async()=>({content:turns[i++]??turns.at(-1),text:'',predictionId:'p',metrics:{}})};
 const big='data:image/jpeg;base64,'+'A'.repeat(400000);
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider,initialImage:big,context:{brief:'x',toolMode:true},limits:{calls:4,repairs:2,budgetUsd:0,contextBytes:96000},requireVisualReview:true,
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 assert.equal(state.status,'preview_ready',state.reason);
});
test('catalog searches through the host tool; an over-long query is refused as misuse',async()=>{
 const seen=[];
 const {state}=await harness([
  [use('a','catalog',{query:'browser frame'}),use('b','catalog',{query:'x'.repeat(300)}),use('c','write',{path:'index.html',content:'<html>ok</html>'}),use('d','preview',{times:[1]})],
  [use('e','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('f','finish',{summary:'Done'})],
 ],{tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),catalog:async a=>{seen.push(a.query);return {results:[{name:'browser-device-stage'}]};}}});
 assert.equal(state.status,'preview_ready');assert.deepEqual(seen,['browser frame']);assert.equal(state.repairs,1);
});
test('the critic has the last word: a revise verdict returns directives and blocks finish until the next passing review; a pass finishes with its scores',async()=>{
 const critics=[],strips=[];
 const verdicts=[{ok:true,verdict:'revise',scores:{hook:5,hierarchy:7,density:6,energy:7,performance:7},mean:6.4,directives:['2 s: make the headline three times larger'],note:''},
  {ok:true,verdict:'pass',scores:{hook:8,hierarchy:9,density:8,energy:8,performance:8},mean:8.2,directives:[],note:'Lands'}];
 const {state,seen}=await harness([
  [use('a','write',{path:'index.html',content:'<html><script>window.__timelines["main"]=1;</script></html>'}),use('b','preview',{times:[1]})],
  [use('c','visual_review',{decision:'pass',findings:'Good',scores:[{time:1,score:9,problems:[]}]}),use('d','finish',{summary:'Done'})],
  [use('e','patch',{path:'index.html',before:'main',after:'main2'}),use('f','preview',{times:[1]})],
  [use('g','visual_review',{decision:'pass',findings:'Better',scores:[{time:1,score:9,problems:[]}]}),use('h','finish',{summary:'Done'})],
 ],{limits:{calls:8,repairs:3,criticCalls:2},tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
  strip:async()=>{strips.push(1);return {ok:true,providerImage:'data:image/jpeg;base64,Yg=='};},
  critic:async a=>{critics.push(a);return verdicts[critics.length-1];}})});
 assert.equal(state.status,'preview_ready');assert.equal(critics.length,2);assert.equal(strips.length,2,'a strip per critic round');
 assert.equal(critics[0].sheet.image,'data:image/jpeg;base64,YQ==');assert.equal(critics[0].strip,'data:image/jpeg;base64,Yg==');assert.equal(critics[1].round,2);
 const r=seen[2].messages.at(-1).content.filter(b=>b.type==='tool_result');
 assert.match(r[0].content,/directives/);assert.match(r[0].content,/three times larger/);
 assert.equal(r[1].is_error,true,'finish is refused until the next passing review');assert.match(r[1].content,/Visual review is required/);
 assert.match(state.summary,/Critic: hook 8, hierarchy 9/);assert.equal(state.criticCalls,2);
});
test('without a critic tool the review pass finishes as before; a look run asks for no strip',async()=>{
 const strips=[];
 const {state}=await harness([
  [use('a','write',{path:'index.html',content:'<html>1</html>'}),use('b','preview',{times:[1]})],
  [use('c','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]})],
 ],{limits:{calls:4,repairs:2,criticCalls:1},tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),strip:async()=>{strips.push(1);return {ok:true};},
  critic:async()=>({ok:true,verdict:'pass',scores:{hook:8,hierarchy:8,density:8,energy:8,performance:8},mean:8,directives:[],note:''})}),context:{lookOnly:true}});
 assert.equal(state.status,'preview_ready');assert.equal(strips.length,0,'no strip for stills');
});
test('earlier file writes leave the history so a build of large files fits the context',async()=>{
 const big=n=>'<html>'+'x'.repeat(n)+'</html>';
 const {state,seen}=await harness([
  [use('a','write',{path:'index.html',content:big(30000)}),use('b','write',{path:'style.css',content:'/*'+'y'.repeat(30000)+'*/'}),use('c','write',{path:'main.js',content:'//'+'z'.repeat(30000)})],
  [use('d','patch',{path:'index.html',before:'<html>','after':'<html lang="en">'}),use('e','preview',{times:[1]})],
  [use('f','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('g','finish',{summary:'Done'})],
 ],{limits:{calls:6,repairs:2,contextBytes:96000}});
 assert.equal(state.status,'preview_ready',state.reason);
 const first=seen[2].messages.find(m=>m.role==='assistant');
 assert.match(first.content[0].input.content,/^\[written earlier, 30\d+ bytes/,'the old write is a placeholder');
 assert.ok(Buffer.byteLength(JSON.stringify(seen[2].messages))<40000);
});
