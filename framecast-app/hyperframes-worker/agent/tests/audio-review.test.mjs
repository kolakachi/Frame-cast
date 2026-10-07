import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp,rm} from 'node:fs/promises';
import {alignScript,cueSync,mixFindings,heardChecks,problems,listenToExport,close} from '../audio-review.mjs';
const run=promisify(execFile);
const W=(text,start,end)=>({text,start,end});
const id=n=>'req-'+String(n).repeat(20).slice(0,20);
test('the script is matched in order; a recogniser spelling of a coined name still counts; a missing passage is named',()=>{
 assert.ok(close('wiv','wiv'));assert.ok(close('studios','studio'));assert.ok(!close('weave','wiv'));
 const lines=['First, give Wiv Studio your product and a clear idea.','Next, review your script and choose your presenter.'];
 const all=[W('First',0.3,0.6),W('give',0.6,0.8),W('Wiv',0.8,1),W('Studio',1,1.4),W('your',1.4,1.5),W('product',1.5,1.9),W('and',1.9,2),W('a',2,2.05),W('clear',2.05,2.3),W('idea.',2.3,2.7),
  W('Next,',3,3.3),W('review',3.3,3.6),W('your',3.6,3.7),W('script',3.7,4),W('and',4,4.1),W('choose',4.1,4.4),W('your',4.4,4.5),W('presenter.',4.5,5)];
 const full=alignScript(lines,all);
 assert.equal(full.coverage,1);assert.deepEqual(full.missing,[]);assert.deepEqual(full.lineStarts,[0.3,3]);
 const cut=alignScript(lines,all.slice(0,12).concat(all.slice(16)));
 assert.ok(cut.coverage<1);assert.equal(cut.missing.length,1);assert.match(cut.missing[0],/script and choose/);
});
test('text tied to a spoken phrase is timed against what the export actually says',()=>{
 const html='<div id="h1" data-start="3.0" data-spoken="Next, review">Step 2</div><div id="h2" data-start="1.0" data-spoken="clear idea">x</div><div id="h3" data-start="5" data-spoken="download">y</div>';
 const c=cueSync(html,[W('clear',2.05,2.3),W('idea',2.3,2.7),W('Next',3,3.3),W('review',3.3,3.6)]);
 assert.deepEqual(c.map(x=>[x.id,x.off??null,x.heard]),[['h1',0,3],['h2',-1.05,2.05],['h3',null,null]]);
});
test('the mix: voice over music, dead air and an abrupt or faded end',()=>{
 const words=[W('a',1,2),W('b',4,5)];
 const win=(f)=>Array.from({length:61},(_,i)=>[+(i/10).toFixed(1),f(i/10)]);
 const good=mixFindings(win(t=>t>=0.85&&t<=2.15||t>=3.85&&t<=5.15?-18:t>5.6?-60:-34),words,6);
 assert.equal(good.music,true);assert.equal(good.voice_over_music_db,16);assert.equal(good.abrupt_end,false);assert.deepEqual(good.dead_air,[]);
 const loud=mixFindings(win(t=>t>=0.85&&t<=2.15||t>=3.85&&t<=5.15?-18:-21),words,6);
 assert.equal(loud.voice_over_music_db,3);assert.equal(loud.abrupt_end,true);
 const hole=mixFindings(win(t=>t>2.15&&t<3.95?-80:-20),words,6);
 assert.equal(hole.dead_air.length,1);
 assert.deepEqual(problems({narration:null,sync:[],mix:loud}),['The music may be too loud under the voice.','The sound stops abruptly at the end.']);
});
test('heard requirements get a pass or a fail with evidence; seen ones are left alone',()=>{
 const reqs=[{id:id(1),text:'Use the supplied narration verbatim',category:'text'},{id:id(2),text:'Show each heading exactly when the narrator reaches those words',category:'timing'},
  {id:id(3),text:'A clear voice and quiet upbeat music',category:'audio'},{id:id(4),text:'Large bold text',category:'appearance'}];
 const a={narration:{words:20,matched:20,coverage:1,missing:[],extra:0},narration_ok:true,sync:[{id:'h',phrase:'Next',shows:3,heard:3.1,off:-0.1}],mix:{music:true,voice_over_music_db:12,dead_air:[],abrupt_end:false}};
 const c=heardChecks(reqs,a);
 assert.deepEqual(c.map(x=>[x.id,x.status]),[[id(1),'fulfilled'],[id(2),'fulfilled'],[id(3),'fulfilled']]);
 assert.ok(c.every(x=>x.source==='audio_review'));
 const bad=heardChecks(reqs,{...a,narration_ok:false,narration:{...a.narration,matched:15,coverage:.75,missing:['get ready to share']},mix:{...a.mix,voice_over_music_db:3}});
 assert.deepEqual(bad.map(x=>x.status),['unmet','fulfilled','unmet']);
 assert.match(bad[0].evidence,/get ready to share/);assert.match(bad[2].evidence,/3 dB above the music/);assert.match(bad[2].evidence,/get ready to share/,'every failing part is named');
});
test('end to end on a real soundtrack: narration heard, music under it, faded ending',async()=>{
 const dir=await mkdtemp('/tmp/review-');
 try{
  // Voice stand-in at 1–2 s and 3–4 s over a quiet bed that fades out at the end.
  await run('ffmpeg',['-v','error','-y','-f','lavfi','-i','sine=frequency=200:duration=6:sample_rate=16000','-f','lavfi','-i','sine=frequency=800:duration=6:sample_rate=16000','-f','lavfi','-i','color=c=white:s=160x90:d=6',
   '-filter_complex',"[0]volume=0.1,afade=t=out:st=5:d=1[bed];[1]volume='if(between(t,1,2)+between(t,3,4),0.6,0)':eval=frame[v];[bed][v]amix=inputs=2:normalize=0[a]",'-map','[a]','-map','2:v','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac','-shortest',dir+'/video.mp4']);
  let sent=null;
  const r=await listenToExport({file:dir+'/video.mp4',html:'<p id="t" data-start="1" data-spoken="hello">Hello</p>',requirements:[{id:id(5),text:'Clear narration over quiet music',category:'audio'}],
   listen:async wav=>{sent=wav;return {words:[W('Hello',1,2),W('there',3,4)],script:{written:['Hello there.'],spoken:['Hello there.']}};}});
  assert.match(sent,/sound\.wav$/);
  assert.equal(r.summary.script_coverage,1);assert.equal(r.summary.mix.music,true);
  assert.ok(r.summary.mix.voice_over_music_db>6,'voice above the bed: '+r.summary.mix.voice_over_music_db);
  assert.equal(r.summary.mix.abrupt_end,false);assert.deepEqual(r.summary.problems,[]);
  assert.equal(r.checks[0].status,'fulfilled');
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('a phrase the transcriber dropped after a pause is listened to again on its own before it is called missing',async()=>{
 const dir=await mkdtemp('/tmp/relisten-');
 try{
  await run('ffmpeg',['-v','error','-y','-f','lavfi','-i','sine=frequency=300:duration=8','-f','lavfi','-i','color=c=black:s=64x64:d=8','-shortest','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',dir+'/video.mp4']);
  const calls=[];
  const listen=async f=>{calls.push(f);
   // The whole video: the last line ("wyvstudio.com") is dropped. Its window alone: heard.
   if(calls.length===1)return {words:[W('Describe',0.3,0.8),W('it.',0.8,1),W('Get',2,2.2),W('the',2.2,2.4),W('video.',2.4,2.9)],script:{written:['Describe it.','Get the video.','wyvstudio dot com, today.'],spoken:['Describe it.','Get the video.','Wiv Studio dot com, today.']}};
   return {words:[W('www.wivstudio',0.6,1.4),W('dot',1.4,1.6),W('com,',1.6,1.9),W('today.',1.9,2.3)]};};
  const r=await listenToExport({file:dir+'/video.mp4',listen});
  assert.equal(calls.length,2,'the missing window was heard again');
  assert.deepEqual(r.summary.missing,[]);assert.ok(r.summary.script_coverage>=0.9,JSON.stringify(r.summary));
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('the written or the spoken form of the script, whichever the voice matches better',()=>{
 const words=[W('Try',0,0.3),W('wyvstudio.com',0.3,1.2),W('today',1.2,1.6)];
 const spoken=alignScript(['Try Wiv Studio dot com today'],words),written=alignScript(['Try wyvstudio.com today'],words);
 assert.ok(written.coverage>spoken.coverage);
});

test('every unheard stretch is a hole to listen to again, while only long runs count as missing', () => {
 const w=(t,s)=>({text:t,start:s,end:s+0.3});
 const a=alignScript(['Describe it.','Approve the plan.','Get the video.'],[w('Describe',0),w('it',0.4),w('the',2.1),w('Get',4),w('the',4.4),w('video',4.8)]);
 assert.deepEqual(a.missing,[]);assert.deepEqual(a.gaps,[]);
 assert.ok(a.holes.length>=1,JSON.stringify(a));assert.ok(a.coverage<0.9);
});

test('numbers, percents and web addresses match however the script or the transcriber writes them', () => {
 // Production 2026-10-07: "25% off" (said "twenty-five percent") was called missing and blocked delivery.
 const said='This month get twenty five percent off your first bag start at northside roasters dot com'.split(' ').map((w,i)=>({text:w,start:i*0.3,end:i*0.3+0.2}));
 const a=alignScript(['This month, get 25% off your first bag.','Start at northsideroasters.com.'],said);
 assert.deepEqual(a.missing,[]);assert.ok(a.coverage>=0.9,String(a.coverage));
 const b=alignScript(['This month, get twenty-five percent off.'],'This month get 25% off'.split(' ').map((w,i)=>({text:w,start:i*0.3,end:i*0.3+0.2})));
 assert.deepEqual(b.missing,[]);assert.equal(b.coverage,1);
});
