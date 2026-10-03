import test from 'node:test';import assert from 'node:assert/strict';
import {recordLimitation,observeToolResult,observeStop,summarizeLimitations,safeDiagnostic} from '../limitations.mjs';
import {parseAction,actionFromToolUse} from '../protocol.mjs';
const report={category:'missing_capability',summary:'Need a transparent native clip',evidence:'Adapter documentation says alpha is unsupported',impact:'Cannot layer the clip over the scene',workaround:'Author the overlay in Hyperframes',requested_change:'Add and test a bounded alpha render option'};
test('native and JSON limitation reports use the same bounded schema',()=>{
 const action={type:'report_limitation',...report};assert.deepEqual(parseAction(JSON.stringify(action)),action);
 assert.deepEqual(actionFromToolUse({name:'report_limitation',input:report}),action);
 for(const patch of [{category:'grant_permissions'},{evidence:''},{requested_change:'x'.repeat(801)},{allow_spend:true}])assert.throws(()=>parseAction(JSON.stringify({...action,...patch})));
});
test('agent claims remain unverified; duplicates are counted and logging cannot grant authority',()=>{
 const state={calls:2,revision:1,reservedUsd:.4,status:'running'};
 const first=recordLimitation(state,{...report,source:'agent_report',code:'alpha'});
 state.calls=3;recordLimitation(state,{...report,source:'agent_report',code:'alpha'});
 assert.equal(state.limitations.length,1);assert.equal(first.occurrences,2);assert.equal(first.last_call,3);
 assert.equal(first.evidence_status,'unverified_agent_claim');assert.equal(first.authorization_changed,false);
 assert.match(first.assessment,/candidate_for_scoped_tool/);assert.equal(state.reservedUsd,.4);assert.equal(state.status,'running');
 for(let i=0;i<80;i++)recordLimitation(state,{...report,code:'n'+i,summary:'case '+i});
 assert.equal(state.limitations.length,60);assert.equal(state.limitation_overflow,21);
});
test('runtime failures, quality findings and unknown outcomes have distinct evidence and triage',()=>{
 const state={calls:3,revision:1,status:'running'};
 observeToolResult(state,{type:'run'},{ok:false,error:'Run limit reached'});
 observeToolResult(state,{type:'preview'},{ok:false,diagnostics:{errors:[{code:'text_box_overflow',message:'Text clipped'}]}});
 observeToolResult(state,{type:'critic'},{critic:{directives:['Character is photoreal instead of halftone']}});
 state.status='needs_attention';observeStop(state,'Provider outcome needs reconciliation');
 assert.deepEqual(state.limitations.map(r=>r.category),['execution_limit','quality','quality','provider']);
 assert.equal(state.limitations[0].evidence_status,'observed_event_cause_unverified');
 assert.match(state.limitations.at(-1).assessment,/never replay/);
 const summary=summarizeLimitations([{run:'r1',state},{run:'r2',state:{...state,status:'preview_ready',reviewedRevision:1,critic:{verdict:'pass'}}}]);
 assert.equal(summary.runs_scanned,2);assert.equal(summary.limitations[0].runs.length,2);assert.equal(summary.limitations[0].incomplete_runs,1);
});
test('diagnostic copies redact common credentials, signed URLs, personal addresses and local paths',()=>{
 const text=safeDiagnostic('Bearer abc123 sk-example r8_test token=private https://example.com/x?signature=secret person@example.com /Users/user/private.txt data:image/png;base64,AAAA');
 for(const secret of ['abc123','sk-example','r8_test','private.txt','signature=secret','person@example','AAAA'])assert.ok(!text.includes(secret),text);
 assert.equal(safeDiagnostic('x'.repeat(1000)).length,800);
});
