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
