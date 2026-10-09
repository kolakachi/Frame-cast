// Two timing rules the renderer cannot see on its own.
// 1. Spoken cues: a timed clip tagged data-spoken="phrase" must start within
//    TOLERANCE of the moment that phrase is said in the transcript of the video
//    or audio clip playing underneath it.
// 2. Short media: a video or audio clip whose slot is longer than its file must
//    say what happens after the file ends (data-fit="hold" or "loop"), so a
//    clip never silently freezes or restarts. Shortening the slot or replacing
//    the file are the other fixes.
export const TOLERANCE=0.35;
const norm=s=>String(s).toLowerCase().normalize('NFKD').replace(/[^\p{L}\p{N}\s]/gu,'').replace(/\s+/g,' ').trim();

export function rowsOf(timeline){
 const out=[];const walk=rows=>{for(const r of rows||[]){out.push(r);walk(r.children);}};
 for(const t of timeline?.timeline?.tracks||timeline?.tracks||[])walk(t.rows);
 return out;
}

// data-* attributes of every element that has an id, read from the authored HTML.
export function attributesById(html){
 const map=new Map();
 for(const m of String(html).matchAll(/<([a-z][a-z0-9-]*)\b([^>]*)>/gi)){
  const attrs={};for(const a of m[2].matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|'([^']*)')/g))attrs[a[1].toLowerCase()]=a[3]??a[4];
  if(attrs.id)map.set(attrs.id,{tag:m[1].toLowerCase(),...attrs});
 }
 return map;
}

// Start time of the first occurrence of a phrase in [text,start,end] or {text,start,end} words.
export function phraseStart(words,phrase){
 const target=norm(phrase).split(' ').filter(Boolean);if(!target.length)return null;
 const w=words.map(x=>Array.isArray(x)?{text:x[0],start:x[1]}:x).map(x=>({...x,n:norm(x.text)})).filter(x=>x.n);
 for(let i=0;i+target.length<=w.length;i++){
  // A transcript word can hold two target tokens ("20" vs "twenty percent" is not matched; exact words only).
  if(target.every((t,j)=>w[i+j].n===t))return w[i].start;
 }
 return null;
}

export function timingFindings({rows,html,durations={},transcripts={}}){
 const attrs=attributesById(html),errors=[];
 const media=rows.filter(r=>['video','audio'].includes(r.kind)&&r.src);
 for(const r of media){
  const d=durations[r.src];if(!Number.isFinite(d))continue;
  const rate=Number(r.playbackRate)||1,slot=r.end-r.start,offset=Number(attrs.get(r.id||r.elementId)?.['data-media-start'])||0;
  const plays=(d-offset)/rate,fit=attrs.get(r.id||r.elementId)?.['data-fit'];
  if(slot>plays+0.1&&!['hold','loop'].includes(fit))errors.push({code:'media_shorter_than_slot',selector:'#'+(r.id||r.elementId),
   message:`${r.src} plays ${plays.toFixed(2)} s but its slot is ${slot.toFixed(2)} s.`,
   fixHint:'Choose explicitly: shorten the slot to the clip, replace the clip, or add data-fit="hold" (freeze the last frame) or data-fit="loop" (with the loop attribute) to the element.'});
  const tag=(String(html).match(new RegExp('<[^>]*\\bid=["\']'+(r.id||r.elementId)+'["\'][^>]*>'))?.[0]||'').replace(/=\s*("[^"]*"|'[^']*')/g,'');
  if(fit==='loop'&&!/\sloop(\s|\/?>|$)/.test(tag))
   errors.push({code:'loop_not_set',selector:'#'+(r.id||r.elementId),message:'data-fit="loop" is declared but the element has no loop attribute.',fixHint:'Add the loop attribute, or use data-fit="hold".'});
 }
 for(const r of rows){
  const a=attrs.get(r.id||r.elementId);if(!a?.['data-spoken'])continue;
  // Speech comes from the transcribed clip playing when this cue starts.
  const under=media.find(m=>transcripts[m.src]&&m.start<=r.start+TOLERANCE&&m.end>=r.start);
  if(!under){errors.push({code:'spoken_cue_without_transcript',selector:'#'+(r.id||r.elementId),message:`"${a['data-spoken']}" is tied to speech, but no transcribed clip is playing at ${r.start.toFixed(2)} s.`,fixHint:'Call transcript on the clip that carries the speech, or remove data-spoken.'});continue;}
  const offset=Number(attrs.get(under.id||under.elementId)?.['data-media-start'])||0,rate=Number(under.playbackRate)||1;
  const t=phraseStart(transcripts[under.src],a['data-spoken']);
  if(t===null){errors.push({code:'spoken_phrase_not_found',selector:'#'+(r.id||r.elementId),message:`"${a['data-spoken']}" is not said in ${under.src}.`,fixHint:'Use words exactly as they appear in the transcript.'});continue;}
  const at=under.start+(t-offset)/rate;
  if(Math.abs(r.start-at)>TOLERANCE)errors.push({code:'spoken_cue_off_time',selector:'#'+(r.id||r.elementId),
   message:`Starts at ${r.start.toFixed(2)} s but "${a['data-spoken']}" is said at ${at.toFixed(2)} s.`,fixHint:`Set data-start to ${at.toFixed(2)}.`});
 }
 return errors;
}

// Music under a voice must be ducked: the original music file may not play
// while narration plays; the agent runs media op duck and uses its output.
export function duckingFindings({rows,planMedia=[]}){
 const files=k=>new Set(planMedia.filter(m=>m.kind===k&&m.status==='succeeded'&&m.file).map(m=>m.file));
 const music=files('music'),voice=new Set([...files('voiceover'),...files('cloned_voiceover')]);
 if(!music.size||!voice.size)return [];
 const audio=rows.filter(r=>['video','audio'].includes(r.kind)&&r.src);
 const errors=[];
 for(const m of audio.filter(r=>music.has(r.src))){
  const v=audio.find(r=>voice.has(r.src)&&Math.min(r.end,m.end)-Math.max(r.start,m.start)>0.5);
  if(v)errors.push({code:'music_not_ducked',selector:'#'+(m.id||m.elementId),message:`${m.src} plays under the voice from ${Math.max(v.start,m.start).toFixed(2)} s without ducking.`,
   fixHint:`Run media op duck on ${m.src} with params {"voice":"${v.src}","voice_start":${v.start.toFixed(2)}} and use its output here at data-volume about 0.35.`});
 }
 return errors;
}

// A generated shot's own sound is ambience: under the voice it plays low (about 0.25) or muted, unless the shot
// speaks its own line (then it plays full).
export const AMBIENCE_MAX=0.4;
export function ambienceFindings({rows,html,planMedia=[]}){
 const ok=m=>m.status==='succeeded'&&m.file;
 const shots=new Set(planMedia.filter(m=>ok(m)&&m.kind==='generated_shot'&&!String(m.line||'').trim()).map(m=>m.file));
 const voice=new Set(planMedia.filter(m=>ok(m)&&['voiceover','cloned_voiceover','ugc_take','talking_take','talking_shot'].includes(m.kind)).map(m=>m.file));
 if(!shots.size||!voice.size)return [];
 const attrs=attributesById(html),media=rows.filter(r=>['video','audio'].includes(r.kind)&&r.src),out=[];
 for(const s of media.filter(r=>shots.has(r.src))){
  const a=attrs.get(s.id||s.elementId)||{};
  const tag=(String(html).match(new RegExp('<[^>]*\\bid=["\']'+(s.id||s.elementId)+'["\'][^>]*>'))?.[0]||'').replace(/=\s*("[^"]*"|'[^']*')/g,'');
  if(/\smuted(\s|\/?>|$)/.test(tag)||a['data-has-audio']==='false')continue;
  const volume=a['data-volume']==null||a['data-volume']===''?1:Number(a['data-volume']);
  if(!(volume>AMBIENCE_MAX))continue;
  const v=media.find(r=>voice.has(r.src)&&Math.min(r.end,s.end)-Math.max(r.start,s.start)>0.5);
  if(v)out.push({code:'ambience_too_loud',selector:'#'+(s.id||s.elementId),message:`${s.src} is a generated shot whose own sound plays at volume ${volume} under the voice from ${Math.max(v.start,s.start).toFixed(2)} s.`,
   fixHint:'Set data-volume about 0.25 on it, or mute it when the beat has its own sound design.'});
 }
 return out;
}

// Audio edges. A clip that starts or stops while its file is still sounding is
// heard as a cut-off word or a note chopped mid-bar. Every audio clip's start
// and end, in its file's own time, must fall where the file is quiet; music and
// effects may instead fade there (a volume lane reaching zero at that edge, or
// a file made with media op fade). Narration may not: a fade over a word still
// loses the word, so a voice edge has to sit in a pause.
export const QUIET_DB=-35,EDGE=.03;
const laneAt=(attr,t)=>{
 try{const lane=JSON.parse(attr||'').lanes?.find(l=>l.target==='volume');const p=(lane?.points||[]).slice().sort((a,b)=>a.t-b.t);if(!p.length)return null;
  if(t<=p[0].t)return p[0].v;if(t>=p.at(-1).t)return p.at(-1).v;
  for(let i=1;i<p.length;i++)if(t<=p[i].t){const a=p[i-1],b=p[i];return a.v+(b.v-a.v)*(t-a.t)/Math.max(1e-6,b.t-a.t);}
 }catch{}return null;
};
// The played part of each audio clip in its file's time, and the edges to measure.
// derived: files cut out of something longer (their own edges may be mid-sound); faded: files made by media op fade (their own edges are faded).
export function audioEdges({rows,html,durations={},derived=new Set(),faded=new Set()}){
 const attrs=attributesById(html),out=[];
 for(const r of rows.filter(r=>r.kind==='audio'&&r.src)){
  const d=durations[r.src];if(!Number.isFinite(d))continue;
  const a=attrs.get(r.id||r.elementId)||{},rate=Number(r.playbackRate)||1,slot=r.end-r.start;
  const from=Number(a['data-media-start'])||0,to=Math.min(d,from+slot*rate);
  const edges=[];
  // A file's own beginning is a clean start unless the file was itself cut out of something longer.
  // probe: where the 60 ms loudness window starts. It covers the sound this edge throws away (just before a start,
  // just after an end), or the file's own last moment when the clip plays to the end of the file.
  if(from>EDGE||derived.has(r.src))edges.push({side:'start',at:+from.toFixed(3),probe:+Math.max(0,from-.05).toFixed(3),local:0});
  if(!(faded.has(r.src)&&to>=d-EDGE))edges.push({side:'end',at:+to.toFixed(3),probe:+(to>=d-EDGE?Math.max(0,d-.06):to-.01).toFixed(3),local:+(Math.min(slot,(to-from)/rate)).toFixed(3)});
  if(faded.has(r.src)&&from<=EDGE&&edges[0]?.side==='start')edges.shift();
  out.push({id:r.id||r.elementId,src:r.src,start:r.start,end:r.end,from,to,automation:a['data-automation'],edges});
 }
 return out;
}
const near=(pauses,t)=>{const p=(pauses||[]).map(([s,e])=>({s,e,d:t<s?s-t:t>e?t-e:0})).sort((x,y)=>x.d-y.d).slice(0,2);return p.map(x=>`${x.s.toFixed(2)}–${x.e.toFixed(2)} s`).join(' or ');};
const wordAt=(words,t)=>(words||[]).map(w=>Array.isArray(w)?{text:w[0],start:w[1],end:w[2]}:w).find(w=>w.start<t&&w.end>t);
export function audioEdgeFindings({clips,levels={},voices=new Set(),pauses={},transcripts={}}){
 const errors=[];
 for(const c of clips){
  const voice=voices.has(c.src);
  for(const e of c.edges){
   const db=levels[c.src]?.[e.probe];
   if(db===undefined||db===null||db<QUIET_DB)continue;
   if(!voice){const v=laneAt(c.automation,e.local);if(v!==null&&v<=.05)continue;}
   const w=voice?wordAt(transcripts[c.src],e.at):null;
   const what=voice?(w?`in the middle of "${w.text}" (said ${w.start.toFixed(2)}–${w.end.toFixed(2)} s)`:'while the voice is speaking'):'while the sound is still playing';
   const where=e.side==='start'?`starts ${e.at.toFixed(2)} s into ${c.src}`:`stops ${e.at.toFixed(2)} s into ${c.src}`;
   errors.push({code:voice?'voice_cut_mid_word':'audio_cut_abruptly',selector:'#'+c.id,message:`${c.id} ${where}, ${what}.`,
    fixHint:voice?`Keep the narration as one continuous clip, or move this ${e.side} into a pause${pauses[c.src]?.length?' ('+near(pauses[c.src],e.at)+')':' (media op silences on the file lists them)'}.`
     :`Fade it: media op fade on ${c.src} with {start:${c.from.toFixed(2)},end:${c.to.toFixed(2)},fade_out:${e.side==='end'?'1':'0.05'}${e.side==='start'?',fade_in:0.3':''}} and use that file from data-media-start 0, or a data-automation volume lane that reaches 0 at this ${e.side}.`});
  }
 }
 return errors;
}

// Bought media must be in the video. Every succeeded plan item with a picture or clip appears in the
// composition (as a timed clip or referenced by the page); a talking take plays (nearly) in full and a talking
// shot for most of its length. Audio is checked elsewhere. A file the agent reported as unusable
// (report_limitation naming it) is excused, so a bad purchase is disclosed rather than forced in.
const VISUAL=['ugc_take','generated_shot','talking_take','talking_shot','animate_image','stock_video','stock_image','ai_image','character_poses','character_variants','brand_kit'];
// A video cutout stands in for the clip it was cut from (the one its description names, else the latest video
// before it), so placing the cutout counts as placing that clip.
const VIDEO=['ugc_take','generated_shot','talking_take','talking_shot','animate_image','stock_video'];
export function cutoutSources(planMedia=[]){
 const by={},base=f=>String(f||'').split('/').pop();
 planMedia.forEach((m,i)=>{
  if(m.kind!=='cutout_video'||m.status!=='succeeded'||!m.file)return;
  const earlier=planMedia.slice(0,i).filter(v=>VIDEO.includes(v.kind)&&v.status==='succeeded'&&v.file);
  const named=rtrimWord(m.description);
  const src=earlier.find(v=>named&&base(v.file)===base(named))||earlier.at(-1);
  if(src)(by[src.file]??=[]).push(m.file);
 });
 return by;
}
const rtrimWord=d=>(String(d||'').trim().split(/\s+/)[0]||'').replace(/[:,.;]+$/,'');
export function clipUsageFindings({planMedia=[],html='',rows=[],durations={},limitations=[]}){
 const out=[],said=JSON.stringify(limitations||[]),cut=cutoutSources(planMedia);
 const page=String(html);
 for(const m of planMedia.filter(m=>m.status==='succeeded'&&m.file&&VISUAL.includes(m.kind))){
  const files=[m.file,...(m.more_files||[]),...(cut[m.file]||[])].filter(Boolean);
  if(files.some(f=>said.includes(f)))continue;
  const used=files.filter(f=>page.includes(f)||rows.some(r=>r.src===f));
  if(!used.length){out.push({code:'bought_media_unused',message:`${m.file} (${m.kind.replace('_',' ')}: ${String(m.description||'').slice(0,60)}) was bought for this video but is not in it.`,
   fixHint:'Place it where the plan put it, or report_limitation naming the file and why it cannot be used.'});continue;}
  if(m.kind==='talking_take'||m.kind==='talking_shot'||m.kind==='ugc_take'){
   const d=durations[m.file];if(!Number.isFinite(d)||d<=0)continue;
   const shown=rows.filter(r=>r.src===m.file||(cut[m.file]||[]).includes(r.src)).reduce((n,r)=>n+Math.max(0,r.end-r.start)*(Number(r.playbackRate)||1),0);
   const need=m.kind==='talking_shot'?0.8:0.9;
   if(shown<d*need)out.push({code:'talking_clip_cut_short',message:`${m.file} is ${d.toFixed(1)} s of the presenter speaking but only ${shown.toFixed(1)} s of it is in the video.`,
    fixHint:m.kind!=='talking_shot'?'A talking take carries the whole script: give it slots covering its full length (cutaways over it are fine; keep its audio).':'Give the talking shot a slot for most of its length.'});
  }
 }
 return out.slice(0,6);
}
