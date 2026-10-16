import test from 'node:test';
import assert from 'node:assert/strict';
import {guardResult,guardFindings,disputeLine,repeatingFindings} from '../check-guard.mjs';
import {reviewStatus} from '../review-status.mjs';

const beat={code:'beats_unmarked',message:'No beat containers are marked, so their timing cannot follow the narration.'};
const fail=(...errors)=>({ok:false,diagnostics:{ok:false,errors}});

test('a finding of ours that survives two repairs is disputed and stops blocking (the Parcelpath stop, 2026-10-16)',()=>{
 const state={};
 assert.equal(guardResult(state,fail(beat),3).ok,false,'first time: blocks');
 assert.equal(guardResult(state,fail(beat),4).ok,false,'after one repair: still blocks');
 const r=guardResult(state,fail(beat),6);
 assert.equal(r.ok,true,'after two repairs: disputed');
 assert.equal(r.diagnostics.disputed[0].code,'beats_unmarked');
 assert.deepEqual(state.disputes[0].seen_at,[3,4,6]);
 assert.equal(guardResult(state,fail(beat),7).ok,true,'a disputed finding stays advisory for the rest of the run');
 assert.equal(state.disputes.length,1,'recorded once');
});

test('re-checking without a repair does not count; HyperFrames lint and invented numbers never become disputed',()=>{
 const s1={};for(let i=0;i<5;i++)guardResult(s1,fail(beat),3);
 assert.equal(guardResult(s1,fail(beat),3).ok,false,'same revision five times is one sighting');
 const s2={},lint={code:'missing_asset',message:'x.png is not in the project'},num={code:'unapproved_number',message:'"50%" was not approved'};
 for(const r of [1,2,3,4])assert.equal(guardResult(s2,fail(lint,num),r).ok,false);
 assert.equal((s2.disputes||[]).length,0);
});

test('only the disputed finding stops blocking; the others still block',()=>{
 const state={},other={code:'missing_asset',message:'gone'};
 guardResult(state,fail(beat),1);guardResult(state,fail(beat),2);
 const r=guardResult(state,fail(beat,other),3);
 assert.equal(r.ok,false);
 assert.deepEqual(r.diagnostics.errors.map(e=>e.code),['missing_asset']);
 assert.equal(r.diagnostics.disputed[0].code,'beats_unmarked');
});

test('the user is told what was not confirmed; a failed run reports what kept repeating',()=>{
 const state={};for(const r of [1,2,3])guardResult(state,fail(beat),r);
 assert.match(disputeLine(state.disputes),/^Not confirmed: No beat containers are marked.*give that part a look/);
 assert.equal(disputeLine([]),'');
 const st={revision:3,checkedRevision:3,snapshotRevision:3,disputes:state.disputes};
 const rs=reviewStatus(st);
 assert.equal(rs.status,'issues');assert.match(rs.findings[0],/^Not confirmed/);
 const f={};guardFindings(f,[beat],1);guardFindings(f,[beat],2);
 assert.deepEqual(repeatingFindings(f).map(x=>[x.code,x.seen_at.length,!!x.message]),[['beats_unmarked',2,true]]);
});
