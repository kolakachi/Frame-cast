// Listening to the finished video. The critic only sees silent frames, so the
// requirements a viewer hears (narration, sync, music, sound) are checked here,
// on the encoded export: its soundtrack is transcribed and compared with the
// approved script (as the voice was asked to say it), on-screen cues tied to
// spoken words are timed against what is actually heard, and the mix is
// measured for voice over music, dead air and an abrupt ending.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {mkdtemp,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {attributesById,phraseStart,TOLERANCE} from './timing-check.mjs';
import {heard} from './requirement-review.mjs';
const run=promisify(execFile);

export const RULES={coverage:.9,missingRun:3,extra:.25,voiceOverMusicDb:6,quietDb:-55,musicDb:-50,deadAir:1.5,abruptDb:-35};
const norm=s=>String(s??'').toLowerCase().normalize('NFKD').replace(/[^\p{L}\p{N}\s']/gu,' ').replace(/'/g,'').trim();
export const tokens=text=>norm(text).split(/\s+/).filter(Boolean);
const lev=(a,b)=>{const d=Array.from({length:a.length+1},(_,i)=>[i]);for(let j=1;j<=b.length;j++)d[0][j]=j;
 for(let i=1;i<=a.length;i++)for(let j=1;j<=b.length;j++)d[i][j]=Math.min(d[i-1][j]+1,d[i][j-1]+1,d[i-1][j-1]+(a[i-1]===b[j-1]?0:1));return d[a.length][b.length];};
// Recognisers misspell coined words and names; a near spelling of a long word counts as heard.
export const close=(a,b)=>a===b||(a.length>=4&&b.length>=4&&lev(a,b)<=1)||(a.length>=5&&b.length>=5&&(a.startsWith(b)||b.startsWith(a)));

/** The script against the words heard, in order: coverage, missing passages, extra words and when each line begins. */
export function alignScript(lines,words){
 const script=lines.flatMap((l,line)=>tokens(l).map(t=>({t,line})));
 const heardWords=(words||[]).flatMap(w=>tokens(w.text).map(t=>({t,start:+w.start,end:+w.end})));
 const n=script.length,m=heardWords.length;
 const L=Array.from({length:n+1},()=>new Int32Array(m+1));
 for(let i=n-1;i>=0;i--)for(let j=m-1;j>=0;j--)L[i][j]=close(script[i].t,heardWords[j].t)?L[i+1][j+1]+1:Math.max(L[i+1][j],L[i][j+1]);
 const match=new Array(n).fill(null);
 for(let i=0,j=0;i<n&&j<m;){if(close(script[i].t,heardWords[j].t)){match[i]=j;i++;j++;}else if(L[i+1][j]>=L[i][j+1])i++;else j++;}
 const missing=[];let runText=[];
 const flush=()=>{if(runText.length>=RULES.missingRun)missing.push(runText.join(' '));runText=[];};
 for(let i=0;i<n;i++){if(match[i]===null)runText.push(script[i].t);else flush();}flush();
 const matched=match.filter(x=>x!==null).length;
 const lineStarts=lines.map((_,line)=>{const i=script.findIndex((s,k)=>s.line===line&&match[k]!==null);return i<0?null:+heardWords[match[i]].start.toFixed(2);});
 return {words:n,heard:m,matched,coverage:n?+(matched/n).toFixed(3):null,missing:missing.slice(0,6),extra:Math.max(0,m-matched),lineStarts};
}

/** Text tied to spoken words (data-spoken) against when those words are actually heard in the export. */
export function cueSync(html,words){
 const out=[];
 for(const [id,a] of attributesById(html)){
  if(!a['data-spoken']||!Number.isFinite(Number(a['data-start'])))continue;
  const at=phraseStart(words||[],a['data-spoken']);
  if(at===null){out.push({id,phrase:a['data-spoken'],shows:+Number(a['data-start']).toFixed(2),heard:null});continue;}
  out.push({id,phrase:a['data-spoken'],shows:+Number(a['data-start']).toFixed(2),heard:+at.toFixed(2),off:+(Number(a['data-start'])-at).toFixed(2)});
 }
 return out;
}

/** Loudness every 0.1 s of a file: [[seconds, dBFS], ...]. */
export async function levelWindows(file,ffmpeg='ffmpeg'){
 const {stderr}=await run(ffmpeg,['-hide_banner','-nostats','-i',file,'-map','0:a:0','-af','aresample=16000,asetnsamples=n=1600:p=0,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level','-f','null','-'],{timeout:120000,maxBuffer:64*1024*1024});
 const out=[];let t=null;
 for(const line of stderr.split('\n')){const p=line.match(/pts_time:([\d.]+)/);if(p)t=Number(p[1]);const v=line.match(/RMS_level=(-?[\d.]+|-inf)/);if(v&&t!==null){out.push([+t.toFixed(2),v[1]==='-inf'?-120:Number(v[1])]);t=null;}}
 return out;
}

const median=a=>{if(!a.length)return null;const s=[...a].sort((x,y)=>x-y);return s[Math.floor(s.length/2)];};
/** Voice over music, dead air and the ending, from the windows and the heard word times. */
export function mixFindings(windows,words,duration){
 const speaking=t=>(words||[]).some(w=>t>=w.start-.15&&t<=w.end+.15);
 const inner=windows.filter(([t])=>t>=.3&&t<=duration-.6);
 const speech=inner.filter(([t])=>speaking(t)).map(w=>w[1]),gaps=inner.filter(([t])=>!speaking(t)).map(w=>w[1]);
 const speechDb=median(speech),gapDb=gaps.length>=5?median(gaps):null;
 const music=gapDb!==null&&gapDb>RULES.musicDb;
 const voiceOverMusic=speechDb!==null&&music?+(speechDb-gapDb).toFixed(1):null;
 const dead=[];let from=null;
 for(const [t,db] of windows){const quiet=db<RULES.quietDb&&t>.5&&t<duration-.5;if(quiet&&from===null)from=t;if(!quiet&&from!==null){if(t-from>=RULES.deadAir)dead.push({at:from,seconds:+(t-from).toFixed(1)});from=null;}}
 const tail=windows.filter(([t])=>t>=duration-.15).map(w=>w[1]),before=windows.filter(([t])=>t>=duration-1.2&&t<duration-.6).map(w=>w[1]);
 const endDb=tail.length?Math.max(...tail):null,beforeDb=before.length?median(before):null;
 // A fade drops well below the level just before it; a cut ends near that level.
 const abrupt=endDb!==null&&endDb>RULES.abruptDb&&(beforeDb===null||beforeDb-endDb<6);
 return {speech_db:speechDb,gap_db:gapDb,music,voice_over_music_db:voiceOverMusic,dead_air:dead.slice(0,4),end_db:endDb,abrupt_end:abrupt};
}

/** The plain problems a viewer would hear, for the summary and Before you post. */
export function problems(a){
 const out=[];
 if(a.narration&&!a.narration_ok)out.push(a.narration.matched===0?'The narration cannot be heard in the video.':`Part of the narration is missing: "${a.narration.missing[0]??'some words'}".`);
 if(a.narration&&a.narration.words&&a.narration.extra>a.narration.words*RULES.extra)out.push('Some narration seems to play twice or overlap.');
 const off=(a.sync||[]).filter(c=>c.heard===null||Math.abs(c.off)>TOLERANCE);
 if(off.length)out.push(`Text appears out of step with the voice${off[0].heard!==null?` ("${off[0].phrase}" shows at ${off[0].shows} s, is said at ${off[0].heard} s)`:` ("${off[0].phrase}" is not heard)`}.`);
 if(a.mix.voice_over_music_db!==null&&a.mix.voice_over_music_db<RULES.voiceOverMusicDb)out.push('The music may be too loud under the voice.');
 if(a.mix.dead_air.length)out.push(`The sound drops to silence at ${a.mix.dead_air[0].at.toFixed(1)} s for ${a.mix.dead_air[0].seconds} s.`);
 if(a.mix.abrupt_end)out.push('The sound stops abruptly at the end.');
 return out;
}

/** What the listening check can say about each requirement a viewer hears; others are left as they were. */
export function heardChecks(requirements,a){
 const out=[];
 for(const r of (requirements||[]).filter(r=>/^req-[a-f0-9]{20}$/.test(r?.id)&&heard(r))){
  const t=String(r.text||''),parts=[];
  // A requirement about timing to the words is judged on sync alone, not on whether every word was said.
  const isSync=/sync|when|timing|reach|exactly|land/i.test(t);
  if(!isSync&&/narrat|script|verbatim|voice|spoken|said|words|hook|cta/i.test(t)&&a.narration)parts.push(a.narration_ok?[true,`${Math.round(a.narration.coverage*100)}% of the script heard in order`]:[false,problems({...a,sync:[],mix:{dead_air:[],voice_over_music_db:null}})[0]||'Narration incomplete']);
  if(isSync){const cues=a.sync||[];const off=cues.filter(c=>c.heard===null||Math.abs(c.off)>TOLERANCE);
   if(cues.length)parts.push(off.length?[false,`${off.length} of ${cues.length} spoken cues are off by more than ${TOLERANCE} s`]:[true,`${cues.length} spoken cues land within ${TOLERANCE} s of their words`]);}
  if(/music/i.test(t))parts.push(!a.mix.music?[false,'No music bed heard under the voice']:a.mix.voice_over_music_db!==null&&a.mix.voice_over_music_db<RULES.voiceOverMusicDb?[false,`Voice only ${a.mix.voice_over_music_db} dB above the music`]:[true,`Music heard${a.mix.voice_over_music_db!==null?`, voice ${a.mix.voice_over_music_db} dB above it`:''}`]);
  if(/audio|sound|voice/i.test(t)&&!/music/i.test(t)){const bad=a.mix.abrupt_end?'The sound stops abruptly at the end':a.mix.dead_air.length?'Silence in the middle':null;parts.push(bad?[false,bad]:[true,'Sound runs through to a clean ending']);}
  if(!parts.length)continue;
  const fails=parts.filter(p=>!p[0]);
  out.push({id:r.id,text:r.text,version:r.version??1,status:fails.length?'unmet':'fulfilled',evidence:(fails.length?fails:parts).map(p=>p[1]).join('; ').slice(0,400),source:'audio_review'});
 }
 return out;
}

/** The whole check on one encoded video. listen(wavPath) returns {words, script:{written, spoken}} from the app. */
export async function listenToExport({file,html='',requirements=[],listen,ffmpeg='ffmpeg'}){
 const dir=await mkdtemp(path.join(os.tmpdir(),'listen-'));
 try{
  const wav=path.join(dir,'sound.wav');
  await run(ffmpeg,['-hide_banner','-loglevel','error','-y','-i',file,'-vn','-ac','1','-ar','16000',wav],{timeout:120000});
  const {stdout}=await run('ffprobe',['-v','error','-show_entries','format=duration','-of','csv=p=0',file]);
  const duration=Number(stdout.trim())||0;
  const heardFile=await listen(wav);
  const words=(heardFile?.words||[]).map(w=>({text:w.text,start:+w.start,end:+w.end}));
  const lines=(heardFile?.script?.spoken?.length?heardFile.script.spoken:heardFile?.script?.written)||[];
  const narration=lines.length?alignScript(lines,words):null;
  const narration_ok=narration?narration.coverage>=RULES.coverage&&!narration.missing.length:null;
  const mix=mixFindings(await levelWindows(wav,ffmpeg),words,duration);
  const a={narration,narration_ok,sync:cueSync(html,words),mix,duration};
  const found=problems(a);
  return {summary:{ok:!found.length,heard_words:words.length,...(narration?{script_coverage:narration.coverage,missing:narration.missing,line_starts:narration.lineStarts}:{}),
   sync:(a.sync||[]).slice(0,12),mix,problems:found},checks:heardChecks(requirements,a)};
 }finally{await rm(dir,{recursive:true,force:true});}
}
