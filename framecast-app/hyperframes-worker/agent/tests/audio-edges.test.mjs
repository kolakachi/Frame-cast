import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp,rm} from 'node:fs/promises';
import {audioEdges,audioEdgeFindings} from '../timing-check.mjs';
import {mediaOp} from '../media-tool.mjs';
const run=promisify(execFile);

// The narration and music of a real build (run 7e766c06): one voice file split at rounded seconds, music stopped at 30 s.
const vo='voice.wav',music='music.wav';
const clip=(id,src,start,dur,from,extra='')=>`<audio id="${id}" class="clip" src="${src}" data-start="${start}" data-media-start="${from}" data-duration="${dur}"${extra}></audio>`;
const real=[['vo1',0.3,4,0],['vo2',6.15,4.4,4.3],['vo3',11.75,3.3,8.8],['vo4',17.25,2.9,12.1],['vo5',22.4,3.95,15],['vo6',26.9,2.2,19]];
const html=real.map(([id,s,d,f])=>clip(id,vo,s,d,f)).join('')+clip('music',music,0,30,0);
const rows=[...real.map(([id,s,d])=>({id,kind:'audio',src:vo,start:s,end:s+d})),{id:'music',kind:'audio',src:music,start:0,end:30}];
// Loudness measured on the real files (dBFS, 60 ms on the side each edge throws away); below -35 is a pause.
const levels={[vo]:{3.99:-58.9,4.25:-46.7,8.69:-14.8,8.75:-17.6,12.09:-13.4,12.05:-17.4,14.99:-79.9,14.95:-79.9,18.94:-23,18.95:-20.7,21.14:-91},[music]:{29.99:-33.4}};
const words=[['First,',4.30,4.71],['Next,',8.58,8.95],['review',8.95,9.3],['Generate',11.99,12.4],['Happy',18.96,19.3]];

test('a voice split at rounded seconds is caught at every edge that lands in a word, and the music stopping mid-track is caught',()=>{
 const clips=audioEdges({rows,html,durations:{[vo]:21.2,[music]:31}});
 const e=audioEdgeFindings({clips,levels,voices:new Set([vo]),pauses:{[vo]:[[7.97,8.58],[11.48,11.99],[18.55,18.96]]},transcripts:{[vo]:words}});
 assert.deepEqual(e.map(x=>[x.code,x.selector]),[['voice_cut_mid_word','#vo2'],['voice_cut_mid_word','#vo3'],['voice_cut_mid_word','#vo3'],['voice_cut_mid_word','#vo4'],['voice_cut_mid_word','#vo5'],['voice_cut_mid_word','#vo6'],['audio_cut_abruptly','#music']]);
 assert.match(e[1].message,/starts 8\.80 s into voice\.wav, in the middle of "Next," \(said 8\.58–8\.95 s\)/);
 assert.match(e[0].fixHint,/one continuous clip.*7\.97–8\.58 s/);
 assert.match(e[6].fixHint,/media op fade on music\.wav .*fade_out:1/);
 assert.match(e[5].message,/starts 19\.00 s into voice\.wav, in the middle of "Happy"/,'the gap between two clips drops the start of a word');
});

test('one continuous narration clip and faded music pass; a fade on a voice edge does not excuse a cut word',()=>{
 const lane=` data-automation='{"version":1,"lanes":[{"target":"volume","points":[{"t":0,"v":1},{"t":29,"v":1},{"t":30,"v":0}]}]}'`;
 const h=clip('vo',vo,0.3,21.2,0)+clip('music',music,0,30,0,lane);
 const r=[{id:'vo',kind:'audio',src:vo,start:0.3,end:21.5},{id:'music',kind:'audio',src:music,start:0,end:30}];
 const clips=audioEdges({rows:r,html:h,durations:{[vo]:21.2,[music]:31}});
 assert.deepEqual(audioEdgeFindings({clips,levels,voices:new Set([vo])}),[]);
 const cutVoice=audioEdges({rows:[{id:'vo',kind:'audio',src:vo,start:0,end:8.8}],html:clip('vo',vo,0,8.8,0,lane.replace('29','7.8').replace('"t":30','"t":8.8')),durations:{[vo]:21.2}});
 assert.equal(audioEdgeFindings({clips:cutVoice,levels:{[vo]:{8.79:-17}},voices:new Set([vo])})[0].code,'voice_cut_mid_word');
});

test('a file made by fade keeps its own edges; a file cut from something longer has its start measured',()=>{
 const h=clip('m',music,0,30,0)+clip('t','trim.wav',0,2,0);
 const r=[{id:'m',kind:'audio',src:music,start:0,end:30},{id:'t',kind:'audio',src:'trim.wav',start:0,end:2}];
 const clips=audioEdges({rows:r,html:h,durations:{[music]:30,'trim.wav':2},faded:new Set([music]),derived:new Set(['trim.wav'])});
 assert.deepEqual(clips.map(c=>c.edges.map(e=>e.side)),[[],['start','end']]);
});

test('levels and fade run on real audio: quiet in the pause, loud in the tone, and a faded slice ends quiet',async()=>{
 const dir=await mkdtemp('/tmp/audio-edges-');
 try{
  // 1 s tone, 0.5 s silence, 1 s tone.
  await run('ffmpeg',['-v','error','-y','-f','lavfi','-i','sine=frequency=440:duration=1:sample_rate=16000','-f','lavfi','-i','anullsrc=r=16000:cl=mono','-f','lavfi','-i','sine=frequency=440:duration=1:sample_rate=16000',
   '-filter_complex','[1]atrim=duration=0.5[s];[0][s][2]concat=n=3:v=0:a=1','-c:a','pcm_s16le',dir+'/tone.wav']);
  let n=0;const nextName=(op,ext)=>`derived-${++n}-${op}.${ext}`;
  const l=await mediaOp({projectDir:dir,request:{op:'levels',input:'tone.wav',params:{at:[0.5,1.25,2]}},nextName});
  assert.ok(l.levels[0][1]>-35,'tone is loud');assert.ok(l.levels[1][1]<-35,'pause is quiet');assert.ok(l.levels[2][1]>-35);
  const f=await mediaOp({projectDir:dir,request:{op:'fade',input:'tone.wav',params:{start:0,end:2,fade_out:0.5}},nextName});
  assert.equal(f.output,'derived-1-fade.wav');assert.ok(Math.abs(f.info.duration-2)<0.05);
  assert.deepEqual(f.source_map,[{out_start:0,out_end:2,src_start:0,src_end:2}]);
  const end=await mediaOp({projectDir:dir,request:{op:'levels',input:f.output,params:{at:[1.98]}},nextName});
  assert.ok(end.levels[0][1]<-35,'the faded slice ends quiet: '+end.levels[0][1]);
  const s=await mediaOp({projectDir:dir,request:{op:'silences',input:'tone.wav',params:{noise_db:-35,min_silence:0.1}},nextName});
  assert.equal(s.silences.length,1);
 }finally{await rm(dir,{recursive:true,force:true});}
});
