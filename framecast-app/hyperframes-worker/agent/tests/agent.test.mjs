import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile,symlink,rm} from 'node:fs/promises';
import os from 'node:os';import path from 'node:path';
import {runAgent} from '../runner.mjs';import {Workspace,digest} from '../workspace.mjs';import {parseAction} from '../protocol.mjs';import {ReplicateProvider} from '../replicate.mjs';
const action=x=>JSON.stringify(x);
async function harness(t,actions,{limits={},tools,provider}={}) {
 const root=await mkdtemp(path.join(os.tmpdir(),'hf-agent-'));t.after(()=>rm(root,{recursive:true,force:true}));
 await writeFile(root+'/index.html','<h1>Original</h1>');await writeFile(root+'/product.png','protected');
 const workspace=new Workspace(root,[{path:'product.png',sha256:digest('protected')}]);
 let calls=0;const seen=[];
 provider??={id:'fake',maxCallUsd:0,complete:async request=>{seen.push(request);return {text:actions[calls++]};}};
 const args={stateFile:root+'/state.json',context:{brief:'Use my product',baseRevision:'r1',approvedFacts:[],lockedAssets:['product.png']},workspace,provider,tools:tools??{check:async()=>({ok:true}),snapshot:async()=>({ok:true,paths:['frame.png']})},limits};
 return {root,args,run:()=>runAgent(args),seen};
}
test('valid loop patches source, checks, snapshots and resumes without redoing calls',async t=>{
 const h=await harness(t,[action({type:'read',path:'index.html'}),action({type:'patch',path:'index.html',before:'Original',after:'Updated'}),action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Updated opening'})]);
 assert.equal((await h.run()).status,'preview_ready');assert.equal(await readFile(h.root+'/index.html','utf8'),'<h1>Updated</h1>');assert.match(h.seen[1].prompt,/Original/);await h.run();assert.equal(h.seen.length,5);
});
test('rejects shell commands, extra fields, fenced JSON and invalid times',()=>{
 for(const raw of [action({type:'shell',command:'ls'}),action({type:'check',command:'ls'}),'```json\n{}\n```',action({type:'snapshot',times:[31]})])assert.throws(()=>parseAction(raw));
});
test('bounded malformed response repair',async t=>{const h=await harness(t,['no','no','no']);assert.equal((await h.run()).reason,'Action repair limit reached');assert.equal(h.seen.length,3);});
test('cannot finish without verified current revision',async t=>{const h=await harness(t,[action({type:'finish',summary:'Done'})]);assert.equal((await h.run()).status,'failed');});
test('editing invalidates earlier checks and snapshots',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'write',path:'index.html',content:'changed'}),action({type:'finish',summary:'Done'})]);assert.equal((await h.run()).status,'failed');});
test('media proposal pauses without creating assets',async t=>{const h=await harness(t,[action({type:'propose_media',description:'New voice needed'})]);assert.equal((await h.run()).status,'awaiting_media_approval');});
test('budget exhausted before provider request',async t=>{let calls=0;const h=await harness(t,[],{provider:{id:'paid-fake',maxCallUsd:1,complete:async()=>{calls++;}}});assert.equal((await h.run()).reason,'Model budget exhausted');assert.equal(calls,0);});
test('unknown paid outcome is never automatically resubmitted',async t=>{let calls=0;const h=await harness(t,[],{limits:{budgetUsd:2},provider:{id:'paid-fake',maxCallUsd:1,complete:async({onPrediction})=>{calls++;await onPrediction('known-id');throw Error('timeout');}}});const state=await h.run();assert.equal(state.status,'needs_attention');assert.equal(state.pending.predictionId,'known-id');await h.run();assert.equal(calls,1);assert.equal(state.reservedUsd,1);});
test('source path traversal, asset overwrite and symlink writes denied',async t=>{const h=await harness(t,[]);for(const p of ['../x.html','/tmp/x.html','product.png'])await assert.rejects(h.args.workspace.write(p,'bad'));await symlink('/etc/passwd',h.root+'/escape.html');await assert.rejects(h.args.workspace.write('escape.html','bad'));});
test('protected asset corruption stops before model call',async t=>{const h=await harness(t,[]);await writeFile(h.root+'/product.png','changed');assert.equal((await h.run()).status,'failed');assert.equal(h.seen.length,0);});
test('call and context limits stop loops',async t=>{const h=await harness(t,[action({type:'assets'})],{limits:{calls:1}});assert.equal((await h.run()).reason,'Model call limit reached');const j=await harness(t,[],{limits:{contextBytes:2}});assert.equal((await j.run()).reason,'Context limit reached');});
test('cancellation before call has no spend',async t=>{const h=await harness(t,[]);h.args.signal=AbortSignal.abort();assert.equal((await h.run()).status,'cancelled');assert.equal(h.seen.length,0);});
const contract={model:'anthropic/claude-opus-4.6',observedVersion:'test-version',input:{properties:{max_tokens:{minimum:1024,maximum:128000}}}};
test('Replicate adapter handles array chunks, polling and records prediction before poll',async()=>{
 const calls=[];let saved=false;const provider=new ReplicateProvider({contract,token:'test-only',enabled:true,maxCallUsd:1,pollMs:0,fetchImpl:async(url,options)=>{calls.push({url,options});if(calls.length===1)return {ok:true,json:async()=>({id:'abc',status:'starting'})};assert.ok(saved);return {ok:true,json:async()=>({id:'abc',status:'succeeded',version:'test-version',output:['{','}'],metrics:{predict_time:1}})};}});
 const result=await provider.complete({prompt:'x',system:'policy',maxTokens:1024,onPrediction:async()=>{saved=true;}});assert.equal(result.text,'{}');assert.equal(result.actualCostUsd,null);assert.equal(calls.length,2);assert.equal(JSON.parse(calls[0].options.body).input.system_prompt,'policy');
});
test('Replicate stays disabled by default',()=>assert.throws(()=>new ReplicateProvider({contract,token:'test',maxCallUsd:1})));
test('Replicate create failure is not retried',async()=>{let calls=0;const p=new ReplicateProvider({contract,token:'test',enabled:true,maxCallUsd:1,fetchImpl:async()=>{calls++;throw Error('network');}});await assert.rejects(p.complete({prompt:'x',system:'x',maxTokens:1024}));assert.equal(calls,1);});
test('aggregate token allowance blocks before provider call',async t=>{const h=await harness(t,[],{limits:{totalOutputTokenAllowance:100}});assert.equal((await h.run()).reason,'Output token allowance exhausted');assert.equal(h.seen.length,0);});
test('interrupted in-flight tool is fenced on resume',async t=>{const h=await harness(t,[action({type:'assets'})],{limits:{calls:1}});const state=await h.run();state.status='running';state.pending={kind:'tool',action:{type:'write'}};await writeFile(h.root+'/state.json',JSON.stringify(state));assert.equal((await h.run()).status,'needs_attention');assert.equal(h.seen.length,1);});
test('changed context cannot reuse old run',async t=>{const h=await harness(t,[action({type:'needs_input',question:'Which product?'})]);await h.run();h.args.context.brief='another request';await assert.rejects(h.run(),/context changed/);});
test('version drift fails rather than trusting new schema',async()=>{const p=new ReplicateProvider({contract,token:'test',enabled:true,maxCallUsd:1,fetchImpl:async()=>({ok:true,json:async()=>({id:'abc',status:'succeeded',version:'changed',output:['hello']})})});await assert.rejects(p.complete({prompt:'x',system:'x',maxTokens:1024}),/version drift/);});
test('deadline bounds a provider that ignores its signal',async t=>{
 const keepAlive=setTimeout(()=>{},1000);t.after(()=>clearTimeout(keepAlive));
 const h=await harness(t,[],{limits:{elapsedMs:20},provider:{id:'hung-fake',maxCallUsd:0,complete:()=>new Promise(()=>{})}});
 assert.equal((await h.run()).status,'needs_attention');
});
test('external edits invalidate a saved preview on resume',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Ready'})]);await h.run();await writeFile(h.root+'/index.html','external edit');await assert.rejects(h.run(),/bundle changed/);});
test('visual completion requires an image-backed review when enabled',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Ready'})]);h.args.requireVisualReview=true;assert.equal((await h.run()).reason,'Visual review is required');});
test('host image reaches provider and current visual review permits completion',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'visual_review',scores:[{time:1,score:9,problems:[]}],decision:'pass',findings:'Readable sampled layout'}),action({type:'finish',summary:'Ready'})],{tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});h.args.requireVisualReview=true;assert.equal((await h.run()).status,'preview_ready');assert.equal(h.seen[2].image,'data:image/jpeg;base64,YQ==');});
test('unsupported visual review without an image is rejected',async t=>{const h=await harness(t,[action({type:'visual_review',scores:[{time:1,score:9,problems:[]}],decision:'pass',findings:'Looks fine'})]);assert.equal((await h.run()).status,'failed');});
test('large tool reports are bounded before the next model call',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'needs_input',question:'Continue?'})],{tools:{check:async()=>({ok:true,details:'x'.repeat(300000)})}});assert.equal((await h.run()).status,'needs_input');assert.ok(h.seen[1].prompt.length<20000);});
test('hidden version is checked against advertised model API schema',async()=>{let requests=0;const p=new ReplicateProvider({contract,token:'test',enabled:true,maxCallUsd:1,fetchImpl:async()=>({ok:true,json:async()=>++requests===1?{id:'abc',status:'succeeded',version:'hidden',output:['ok']}:{latest_version:{id:'test-version'}}})});assert.equal((await p.complete({prompt:'x',system:'x',maxTokens:1024})).text,'ok');assert.equal(requests,2);});
test('pinned guidance can be read without broad workspace access',async t=>{const h=await harness(t,[action({type:'read',path:'references/minimal-composition.md'}),action({type:'needs_input',question:'Next?'})]);h.args.tools.guidance=async p=>{assert.equal(p,'references/minimal-composition.md');return 'pinned example';};assert.equal((await h.run()).status,'needs_input');assert.match(h.seen[1].prompt,/pinned example/);});
test('shared test budget refuses excess and retains unknown reservations',async t=>{const {TestBudget}=await import('../budget.mjs');const h=await harness(t,[]);const budget=new TestBudget(h.root+'/budget.json',.02);await budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'});await assert.rejects(budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'}),/budget exhausted/);});
test('commentary around exactly one validated action is ignored, never executed',()=>{assert.deepEqual(parseAction('I will inspect.\n{"type":"check"}'),{type:'check'});assert.throws(()=>parseAction('{"type":"check"} {"type":"assets"}'));assert.throws(()=>parseAction('```json\n{"type":"check"}\n```\n```json\n{"type":"assets"}\n```'));});

test('snapshot overflow gives actionable bounded correction',()=>{assert.throws(()=>parseAction(action({type:'snapshot',times:[1,3,5,8,12,14]})),/1 to 5/);assert.deepEqual(parseAction(action({type:'snapshot',times:[1,7,13]})).times,[1,7,13]);});
test('combined preview validates and captures before image-backed completion',async t=>{let checks=0,shots=0;const h=await harness(t,[action({type:'preview',times:[1,7,13]}),action({type:'visual_review',scores:[{time:1,score:9,problems:[]}],decision:'pass',findings:'Sampled layout passes'})],{tools:{check:async()=>{checks++;return {ok:true}},snapshot:async()=>{shots++;return {ok:true,providerImage:'data:image/jpeg;base64,YQ=='}}}});h.args.requireVisualReview=true;assert.equal((await h.run()).status,'preview_ready');assert.equal(checks,1);assert.equal(shots,1);assert.equal(h.seen.length,2);});
test('failed combined preview never captures or marks current source checked',async t=>{let shots=0;const h=await harness(t,[action({type:'preview',times:[1]}),action({type:'needs_input',question:'Fix source?'})],{tools:{check:async()=>({ok:false}),snapshot:async()=>{shots++;}}});const s=await h.run();assert.equal(s.checkedRevision,-1);assert.equal(shots,0);});
test('compacted history drops old source but preserves diagnostics and recent reads',async()=>{const {promptHistory}=await import('../prompt-context.mjs');const source='x'.repeat(30000);const messages=[{role:'assistant',content:action({type:'write',path:'index.html',content:source})},{role:'tool',content:{text:source}},{role:'tool',content:{error:'Missing asset'}},...Array.from({length:6},()=>({role:'tool',content:{text:'latest source'}}))];const result=promptHistory(messages);assert.ok(JSON.stringify(result).length<2000);assert.match(JSON.stringify(result),/Missing asset/);assert.equal(result.at(-1).content.text,'latest source');assert.equal(messages[1].content.text,source);});
test('timeline and installed primitives dispatch without arbitrary commands',async t=>{const h=await harness(t,[action({type:'timeline'}),action({type:'primitives'}),action({type:'needs_input',question:'Next?'})]);h.args.tools.timeline=async()=>({duration:15});const s=await h.run();assert.equal(s.status,'needs_input');assert.match(h.seen[2].prompt,/gsap-timeline/);assert.equal(s.usage.length,3);});
test('mismatched products pause without a paid call or invented endorsement',async t=>{const h=await harness(t,[]);h.args.context.productIdentityConflict=true;assert.equal((await h.run()).status,'needs_input');assert.equal(h.seen.length,0);});
test('unsupported claims and short footage need a decision before spending',async t=>{for(const extra of [{requestedClaims:['Guaranteed cure']},{sourceDuration:5,duration:15}]){const h=await harness(t,[]);Object.assign(h.args.context,extra);assert.equal((await h.run()).status,'needs_input');assert.equal(h.seen.length,0);}});
test('new spoken hook is a media proposal, never an automatic purchase',async t=>{const h=await harness(t,[]);h.args.context.requestedNewSpeech=true;assert.equal((await h.run()).status,'awaiting_media_approval');assert.equal(h.seen.length,0);});
test('locked source rejection gives bounded repair without changing the source',async t=>{
 const h=await harness(t,[action({type:'write',path:'index.html',content:'Changed everything'}),action({type:'write',path:'index.html',content:'<main><h1>Original</h1></main>'}),action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Preserved source'})]);
 h.args.context.lockedSourceFragments=['<h1>Original</h1>'];
 const state=await h.run();assert.equal(state.status,'preview_ready');assert.equal(state.repairs,1);assert.match(h.seen[1].prompt,/sourceUnchanged/);assert.equal(await readFile(h.root+'/index.html','utf8'),'<main><h1>Original</h1></main>');
});
test('repeated destructive or duplicated locked sources exhaust repair allowance',async t=>{
 const bad=action({type:'write',path:'index.html',content:'<h1>Original</h1><h1>Original</h1>'});
 const h=await harness(t,[bad,bad,bad]);h.args.context.lockedSourceFragments=['<h1>Original</h1>'];
 assert.equal((await h.run()).reason,'Authoring repair limit reached');assert.equal(h.seen.length,3);assert.equal(await readFile(h.root+'/index.html','utf8'),'<h1>Original</h1>');
});
test('failed exact patch can recover by reading the current source',async t=>{
 const h=await harness(t,[action({type:'patch',path:'index.html',before:'Missing',after:'Changed'}),action({type:'read',path:'index.html'}),action({type:'patch',path:'index.html',before:'Original',after:'Updated'}),action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Done'})]);
 assert.equal((await h.run()).status,'preview_ready');assert.equal(await readFile(h.root+'/index.html','utf8'),'<h1>Updated</h1>');
});
test('source reads are not silently truncated by diagnostic report limits',async t=>{
 const h=await harness(t,[action({type:'read',path:'index.html'}),action({type:'needs_input',question:'Confirm?'})]);
 await writeFile(h.root+'/index.html','x'.repeat(20000)+'END_OF_SOURCE');
 assert.equal((await h.run()).status,'needs_input');assert.match(h.seen[1].prompt,/END_OF_SOURCE/);
});
test('sandbox write rejection remains terminal, not an authoring retry',async t=>{
 const h=await harness(t,[action({type:'write',path:'../outside.html',content:'unsafe'})]);
 assert.equal((await h.run()).reason,'Source path is not allowed');assert.equal(h.seen.length,1);
});

test('concurrent test reservations cannot exceed cap',async t=>{const {TestBudget}=await import('../budget.mjs');const h=await harness(t,[]);const budget=new TestBudget(h.root+'/budget.json',.02);const results=await Promise.allSettled(Array.from({length:4},()=>budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'})));assert.equal(results.filter(r=>r.status==='fulfilled').length,1);const data=JSON.parse(await readFile(h.root+'/budget.json'));assert.equal(data.calls.length,1);});
test('settlement preserves other reservations and is idempotent',async t=>{const {TestBudget}=await import('../budget.mjs');const h=await harness(t,[]);const budget=new TestBudget(h.root+'/budget.json',1);const a=await budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'});await budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'});const response={predictionId:'a',metrics:{token_input_count:10,token_output_count:10}};await a(response);await a(response);const data=JSON.parse(await readFile(h.root+'/budget.json'));assert.equal(data.calls.length,2);assert.equal(data.calls[1].status,'reserved');await assert.rejects(a({...response,predictionId:'b'}),/another prediction/);});
test('failed inspection keeps blocking findings and fixes without warning noise',async()=>{const {inspectionReport}=await import('../inspection-report.mjs');const result=inspectionReport(JSON.stringify({ok:false,lint:{findings:[{severity:'warning',message:'noise'.repeat(10000)},{severity:'error',code:'nested',message:'Nested media',fixHint:'Move media to root'}]}}));assert.equal(result.ok,false);assert.equal(result.errors.length,1);assert.equal(result.errors[0].fixHint,'Move media to root');assert.ok(JSON.stringify(result).length<1000);assert.equal(inspectionReport('not json').ok,false);});

test('media action runs the sandbox tool, protects the derived file and rejects unknown inputs', async () => {
  const {runAgent} = await import('../runner.mjs');
  const {Workspace} = await import('../workspace.mjs');
  const {mkdtemp, writeFile} = await import('node:fs/promises');
  const {tmpdir} = await import('node:os');
  const dir = await mkdtemp(tmpdir() + '/media-');
  await writeFile(dir + '/index.html', '<html></html>');
  await writeFile(dir + '/asset-1-x.mp4', 'video');
  const ws = new Workspace(dir, [{path: 'asset-1-x.mp4', sha256: (await import('../workspace.mjs')).digest('video')}]);
  const steps = [
    {type: 'media', op: 'trim', input: 'secret.mp4', params: {start: 0, end: 1}},
    {type: 'media', op: 'trim', input: 'asset-1-x.mp4', params: {start: 0, end: 1}},
    {type: 'needs_input', question: 'stop'},
  ];
  let i = 0, calls = [];
  const provider = {id: 't', maxCallUsd: 0, complete: async () => ({text: JSON.stringify(steps[i++])})};
  const state = await runAgent({stateFile: dir + '/state.json', workspace: ws, provider, context: {brief: 'x'},
    limits: {calls: 5, repairs: 3, budgetUsd: 0},
    tools: {media: async args => { calls.push(args.input); await writeFile(dir + '/derived-1-trim.mp4', 'cut'); return {ok: true, output: 'derived-1-trim.mp4', sha256: (await import('../workspace.mjs')).digest('cut')}; }}});
  assert.equal(state.status, 'needs_input');
  assert.deepEqual(calls, ['asset-1-x.mp4'], 'unknown input never reaches the tool');
  assert.equal(ws.assets.find(a => a.path === 'derived-1-trim.mp4').derivedFrom, 'asset-1-x.mp4');
  await writeFile(dir + '/derived-1-trim.mp4', 'tampered');
  await assert.rejects(() => ws.verifyAssets(), /Protected asset changed/);
});

test('media params must be a small object', async () => {
  const {parseAction} = await import('../protocol.mjs');
  assert.equal(parseAction('{"type":"media","op":"speed","input":"a.mp4","params":{"factor":2}}').params.factor, 2);
  assert.throws(() => parseAction('{"type":"media","op":"speed","input":"a.mp4","params":"--rm -rf"}'), /Invalid media params/);
  assert.throws(() => parseAction('{"type":"media","op":"speed","input":"a.mp4"}'), /Unexpected or missing/);
});
test('edit-only runs must patch, while redesigns may rewrite',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile,readFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 for(const [brief,expectRewrite] of [['Make the headline orange',false],['Redesign it from scratch in a new style',true]]){
  const dir=await mkdtemp(tmpdir()+'/edit-');await writeFile(dir+'/index.html','<html><h1>Old</h1></html>');
  const ws=new Workspace(dir,[]);const steps=[{type:'write',path:'index.html',content:'<html><h1>New</h1></html>'},{type:'needs_input',question:'stop'}];let i=0;
  const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief,baseRevision:'r1',editOnly:!expectRewrite},limits:{calls:4,repairs:3,budgetUsd:0},tools:{}});
  const first=state.messages.find(m=>m.role==='tool').content;
  if(expectRewrite)assert.equal(first.revision,1);else{assert.match(first.error,/patch actions/);assert.match(await readFile(dir+'/index.html','utf8'),/Old/);}
 }
});
test('the same findings after a repair come back with a plain note to change approach',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/rep-');await writeFile(dir+'/index.html','<html></html>');
 const ws=new Workspace(dir,[]);const steps=[{type:'check'},{type:'check'},{type:'needs_input',question:'stop'}];let i=0;
 const fail={ok:false,diagnostics:{ok:false,errors:[{code:'content_overlap',selector:'#hook span'}]}};
 const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:5,repairs:4,budgetUsd:0},tools:{check:async()=>fail}});
 const [first,second]=state.messages.filter(m=>m.role==='tool').map(m=>m.content);
 assert.equal(first.diagnostics.repeated,undefined);assert.equal(second.diagnostics.repeated,true);assert.match(second.diagnostics.note,/data-layout-allow-overlap/);
});
test('a cut-off reply gets a split-the-work hint and is never replayed in full',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');const {promptHistory}=await import('../prompt-context.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/cut-');await writeFile(dir+'/index.html','<html></html>');
 const ws=new Workspace(dir,[]);const long='{"type":"write","path":"index.html","content":"'+'x'.repeat(9000);let i=0;
 const replies=[{text:long,stopReason:'max_tokens'},{text:JSON.stringify({type:'needs_input',question:'stop'})}];
 const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>replies[i++]},context:{brief:'x'},limits:{calls:4,repairs:3,budgetUsd:0},tools:{}});
 const err=state.messages.find(m=>m.role==='tool').content;
 assert.match(err.hint,/style\.css and main\.js/);
 const hist=promptHistory(state.messages);assert.ok(JSON.stringify(hist).length<3000,'the cut-off reply is summarised');
});
test('a draft that passed checks and snapshots is delivered when the call limit is reached',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/lim-');await writeFile(dir+'/index.html','<html></html>');
 const steps=[{type:'write',path:'index.html',content:'<html><h1>Done</h1></html>'},{type:'preview',times:[1]}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:2,repairs:3,budgetUsd:0},requireVisualReview:true,
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(state.status,'preview_ready');assert.match(state.internalNote,/visual review/);
 assert.equal((await import('../review-status.mjs')).reviewStatus(state).status,'issues','a recovered draft is delivered with a note saying so');
 const dir2=await mkdtemp(tmpdir()+'/lim2-');await writeFile(dir2+'/index.html','<html></html>');let j=0;
 const failing=await runAgent({stateFile:dir2+'/s.json',workspace:new Workspace(dir2,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[j++])})},context:{brief:'x'},limits:{calls:2,repairs:3,budgetUsd:0},requireVisualReview:true,
  tools:{check:async()=>({ok:false,diagnostics:{ok:false,errors:[{code:'x'}]}}),snapshot:async()=>({ok:true})}});
 assert.equal(failing.status,'failed','a draft that failed its checks is never delivered');
});
test('on the last call a checked draft is delivered with its open issues instead of held as a question',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/last-');await writeFile(dir+'/index.html','<html></html>');
 const steps=[{type:'write',path:'index.html',content:'<html><h1>Draft</h1></html>'},{type:'preview',times:[1]},{type:'needs_input',question:'Tiles do not exit at 11.5 s.'}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:3,repairs:3,budgetUsd:0},requireVisualReview:true,
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(state.status,'preview_ready');assert.match(state.internalNote,/Tiles do not exit/);
});
test('a question on the second-to-last call with an earlier checked draft delivers that draft, not the later edit',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile,readFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/late-');await writeFile(dir+'/index.html','<html></html>');
 const steps=[{type:'write',path:'index.html',content:'<html><h1>Good</h1></html>'},{type:'preview',times:[1]},{type:'patch',path:'index.html',before:'Good',after:'Half fixed'},{type:'needs_input',question:'The closing line repeats a word.'}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:5,repairs:3,budgetUsd:0},requireVisualReview:true,
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(state.status,'preview_ready');assert.match(state.internalNote,/repeats a word/);
 assert.match(await readFile(dir+'/index.html','utf8'),/Good/);
});
test('a question early in a build is still asked',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/early-');await writeFile(dir+'/index.html','<html></html>');
 const steps=[{type:'needs_input',question:'Which offer should the video end on?'}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:8,repairs:3,budgetUsd:0},
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(state.status,'needs_input');
});
test('running out of context after a checked draft delivers that draft',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile,readFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/ctx-');await writeFile(dir+'/index.html','<html></html>');
 const big='x'.repeat(4000);
 const steps=[{type:'write',path:'index.html',content:'<html><h1>Good</h1></html>'},{type:'preview',times:[1]},{type:'patch',path:'index.html',before:'Good',after:'Good '+big}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++]??{type:'check'})})},context:{brief:'x'},limits:{calls:8,repairs:3,budgetUsd:0,contextBytes:4500},
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(state.status,'preview_ready');assert.match(state.internalNote,/Context limit reached/);assert.doesNotMatch(state.summary,/review|limit|score/i);
 assert.equal(await readFile(dir+'/index.html','utf8'),'<html><h1>Good</h1></html>');
});
test('the last call within the per-run budget is not refused by rounding',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/budget-');await writeFile(dir+'/index.html','<html></html>');let calls=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:new Workspace(dir,[]),provider:{id:'t',maxCallUsd:0.45,complete:async()=>{calls++;return {text:JSON.stringify(calls<16?{type:'read',path:'index.html'}:{type:'needs_input',question:'q'}),actualCostUsd:0.01}}},context:{brief:'x'},limits:{calls:16,repairs:3,budgetUsd:16*0.45,maxOutputTokens:256,totalOutputTokenAllowance:100000},
  tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/png;base64,AA=='})}});
 assert.equal(calls,16);
});
test('a visual review that passes a frame under 8 is rejected and the agent is told to repair',async t=>{
 const h=await harness(t,[action({type:'preview',times:[1,7]}),action({type:'visual_review',scores:[{time:1,score:9,problems:[]},{time:7,score:6,problems:['Subject tiny','Text covers the card']}],decision:'pass',findings:'Fine'}),action({type:'visual_review',scores:[{time:1,score:9,problems:[]},{time:7,score:6,problems:['Subject tiny','Text covers the card']}],decision:'repair',findings:'Fix the 7 s frame'}),action({type:'needs_input',question:'stop'})],{tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});
 h.args.requireVisualReview=true;const state=await h.run();
 const tool=state.messages.filter(m=>m.role==='tool').map(m=>JSON.stringify(m.content));
 assert.ok(tool.some(c=>/cannot pass/.test(c)),'the pass was refused');
 assert.ok(tool.some(c=>/Fix the lowest-scoring frames first: Subject tiny; Text covers the card/.test(c)),'the repair names the worst problems');
 assert.deepEqual(state.scores.map(x=>x.score),[9,6]);
});

test('JSON action mode carries reference evidence once and keeps output review separate',async t=>{
 const refImage='data:image/jpeg;base64,Yg==',outputImage='data:image/jpeg;base64,YQ==';
 const h=await harness(t,[
  action({type:'inspect_reference',input:'ref.png',params:{mode:'frames',times:[0]}}),
  action({type:'write',path:'index.html',content:'<html>Own output</html>'}),
  action({type:'preview',times:[1]}),
  action({type:'visual_review',decision:'pass',findings:'Output checked',scores:[{time:1,score:9,problems:[]}]}),
  action({type:'finish',summary:'Done'}),
 ],{tools:{inspect_reference:async()=>({ok:true,renderable:false,providerImage:refImage}),check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:outputImage})}});
 h.args.context.assets=[{name:'ref.png',purpose:'reference',asset_type:'image'}];h.args.requireVisualReview=true;
 const state=await h.run();assert.equal(state.status,'preview_ready');
 assert.equal(h.seen[1].image,refImage);assert.equal(JSON.parse(h.seen[1].prompt).attachedSnapshot,null);
 assert.match(JSON.parse(h.seen[1].prompt).attachedReference.instruction,/reference evidence/);
 assert.equal(h.seen[2].image,undefined);assert.equal(h.seen[3].image,outputImage);
 assert.deepEqual(h.args.workspace.assets.map(a=>a.path),['product.png']);
});
test('open pacing errors are notes: the user reviews the result, so finish is accepted at once',async t=>{
 const still={code:'still_stretch',severity:'error',time:3,message:'Nothing on screen moves or changes from 3.0 s to 6.0 s (3.0 s).',fixHint:'Give the beat life'};
 const tools={check:async()=>({ok:true,pacing:[still]}),snapshot:async()=>({ok:true,paths:['frame.png']})};
 const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Done'})],{tools});
 assert.equal((await h.run()).status,'preview_ready');assert.equal(h.seen.length,3);
});
test('when findings are set to block, finish is refused once while the checked draft has open pacing errors; finishing again with a reason is accepted',async t=>{
 const still={code:'still_stretch',severity:'error',time:3,message:'Nothing on screen moves or changes from 3.0 s to 6.0 s (3.0 s).',fixHint:'Give the beat life'};
 const tools={check:async()=>({ok:true,pacing:[still,{code:'slow_drift',severity:'warning',time:1,message:'drift'}]}),snapshot:async()=>({ok:true,paths:['frame.png']})};
 const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Done'}),action({type:'finish',summary:'Done. The 3 s hold on the price card is intentional: it is the moment to read the price.'})],{tools});
 h.args.context.findingsBlockFinish=true;
 const r=await h.run();
 assert.equal(r.status,'preview_ready');assert.equal(h.seen.length,4);
 assert.match(h.seen[3].prompt,/Not finished: this draft still has these findings/);assert.match(h.seen[3].prompt,/still_stretch/);
 assert.doesNotMatch(h.seen[3].prompt.split('Not finished')[1]??'',/slow_drift/,'warnings do not hold a finish');
});
test('a draft with no open pacing errors finishes at once',async t=>{
 const tools={check:async()=>({ok:true,pacing:[{code:'slow_drift',severity:'warning',time:1,message:'drift'}]}),snapshot:async()=>({ok:true,paths:['frame.png']})};
 const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Done'})],{tools});
 assert.equal((await h.run()).status,'preview_ready');
});
test('Stop after a checked draft lets the step finish and keeps that draft, not the later unchecked edit',async t=>{
 let stop=false;
 const h=await harness(t,[action({type:'patch',path:'index.html',before:'Original',after:'Checked'}),action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'patch',path:'index.html',before:'Checked',after:'Half-done'}),action({type:'finish',summary:'never'})]);
 h.args.stopRequested=()=>stop;
 const provider=h.args.provider,complete=provider.complete;let n=0;
 provider.complete=async r=>{if(++n===4)stop=true;return complete(r);};
 const r=await h.run();
 assert.equal(r.status,'preview_ready');assert.equal(r.stoppedByUser,true);
 assert.equal(r.summary,'Stopped at your request. This is the last finished version.');
 assert.equal(await readFile(h.root+'/index.html','utf8'),'<h1>Checked</h1>','the unchecked edit made after Stop is rolled back');
 assert.equal(h.seen.length,4,'no model call after Stop');
});
test('Stop before any draft passed its checks ends the build cancelled',async t=>{
 const h=await harness(t,[action({type:'patch',path:'index.html',before:'Original',after:'Draft'}),action({type:'check'})]);
 h.args.stopRequested=()=>true;
 const r=await h.run();
 assert.equal(r.status,'cancelled');assert.match(r.reason,/before any version passed its checks/);assert.equal(h.seen.length,0);
});
test('on the storyboard, held stills are not a still-stretch finding, so finish is not held for them',async t=>{
 const still={code:'still_stretch',severity:'error',time:0,message:'Nothing on screen moves',fixHint:'x'};
 const tools={check:async()=>({ok:true,pacing:[still]}),snapshot:async()=>({ok:true,paths:['frame.png']})};
 const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'finish',summary:'Storyboard'})],{tools});
 h.args.context={...h.args.context,lookOnly:true};
 assert.equal((await h.run()).status,'preview_ready');assert.equal(h.seen.length,3);
});
test('reading a file that is not there goes back to the builder instead of ending the run',async t=>{const h=await harness(t,[action({type:'read',path:'wyv-mascot3d.js'}),action({type:'needs_input',question:'Next?'})]);assert.equal((await h.run()).status,'needs_input');assert.match(h.seen[1].prompt,/kit\/remotion\.md/);});
test('writing or reading a file name the composition cannot have goes back to the builder',async t=>{const h=await harness(t,[action({type:'write',path:'cards.txt',content:'notes'}),action({type:'read',path:'notes.md'}),action({type:'needs_input',question:'Next?'})]);assert.equal((await h.run()).status,'needs_input');assert.match(h.seen[1].prompt,/Source path is not allowed/);});
test('an unexpected error after a checked draft delivers that draft instead of failing',async t=>{
 const tools={check:async()=>({ok:true}),snapshot:async()=>({ok:true,paths:['frame.png']}),timeline:async()=>{throw Error('Renderer crashed');}};
 const h=await harness(t,[action({type:'patch',path:'index.html',before:'Original',after:'Updated'}),action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'timeline'})],{tools});
 const state=await h.run();
 assert.equal(state.status,'preview_ready');assert.equal(state.recoveredDraft,true);assert.match(state.internalNote,/Renderer crashed/);
 assert.equal(await readFile(h.root+'/index.html','utf8'),'<h1>Updated</h1>');
});
test('without a checked draft an unexpected error still fails',async t=>{
 const tools={check:async()=>({ok:true}),snapshot:async()=>({ok:true,paths:['frame.png']}),timeline:async()=>{throw Error('Renderer crashed');}};
 const h=await harness(t,[action({type:'timeline'})],{tools});assert.equal((await h.run()).status,'failed');
});
