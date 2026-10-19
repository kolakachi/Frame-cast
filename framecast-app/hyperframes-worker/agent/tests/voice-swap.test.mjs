import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {mkdtemp,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {plan,ffmpegArgs,swapSrc} from '../voice-swap.mjs';
const run=promisify(execFile);

test('each new line starts where the old one did; the pause after a line gives way first, then a little speed-up',()=>{
 const s=plan({oldStarts:[0.3,2.4,5.0],newStarts:[0.2,2.6,5.4],newEnds:[2.1,5.1,7.6],oldDuration:8,newDuration:8.1});
 assert.deepEqual(s.map(x=>x.at),[0.22,1.95,4.75],'each cut starts after the previous line, placed so its first heard word lands on the old start');
 assert.deepEqual(s.map(x=>x.to),[2.3,5.3,7.8],'each line ends 0.2 s after its last word, so its pause is not sped up');
 assert.deepEqual(s.map(x=>x.tempo),[1.0381,1.0606,1],'a few percent quicker where a line runs long');
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
test('an opening word the aligner missed is kept: the cut starts after the previous line ends',()=>{
 // Line 2's first heard word starts at 3.0, but the voice really starts it at 2.6 ("Branded" not matched); line 1 ends at 2.5.
 const s=plan({oldStarts:[0.3,3.4],newStarts:[0.2,3.0],newEnds:[2.5,5.0],oldDuration:7,newDuration:6});
 assert.equal(s[1].from,2.55,'cut right after line 1, keeping the missed word');
 assert.equal(s[1].at,2.95,'placed so its first heard word still lands where the old one did (3.4 - 0.45)');
});


test('a re-split voice is one continuous take over the old narration, sped up a little only if longer (owner free-ad test, 2026-10-17)',async()=>{
 const {continuousPlan}=await import('../voice-swap.mjs');
 const seg=continuousPlan({oldStarts:[0.3,2,4,6,8],newStarts:[0.2,1.5,3.1,4.0,6.2],newEnds:[1.4,2.9,3.9,6.0,8.1],oldDuration:12,newDuration:8.6});
 assert.equal(seg.length,1);
 assert.deepEqual(seg[0],{from:0.12,to:8.3,at:0.22,tempo:1});
 const fast=continuousPlan({oldStarts:[0.3,2],newStarts:[0.2,5],newEnds:[4.8,12.4],oldDuration:12,newDuration:12.6});
 assert.ok(fast[0].tempo>1&&fast[0].tempo<=1.15);
 assert.throws(()=>continuousPlan({oldStarts:[0.3,2],newStarts:[0.2,5],newEnds:[4.8,19],oldDuration:12,newDuration:20}),/too long for its moment/);
});

test('a line that overruns its slot falls back to one continuous read; a free ad that still does not fit is told to shorten (2026-10-18)',async()=>{
 const {fit}=await import('../voice-swap.mjs');
 // Line 2 of 2 runs 4.4 s against a 3.85 s slot, but the whole read (6.4 s) fits the 11.6 s narration.
 const timing={oldStarts:[0.3,6],newStarts:[0.3,2.1],newEnds:[1.9,6.5],oldDuration:10,newDuration:6.8};
 const r=fit(timing,{});assert.equal(r.continuous,true);assert.equal(r.segments.length,1);
 const tooLong={oldStarts:[0.3,6],newStarts:[0.3,2.1],newEnds:[1.9,13.5],oldDuration:10,newDuration:14};
 assert.throws(()=>fit(tooLong,{free:true}),/too long for its moment .*said shorter; the words on screen stay/);
 assert.throws(()=>fit(tooLong,{}),/too long for its moment/);
 assert.equal(fit({oldStarts:[0.3,4],newStarts:[0.3,4.2],newEnds:[3.5,7],oldDuration:10,newDuration:7.5},{}).continuous,false,'lines that fit stay line by line');
});

test('SWAP-SAFE: a line 1.12x long is sped up rather than refused, and the message names what was measured', async () => {
 const {plan, RULES} = await import('../voice-swap.mjs');
 assert.equal(RULES.maxTempo, 1.15);
 // Line 1 has a 2.0 s slot (next line at 2.3 s, less the lead) and takes about 2.2 s to say (with its tail): sped up under 1.15x.
 const segs = plan({oldStarts: [0.3, 2.3], newStarts: [0.3, 2.6], newEnds: [2.25, 4], oldDuration: 6, newDuration: 4.5});
 assert.ok(segs[0].tempo > 1 && segs[0].tempo <= 1.15);
 assert.throws(() => plan({oldStarts: [0.3, 2.3], newStarts: [0.3, 3.5], newEnds: [3.3, 5], oldDuration: 6, newDuration: 5.5}), /line 1 takes [\d.]+ s to say against a [\d.]+ s slot/);
});
