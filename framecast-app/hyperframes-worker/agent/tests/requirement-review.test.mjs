import test from 'node:test';
import assert from 'node:assert/strict';
import {requirementChecks} from '../requirement-review.mjs';
import {parseCriticVerdict,CRITERIA} from '../critic.mjs';
import {reviewStatus} from '../review-status.mjs';
const id=n=>'req-'+String(n).padStart(20,'0');
const requirements=[{id:id(1),text:'Open the box',category:'action',review_stage:'production',after_ids:[]},{id:id(2),text:'Look surprised',category:'action',review_stage:'production',after_ids:[id(1)]},{id:id(3),text:'Point',category:'action',review_stage:'production',after_ids:[id(2)]}];
const checks=requirements.map((r,i)=>({id:r.id,status:'fulfilled',evidence:'Visible action',start:i*2,end:i*2+1}));
const reply={scores:Object.fromEntries(CRITERIA.map(k=>[k,9])),verdict:'pass',requirement_checks:checks};
test('every requirement needs unique evidence and timing for ordered actions',()=>{
 assert.deepEqual(requirementChecks(checks,requirements).map(r=>r.status),['fulfilled','fulfilled','fulfilled']);
 for(const raw of [[],[...checks,checks[0]],checks.map(({start,end,...c})=>c)]){
  assert.equal(parseCriticVerdict(JSON.stringify({...reply,requirement_checks:raw}),{requirements}).verdict,'revise');
 }
 assert.equal(parseCriticVerdict(JSON.stringify(reply),{requirements}).verdict,'pass');
 assert.equal(parseCriticVerdict('bad JSON',{requirements}).requirement_checks.length,3);
});
test('wrong order and unverified predecessors propagate regardless of display order',()=>{
 const backwards=[checks[0],{...checks[1],start:0},checks[2]];
 const results=requirementChecks(backwards,[...requirements].reverse());
 assert.equal(results.find(c=>c.id===id(2)).status,'unmet');
 assert.equal(results.find(c=>c.id===id(3)).status,'unverified');
 assert.equal(requirementChecks(checks,[{...requirements[0],order_unresolved:true}])[0].status,'unverified');
});
test('storyboards defer performance, never appearance; frames cannot certify sound',()=>{
 const r=[{id:id(1),text:'Halftone',category:'appearance',review_stage:'design'},{id:id(2),text:'Narration',category:'audio',review_stage:'production'}];
 const deferred=r.map(x=>({id:x.id,status:'deferred',evidence:'Motion later'}));
 assert.deepEqual(requirementChecks(deferred,r,{lookOnly:true}).map(x=>x.status),['unverified','deferred']);
 assert.deepEqual(requirementChecks(deferred,r).map(x=>x.status),['unverified','by_ear'],'sound waits for the listening check');
 assert.equal(requirementChecks([{id:id(2),status:'fulfilled',evidence:'There is audio'}],[r[1]])[0].status,'by_ear','frames cannot certify sound');
});
test('requirements from a stale or recovered review cannot be attached to the delivered result',()=>{
 const state={revision:4,reviewedRevision:4,criticRevision:4,critic:{verdict:'pass',requirement_checks:checks}};
 assert.deepEqual(reviewStatus(state).requirement_checks,checks);
 assert.equal(reviewStatus({...state,revision:5}).requirement_checks,undefined);
 assert.equal(reviewStatus({...state,recoveredDraft:true}).requirement_checks,undefined);
});

test('a stable ID does not let old evidence certify an amended requirement',()=>{
 assert.equal(requirementChecks([checks[0]],[{...requirements[0],version:2}])[0].status,'unverified');
 assert.equal(requirementChecks([{...checks[0],version:2}],[{...requirements[0],version:2}])[0].status,'fulfilled');
});

test('heard requirements are left to the listening check and never block the visual review',async()=>{
 const {parseCriticVerdict}=await import('../critic.mjs');
 const reqs=[{id:id(1),text:'Use the supplied narration verbatim',category:'text'},{id:id(2),text:'Show each heading exactly when the narrator reaches those words',category:'timing'},{id:id(3),text:'Large bold text',category:'appearance'}];
 const raw=JSON.stringify({scores:{hook:8,hierarchy:8,density:8,energy:8,performance:8},verdict:'pass',
  unmet_requirements:['Narration is unverified: frames cannot confirm the script is spoken','The closing CTA text is missing'],
  requirement_checks:[{id:id(1),version:1,status:'unverified',evidence:'No audio'},{id:id(2),version:1,status:'unmet',evidence:'Cannot hear'},{id:id(3),version:1,status:'fulfilled',evidence:'Big type',start:0,end:3}],directives:[]});
 const v=parseCriticVerdict(raw,{requirements:reqs});
 assert.deepEqual(v.requirement_checks.map(c=>c.status),['by_ear','by_ear','fulfilled']);
 assert.deepEqual(v.unmet_requirements,['The closing CTA text is missing'],'a seen miss still counts; an unheard one does not');
 assert.equal(v.verdict,'revise');
 const clean=parseCriticVerdict(raw.replace(',"The closing CTA text is missing"',''),{requirements:reqs});
 assert.equal(clean.verdict,'pass','sound alone no longer blocks a pass');
});
