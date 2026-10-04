// The finished video's rhythm against the reference's: how often the picture
// changes, how fast the narration speaks, and how soon text follows the words.
// Differences become plain suggestions for the next version, never failures:
// the brief may ask for a calmer or faster piece than the reference.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
const run=promisify(execFile);

/** Hard cuts in a video: frames whose scene change score passes 0.3, at 10 frames a second, merged within 0.25 s. */
export async function cutTimes(file,ffmpeg='ffmpeg'){
 const {stderr}=await run(ffmpeg,['-hide_banner','-nostats','-i',file,'-an','-vf',"fps=10,scale=160:-2,select='gt(scene,0.3)',showinfo",'-f','null','-'],{timeout:120000,maxBuffer:32*1024*1024});
 const out=[];for(const m of stderr.matchAll(/pts_time:([\d.]+)/g)){const t=Number(m[1]);if(t>0.15&&(!out.length||t-out.at(-1)>0.25))out.push(+t.toFixed(2));}
 return out;
}

const median=a=>{if(!a.length)return null;const s=[...a].sort((x,y)=>x-y);return s[Math.floor(s.length/2)];};
/** The output's figures, comparable with the reference study's pacing. */
export function outputPace({cuts,duration,words=[],sync=[]}){
 const shots=cuts.length+1,spoken=words.length?Math.max(.1,words.at(-1).end-words[0].start):null;
 const delays=sync.filter(c=>Number.isFinite(c.off)).map(c=>c.off);
 return {cuts_per_10_seconds:duration?+(cuts.length/duration*10).toFixed(1):null,average_shot_seconds:+(duration/shots).toFixed(2),
  words_per_second:spoken?+(words.length/spoken).toFixed(2):null,text_to_speech_delay_seconds:delays.length?+median(delays).toFixed(2):null};
}

/** Plain suggestions where the output's rhythm is far from the reference's. */
export function paceNotes(ref,out){
 const notes=[];if(!ref)return notes;
 const r=ref.cuts_per_10_seconds,o=out.cuts_per_10_seconds;
 if(Number.isFinite(r)&&Number.isFinite(o)&&r>=1){
  if(o<r*0.6)notes.push(`The reference changes picture about every ${ref.average_shot_seconds} s; this video holds about ${out.average_shot_seconds} s. Cutting faster would match its energy.`);
  else if(o>r*1.6)notes.push(`This video cuts much faster than the reference (about every ${out.average_shot_seconds} s against ${ref.average_shot_seconds} s); longer holds would match its calm.`);
 }
 if(Number.isFinite(ref.words_per_second)&&Number.isFinite(out.words_per_second)&&out.words_per_second<ref.words_per_second*0.75)
  notes.push(`The narration is slower than the reference (${out.words_per_second} against ${ref.words_per_second} words a second).`);
 if(Number.isFinite(ref.text_to_speech_delay_seconds)&&Number.isFinite(out.text_to_speech_delay_seconds)&&Math.abs(out.text_to_speech_delay_seconds-ref.text_to_speech_delay_seconds)>0.4)
  notes.push(`Text appears ${out.text_to_speech_delay_seconds} s after it is said; in the reference it is ${ref.text_to_speech_delay_seconds} s.`);
 return notes.slice(0,3);
}
