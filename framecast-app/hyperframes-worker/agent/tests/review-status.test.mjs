import test from 'node:test';
import assert from 'node:assert/strict';
import {reviewStatus} from '../review-status.mjs';
const passed={revision:3,reviewedRevision:3,criticRevision:3,critic:{verdict:'pass'}};
test('only a critic pass for the delivered revision counts',()=>{
 assert.equal(reviewStatus(passed).status,'passed');
 for(const state of [{revision:3},{...passed,revision:4},{...passed,recoveredDraft:true},{...passed,criticRevision:2}])assert.equal(reviewStatus(state).status,'incomplete');
});
test('critic exhaustion and unresolved layout findings stay visible',()=>{
 const result=reviewStatus({...passed,critic:{verdict:'revise',directives:['Enlarge the product']}});
 assert.equal(result.status,'incomplete');assert.deepEqual(result.findings,['Enlarge the product']);
 const advisory=reviewStatus({...passed,layoutAdvisories:[{message:'Headline is occluded'}]});
 assert.equal(advisory.status,'incomplete');assert.deepEqual(advisory.findings,['Headline is occluded']);
});
test('performance evidence is saved only for the delivered reviewed revision',()=>{
 const checks=[{id:'perf-blink',status:'pass',evidence:'1.2s eyelids close'}];
 const current={...passed,critic:{verdict:'pass',performance_checks:checks}};
 assert.deepEqual(reviewStatus(current).performance_checks,checks);
 assert.equal(reviewStatus({...current,revision:4}).performance_checks,undefined);
 assert.equal(reviewStatus({...current,recoveredDraft:true}).performance_checks,undefined);
});
