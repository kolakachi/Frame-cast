import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {mkdtemp,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {plan,ffmpegArgs,swapSrc} from '../voice-swap.mjs';
const run=promisify(execFile);

test('each new line starts where the old one did; the pause after a line gives way first, then a little speed-up',()=>{
 const s=plan({oldStarts:[0.3,2.4,5.0],newStarts:[0.2,2.6,5.4],newEnds:[2.1,5.1,7.6],oldDuration:8,newDuration:8.1});
 assert.deepEqual(s.map(x=>x.at),[0.22,2.32,4.92],'placed at the old line starts');
 assert.deepEqual(s.map(x=>x.to),[2.3,5.3,7.8],'each line ends 0.2 s after its last word, so its pause is not sped up');
 assert.deepEqual(s.map(x=>x.tempo),[1.0381,1.0692,1],'2.18 s into 2.1 s and 2.78 s into 2.6 s: a few percent quicker');
});
test('a line that cannot fit, a missing line or lines out of order go to the full editor',()=>{
 assert.throws(()=>plan({oldStarts:[0.3,1.0],newStarts:[0.3,2.0],oldDuration:4,newDuration:4}),e=>e.code==='VOICE_SWAP_UNFIT'&&/line 1/.test(e.message));
 assert.throws(()=>plan({oldStarts:[0.3,null],newStarts:[0.3,1.2],oldDuration:4,newDuration:4}),e=>e.code==='VOICE_SWAP_UNFIT');
 assert.throws(()=>plan({oldStarts:[0.3,1.2],newStarts:[0.3],oldDuration:4,newDuration:4}),e=>e.code==='VOICE_SWAP_UNFIT');
});
test('the narration file is swapped everywhere the version names it, and nowhere else',()=>{
 const b={'index.html':'<audio src="asset-1-aa.wav"></audio><audio src="asset-2-bb.wav"></audio>','main.js':'load("asset-1-aa.wav")'};
 assert.deepEqual(swapSrc(b,'asset-1-aa.wav','voice-aligned.wav'),{'index.html':'<audio src="voice-aligned.wav"></audio><audio src="asset-2-bb.wav"></audio>','main.js':'load("voice-aligned.wav")'});
 assert.throws(()=>swapSrc(b,'asset-9-zz.wav','x.wav'),e=>e.code==='VOICE_SWAP_UNFIT');
});
test('ffmpeg builds the aligned voice at exactly the old length',async()=>{
 const dir=await mkdtemp(path.join(os.tmpdir(),'swap-'));
 try{
  await run('ffmpeg',['-v','error','-y','-f','lavfi','-i','sine=frequency=440:duration=6','-ar','44100','-ac','1',dir+'/new.wav']);
  const segments=plan({oldStarts:[0.3,3.0],newStarts:[0.2,3.2],newEnds:[2.6,5.5],oldDuration:7.5,newDuration:6});
  await run('ffmpeg',ffmpegArgs({input:dir+'/new.wav',segments,duration:7.5,output:dir+'/out.wav'}));
  const d=Number((await run('ffprobe',['-v','error','-show_entries','format=duration','-of','csv=p=0',dir+'/out.wav'])).stdout.trim());
  assert.ok(Math.abs(d-7.5)<0.05,'the timeline keeps its length: '+d);
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('a swap fetches the brand fonts the version loads',async()=>{
 const {familiesIn}=await import('../brand-fonts.mjs');
 const bundle={'style.css':"@font-face{src:url(brand-dm-sans-700.ttf)}@font-face{src:url(brand-space-mono-400.ttf)}",'index.html':'<b>brand-dm-sans-400.ttf</b>'};
 assert.deepEqual(familiesIn(bundle,['Inter','DM Sans','Space Mono','Roboto']),['DM Sans','Space Mono']);
 assert.deepEqual(familiesIn({'index.html':'<b></b>'},['DM Sans']),[]);
});
test('a swapped version reports as ready for the final checks, not as a crash',async()=>{
 const {reviewStatus}=await import('../review-status.mjs');
 assert.deepEqual(reviewStatus({status:'preview_ready',revision:1,checkedRevision:1,snapshotRevision:1,summary:'x'}),{status:'ready',revision:1,findings:[]});
 assert.equal(reviewStatus({status:'preview_ready'}).status,'ready','no revision fields at all is not a crash either');
});
