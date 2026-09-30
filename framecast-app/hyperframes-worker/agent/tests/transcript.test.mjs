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
test('cut suggestions find filler, false starts and pauses',async()=>{
 const {suggestCuts}=await import('../transcript-map.mjs');
 const w=[['So',0,0.2],['um',0.3,0.5],['we',0.6,0.8],['we',0.9,1.1],['built',1.2,1.5],['this',1.6,1.8],['.',1.8,1.8],['It',3.2,3.4],['works',3.5,3.9]];
 const c=suggestCuts(w);
 assert.deepEqual(c.map(x=>[x.reason,x.text]),[['filler','um'],['repeat','we'],['pause','']]);
 assert.deepEqual([c[1].start,c[1].end],[0.6,0.9]);
});
test('a cut reports removed words and flags content that was not filler or a repeat',async()=>{
 const {removedWords}=await import('../transcript-map.mjs');
 const w=[['So',0,0.2],['um',0.3,0.5],['we',0.6,0.8],['we',0.9,1.1],['built',1.2,1.5],['this',1.6,1.8]];
 const clean=removedWords(w,{sourceMap:[{src_start:0,src_end:0.25},{src_start:0.85,src_end:2}]});
 assert.deepEqual(clean,{removed:['um','we'],content:[]});
 const lossy=removedWords(w,{sourceMap:[{src_start:0,src_end:1.15}]});
 assert.deepEqual(lossy.content,['built','this']);
});
test('cutting transcribed speech reports removed words, flags lost content and records the edit',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace,digest}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/cut-');await writeFile(dir+'/index.html','<html></html>');await writeFile(dir+'/asset-1-x.mp4','video');
 const ws=new Workspace(dir,[{path:'asset-1-x.mp4',sha256:digest('video')}]);
 const said=[{text:'So',start:0,end:0.2},{text:'um',start:0.3,end:0.5},{text:'save',start:0.6,end:0.9},{text:'twenty',start:1.0,end:1.4}];
 const steps=[{type:'transcript',input:'asset-1-x.mp4'},{type:'media',op:'cut',input:'asset-1-x.mp4',params:{keep:[[0,0.25],[0.55,0.95]]}},{type:'needs_input',question:'stop'}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:5,repairs:3,budgetUsd:0},
  tools:{transcript:async()=>({words:said,segments:[]}),media:async()=>{await writeFile(dir+'/derived-1-cut.mp4','c');return {ok:true,output:'derived-1-cut.mp4',sha256:digest('c'),info:{duration:2.0},source_map:[{out_start:0,out_end:0.25,src_start:0,src_end:0.25},{out_start:0.25,out_end:0.65,src_start:0.55,src_end:0.95}]};}}});
 const cut=state.messages.filter(m=>m.role==='tool').map(m=>m.content).find(r=>r.output==='derived-1-cut.mp4');
 assert.deepEqual(cut.removed_words,['um','twenty']);assert.deepEqual(cut.content_removed,['twenty']);assert.match(cut.sync_warning,/drift/);
 assert.deepEqual(state.edits[0].content_removed,['twenty']);
 assert.deepEqual(state.transcripts['derived-1-cut.mp4'].map(w=>w[0]),['So','save'],'the cut file has its own mapped transcript');
});
test('tighten turns filler and false-start suggestions into keep ranges',async()=>{
 const {tightenRanges}=await import('../transcript-map.mjs');
 const w=[{text:'So',start:0,end:0.3},{text:'um',start:0.4,end:0.7},{text:'we',start:0.9,end:1.1},{text:'we',start:1.3,end:1.5},{text:'brew',start:1.6,end:2.0},{text:'it',start:3.4,end:3.6}];
 assert.deepEqual(tightenRanges(w,4),{keep:[[0,0.36],[0.74,0.86],[1.3,4]],removed:2},'the cut never clips the word that is kept');
 assert.equal(tightenRanges(w,4,{pauses:true}).keep.length,4,'a long pause is dropped only when asked');
 assert.deepEqual(tightenRanges([{text:'clean',start:0,end:1}],2),{keep:[[0,2]],removed:0});
});
test('the tighten action cuts the suggested ranges and reports what went',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace,digest}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/tighten-');await writeFile(dir+'/index.html','<html></html>');await writeFile(dir+'/asset-1-x.mp4','video');
 const ws=new Workspace(dir,[{path:'asset-1-x.mp4',sha256:digest('video')}]);
 const said=[{text:'So',start:0,end:0.3},{text:'um',start:0.4,end:0.7},{text:'we',start:0.9,end:1.1},{text:'we',start:1.3,end:1.5},{text:'brew',start:1.6,end:2.0}];
 const steps=[{type:'media',op:'tighten',input:'asset-1-x.mp4',params:{}},{type:'transcript',input:'asset-1-x.mp4'},{type:'media',op:'tighten',input:'asset-1-x.mp4',params:{}},{type:'needs_input',question:'stop'}];let i=0;const ops=[];
 const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:6,repairs:3,budgetUsd:0},
  tools:{transcript:async()=>({words:said,segments:[]}),media:async({op,params})=>{ops.push([op,params]);if(op==='probe')return {ok:true,info:{duration:2.2}};await writeFile(dir+'/derived-1-cut.mp4','c');const map=[];let o=0;for(const [s,e] of params.keep){map.push({out_start:o,out_end:o+e-s,src_start:s,src_end:e});o+=e-s;}return {ok:true,output:'derived-1-cut.mp4',sha256:digest('c'),info:{duration:o},source_map:map};}}});
 const results=state.messages.filter(m=>m.role==='tool').map(m=>m.content);
 assert.match(results[0].error,/transcript on this file first/);
 const cut=results.find(r=>r.output==='derived-1-cut.mp4');
 assert.deepEqual(cut.removed_words,['um','we']);assert.equal(cut.content_removed,undefined);
 assert.deepEqual(ops.map(o=>o[0]),['probe','cut']);
});
