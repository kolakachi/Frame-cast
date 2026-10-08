// A voice-only change: the new voice is lined up with the old one, line by line, and swapped into the version as it
// is. No builder runs, so nothing on screen moves (VOICE-ONLY, 2026-10-08: re-voices ran the builder, cost 107-133
// credits and sometimes changed the picture). Each new line starts where the old line started; a line longer than its
// slot is sped up a little, and one that still does not fit is sent back to the full editor.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {readFile,writeFile} from 'node:fs/promises';import {createHash} from 'node:crypto';
import {alignScript} from './audio-review.mjs';

const run=promisify(execFile);
export const RULES={lead:0.08,maxTempo:1.1,tail:0.2};

/** The cut and placement of each new line. starts: when each line begins in the old and new files (seconds). */
export function plan({oldStarts,newStarts,newEnds=[],oldDuration,newDuration,rules=RULES}){
 const n=oldStarts.length;
 if(!n||newStarts.length!==n)throw unfit('the new voice has a different number of lines');
 if([...oldStarts,...newStarts].some(t=>t===null||!Number.isFinite(t)))throw unfit('a line could not be found in one of the voices');
 for(let i=1;i<n;i++)if(!(oldStarts[i]>oldStarts[i-1])||!(newStarts[i]>newStarts[i-1]))throw unfit('the lines are not in order');
 return oldStarts.map((at,i)=>{
  // A line ends shortly after its last word: the pause after it gives way before any speed-up.
  const next=i+1<n?newStarts[i+1]-rules.lead:newDuration,end=Number.isFinite(newEnds[i])?Math.min(next,newEnds[i]+rules.tail):next;
  const from=Math.max(0,newStarts[i]-rules.lead),to=Math.max(from,end);
  const place=Math.max(0,at-rules.lead),slot=(i+1<n?oldStarts[i+1]-rules.lead:oldDuration-rules.tail)-place;
  const length=to-from,tempo=length>slot?length/slot:1;
  if(slot<=0||tempo>rules.maxTempo)throw unfit(`line ${i+1} is ${length.toFixed(2)} s against a ${Math.max(0,slot).toFixed(2)} s slot`);
  return {from:+from.toFixed(3),to:+to.toFixed(3),at:+place.toFixed(3),tempo:+tempo.toFixed(4)};
 });
}

/** ffmpeg arguments: each segment trimmed, sped up if needed, delayed to its place and mixed; padded to the old length. */
export function ffmpegArgs({input,segments,duration,output}){
 const n=segments.length,parts=[`[0:a]asplit=${n}${segments.map((_,i)=>`[s${i}]`).join('')}`];
 segments.forEach((s,i)=>{const ms=Math.round(s.at*1000);
  parts.push(`[s${i}]atrim=start=${s.from}:end=${s.to},asetpts=PTS-STARTPTS${s.tempo>1?`,atempo=${s.tempo}`:''},adelay=${ms}:all=1[o${i}]`);});
 parts.push(`${segments.map((_,i)=>`[o${i}]`).join('')}amix=inputs=${n}:normalize=0:duration=longest,apad=whole_dur=${duration.toFixed(3)},atrim=end=${duration.toFixed(3)}[out]`);
 return ['-hide_banner','-loglevel','error','-y','-i',input,'-filter_complex',parts.join(';'),'-map','[out]','-ac','1','-ar','44100','-c:a','pcm_s16le',output];
}

/** The version's files with the narration file swapped for the new one. */
export function swapSrc(bundle,oldSrc,newSrc){
 const out={};let hits=0;
 for(const [name,text] of Object.entries(bundle||{})){const t=String(text);const parts=t.split(oldSrc);hits+=parts.length-1;out[name]=parts.join(newSrc);}
 if(!hits)throw unfit('the narration file is not in the version');
 return out;
}

function unfit(why){return Object.assign(Error('This change needs the full editor: '+why+'. Send it again and the video will be rebuilt with the new voice.'),{code:'VOICE_SWAP_UNFIT'});}

const seconds=async(file,ffprobe)=>Number((await run(ffprobe,['-v','error','-show_entries','format=duration','-of','csv=p=0',file])).stdout.trim())||0;

/**
 * Lines the new voice up with the old one and swaps it in. listen(file) returns {words:[{text,start,end}]}.
 * Returns what the build would: the bundle, the derived file (its source is the bought voice) and a summary.
 */
export async function voiceSwap({project,bundle,swap,newVoice,listen,ffmpeg='ffmpeg',ffprobe='ffprobe'}){
 const oldFile=project+'/'+swap.old_src,newFile=project+'/'+newVoice;
 const [oldHeard,newHeard]=await Promise.all([listen(oldFile),listen(newFile)]);
 const oldStarts=alignScript(swap.old_lines,oldHeard?.words||[]).lineStarts,newAlign=alignScript(swap.new_lines,newHeard?.words||[]);
 const newStarts=newAlign.lineStarts,newEnds=newAlign.lineEnds;
 const [oldDuration,newDuration]=await Promise.all([seconds(oldFile,ffprobe),seconds(newFile,ffprobe)]);
 const segments=plan({oldStarts,newStarts,newEnds,oldDuration,newDuration});
 const name='voice-aligned.wav';
 await run(ffmpeg,ffmpegArgs({input:newFile,segments,duration:oldDuration,output:project+'/'+name}),{timeout:120000});
 const sha256=createHash('sha256').update(await readFile(project+'/'+name)).digest('hex');
 const next=swapSrc(bundle,swap.old_src,name);
 for(const [file,text] of Object.entries(next))await writeFile(project+'/'+file,text,{mode:0o600});
 const sped=segments.filter(s=>s.tempo>1).length;
 return {bundle:next,derived:[{path:name,derivedFrom:newVoice,operation:'space',params:{aligned_to:swap.old_src,lines:segments.length,sped},sha256}],
  // One revision, checked by the render and the final checks that follow (there is no builder review to report).
  state:{status:'preview_ready',revision:1,checkedRevision:1,snapshotRevision:1,summary:'I replaced the voice and lined each line up with where the old one started, so the picture and its timing are unchanged'+(sped?` (${sped} line${sped>1?'s':''} slightly quicker to fit)`:'')+'.'}};
}
