import test from 'node:test';
import assert from 'node:assert/strict';
import {reviewStatus} from '../review-status.mjs';
const passed={revision:3,reviewedRevision:3,criticRevision:3,critic:{verdict:'pass'}};
const checked={revision:3,checkedRevision:3,snapshotRevision:3};
test('a checked version is ready for the user; a critic pass is passed; unchecked is incomplete',()=>{
 assert.equal(reviewStatus(checked).status,'ready');assert.deepEqual(reviewStatus(checked).findings,[]);
 assert.equal(reviewStatus({...checked,...passed}).status,'passed');
 for(const state of [{revision:3},{...checked,revision:4}])assert.equal(reviewStatus(state).status,'incomplete');
 const recovered=reviewStatus({...checked,recoveredDraft:true});assert.equal(recovered.status,'issues');assert.match(recovered.findings[0],/last version that passed its checks/);
});
test('specific findings reach the user, with their time, and make the version issues',()=>{
 const result=reviewStatus({...checked,...passed,critic:{verdict:'revise',directives:['Enlarge the product']}});
 assert.equal(result.status,'issues');assert.deepEqual(result.findings,['Enlarge the product']);
 const advisory=reviewStatus({...checked,layoutAdvisories:[{message:'Headline is occluded'}]});
 assert.equal(advisory.status,'issues');assert.deepEqual(advisory.findings,['Headline is occluded']);
 const notes=reviewStatus({...checked,layoutNotes:[{code:'slot_missed',time:3.2,message:'The m6 card is missing from its slot'}],pacing:{revision:3,findings:[{code:'move_unused',severity:'error',message:'The plan names toss, but the composition never calls it'},{code:'slow_drift',severity:'warning',message:'drift'}]}});
 assert.equal(notes.status,'issues');assert.deepEqual(notes.findings,['At 3.2 s: The m6 card is missing from its slot','The plan names toss, but the composition never calls it']);
});
test('performance evidence is saved only for the delivered reviewed revision',()=>{
 const checks=[{id:'perf-blink',status:'pass',evidence:'1.2s eyelids close'}];
 const current={...passed,critic:{verdict:'pass',performance_checks:checks}};
 assert.deepEqual(reviewStatus(current).performance_checks,checks);
 assert.equal(reviewStatus({...current,revision:4}).performance_checks,undefined);
 assert.equal(reviewStatus({...current,recoveredDraft:true}).performance_checks,undefined);
});
