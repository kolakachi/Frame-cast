import test from 'node:test';import assert from 'node:assert/strict';
import {timingFindings,phraseStart,attributesById,rowsOf} from '../timing-check.mjs';
const words=[['Brewline',0,0.44],['cold',0.44,0.8],['brew',0.8,1.04],['Save',1.62,1.66],['20',1.66,2.06],['today',2.48,2.94]];
const html=`<div id="root" data-composition-id="main"><video class="clip" id="v1" src="talk.mp4" data-start="0" data-duration="8"></video>
<div class="clip" id="t1" data-start="1.6" data-duration="1" data-spoken="Save 20">Save 20%</div>
<div class="clip" id="t2" data-start="0.2" data-duration="1" data-spoken="today">Today</div>
<div class="clip" id="t3" data-start="3" data-duration="1" data-spoken="tomorrow">x</div></div>`;
const rows=[{id:'v1',kind:'video',src:'talk.mp4',start:0,end:8},{id:'t1',kind:'div',start:1.6,end:2.6},{id:'t2',kind:'div',start:0.2,end:1.2},{id:'t3',kind:'div',start:3,end:4}];
test('spoken cues must land within tolerance of the words',()=>{
 const e=timingFindings({rows,html,durations:{'talk.mp4':8},transcripts:{'talk.mp4':words}});
 assert.deepEqual(e.map(x=>[x.code,x.selector]),[['spoken_cue_off_time','#t2'],['spoken_phrase_not_found','#t3']]);
 assert.match(e[0].fixHint,/2\.48/);
});
test('cues follow the clip offset and playback rate',()=>{
 const r=[{id:'v1',kind:'video',src:'talk.mp4',start:4,end:12,playbackRate:2},{id:'t1',kind:'div',start:4.81,end:5.8}];
 assert.deepEqual(timingFindings({rows:r,html,durations:{'talk.mp4':20},transcripts:{'talk.mp4':words}}),[]);
});
test('a cue with no transcribed speech underneath is reported',()=>{
 const e=timingFindings({rows,html,durations:{'talk.mp4':8},transcripts:{}});
 assert.ok(e.every(x=>x.code==='spoken_cue_without_transcript'));
});
test('media shorter than its slot needs an explicit choice',()=>{
 const h='<video class="clip" id="v1" src="short.mp4"></video>',r=[{id:'v1',kind:'video',src:'short.mp4',start:0,end:6}];
 assert.equal(timingFindings({rows:r,html:h,durations:{'short.mp4':2}})[0].code,'media_shorter_than_slot');
 assert.deepEqual(timingFindings({rows:r,html:'<video class="clip" id="v1" src="short.mp4" data-fit="hold"></video>',durations:{'short.mp4':2}}),[]);
 assert.equal(timingFindings({rows:r,html:'<video class="clip" id="v1" src="short.mp4" data-fit="loop"></video>',durations:{'short.mp4':2}})[0].code,'loop_not_set');
 assert.deepEqual(timingFindings({rows:r,html:'<video class="clip" id="v1" src="short.mp4" data-fit="loop" loop muted></video>',durations:{'short.mp4':2}}),[]);
 assert.deepEqual(timingFindings({rows:[{...r[0],end:2}],html:h,durations:{'short.mp4':2}}),[],'a slot that fits needs nothing');
});
test('helpers read attributes, nested rows and multi-word phrases',()=>{
 assert.equal(attributesById(html).get('t1')['data-spoken'],'Save 20');
 assert.equal(rowsOf({timeline:{tracks:[{rows:[{id:'a',children:[{id:'b'}]}]}]}}).length,2);
 assert.equal(phraseStart(words,'cold brew'),0.44);assert.equal(phraseStart(words,'Brew, today'),null);
});
test('runner turns timing findings into a repairable check failure',async()=>{
 const {runAgent}=await import('../runner.mjs');const {Workspace,digest}=await import('../workspace.mjs');
 const {mkdtemp,writeFile}=await import('node:fs/promises');const {tmpdir}=await import('node:os');
 const dir=await mkdtemp(tmpdir()+'/timing-');await writeFile(dir+'/index.html',html);await writeFile(dir+'/talk.mp4','v');
 const ws=new Workspace(dir,[{path:'talk.mp4',sha256:digest('v')}]);
 const steps=[{type:'check'},{type:'needs_input',question:'stop'}];let i=0;
 const state=await runAgent({stateFile:dir+'/s.json',workspace:ws,provider:{id:'t',maxCallUsd:0,complete:async()=>({text:JSON.stringify(steps[i++])})},context:{brief:'x'},limits:{calls:4,repairs:3,budgetUsd:0},
  tools:{check:async()=>({ok:true}),timeline:async()=>({ok:true,diagnostics:{timeline:{tracks:[{rows}]}}}),media:async({op})=>({ok:true,info:{duration:8}})}});
 const r=state.messages.filter(m=>m.role==='tool')[0].content;
 assert.equal(r.ok,false);assert.equal(r.diagnostics.errors[0].code,'spoken_cue_without_transcript');assert.equal(state.checkedRevision,-1);
});
test('music playing under the narration without ducking is flagged; ducked music is not',async()=>{
 const {duckingFindings}=await import('../timing-check.mjs');
 const planMedia=[{kind:'voiceover',status:'succeeded',file:'vo.wav'},{kind:'music',status:'succeeded',file:'bed.wav'}];
 const rows=[{kind:'audio',src:'vo.wav',start:0.3,end:12,id:'vo'},{kind:'audio',src:'bed.wav',start:0,end:15,id:'music'}];
 const e=duckingFindings({rows,planMedia});
 assert.equal(e.length,1);assert.equal(e[0].code,'music_not_ducked');assert.match(e[0].fixHint,/"voice":"vo.wav","voice_start":0.30/);
 assert.deepEqual(duckingFindings({rows:[rows[0],{...rows[1],src:'derived-1-duck.wav'}],planMedia}),[]);
 assert.deepEqual(duckingFindings({rows,planMedia:[planMedia[1]]}),[],'no narration, nothing to duck under');
});
