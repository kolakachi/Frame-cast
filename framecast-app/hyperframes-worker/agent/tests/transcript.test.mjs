import test from 'node:test';import assert from 'node:assert/strict';
import {chainFor,mapThrough,compact} from '../transcript-map.mjs';
const words=[{text:'Hi',start:0.5,end:0.9},{text:'um',start:1.2,end:1.5},{text:'save',start:3.0,end:3.4},{text:'twenty',start:3.4,end:3.9},{text:'percent',start:5.8,end:6.3}];
test('cuts drop removed words, shift kept ones and clip words that straddle an edge',()=>{
 const map=[{out_start:0,out_end:1,src_start:0,src_end:1},{out_start:1,out_end:2.6,src_start:2.9,src_end:4.5},{out_start:2.6,out_end:3.4,src_start:6.0,src_end:6.8}];
 const out=mapThrough(words,[{operation:'cut',params:{},sourceMap:map}]);
 assert.deepEqual(out.map(w=>w.text),['Hi','save','twenty','percent']);
 assert.deepEqual(out.find(w=>w.text==='save'),{text:'save',start:1.1,end:1.5});
 assert.deepEqual(out.find(w=>w.text==='percent'),{text:'percent',start:2.6,end:2.9},'clipped to the kept range');
});
test('speed rescales time and look-only edits leave it alone',()=>{
 const out=mapThrough(words.slice(0,1),[{operation:'grade',params:{look:'warm'}},{operation:'speed',params:{factor:2}},{operation:'stabilize',params:{}}]);
 assert.deepEqual(out,[{text:'Hi',start:0.25,end:0.45}]);
 assert.throws(()=>mapThrough(words,[{operation:'frame',params:{}}]),/cannot be carried/);
 assert.throws(()=>mapThrough(words,[{operation:'trim',params:{}}]),/no source map/);
});
test('chain walks back to the original file in order',()=>{
 const assets=[{path:'asset-1.mp4'},{path:'derived-1-trim.mp4',derivedFrom:'asset-1.mp4',operation:'trim',params:{},sourceMap:[]},{path:'derived-2-speed.mp4',derivedFrom:'derived-1-trim.mp4',operation:'speed',params:{factor:2}}];
 const c=chainFor('derived-2-speed.mp4',assets);
 assert.equal(c.root,'asset-1.mp4');assert.deepEqual(c.steps.map(s=>s.operation),['trim','speed']);
 assert.throws(()=>chainFor('nope.mp4',assets),/not a file/);
});
test('compact output fits the context and says when it was cut short',()=>{
 const many=Array.from({length:2000},(_,i)=>({text:'w',start:i,end:i+.5}));
 const c=compact({words:many,segments:[]});assert.equal(c.words.length,1500);assert.equal(c.truncated,true);assert.deepEqual(c.words[0],['w',0,0.5]);
});
test('agent transcript action transcribes the original once and maps it to the edited file',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace,digest}=await import('../workspace.mjs');
 const {mkdtemp,writeFile,readFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/transcript-');await writeFile(dir+'/index.html','<html></html>');await writeFile(dir+'/asset-1-x.mp4','video');
 const ws=new Workspace(dir,[{path:'asset-1-x.mp4',sha256:digest('video')}]);
 const steps=[{type:'media',op:'cut',input:'asset-1-x.mp4',params:{keep:[[0,1],[2.9,4.5]]}},{type:'transcript',input:'derived-1-cut.mp4'},{type:'transcript',input:'index.html'},{type:'needs_input',question:'stop'}];
 let i=0;const asked=[];
 const provider={id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})};
 const state=await runAgent({stateFile:dir+'/state.json',workspace:ws,provider,context:{brief:'x'},limits:{calls:6,repairs:3,budgetUsd:0},
  tools:{media:async()=>{await writeFile(dir+'/derived-1-cut.mp4','cut');return {ok:true,output:'derived-1-cut.mp4',sha256:digest('cut'),source_map:[{out_start:0,out_end:1,src_start:0,src_end:1},{out_start:1,out_end:2.6,src_start:2.9,src_end:4.5}]};},
   transcript:async({input})=>{asked.push(input);if(input!=='asset-1-x.mp4')throw Error('Only supplied audio or video can be transcribed');return {words,segments:[{text:'Hi um save twenty',start:0.5,end:3.9}]};}}});
 assert.equal(state.status,'needs_input');
 const results=state.messages.filter(m=>m.role==='tool').map(m=>m.content);
 const t=results.find(r=>r.input==='derived-1-cut.mp4');
 assert.deepEqual(t.words,[['Hi',0.5,0.9],['save',1.1,1.5],['twenty',1.5,2]]);
 assert.match(t.timeline,/mapped from asset-1-x.mp4 through cut/);
 assert.ok(results.some(r=>r.ok===false&&/not a file/.test(r.error)),'a project file is refused before any transcription');
 assert.deepEqual(asked,['asset-1-x.mp4'],'only the original is transcribed');
});
test('transcript action shape is fixed',async()=>{
 const {parseAction}=await import('../protocol.mjs');
 assert.equal(parseAction('{"type":"transcript","input":"a.mp4"}').input,'a.mp4');
 assert.throws(()=>parseAction('{"type":"transcript","input":"a.mp4","extra":1}'),/Unexpected or missing/);
});
