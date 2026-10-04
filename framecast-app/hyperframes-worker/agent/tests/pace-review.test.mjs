import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp,rm} from 'node:fs/promises';
import {cutTimes,outputPace,paceNotes} from '../pace-review.mjs';
const run=promisify(execFile);
test('cuts are found in a real video',async()=>{
 const dir=await mkdtemp('/tmp/pace-');
 try{
  await run('ffmpeg',['-v','error','-y','-f','lavfi','-i','color=c=red:s=160x90:d=2:r=24','-f','lavfi','-i','color=c=blue:s=160x90:d=2:r=24','-f','lavfi','-i','color=c=white:s=160x90:d=2:r=24',
   '-filter_complex','[0][1][2]concat=n=3:v=1:a=0[v]','-map','[v]','-c:v','libx264','-pix_fmt','yuv420p',dir+'/v.mp4']);
  const cuts=await cutTimes(dir+'/v.mp4');
  assert.equal(cuts.length,2);assert.ok(Math.abs(cuts[0]-2)<0.15&&Math.abs(cuts[1]-4)<0.15,JSON.stringify(cuts));
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('the output rhythm is measured like the reference study',()=>{
 const p=outputPace({cuts:[4,10,16,22],duration:30,words:[{text:'a',start:0.3,end:0.6},{text:'b',start:9.8,end:10.3}],sync:[{off:0.1},{off:0.3},{off:0.2}]});
 assert.deepEqual(p,{cuts_per_10_seconds:1.3,average_shot_seconds:6,words_per_second:0.2,text_to_speech_delay_seconds:0.2});
});
test('far from the reference: plain suggestions; close to it: nothing to say',()=>{
 const ref={average_shot_seconds:2.9,cuts_per_10_seconds:3.1,words_per_second:3.6,text_to_speech_delay_seconds:-0.05};
 const slow={average_shot_seconds:6,cuts_per_10_seconds:1.3,words_per_second:2.2,text_to_speech_delay_seconds:0.6};
 const notes=paceNotes(ref,slow);
 assert.equal(notes.length,3);
 assert.match(notes[0],/changes picture about every 2.9 s; this video holds about 6 s/);
 assert.match(notes[1],/narration is slower than the reference \(2.2 against 3.6/);
 assert.match(notes[2],/Text appears 0.6 s after it is said; in the reference it is -0.05 s/);
 assert.deepEqual(paceNotes(ref,{average_shot_seconds:3.1,cuts_per_10_seconds:2.9,words_per_second:3.3,text_to_speech_delay_seconds:0.1}),[]);
 assert.deepEqual(paceNotes(null,slow),[]);
});
