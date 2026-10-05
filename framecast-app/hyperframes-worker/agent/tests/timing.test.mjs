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
import {clipUsageFindings} from '../timing-check.mjs';
test('everything bought with a picture is in the video; a talking take plays nearly in full; a reported bad file is excused',()=>{
 const planMedia=[{kind:'talking_take',status:'succeeded',file:'take.mp4',description:'Presenter reads the script'},{kind:'ai_image',status:'succeeded',file:'bg.png',description:'Studio'},
  {kind:'character_poses',status:'succeeded',file:'pose-1.png',more_files:['pose-2.png'],description:'Poses'},{kind:'stock_video',status:'succeeded',file:'city.mp4',description:'City'},
  {kind:'music',status:'succeeded',file:'music.wav'},{kind:'ai_image',status:'failed',file:'nope.png'}];
 const html='<video id="t" src="take.mp4"></video><img src="pose-2.png">';
 const rows=[{id:'t',kind:'video',src:'take.mp4',start:0,end:12}];
 const f=clipUsageFindings({planMedia,html,rows,durations:{'take.mp4':20}});
 assert.deepEqual(f.map(x=>x.code),['talking_clip_cut_short','bought_media_unused','bought_media_unused']);
 assert.match(f[0].message,/take.mp4 is 20.0 s of the presenter speaking but only 12.0 s/);
 assert.match(f[1].message,/bg.png/);assert.match(f[2].message,/city.mp4/);
 const css=clipUsageFindings({planMedia,html:html+'\n.bg{background:url(bg.png)}',rows:[{...rows[0],end:19}],durations:{'take.mp4':20},limitations:[{evidence:'city.mp4 shows the wrong city'}]});
 assert.deepEqual(css,[],'a stylesheet use counts, 19 of 20 s is enough, and the reported stock clip is excused');
});

test('a UGC take and generated shots must be in the video, the take nearly whole', () => {
 const planMedia=[{kind:'ugc_take',status:'succeeded',file:'take.mp4',description:'founder to camera'},{kind:'generated_shot',status:'succeeded',file:'shot.mp4',description:'window push'},
  {kind:'reference_sheet',status:'succeeded',file:'sheet.png',description:'cast'}];
 const cut=clipUsageFindings({planMedia,html:'',rows:[{src:'take.mp4',start:0,end:9}],durations:{'take.mp4':14}});
 assert.deepEqual(cut.map(f=>f.code).sort(),['bought_media_unused','talking_clip_cut_short'],'the shot is missing and the take is cut; the sheet is only a reference');
 assert.equal(clipUsageFindings({planMedia,html:'',rows:[{src:'take.mp4',start:0,end:13.5},{src:'shot.mp4',start:2,end:6}],durations:{'take.mp4':14}}).length,0);
});

test('a generated shot\'s ambience must sit low under the voice unless it speaks its own line',async()=>{
 const {ambienceFindings}=await import('../timing-check.mjs');
 const planMedia=[{kind:'voiceover',status:'succeeded',file:'vo.wav'},{kind:'generated_shot',status:'succeeded',file:'shot1.mp4'},{kind:'generated_shot',status:'succeeded',file:'shot2.mp4',line:'Hello there'}];
 const rows=[{kind:'audio',src:'vo.wav',id:'vo',start:0.3,end:12},{kind:'video',src:'shot1.mp4',id:'s1',start:0,end:4},{kind:'video',src:'shot2.mp4',id:'s2',start:4,end:8}];
 const html=v=>`<audio id="vo" src="vo.wav"></audio><video id="s1" src="shot1.mp4" ${v}></video><video id="s2" src="shot2.mp4"></video>`;
 assert.deepEqual(ambienceFindings({rows,html:html(''),planMedia}).map(f=>f.selector),['#s1']);
 assert.equal(ambienceFindings({rows,html:html('data-volume="0.25"'),planMedia}).length,0);
 assert.equal(ambienceFindings({rows,html:html('muted'),planMedia}).length,0);
});
