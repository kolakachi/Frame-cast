import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {createHash} from 'node:crypto';
import {runAgent} from '../runner.mjs';import {Workspace} from '../workspace.mjs';
import {parseAction,actionFromToolUse} from '../protocol.mjs';
test('requirement links work in native and envelope purchases and reach the host',async()=>{
 const id='req-'+'a'.repeat(20), input={kind:'sfx',description:'Whoosh',requirement_ids:[id]};
 assert.deepEqual(parseAction(JSON.stringify({type:'buy',...input})),actionFromToolUse({name:'buy',input}));
 assert.equal(parseAction(JSON.stringify({type:'buy',kind:'sfx',description:'Whoosh'})).kind,'sfx');
 assert.throws(()=>actionFromToolUse({name:'buy',input:{...input,requirement_ids:['invented']}}));
 const calls=[];
 const {state}=await harness([[use('a','buy',input)],[use('b','needs_input',{question:'Continue?'})]],{tools:{buy:async args=>{calls.push(args);return {ok:true,task_id:'task-test',requirement_ids:[id],files:[],charged_credits:0};}}});
 assert.deepEqual(calls[0].requirement_ids,[id]);
 assert.equal(calls.length,1);
 assert.equal(state.status,'needs_input');
});
const use=(id,name,input)=>({type:'tool_use',id,name,input});
async function harness(turns,{limits={},requireVisualReview=true,tools,context={}}={}){
 const dir=await mkdtemp(tmpdir()+'/tool-');await writeFile(dir+'/index.html','<html></html>');
 let i=0;const seen=[];
 const provider={id:'t',maxCallUsd:0,complete:async args=>{seen.push(args);const t=turns[i++]??turns.at(-1);return {content:t,text:'',predictionId:'p'+i,metrics:{}};}};
 const workspace=new Workspace(dir,[],dir+'-work');
 const state=await runAgent({stateFile:dir+'/s.json',workspace,provider,context:{brief:'x',toolMode:true,...context},limits:{calls:6,repairs:3,budgetUsd:0,...limits},requireVisualReview,
  tools:(typeof tools==='function'?tools(dir):tools)??{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 return {state,seen,dir,workspace};
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
test('art searches and places through the host tool; a 3D item becomes a protected, kept file',async()=>{
 const seen=[];
 const {state,workspace}=await harness([
  [use('a','art',{query:'coin 3d'}),use('b','art',{query:'coin',use:'fluent3d:coin'}),use('c','art',{query:'x',use:'../escape'}),use('d','write',{path:'index.html',content:'<html><img src="art-fluent3d-coin.png"></html>'}),use('e','preview',{times:[1]})],
  [use('f','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('g','finish',{summary:'Done'})],
 ],{tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),art:async a=>{seen.push([a.query,a.use??null]);
   if(a.use)await writeFile(dir+'/art-fluent3d-coin.png','png');
   return a.use?{id:a.use,kind:'png',file:{path:'art-fluent3d-coin.png',sha256:createHash('sha256').update('png').digest('hex')},how:'img'}:{results:[{id:'fluent3d:coin',style:'3d',kind:'png'}]};}})});
 assert.equal(state.status,'preview_ready');assert.deepEqual(seen,[['coin 3d',null],['coin','fluent3d:coin']]);assert.equal(state.repairs,1,'a use that is not an id is refused as misuse');
 assert.deepEqual(workspace.assets.filter(a=>a.operation==='library').map(a=>[a.path,a.params.art]),[['art-fluent3d-coin.png','fluent3d:coin']]);
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
  strip:async()=>{strips.push(1);return {ok:true,providerImage:'data:image/jpeg;base64,Yg==',coverage:{duration_seconds:30,times:[.5,29.5]}};},
  critic:async a=>{critics.push(a);return verdicts[critics.length-1];}})});
 assert.equal(state.status,'preview_ready');assert.equal(critics.length,2);assert.equal(strips.length,2,'a strip per critic round');
 assert.equal(critics[0].sheet.image,'data:image/jpeg;base64,YQ==');assert.equal(critics[0].strip,'data:image/jpeg;base64,Yg==');assert.equal(critics[1].round,2);
 assert.deepEqual(critics[0].stripEvidence,{duration_seconds:30,times:[.5,29.5]});
 const r=seen[2].messages.at(-1).content.filter(b=>b.type==='tool_result');
 assert.match(r[0].content,/directives/);assert.match(r[0].content,/three times larger/);
 assert.equal(r[1].is_error,true,'finish is refused until the next passing review');assert.match(r[1].content,/Visual review is required/);
 assert.match(state.internalNote,/Critic: hook 8, hierarchy 9/);assert.equal(state.summary,'Your video is ready.','users never see scores');assert.equal(state.criticCalls,2);
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
test('the first turn is a cache breakpoint',async()=>{
 const {seen}=await harness([[use('a','write',{path:'index.html',content:'<html>1</html>'}),use('b','preview',{times:[1]})],[use('c','visual_review',{decision:'pass',findings:'x',scores:[{time:1,score:9,problems:[]}]}),use('d','finish',{summary:'Done'})]]);
 const first=seen[1].messages[0];assert.ok(first.content.some(b=>b.type==='text'&&b.cache_control?.type==='ephemeral'),'the context block is the breakpoint');
 assert.equal(first.content.filter(b=>/^Remaining calls/.test(b.text||'')).length,0,'the budget line moves with the last turn');
});
test('a tool call whose input arrived as an empty array is kept as an object in the history',async()=>{
 const {seen}=await harness([[Object.assign(use('a','write',{path:'index.html',content:'<html>1</html>'})),{type:'tool_use',id:'b',name:'check',input:[]}],[use('c','finish',{summary:'x'})]],{requireVisualReview:false});
 const first=seen[1].messages.find(m=>m.role==='assistant');
 assert.deepEqual(first.content[1].input,{});assert.equal(JSON.stringify(first.content[1].input),'{}');
});
test('tool calls are forgiven their transport: empty params as [] and a script named with its folder',async()=>{
 const {actionFromToolUse}=await import('../protocol.mjs');
 assert.deepEqual(actionFromToolUse({name:'media',input:{op:'probe',input:'clip.mp4',params:[]}}).params,{});
 assert.deepEqual(actionFromToolUse({name:'run',input:{cmd:'node',args:['work/show.mjs','3']}}).args,['show.mjs','3']);
 const runs=[];
 const {state}=await harness([[use('b','run',{cmd:'node',args:['work/show.mjs']}),use('c','write',{path:'index.html',content:'<html>1</html>'}),use('e','preview',{times:[1]})],[use('d','finish',{summary:'x'})]],
  {requireVisualReview:false,tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true}),run:async a=>{runs.push(a);return {ok:true,exit:0,stdout:'',stderr:'',outputs:[],scratch:[]};}})});
 assert.deepEqual(runs[0].args,['show.mjs']);assert.equal(state.repairs,0);
});
test('layout findings that survive two repairs become advisory and the build goes on to review',async()=>{
 let checks=0;
 const fail={ok:false,diagnostics:{ok:false,errors:[{code:'text_occluded',selector:'#f1',message:'hidden'}]}};
 const {state}=await harness([
  [use('a','write',{path:'index.html',content:'<html>1</html>'}),use('b','preview',{times:[1]})],
  [use('c','patch',{path:'index.html',before:'1',after:'2'}),use('d','preview',{times:[1]})],
  [use('e','patch',{path:'index.html',before:'2',after:'3'}),use('f','preview',{times:[1]})],
  [use('g','visual_review',{decision:'pass',findings:'Readable in the frames',scores:[{time:1,score:8,problems:[]}]}),use('h','finish',{summary:'Done'})],
 ],{limits:{calls:8,repairs:8},tools:dir=>({check:async()=>{checks++;return structuredClone(fail);},snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})})});
 assert.equal(state.status,'preview_ready',state.reason);assert.equal(checks,3);assert.equal(state.repairs,2);
});

test('the protected review window uses the critic before another author call',async()=>{
 for(const verdict of ['pass','revise']){
  let reviews=0;
  const {state,seen}=await harness([[use('w','write',{path:'index.html',content:'<html>Ready</html>'}),use('p','preview',{times:[1]})]],{
   limits:{calls:2,criticCalls:1,reviewReserveMs:1000},context:{lookOnly:false},
   tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
    strip:async()=>({ok:true,providerImage:'data:image/jpeg;base64,Yg==',coverage:{duration_seconds:30,times:[.5,29.5]}}),
    critic:async args=>{assert.deepEqual(args.stripEvidence,{duration_seconds:30,times:[.5,29.5]});reviews++;return {verdict,scores:{hook:8,hierarchy:8,density:8,energy:8,performance:8},directives:verdict==='revise'?['Improve the opening']:[]};}}});
  assert.equal(reviews,1);assert.equal(seen.length,1);assert.equal(state.status,'preview_ready');assert.equal(state.pending,null);
  assert.equal((await import('../review-status.mjs')).reviewStatus(state).status,verdict==='pass'?'passed':'issues');
 }
});
test('an uncertain final critic call remains fenced for reconciliation',async()=>{
 const {state}=await harness([[use('w','write',{path:'index.html',content:'<html>Ready</html>'}),use('p','preview',{times:[1]})]],{
  limits:{calls:2,criticCalls:1,reviewReserveMs:1000},context:{lookOnly:true},tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),critic:async()=>{throw Error('connection lost');}}});
 assert.equal(state.status,'needs_attention');assert.equal(state.pending.purpose,'final_review');
});

test('upstream skill reads are routed to guidance rather than composition files',async()=>{
 const reads=[];
 const {state}=await harness([[use('r','read',{path:'skills/hyperframes/product-launch-video/SKILL.md'}),use('q','needs_input',{question:'Which product should I feature?'})]],{
  tools:{guidance:async name=>{reads.push(name);return 'Workflow reference';}}});
 assert.deepEqual(reads,['skills/hyperframes/product-launch-video/SKILL.md']);assert.equal(state.status,'needs_input');
});

test('Barty adapter instructions route through guidance',async()=>{
 const reads=[];
 await harness([[use('r','read',{path:'kit/barty.md'}),use('q','needs_input',{question:'Which product?'})]],{tools:{guidance:async name=>{reads.push(name);return 'Adapter reference';}}});
 assert.deepEqual(reads,['kit/barty.md']);
});

test('reference images reach the agent without becoming assets or approving the rendered draft',async()=>{
 const inspected=[];
 const {state,seen}=await harness([
  [use('r','inspect_reference',{input:'ref.mp4',params:{mode:'sequence',start:1,end:1.2,every_frame:true,page:1}})],
  [use('no','visual_review',{decision:'pass',findings:'A reference is not output',scores:[{time:1,score:9,problems:[]}]}),use('w','write',{path:'index.html',content:'<html>new output</html>'}),use('p','preview',{times:[1]})],
  [use('v','visual_review',{decision:'pass',findings:'Output reviewed',scores:[{time:1,score:9,problems:[]}]}),use('f','finish',{summary:'Done'})],
 ],{context:{assets:[{name:'ref.mp4',purpose:'reference',asset_type:'video',renderable:false}]},tools:{
  inspect_reference:async a=>{inspected.push(a);return {ok:true,renderable:false,frames:[{cell:1,requested_seconds:1}],providerImage:'data:image/jpeg;base64,Yg=='};},
  check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
 }});
 assert.equal(state.status,'preview_ready');assert.equal(inspected.length,1);
 assert.equal(inspected[0].params.every_frame,true);assert.equal(inspected[0].params.page,1);
 assert.equal(state.inspectionHistory.length,1);assert.equal(state.inspectionHistory[0].renderable,false);assert.equal(state.inspectionHistory[0].providerImage,undefined);
 const ref=seen[1].messages.at(-1).content[0];
 assert.equal(ref.content[1].type,'image');assert.equal(ref.content[1].source.data,'Yg==');
 assert.match(ref.content[0].text,/"renderable":false/);assert.doesNotMatch(ref.content[0].text,/base64/);
 assert.match(seen[2].messages.at(-1).content[0].content,/requires current host-provided snapshot/);
 assert.equal(state.snapshotRevision,1);assert.equal(state.referenceInspections,1);
 assert.ok(!state.messages.some(m=>m.role==='tool'&&JSON.stringify(m.content).includes('base64')),'image bytes never pollute textual history');
});

test('unlisted and source attachments cannot invoke reference inspection',async()=>{
 let calls=0;
 const {state,seen}=await harness([
  [use('r','inspect_reference',{input:'source.png',params:{mode:'frames',times:[0]}}),use('x','inspect_reference',{input:'missing.png',params:{mode:'frames',times:[0]}}),use('w','write',{path:'index.html',content:'<html>ok</html>'}),use('p','preview',{times:[1]})],
  [use('v','visual_review',{decision:'pass',findings:'ok',scores:[{time:1,score:9,problems:[]}]}),use('f','finish',{summary:'Done'})],
 ],{context:{assets:[{name:'source.png',purpose:'source',asset_type:'image'}]},tools:{inspect_reference:async()=>{calls++;return {ok:true};},check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 assert.equal(calls,0);assert.equal(state.status,'preview_ready');
 assert.equal(seen[1].messages.at(-1).content[0].is_error,true);
});

test('the agent logs a limitation while useful work continues and the report is persisted',async()=>{
 const {state,seen,dir}=await harness([
  [use('l','report_limitation',{category:'quality',summary:'The pose does not match the treatment',evidence:'The supplied image looks photographic',impact:'The character style would be wrong',workaround:'Prepare layout without claiming the character is complete',requested_change:'Approve a corrected pose before video purchase'}),use('w','write',{path:'index.html',content:'<html>draft</html>'}),use('p','preview',{times:[1]})],
  [use('v','visual_review',{decision:'pass',findings:'Layout okay',scores:[{time:1,score:9,problems:[]}]}),use('f','finish',{summary:'Draft with character correction still pending'})],
 ]);
 assert.equal(state.status,'preview_ready');assert.equal(state.revision,1);assert.equal(state.calls,2);
 assert.equal(state.limitations[0].source,'agent_report');assert.equal(state.reservedUsd,0);
 const result=seen[1].messages.at(-1).content[0];assert.match(result.content,/fix_workflow_or_model_first/);assert.match(result.content,/"authorization_changed":false/);
 const report=JSON.parse(await readFile(dir+'/s.limitations.json','utf8'));
 assert.equal(report.status,'preview_ready');assert.equal(report.records.length,1);assert.equal(report.records[0].category,'quality');
});
test('history trimming counts text, not image bytes, and never shortens the file the model just read',async()=>{
 const big='<html>'+'x'.repeat(5000)+'</html>';
 const largeImage='data:image/jpeg;base64,'+'A'.repeat(300000);
 const {seen}=await harness([
  [use('a','write',{path:'index.html',content:big}),use('b','preview',{times:[1]})],
  [use('c','read',{path:'index.html'})],
  [use('d','visual_review',{decision:'pass',findings:'x',scores:[{time:1,score:9,problems:[]}]}),use('e','finish',{summary:'Done'})],
 ],{limits:{calls:5,repairs:2,contextBytes:96000},tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:largeImage})})});
 const readResult=seen[2].messages.at(-1).content.find(b=>b.type==='tool_result');
 assert.ok(!/shortened/.test(readResult.content),'the read came back whole');
 assert.ok(readResult.content.length>5000);
});
test('the host final review still runs after the author has reviewed the same revision',async()=>{
 const critics=[];
 const {state}=await harness([
  [use('a','write',{path:'index.html',content:'<html>1</html>'}),use('b','preview',{times:[1]})],
  [use('c','visual_review',{decision:'repair',findings:'Cards too small',scores:[{time:1,score:6,problems:['small']}]})],
  [{type:'text',text:'thinking'}],
 ],{limits:{calls:3,repairs:4,criticCalls:1,reviewReserveMs:1000},tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
  critic:async a=>{critics.push(a);return {ok:true,verdict:'revise',scores:{hook:6,hierarchy:6,density:6,energy:6,performance:6},mean:6,directives:['Fill the frame'],note:''};}})});
 assert.equal(critics.length,1,'the final review ran on the reviewed frames');
 assert.equal(critics[0].sheet.image,'data:image/jpeg;base64,YQ==');
 assert.equal(state.status,'preview_ready');assert.match(state.internalNote,/Critic/);
});
test('unlimited limits: more tool calls per turn, larger results and larger source files are honoured',async()=>{
 const writes=Array.from({length:12},(_,i)=>use('w'+i,'write',{path:'f'+i+'.js',content:'//'+i}));
 const {state}=await harness([[...writes,use('p','write',{path:'index.html',content:'<html>1</html>'}),use('q','finish',{summary:'x'})]],
  {requireVisualReview:false,limits:{calls:1,usesPerTurn:20,resultBytes:64000}});
 assert.equal(state.revision,13,'all thirteen writes in one turn ran');
 const {mkdtemp}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/ws-');
 const big='x'.repeat(200_000);
 await assert.rejects(()=>new Workspace(dir,[]).write('index.html',big),/too large/);
 await new Workspace(dir,[],null,1_000_000).write('index.html',big);
});
test('a review that stops improving ends the build with the best draft and its open notes, however many calls remain',async()=>{
 let rounds=0;
 const flat={ok:true,verdict:'revise',scores:{hook:7,hierarchy:6,density:6,energy:6,performance:6},mean:6.2,directives:['Fill the lower third'],note:''};
 const turn=k=>[use('p'+k,'patch',{path:'index.html',before:'v'+k,after:'v'+(k+1)}),use('q'+k,'preview',{times:[1]})];
 const review=k=>[use('r'+k,'visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]})];
 const {state}=await harness([
  [use('a','write',{path:'index.html',content:'<html>v0</html>'}),use('b','preview',{times:[1]})],review(0),turn(0),review(1),turn(1),review(2),turn(2),review(3),
 ],{limits:{calls:200,repairs:100,criticCalls:10},tools:dir=>({check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='}),
  critic:async()=>{rounds++;return rounds===1?flat:{...flat,mean:6.3};}})});
 assert.equal(state.status,'preview_ready');assert.equal(rounds,3,'one baseline round, then two without improvement');
 assert.match(state.internalNote,/stopped improving after 3 rounds/);assert.match(state.internalNote,/Fill the lower third/);assert.equal(state.summary,'Here is the best version so far. You can keep improving it.');
 assert.ok(state.calls<10,'it did not run on toward the call limit');
});

test('a budgeted build counts what its calls cost, not each call at its ceiling',async()=>{
 const turns=[[use('a','write',{path:'index.html',content:'<html>ok</html>'}),use('b','preview',{times:[1]})],[use('c','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('d','finish',{summary:'Done'})]];
 const run=async spendBudget=>{
  const dir=await mkdtemp(tmpdir()+'/budget-');await writeFile(dir+'/index.html','<html></html>');let i=0;
  // Each call may cost up to $0.30 but actually costs $0.05; the step's budget is $0.50.
  const provider={id:'t',maxCallUsd:0.3,complete:async()=>{const t=turns[i++]??turns.at(-1);return {content:t,text:'',predictionId:'p'+i,metrics:{},actualCostUsd:0.05};}};
  return runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[],dir+'-work'),provider,context:{brief:'x',toolMode:true},limits:{calls:6,repairs:3,budgetUsd:0.5,spendBudget},requireVisualReview:true,
   tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 };
 const spent=await run(true);assert.equal(spent.status,'preview_ready');assert.equal(spent.calls,2,'two $0.05 calls fit a $0.50 budget');
 // Counted at the ceiling, the second call does not fit: the build stops and delivers its last checked draft.
 const capped=await run(false);assert.equal(capped.calls,1);
});
test('an empty text block in a reply never goes back to the model (the API refuses it)',async()=>{
 const {state,seen}=await harness([[{type:'text',text:''},use('a','read',{path:'index.html'})],[],[use('b','needs_input',{question:'Continue?'})]]);
 assert.equal(state.status,'needs_input');
 for(const call of seen.slice(1))for(const m of JSON.parse(call.messages_json??JSON.stringify(call.messages??[])))for(const b of m.content)if(b.type==='text')assert.notEqual(String(b.text).trim(),'','no empty text block: '+JSON.stringify(m));
});
test('an edit returns the file as it is now, so the next patch needs no read; only the newest copy stays',async()=>{
 const {state,seen}=await harness([
  [use('a','write',{path:'index.html',content:'<html><body>v1</body></html>'})],
  [use('b','patch',{path:'index.html',before:'v1',after:'v2'}),use('c','preview',{times:[1]})],
  [use('d','patch',{path:'index.html',before:'v2',after:'v3'}),use('e','preview',{times:[1]})],
  [use('f','visual_review',{decision:'pass',findings:'Fine',scores:[{time:1,score:9,problems:[]}]}),use('g','finish',{summary:'Done'})],
 ],{limits:{calls:6,repairs:2,contextBytes:96000}});
 assert.equal(state.status,'preview_ready',state.reason);
 const results=seen[3].messages.filter(m=>m.role==='user').flatMap(m=>m.content).filter(b=>b.type==='tool_result'&&typeof b.content==='string'&&b.content.includes('"current"')).map(b=>JSON.parse(b.content));
 assert.deepEqual(results.map(r=>r.current),['[superseded: a later edit returned the newer text]','[superseded: a later edit returned the newer text]','<html><body>v3</body></html>']);
 assert.ok(results.every(r=>r.path==='index.html'));
});
