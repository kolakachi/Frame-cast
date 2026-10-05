// The checks on the finished video (todo D), run on the final encode after render, not on the source clips: an
// action trimmed out in the edit is a miss. Each check is pass, fail or unverified, with times as evidence. Blocking
// checks are the approved content and technical faults: lost words, a missing required item, a person who changes
// between shots, unintended blank frames, a planned move left out. Polish (lettering in the picture, a directed action
// that is unclear) is advisory. A check that could not run is "unverified" and never counts as a pass.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {readFile,mkdtemp,rm} from 'node:fs/promises';import {tmpdir} from 'node:os';
const run=promisify(execFile);

/** Stretches of (near) black picture: [{start, end}]. */
export async function blankSpans(file,ffmpeg='ffmpeg'){
 const {stderr}=await run(ffmpeg,['-hide_banner','-nostats','-i',file,'-an','-vf','blackdetect=d=0.2:pic_th=0.97:pix_th=0.08','-f','null','-'],{timeout:120000,maxBuffer:16*1024*1024});
 return [...stderr.matchAll(/black_start:([\d.]+)\s+black_end:([\d.]+)/g)].map(m=>({start:+Number(m[1]).toFixed(2),end:+Number(m[2]).toFixed(2)}));
}

/** Blank picture nobody asked for: anything but a short fade at the very start or the very end. */
export function unintendedBlanks(spans,duration){
 return spans.filter(s=>!(s.start<=0.05&&s.end<=0.6)&&!(duration&&s.end>=duration-0.05&&s.end-s.start<=0.6));
}

/** Frames spread over the video for the look check, plus extra [{time, label}] (a shot's own frames): [{time, path, label?}]. */
export async function sampleFrames(file,duration,{count=16,extra=[],ffmpeg='ffmpeg'}={}){
 const dir=await mkdtemp(tmpdir()+'/final-frames-'),n=Math.max(4,Math.min(count,Math.round(duration*1.2)));
 const times=[...Array.from({length:n},(_,k)=>({time:+((duration*(k+0.5))/n).toFixed(2)})),...extra];
 const out=[];
 for(const [k,f] of times.entries()){const p=dir+'/f'+k+'.jpg';try{await run(ffmpeg,['-hide_banner','-loglevel','error','-y','-ss',String(f.time),'-i',file,'-frames:v','1','-vf','scale=384:-2','-q:v','6',p],{timeout:30000});out.push({...f,path:p});}catch{/* a frame that cannot be read is left out */}}
 return {frames:out,cleanup:()=>rm(dir,{recursive:true,force:true})};
}

/**
 * Where each generated shot plays in the composition (D2): [{shot, start, end}], shot numbered in plan-media order
 * among generated shots (as the look check numbers them). html: the composition's index.html.
 */
export function shotWindows(html,planMedia=[]){
 const out=[];
 planMedia.filter(m=>m.kind==='generated_shot').forEach((m,k)=>{
  if(m.status!=='succeeded'||!m.file)return;
  for(const t of String(html||'').matchAll(/<video\b[^>]*>/gi)){
   const a=Object.fromEntries([...t[0].matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|'([^']*)')/g)].map(x=>[x[1].toLowerCase(),x[3]??x[4]]));
   const start=Number(a['data-start']),dur=Number(a['data-duration']);
   if(a.src===m.file&&Number.isFinite(start)&&dur>0){out.push({shot:k+1,start,end:start+dur});break;}
  }
 });
 return out;
}

/** Three frames inside each shot's window (early, middle, late), for its directed action: [{time, label}]. */
export function shotFrames(windows,duration,max=6){
 return windows.slice(0,max).flatMap(w=>[0.2,0.5,0.85].map(p=>({time:+Math.min(duration-0.05,w.start+(w.end-w.start)*p).toFixed(2),label:'shot '+w.shot})));
}

const at=t=>Number.isFinite(t)?'At '+Number(t).toFixed(1)+' s: ':'';

/**
 * Combines the measurements into checks. look: the vision verdict ({status:'checked'|'unverified', required, identity,
 * lettering, actions}); audio: the listening summary; moves: missing planned moves; blanks: unintended blank spans.
 */
export function finalVerdict({plan={},look=null,audio=null,moves=[],blanks=[],expectsSpeech=false,generatedPeople=false}){
 const checks=[];
 const add=(id,label,status,blocking,message,times=[])=>checks.push({id,label,status,blocking,message,times});
 // Approved words (narration or a take) in the delivered audio.
 if(expectsSpeech){
  if(!audio)add('words','Every approved word is spoken','unverified',true,'The final audio could not be listened to.');
  else{const ok=(audio.script_coverage??0)>=0.9&&!(audio.missing||[]).length;
   add('words','Every approved word is spoken',ok?'pass':'fail',true,ok?'':'Missing from the narration: "'+((audio.missing||[])[0]??'some words')+'".');}
 }
 // The agreement's required items, by sight (spoken items are settled by listening).
 const required=plan.agreement?.required||[];
 if(required.length){
  if(look?.status!=='checked')add('required','Everything that must appear is in the video','unverified',true,'The final video could not be looked at.');
  else for(const [k,item] of required.entries()){
   // The plan's own items decide; an item the look did not answer is unverified, an extra one it invented is ignored.
   const key=x=>String(x||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
   const r=(look.required||[]).find(x=>key(x.item)===key(item))??(look.required||[])[k]??{status:'unclear'};
   if(r.status==='missing')add('required','Must appear: '+item,'fail',true,'Not seen in the final video'+(r.note?' ('+r.note+')':'')+'.');
   else add('required','Must appear: '+item,r.status==='present'?'pass':'unverified',true,r.status==='present'?'':'Could not be confirmed by sight'+(r.note?': '+r.note:'')+'.',r.time!=null?[r.time]:[]);
  }
 }
 // The same people throughout (when generated people and their cast exist).
 if(generatedPeople){
  if(look?.status!=='checked'||!look.has_cast)add('identity','The same people throughout','unverified',true,'Identity could not be compared with the approved cast.');
  else if(look.identity?.status==='drift')add('identity','The same people throughout','fail',true,'A person looks different from the approved cast'+(look.identity.note?': '+look.identity.note:'')+'.',look.identity.times||[]);
  else add('identity','The same people throughout',look.identity?.status==='consistent'?'pass':'unverified',true,'');
 }
 // Lettering baked into generated pictures: advisory (real packaging and overlays are fine).
 if(look?.status==='checked'&&look.lettering?.status==='garbled')add('lettering','No garbled lettering in the picture','fail',false,'Garbled letters or a fake logo'+(look.lettering.note?': '+look.lettering.note:'')+'.',look.lettering.times||[]);
 // Directed actions of generated shots: advisory unless the agreement requires them (handled above).
 for(const a of look?.status==='checked'?look.actions||[]:[])if(a.status==='missing')add('action-'+a.shot,'Shot '+a.shot+' shows its action','fail',false,'Its directed action is not visible'+(a.note?': '+a.note:'')+'.',a.time!=null?[a.time]:[]);
 // Unintended blank frames: a technical fault.
 if(blanks.length)add('blank','No blank frames','fail',true,'The picture goes blank'+(blanks.length>1?' '+blanks.length+' times':'')+'.',blanks.map(b=>b.start));
 // Planned moves the plan named (a reference move, the signature move): blocking.
 for(const m of moves)add('move','Planned move: '+(m.move||m.code||'move'),'fail',true,String(m.message||'A planned move is missing.'),m.time!=null?[m.time]:[]);
 const blocked=checks.some(c=>c.blocking&&c.status==='fail'),unverified=checks.some(c=>c.blocking&&c.status==='unverified');
 return {status:blocked?'blocked':unverified?'unverified':checks.some(c=>c.status==='fail')?'issues':'passed',checks:checks.slice(0,24),
  findings:checks.filter(c=>c.status==='fail').map(c=>at(c.times?.[0])+c.label+(c.message?': '+c.message:'')).slice(0,8)};
}

/** Runs the measurements on the final file and returns the verdict. look(frames) sends frames to the app's vision check. */
export async function finalChecks({file,duration,plan={},planMedia=[],audioSummary=null,moves=[],look,html='',ffmpeg='ffmpeg'}){
 let blanks=[];try{blanks=unintendedBlanks(await blankSpans(file,ffmpeg),duration);}catch{/* measured as unverified below via no evidence */}
 const generated=planMedia.some(m=>['generated_shot','ugc_take'].includes(m.kind)&&m.status==='succeeded');
 const generatedPeople=generated&&(planMedia.some(m=>m.kind==='ugc_take'&&m.status==='succeeded')||planMedia.some(m=>m.kind==='reference_sheet'));
 const needsLook=generated||(plan.agreement?.required||[]).length>0;
 let verdict=null;
 if(needsLook&&look){
  const {frames,cleanup}=await sampleFrames(file,duration,{ffmpeg,extra:shotFrames(shotWindows(html,planMedia),duration)});
  try{verdict=await look(await Promise.all(frames.map(async f=>({time:f.time,label:f.label,jpeg:await readFile(f.path)}))));}catch{verdict=null;}finally{await cleanup();}
 }
 const expectsSpeech=plan.settings_audio!=='silent'&&((plan.narration||[]).length>0||planMedia.some(m=>m.kind==='ugc_take'&&m.status==='succeeded'));
 return finalVerdict({plan,look:verdict??{status:'unverified'},audio:audioSummary,moves,blanks,expectsSpeech,generatedPeople});
}

/**
 * What the build can fix itself after a blocked final check: a required item not in view, blank frames, a planned
 * move left out, narration cut short when it is our own narration file. A person who changed, or a take's own words,
 * need a new clip: those go to the user.
 */
export function repairable(verdict,{takeUsed=false}={}){
 return (verdict?.checks||[]).filter(c=>c.blocking&&c.status==='fail'&&(['required','blank','move'].includes(c.id)||(c.id==='words'&&!takeUsed)));
}

/** The repair round's brief to the builder: only these, with their times; everything else stays. */
export function repairBrief(fixable){
 return 'The finished video was checked against the approved plan and must be fixed before delivery. Fix only these, keep everything else as it is, check, then finish: '
  +fixable.map(c=>(c.times?.length?'at '+c.times[0]+' s, ':'')+c.label+(c.message?' ('+c.message+')':'')).join('; ')+'.';
}
