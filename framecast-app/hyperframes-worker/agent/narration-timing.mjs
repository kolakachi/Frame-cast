// Beats timed by the voice, not by guessed seconds. The plan names, for each
// beat that begins with speech, the narration words it starts on (starts_on).
// Once the narration exists, its word times give each beat's real start; beats
// without words sit proportionally between their neighbours. When the voice
// ends well before the video does, the narration's own pauses are lengthened
// (media op space) rather than leaving a long silent hold at the end.
import {tokens,close} from './audio-review.mjs';
import {attributesById} from './timing-check.mjs';

export const LEAD=0.15,TOLERANCE=0.5,END_ROOM=1.5,MAX_PAUSE=1.5;

/** Where a phrase is first said at or after word index `from`: {index, start} or null. Spelling slips count as heard. */
export function findPhrase(words,phrase,from=0){
 const want=tokens(phrase);if(!want.length)return null;
 const heard=[];for(const [i,w] of (words||[]).entries())for(const t of tokens(w.text??w[0]))heard.push({t,i,start:Number(w.start??w[1])});
 for(let k=0;k+want.length<=heard.length;k++){
  if(heard[k].i<from)continue;
  if(want.every((t,j)=>close(t,heard[k+j].t)))return {index:heard[k].i,start:heard[k].start};
 }
 return null;
}

const words3=w=>(w||[]).map(x=>Array.isArray(x)?{text:x[0],start:x[1],end:x[2]}:x);
/**
 * The beat sheet timed by this narration placed at clipStart (seconds into the video). Returns each beat's planned
 * and voiced span, when the voice ends, and, when it ends too early, the pauses to lengthen.
 */
export function voiceTiming({scenes=[],lines=[],words=[],clipStart=0.3,videoSeconds,lead=LEAD}){
 const w=words3(words);
 // LENGTH-TARGET (2026-10-19): the chosen length is a target. A voice that runs past it (a presenter take sized to its
 // script) sets the video's length, up to 30% over, so every word is heard (a 15 s explainer's 17 s take was cut at 15).
 const chosen=videoSeconds,lastWord=w.at(-1),heardEnd=lastWord?clipStart+Number(lastWord.end):null;
 if(chosen&&heardEnd!==null&&heardEnd>chosen-0.1)videoSeconds=Math.min(+(Math.ceil((heardEnd+END_ROOM/3)*10)/10).toFixed(1),+(chosen*1.3).toFixed(1));
 const beats=scenes.map(s=>({label:s.label,starts_on:s.starts_on||null,planned:[Number(s.start)||0,Number(s.end)||0],heard_at:null,voiced:null}));
 let from=0;
 for(const b of beats){if(!b.starts_on)continue;const f=findPhrase(w,b.starts_on,from);if(f){b.heard_at=+(f.start).toFixed(2);from=f.index;}}
 // Anchored starts, then the rest placed proportionally between known neighbours by their planned lengths.
 const start=beats.map((b,i)=>i===0?0:b.heard_at!==null?Math.max(0,+(clipStart+b.heard_at-lead).toFixed(2)):null);
 for(let i=1;i<beats.length;i++){
  if(start[i]!==null)continue;
  let j=i;while(j<beats.length&&start[j]===null)j++;
  const a=start[i-1],z=j<beats.length?start[j]:videoSeconds,span=beats.slice(i-1,j).reduce((n,b)=>n+Math.max(.1,b.planned[1]-b.planned[0]),0);
  let t=a;for(let k=i;k<j;k++){t+=(z-a)*Math.max(.1,beats[k-1].planned[1]-beats[k-1].planned[0])/span;start[k]=+t.toFixed(2);}
 }
 beats.forEach((b,i)=>{b.voiced=[start[i],i+1<beats.length?start[i+1]:videoSeconds];});
 const last=w.at(-1),voiceEnds=last?+(clipStart+Number(last.end)).toFixed(2):null;
 const spare=voiceEnds===null||!videoSeconds?0:+(videoSeconds-END_ROOM-voiceEnds).toFixed(2);
 let suggestion=null;
 if(spare>1&&lines.length>1){
  // Lengthen the pause before each later line: the points sit between the line's first word and the word before it.
  const points=[];let f=0;
  for(const line of lines.slice(1)){const first=tokens(line).slice(0,2).join(' ');const hit=findPhrase(w,first,f);if(!hit||hit.index===0)continue;
   const prev=w[hit.index-1];points.push(+(((Number(prev.end)+hit.start)/2)).toFixed(2));f=hit.index;}
  if(points.length){const each=Math.min(MAX_PAUSE,+(spare/points.length).toFixed(2));suggestion={op:'space',insert:points.map(t=>[t,each]),adds:+(each*points.length).toFixed(2)};}
 }
 return {clip_start:clipStart,beats,voice_ends:voiceEnds,video_seconds:videoSeconds,...(videoSeconds!==chosen?{chosen_seconds:chosen}:{}),spare_seconds:spare,suggestion};
}

/**
 * The check: each container marked data-beat="<scene label>" starts when its words are heard. The narration clip
 * is found among the rows; its own transcript (carried through any edit) gives the word times.
 */
/**
 * Every element marked data-beat, with or without an id: [key, attributes]. Reading them through attributesById (ids
 * only) missed beats written as <div class="clip beat" data-beat="Step 1">, so a whiteboard build failed every preview
 * for "no beat containers are marked" and stopped with no video (2026-10-16).
 */
export function beatContainers(html){
 const out=[];
 for(const m of String(html).matchAll(/<([a-z][a-z0-9-]*)\b([^>]*)>/gi)){
  const attrs={};for(const a of m[2].matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|'([^']*)')/g))attrs[a[1].toLowerCase()]=a[3]??a[4];
  if(attrs['data-beat'])out.push([attrs.id||null,attrs]);
 }
 return out;
}

export function beatFindings({rows,html,scenes,voiceFiles,transcripts,videoSeconds}){
 const anchored=(scenes||[]).filter(s=>s.starts_on);if(!anchored.length)return [];
 const clip=(rows||[]).find(r=>r.kind==='audio'&&voiceFiles.has(r.src)&&transcripts[r.src]);if(!clip)return [];
 const attrs=attributesById(html),marked=beatContainers(html);
 if(!marked.length)return [{code:'beats_unmarked',message:'No beat containers are marked, so their timing cannot follow the narration.',
  fixHint:'Put data-beat="<scene label>" on each beat\'s container (with its data-start), as in plan.scenes.'}];
 const offset=Number(attrs.get(clip.id||clip.elementId)?.['data-media-start'])||0,rate=Number(clip.playbackRate)||1;
 const words=words3(transcripts[clip.src]).map(x=>({...x,start:(x.start-offset)/rate,end:(x.end-offset)/rate})).filter(x=>x.start>=0);
 const timing=voiceTiming({scenes,words,clipStart:clip.start,videoSeconds});
 const out=[];
 for(const [id,a] of marked){
  const b=timing.beats.find(x=>x.label===a['data-beat']);if(!b||b.heard_at===null)continue;
  const at=Number(a['data-start']);if(!Number.isFinite(at))continue;
  if(Math.abs(at-b.voiced[0])>TOLERANCE)out.push({code:'beat_off_voice',selector:id?'#'+id:`[data-beat="${a['data-beat']}"]`,time:at,
   message:`Beat "${b.label}" starts at ${at.toFixed(2)} s but "${b.starts_on}" is said at ${(clip.start+b.heard_at).toFixed(2)} s.`,
   fixHint:`Set its data-start to ${b.voiced[0].toFixed(2)} and move its contents with it.`});
 }
 return out.slice(0,8);
}
