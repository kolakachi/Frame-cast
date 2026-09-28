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
test('host image reaches provider and current visual review permits completion',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'snapshot',times:[1]}),action({type:'visual_review',decision:'pass',findings:'Readable sampled layout'}),action({type:'finish',summary:'Ready'})],{tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true,providerImage:'data:image/jpeg;base64,YQ=='})}});h.args.requireVisualReview=true;assert.equal((await h.run()).status,'preview_ready');assert.equal(h.seen[2].image,'data:image/jpeg;base64,YQ==');});
test('unsupported visual review without an image is rejected',async t=>{const h=await harness(t,[action({type:'visual_review',decision:'pass',findings:'Looks fine'})]);assert.equal((await h.run()).status,'failed');});
test('large tool reports are bounded before the next model call',async t=>{const h=await harness(t,[action({type:'check'}),action({type:'needs_input',question:'Continue?'})],{tools:{check:async()=>({ok:true,details:'x'.repeat(300000)})}});assert.equal((await h.run()).status,'needs_input');assert.ok(h.seen[1].prompt.length<20000);});
test('hidden version is checked against advertised model API schema',async()=>{let requests=0;const p=new ReplicateProvider({contract,token:'test',enabled:true,maxCallUsd:1,fetchImpl:async()=>({ok:true,json:async()=>++requests===1?{id:'abc',status:'succeeded',version:'hidden',output:['ok']}:{latest_version:{id:'test-version'}}})});assert.equal((await p.complete({prompt:'x',system:'x',maxTokens:1024})).text,'ok');assert.equal(requests,2);});
test('pinned guidance can be read without broad workspace access',async t=>{const h=await harness(t,[action({type:'read',path:'references/minimal-composition.md'}),action({type:'needs_input',question:'Next?'})]);h.args.tools.guidance=async p=>{assert.equal(p,'references/minimal-composition.md');return 'pinned example';};assert.equal((await h.run()).status,'needs_input');assert.match(h.seen[1].prompt,/pinned example/);});
test('shared test budget refuses excess and retains unknown reservations',async t=>{const {TestBudget}=await import('../budget.mjs');const h=await harness(t,[]);const budget=new TestBudget(h.root+'/budget.json',.02);await budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'});await assert.rejects(budget.reserve({prompt:'x',system:'x',maxTokens:100,model:'sonnet'}),/budget exhausted/);});
test('commentary around exactly one validated action is ignored, never executed',()=>{assert.deepEqual(parseAction('I will inspect.\n{"type":"check"}'),{type:'check'});assert.throws(()=>parseAction('{"type":"check"} {"type":"assets"}'));assert.throws(()=>parseAction('```json\n{"type":"check"}\n```\n```json\n{"type":"assets"}\n```'));});

test('snapshot overflow gives actionable bounded correction',()=>{assert.throws(()=>parseAction(action({type:'snapshot',times:[1,3,5,8,12,14]})),/1 to 5/);assert.deepEqual(parseAction(action({type:'snapshot',times:[1,7,13]})).times,[1,7,13]);});
