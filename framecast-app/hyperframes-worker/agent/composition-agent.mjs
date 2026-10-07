import {skillCatalogue,readSkill} from './skill-library.mjs';
import {copyFile,readFile,readdir,writeFile} from 'node:fs/promises';
import {Workspace,digest} from './workspace.mjs';
import {runAgent} from './runner.mjs';
import {accountedCall} from './accounted-call.mjs';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';
import {loadCatalog,searchCatalog,catalogItem} from './registry.mjs';
import {loadArt,searchArt,useArt} from './art-library.mjs';
import {cardRoute,loadCards,readCard,availableCards} from './cards.mjs';
import {criticSystem,criticMessages,parseCriticVerdict} from './critic.mjs';
import {voiceTiming} from './narration-timing.mjs';
import {mapThrough} from './transcript-map.mjs';
import {exactScaffold} from './scaffold.mjs';
import {googleFamilies,namedFamilies,fetchBrandFonts} from './brand-fonts.mjs';

// The instruction only carries the sentences this run can use: a sentence about a thing the run does not have is context spent on nothing.
const INSTRUCTION_GATES=[[/\bnarrationTiming\b/,'narrationTiming'],[/\blookOnly\b/,'lookOnly'],[/\bfromLook\b/,'fromLook'],[/\bstyleNotes\b/,'styleNotes'],[/\bbaseFiles\b/,'baseFiles'],[/\bresumed\b/,'resumed'],[/\bpreparedAudio\b/,'preparedAudio'],[/\bscaffold\b/,'scaffold'],[/run media op duck|End the music with a fade|run that media op space/,'rawAudio'],[/\bmusicBeats\b/,'musicBeats'],[/talking_shot|talking_take/,'talking'],[/character_poses/,'poses'],[/\bhouseStyle\b/,'houseStyle'],[/web page reference/,'page'],[/\bsfx\b/,'sfx'],[/narration \(|includes narration|has narration/,'narration'],[/music file|Sound mix/,'music'],[/caption_text/,'captions'],[/footage has speech/,'speech'],[/When a plan is present/,'plan']];
// A model that is busy or briefly unreachable (the app confirms nothing was sent or charged) is waited out
// and asked again, instead of ending the build. Any other failure is passed on unchanged.
export const BUSY_WAITS_MS=[20000,60000,120000];
// Thorough builds are reviewed before they finish (owner, 2026-10-06): the author's visual review, then the separate
// reviewer for up to its approved rounds, stopping when two rounds in a row do not improve. Quick and Standard deliver
// after the author's own checks, and the user reviews the result. A storyboard (look) run is never held for it.
export function reviewedBuild(input){
 return input?.mode==='agent'&&!!input.execution_policy?.critic&&input.settings?.effort==='thorough'&&input.look_first!==true;
}
export async function whenModelFree(call,{signal,waits=BUSY_WAITS_MS,release,onWait,stop=()=>false}={}){
 for(let i=0;;i++){
  try{return await call();}
  catch(e){
   if(e.code!=='NOT_SENT')throw e;
   await release?.();
   if(i>=waits.length||signal?.aborted||stop())throw e;
   onWait?.(i+1,waits[i]);
   // Waits end early on Stop (the not-sent error is passed on) or on abort.
   await new Promise((resolve,reject)=>{const end=Date.now()+waits[i];const t=setInterval(()=>{if(stop()){clearInterval(t);reject(e);}else if(Date.now()>=end){clearInterval(t);resolve();}},Math.min(1000,waits[i]));signal?.addEventListener('abort',()=>{clearInterval(t);reject(signal.reason??Error('Aborted'));},{once:true});});
  }
 }
}

export function trimInstruction(text,flags){
 return text.split('. ').filter(sentence=>{const gate=INSTRUCTION_GATES.find(([re])=>re.test(sentence));return !gate||flags[gate[1]]!==false;}).join('. ');
}

// Dependencies are host-owned. Neither a prompt nor a tool result chooses the
// provider, accounting policy, filesystem root or executable.
// The kit guides this plan needs, pinned up front so the builder starts with them instead of spending calls reading
// them: the 3D guide for a 3D mascot or objects, the motion kit for reference moves. The long worked examples stay
// readable on demand.
export async function pinnedKits(guidanceDirectory,plan){
 const kits=[];
 if(plan?.mascot3d||plan?.props3d?.length)kits.push(['kit/three.md','three-kit.md']);
 // From scratch the kit is the vocabulary (camera, rhythm and readout moves with their energy), so it is pinned too.
 if((plan?.reference_decisions||[]).some(d=>d?.move)||(plan?.reference_systems||[]).some(x=>x?.move)||typeof plan?.scratch_guide==='string')kits.push(['kit/motion-kit.md','motion-kit.md']);
 let out='';
 for(const [name,file] of kits)try{out+='\n\n# '+name+' (pinned; do not read it again)\n'+await readFile(guidanceDirectory+'/../'+file,'utf8');}catch{/* readable on demand */}
 if(out)out+='\n\nThe worked examples (kit/three-example.html, kit/reference-moves.html) are long: read one only when something you built does not work.';
 // From scratch: the approved concept, format playbook and motion voice (written by the app from the plan).
 if(typeof plan?.scratch_guide==='string')out+=plan.scratch_guide.slice(0,6000);
 return out;
}
/** The user's own voice recording and music track, added as the narration and the bed when the plan bought neither. */
export function withOwnAudio(planMedia=[],manifest=[]){
 const own=kind=>(manifest||[]).find(f=>f.purpose==='source'&&f.asset_type==='audio'&&f.kind===kind&&f.name);
 let out=planMedia;
 if(own('voice')&&!out.some(m=>['voiceover','cloned_voiceover','ugc_take'].includes(m.kind)&&m.status==='succeeded'&&m.file))out=[...out,{kind:'voiceover',status:'succeeded',file:own('voice').name,own:true}];
 if(own('music')&&!out.some(m=>m.kind==='music'&&m.status==='succeeded'&&m.file))out=[...out,{kind:'music',status:'succeeded',file:own('music').name,own:true}];
 return out;
}
export async function executeCompositionAgent({directory,input,manifest,planMedia=[],callPrefix='agent',provider,begin,settle,bindPrediction,receipt,invoke,transcribe,buy,guidanceDirectory,signal,stopRequested=()=>false,onProgress,onTrace}) {
 const assets=[];
 for(const file of manifest){
  // Reference-only media is described in context, never made renderable.
  if(file.purpose!=='source')continue;
  await copyFile(directory+'/inputs/'+file.path,directory+'/project/'+file.name);
  assets.push({path:file.name,sha256:file.sha256});
 }
 // An edit starts from the version being edited; a resumed build from the draft of the build it continues.
 const seed=input.resume?.files??input.base_bundle??null;
 if(seed)for(const [name,text] of Object.entries(seed))if(/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(name))await writeFile(directory+'/project/'+name,text,{mode:0o600});
 // A resumed draft brings the files its code uses (derived audio, rendered clips); they are protected like inputs.
 for(const m of input.resume?.media??[])if(/^[A-Za-z0-9_.-]+$/.test(m.name)){await copyFile(m.path,directory+'/project/'+m.name);}
 // Brand fonts the brief or brand kit names are fetched into the project (GTM-1 #5 fell back to Inter for DM Sans).
 let brandFonts=[];
 try{
  const text=(input.messages??[]).filter(m=>m.role==='user').map(m=>m.content).join('\n');
  const kit=planMedia.flatMap(m=>m?.brand?.fonts??[]);
  const families=namedFamilies(text,kit,(text.trim()||kit.length)?await googleFamilies():[]);
  if(families.length)brandFonts=await fetchBrandFonts(families,directory+'/project');
 }catch{/* without them the bundled faces are used, as before */}
 // Fixed runtime assets are protected alongside uploaded source bytes.
 for(const name of (await readdir(directory+'/project')).filter(n=>/\.(svg|png|jpg|webp|mp4|mp3|wav|ttf)$/.test(n))){
  if(!assets.some(a=>a.path===name))assets.push({path:name,sha256:digest(await readFile(directory+'/project/'+name))});
 }
 // Bought music gets a beat grid up front, so cuts and pops can land on the beat.
 let musicBeats=null;
 // The user's own sound: their voice recording is the narration and their track the music bed, exactly where bought
 // ones would be (beat timing, beat grid, ducking, fade, checks), unless the plan bought its own. Their voice is never
 // sped up or re-spaced.
 planMedia=withOwnAudio(planMedia,manifest);
 const music=planMedia.find(m=>m.kind==='music'&&m.status==='succeeded'&&m.file);
 if(music&&invoke)try{const r=await invoke('media',{op:'beats',input:music.file,signal});if(r?.ok&&r.beats)musicBeats={file:music.file,...r.beats};}catch{/* the build works without it */}
 // Kept beside the run so it is clear afterwards whether the agent had a grid.
 if(music)await writeFile(directory+'/music-beats.json',JSON.stringify(musicBeats??{error:'no grid'},null,1)).catch(()=>{});
 // The bought narration, transcribed up front: beats are timed by its words, and a voice that ends early gets its
 // own pauses lengthened instead of a long silent hold at the end.
 let narrationTiming=null;const initialTranscripts={};
 // A UGC take is the voice track; a cloned narration bought for a lip-synced take is already inside it.
 const voice=planMedia.some(m=>m.kind==='ugc_take'&&m.status==='succeeded'&&m.file)?null:planMedia.find(m=>['voiceover','cloned_voiceover'].includes(m.kind)&&m.status==='succeeded'&&m.file);
 if(voice&&transcribe&&input.look_first!==true&&input.plan?.scenes?.length)try{
  const t=await transcribe({input:voice.file,signal});
  const words=(t?.words||[]).map(w=>[w.text,Number(w.start),Number(w.end)]);
  if(words.length){
   initialTranscripts[voice.file]=words;
   narrationTiming={file:voice.file,...voiceTiming({scenes:input.plan.scenes,lines:input.plan.narration||[],words,clipStart:0.3,videoSeconds:Number(input.settings?.duration_seconds)||30})};
   await writeFile(directory+'/narration-timing.json',JSON.stringify(narrationTiming,null,1)).catch(()=>{});
  }
 }catch{/* the build works without it */}
 // A UGC take is the voice: its own words time the beats (placed at 0 s; the builder keeps it whole).
 const take=planMedia.find(m=>m.kind==='ugc_take'&&m.status==='succeeded'&&m.file);
 if(take&&transcribe&&input.look_first!==true&&input.plan?.scenes?.length)try{
  const t=await transcribe({input:take.file,signal});
  const words=(t?.words||[]).map(w=>[w.text,Number(w.start),Number(w.end)]);
  if(words.length){
   initialTranscripts[take.file]=words;
   narrationTiming={file:take.file,take:true,...voiceTiming({scenes:input.plan.scenes,lines:input.plan.narration||[],words,clipStart:0,videoSeconds:Number(input.settings?.duration_seconds)||30})};
   delete narrationTiming.suggestion;
   await writeFile(directory+'/narration-timing.json',JSON.stringify(narrationTiming,null,1)).catch(()=>{});
  }
 }catch{/* the build works without it */}
 // The sound is prepared before the builder starts, so it places two finished files instead of spending its first
 // minutes editing audio: a voice that runs long is sped up a little (at most 1.25x), one that ends early gets the
 // suggested pauses, and the music is ducked under the fitted voice and faded out at the end.
 let preparedAudio=null;
 if(narrationTiming&&invoke&&transcribe&&input.mode==='agent')try{
  const media=async(op,file,params)=>{const r=await invoke('media',{op,input:file,params,signal});if(!r?.ok||!r.output)throw Error(r?.error||op+' gave no file');return r;};
  const video=Number(input.settings?.duration_seconds)||30,room=video-0.3-0.4;
  const spoken=voice||take;let file=spoken.file,words=initialTranscripts[spoken.file];const made=[];
  const last=Number(words.at(-1)?.[2])||0;
  // The edit's own time map carries the word times over (as the builder's transcript tool does): exact and free.
  const step=(r,op,params)=>{made.push({path:r.output,sha256:r.sha256,derivedFrom:file,operation:op,params,sourceMap:r.source_map??null});
   const mapped=mapThrough(words.map(([text,start,end])=>({text,start,end})),[{operation:op,params,sourceMap:r.source_map??null}]);
   file=r.output;words=mapped.map(w=>[w.text,w.start,w.end]);};
  if(narrationTiming.take||voice?.own){/* a take, or the user's own recording, is never sped up or re-spaced */}
  else if(last>room+0.05){const params={factor:+Math.min(1.25,last/room).toFixed(3)};step(await media('speed',file,params),'speed',params);}
  else if(narrationTiming.suggestion?.insert?.length){const params={insert:narrationTiming.suggestion.insert};step(await media('space',file,params),'space',params);}
  if(file!==spoken.file){
   if(!words.length)throw Error('The fitted narration lost its word times');
   initialTranscripts[file]=words;
   narrationTiming={file,...voiceTiming({scenes:input.plan.scenes,lines:input.plan.narration||[],words,clipStart:0.3,videoSeconds:video})};
   await writeFile(directory+'/narration-timing.json',JSON.stringify(narrationTiming,null,1)).catch(()=>{});
  }
  let bed=null;
  if(music){const d=await media('duck',music.file,{voice:file,voice_start:narrationTiming.take?0:0.3,music_start:0});made.push({path:d.output,sha256:d.sha256,derivedFrom:music.file,operation:'duck',params:{}});
   const f=await media('fade',d.output,{start:0,end:video,fade_in:0.05,fade_out:1});made.push({path:f.output,sha256:f.sha256,derivedFrom:d.output,operation:'fade',params:{},sourceMap:f.source_map??null});bed=f.output;}
  // Derived files are protected assets like the originals, with their lineage, so the builder places them as they are.
  for(const a of made)if(!assets.some(x=>x.path===a.path))assets.push(a);
  // A take carries its own voice in its picture: only the bed is prepared, and the take starts at 0.
  preparedAudio=narrationTiming.take?{take:file,take_start:0,...(bed?{music:bed}:{}),note:'Finished: the take is the voice (keep it whole, at 0 s); place the bed under it.'}:{narration:file,narration_start:0.3,...(bed?{music:bed}:{}),note:'Finished: place them, never run media ops on them.'};
  await writeFile(directory+'/prepared-audio.json',JSON.stringify(preparedAudio,null,1)).catch(()=>{});
 }catch(e){preparedAudio=null;await writeFile(directory+'/prepared-audio.json',JSON.stringify({error:String(e?.message||e).slice(0,400)},null,1)).catch(()=>{});/* the builder prepares the sound itself, as before */}
 // An exact copy starts from a scaffold of the reference layout instead of a blank page (see scaffold.mjs).
 let scaffold=null;
 if(!seed&&input.mode==='agent'&&input.plan?.reference_match==='exact'&&input.look_first!==true)try{
  const t=narrationTiming?initialTranscripts[narrationTiming.file]||[]:[],at0=preparedAudio?.take?0:(preparedAudio?.narration_start??0.3);
  const sc=exactScaffold({plan:input.plan,settings:input.settings,words:t.map(([text,s0,e0])=>({text,start:+(s0+at0).toFixed(2),end:+(e0+at0).toFixed(2)})),audio:preparedAudio});
  if(sc){for(const [name,text] of Object.entries(sc.files))await writeFile(directory+'/project/'+name,text,{mode:0o600});scaffold={beats:sc.beats,slots:sc.slots};}
 }catch(e){await writeFile(directory+'/scaffold-error.txt',String(e?.stack||e).slice(0,1000)).catch(()=>{});}
 const messages=input.messages??[];const messages0=messages;
 const paid=input.mode==='agent',settings=input.settings??{};
 if(!messages.length||!messages.every(m=>['user','assistant'].includes(m.role)&&typeof m.content==='string'))throw Error('Invalid frozen conversation');
 // Doctrine cards by the kind of video: pinned into the guidance; the others readable as cards/<name>.md.
 const route=cardRoute({stylePack:input.style_pack,plan:input.plan,brief:messages.at(-1).content,settings:input.settings??{}});
 const cards=await loadCards(guidanceDirectory,route);
 const otherCards=(await availableCards(guidanceDirectory)).filter(n=>!cards.names.includes(n));
 // An answered upload request points at its file in the project by name.
 const planWithFiles=input.plan?{...input.plan,asks:(input.plan.asks||[]).map(a=>a.asset_id?{...a,file:manifest.find(f=>f.asset_id===a.asset_id)?.name??null}:a)}:null;
 // The critic runs under its own approved policy: separate calls, low effort, no tools.
 const criticPolicy=input.execution_policy?.critic??null;let criticCall=0;
 const thoroughReview=reviewedBuild(input);
 // The last progress report, so a wait for a busy model can say so without losing the spend shown.
 let lastProgress={doing:'',spentUsd:0};const relay=p=>{lastProgress=p;onProgress?.(p);};
 const busyNote=()=>{try{onProgress?.({...lastProgress,doing:'The model is busy, trying again in a moment'});}catch{/* reporting never stops a build */}};
 let busy=0;
 const critic=paid&&criticPolicy&&provider.complete?async({sheet,strip,stripEvidence,detail,detailCells,compare,compareCells,authorScores,findings,round,signal})=>{busy=0;
  const messages=criticMessages({brief:messages0.at(-1).content,plan:planWithFiles,lookOnly:input.look_first===true,route,sheet,strip,stripEvidence,detail,detailCells,compare,compareCells,authorScores,findings,fingerprint:input.style_pack?.fingerprint??null,round});
  const args={prompt:'critic call '+(++criticCall),system:criticSystem,maxTokens:Number(criticPolicy.max_output_tokens)||4096,messages,tools:[],signal};
  const out=await whenModelFree(async()=>{
  if(provider.reserve)try{await provider.reserve();}catch(e){if(e.code!=='BUDGET_EXHAUSTED')e.code='NOT_STARTED';throw e;}
  return accountedCall({key:'critic-'+criticCall+(busy++?'-'+busy:''),kind:'critic',input:{prompt:args.prompt,system:args.system,maxTokens:args.maxTokens,image:null,messagesJson:JSON.stringify(messages),toolsJson:'[]'},begin,settle,
   execute:attemptId=>provider.complete({...args,attemptId,recordPrediction:async()=>{},onPrediction:async id=>{if(!bindPrediction)throw Error('Prediction recorder is required');await bindPrediction(attemptId,id);}}),receipt});
  },{signal,release:provider.release,onWait:busyNote,stop:stopRequested});
  const text=Array.isArray(out.content)?out.content.filter(b=>b.type==='text').map(b=>b.text).join('\n'):(out.text||'');
  return parseCriticVerdict(text,{requirements:input.plan?.requirements??[],performance:input.plan?.character_performance??[],lookOnly:input.look_first===true});
 }:null;
 let call=0;
 const accountedProvider={id:provider.id,maxCallUsd:provider.maxCallUsd,complete:async args=>{
  if(provider.prepareImage && args.image)args={...args,image:await provider.prepareImage(args.image,args.signal)};
  // A failed reservation means no call was started: nothing to reconcile.
  return whenModelFree(async()=>{
  if(provider.reserve)try{await provider.reserve();}catch(e){if(e.code!=='BUDGET_EXHAUSTED')e.code='NOT_STARTED';throw e;}
  return accountedCall({
  // The hash covers exactly what the gateway will hash: tool-mode history and tools as strings.
  key:callPrefix+'-'+(++call),kind:'agent',input:{prompt:args.prompt,system:args.system,maxTokens:args.maxTokens,image:args.image??null,...(args.messages?{messagesJson:JSON.stringify(args.messages),toolsJson:JSON.stringify(args.tools??[])}:{})},begin,settle,
  execute:attemptId=>provider.complete({...args,attemptId,recordPrediction:args.onPrediction,onPrediction:async id=>{
   // Record the provider identity in both app accounting and the local journal.
   if(!bindPrediction)throw Error('Prediction recorder is required');
   await bindPrediction(attemptId,id);await args.onPrediction(id);
  }}),receipt,
 });},{signal:args.signal,release:provider.release,onWait:busyNote,stop:stopRequested});}};
 const dims=({'9:16':[1080,1920],'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio??'9:16'];
 const unlimited=input.execution_policy?.agent?.unlimited===true;
 const workspace=new Workspace(directory+'/project',assets,directory+'/work',1_000_000);
 // The vendored registry catalogue: exact names return the item in full; words or a tag rank the rest.
 const catalog=await loadCatalog(guidanceDirectory+'/../../runtime/registry-catalog.json').catch(()=>[]);
 const catalogTool=async({query})=>{
  const q=String(query||'').trim();
  const exact=catalogItem(catalog,q.toLowerCase());
  if(exact)return {item:exact};
  const tagged=q.match(/^tag:\s*([a-z0-9-]+)$/i);
  const results=tagged?searchCatalog(catalog,{tag:tagged[1],limit:20}):searchCatalog(catalog,{query:q,limit:12});
  return {results:results.map(({variables,...r})=>({...r,variables:variables.map(v=>v.id)})),hint:results.length?'Call catalog with an exact name for its variables, mount and usage header.':'No match; try other words, tag:<tag>, or hand-build it.'};
 };
 // The art library: icons to inline and 3D objects to place, instead of hand-drawing or generating a generic visual.
 const art=await loadArt(guidanceDirectory+'/../../runtime/art-packs').catch(()=>null);
 const artTool=async({query,style,use})=>{
  if(use)return await useArt(art,use,directory+'/project');
  const results=searchArt(art,{query,style,limit:12});
  return {results,hint:results.length?'Call art again with use: an id to get it.':'No match; try other words, or draw it yourself.'};
 };
 // A captured web page is shown to the agent as its first image, so it can rebuild the brand's real screens.
 let initialImage;
 const page=manifest.find(f=>f.purpose==='reference'&&f.asset_type==='image'&&f.reference?.from==='page');
 if(page){try{const bytes=await readFile(directory+'/inputs/'+page.path);if(bytes.length<=1000000)initialImage='data:image/jpeg;base64,'+bytes.toString('base64');}catch{/* the notes still describe it */}}
 // Each pass has its own state (a repair round is a new pass over the same files, not a resume of the first).
 const state=await runAgent({baseSources:editBaseSources(input),initialImage,stopRequested,initialTranscripts,onProgress:relay,onTrace,stateFile:directory+'/agent-state'+(callPrefix==='agent'?'':'-'+String(callPrefix).replace(/[^a-z0-9-]/gi,''))+'.json',workspace,provider:accountedProvider,
  context:{renderAdapters:[{engine:'remotion',tool:'run',guide:'kit/remotion.md',purpose:'Native React motion clips/stills with editable .js source; integrate outputs into the existing Hyperframes composition. No automatic HTML conversion.'}],skillLibrary:await skillCatalogue(guidanceDirectory,{route,speech:planMedia.some(m=>['voiceover','cloned_voiceover','talking_take','talking_shot'].includes(m.kind))||manifest.some(f=>f.purpose==='source'&&f.asset_type==='audio')}),colourTreatment:input.plan?.colour_treatment??null,brief:messages.at(-1).content,messages,previousReview:input.base_review??null,baseRevision:input.base_revision_id,
   // An edit starts with the current source in hand, so no calls go on reading it (bounded; larger bundles are read on demand).
   baseFiles:seed&&Object.values(seed).reduce((n,t)=>n+Buffer.byteLength(String(t)),0)<=30000?seed:null,
   resumed:input.resume?{checked:input.resume.checked,note:input.resume.checked?'The project files are its last draft that passed the check.':'The project files are its latest draft, which had not passed the check yet.'}:null,
   // An edit of an existing version changes it with patches; only a redesign may rewrite it.
   editOnly:!!seed&&!input.from_look&&!/\b(redesign|start over|from scratch|completely new|brand new|totally different)\b/i.test(String(messages.at(-1)?.content||'')),
   // Design first: a look run makes one still per beat for approval; the approved look is then the base for the motion build.
   lookOnly:input.look_first===true,fromLook:input.from_look===true,
   toolMode:input.execution_policy?.agent?.provider!=='replicate'&&(input.execution_policy?.agent?.tool_mode===true||process.env.CREATE_TOOL_MODE==='1'),
   styleNotes:Array.isArray(input.style_notes)&&input.style_notes.length?input.style_notes.slice(0,10):null,
   // A face kit names its patches by asset; the builder gets the staged file names, ready for WyvMascot.face.
   assets:manifest.map(({storage_path,path,...file})=>({...file,renderable:file.purpose==='source',
    ...(file.face_kit?{face_kit:{base:file.name,width:file.face_kit.width,height:file.face_kit.height,
     patches:(file.face_kit.patches||[]).map(p=>({name:p.name,file:manifest.find(f=>f.asset_id===p.asset_id)?.name,x:p.x,y:p.y,w:p.w,h:p.h})).filter(p=>p.file)}}:{})})),
   plan:planWithFiles,
   intentPolicy:'Infer the intended output from the full brief and approved plan.creative_intent, not topic keywords. Education about UGC does not request an avatar. Motion recipes, source skills and style packs are subordinate to intent; static slides, calm footage, stable talking heads and simple cuts are valid. For timing-only edits preserve narration, voice, copy and existing media, and align scene visibility and animation to measured speech without stacking timeline remappers.',
   characterPerformancePolicy:'plan.character_performance lists active character actions and their times. Implement and inspect each action; moving a still image is not facial/body articulation. The user explicitly left out plan.omitted_character_performance, which overrides earlier brief/requirement wording for those actions only. Do not buy media solely to restore omitted actions. In a storyboard defer motion honestly; in a full video, report missing or unverified performance instead of claiming it is complete.',
   planMedia,
   musicBeats,narrationTiming,preparedAudio,scaffold,
   houseStyle:input.style??null,
   stylePack:input.style_pack?{route:input.style_pack.route,name:input.style_pack.name,rules:input.style_pack.rules,fingerprint:input.style_pack.fingerprint||null,
    exampleFiles:Object.keys(input.style_pack.example??{}).map(f=>'style-example/'+f),
    howToUse:'This is the craft the build starts from, not a template. Follow its rules. If exampleFiles are listed, read style-example/index.html once to learn its technique; never copy its layout wholesale. The fingerprint describes the example on six points: structure, opening, signature shot, camera path, score shape and ending. Use the fingerprint to identify techniques the brief asks to preserve and content it asks to replace; do not impose a numerical divergence quota. In your finish summary say which techniques you retained and how you changed the content. Explicit user colour/type direction and the approved plan take precedence over saved defaults; respect explicit brand locks and ask about conflicts. Borrowing reference motion does not automatically mean borrowing its palette.'}:null,
   variantDirection:input.variant_direction??null,approvedFacts:[...(settings.approved_facts??[]),...(input.plan?.on_screen_copy??[])],settings,output:{width:dims[0],height:dims[1],durationSeconds:settings.duration_seconds??15},
   registry:catalog.length?{items:catalog.length,howToUse:'Search with the catalog action before hand-building any named visual; read kit/registry.md once before wiring an item.'}:null,
   // The brief or plan asks for icons, 3D objects or illustrations: the build is expected to look in the library first.
   artExpected:!!art?.items?.length&&/\b(icons?|3d|emoji|illustrations?|stickers?)\b/i.test(JSON.stringify(input.plan?.scenes??[])+' '+JSON.stringify(input.plan?.callouts??input.plan?.on_screen_copy??[])+' '+(input.messages??[]).filter(m=>m.role==='user').map(m=>m.content).join(' ')),
   art:art?.items?.length?{items:art.items.length,howToUse:'For a generic object or icon (coins, a brain, a calendar, a checkmark, a shop), search the art action and use what it finds before drawing or generating one. Brand visuals come from the user\'s files.'}:null,
   cards:{route,pinned:cards.names,onDemand:otherCards.map(n=>'cards/'+n+'.md')},
   runtimeFiles:[{path:'wyv-3d.js',purpose:'3D inside the composition (load after gsap.min.js and three-wyv.js): W3D.mascot draws plan.mascot3d on a canvas (talking on the narration, blinking, winking, turning), W3D.prop draws an object modelled in code (with the product spin), W3D.clock(tl, duration) drives them from the timeline. The default for 3D. Read kit/three.md, worked example kit/three-example.html.'},{path:'three-wyv.js',purpose:'three.js with the mascot and prop builders as one browser script; load before wyv-3d.js.'},{path:'wyv-mascot3d.js',purpose:'Parametric 3D mascot for Remotion clips (import {Mascot3D} from \'./wyv-mascot3d.js\'): a character from plan.mascot3d.spec, rigged by construction (head turn, blink, wink, gaze, mouth on the words, expressions), clay, ordered-dither or toon finish; and Prop3D, shapes and spinAt for 3D objects modelled in code in the same finishes. Read kit/remotion.md, sections 3D characters and 3D props.'},{path:'wyv-mascot.js',purpose:'Character performance on the Hyperframes timeline: WyvMascot.face plays a face kit (an asset with face_kit) as a talking, blinking face with expressions; WyvMascot.attach drives a prepared layered-SVG rig (blinks, gaze, head tilt, four mouths). Not an image-to-rig converter: a face kit comes from an expression sheet. Read kit/mascot.md first. Never substitute the fixture for an approved character.'},{path:'barty-motion.js',purpose:'Optional pinned Barty spring/shape engine. Load with barty-hyperframes.js after GSAP; read kit/barty.md first. One full-frame scene; no standalone render commands.'},{path:'barty-hyperframes.js',purpose:'Barty to Hyperframes timeline bridge; approved colours remain authored inputs.'},{path:'gsap.min.js',purpose:'Local GSAP runtime'},{path:'wyv-motion.js',purpose:'WyvStudio motion kit, load after gsap.min.js: spring eases, cursor, button press, typing, toggle, counter, shape morph and scene transitions (whip, push, wipe, light leak). Read kit/motion-kit.md for the API before using it.'},{path:'font.ttf',purpose:'DejaVu Sans, plain fallback'},
    {path:'inter.ttf',purpose:'Inter, variable weight 100-900: clean modern sans for body and bold headlines'},
    {path:'anton.ttf',purpose:'Anton, heavy condensed display: punchy ad headlines'},
    {path:'bebas-neue.ttf',purpose:'Bebas Neue, tall all-caps display'},
    {path:'playfair.ttf',purpose:'Playfair Display, variable weight 400-900: editorial serif'},
    {path:'space-grotesk.ttf',purpose:'Space Grotesk, variable weight 300-700: techy geometric sans'},
    {path:'caveat.ttf',purpose:'Caveat, variable weight 400-700: handwritten accents'},
    ...brandFonts.map(({path,purpose})=>({path,purpose}))],
   fontNote:'Load fonts with @font-face using these exact file names; declare font-weight ranges for variable fonts (for example font-weight:100 900 for Inter) so bold renders truly bold.',
   instruction:trimInstruction('narrationTiming, when present, is the beat sheet timed by the bought narration placed at 0.3 s (narrationTiming.take: by the UGC take\'s own words, the take placed whole at 0 s): start each beat at its voiced time, and mark each beat\'s container with data-beat set to its scene label and that data-start, because the check measures beats against the words they start on (plan.scenes[].starts_on). plan.reference_pacing, when present, is the reference\'s rhythm: hold each shot about average_shot_seconds (about cuts_per_10_seconds changes every 10 s), show text about text_to_speech_delay_seconds after its words are said, and when cuts_on_beat is 0.5 or more land the changes on the music\'s beats. If narrationTiming.suggestion is present the voice ends well before the video: run that media op space on the narration file, call transcript on the result and use it as the one narration clip, then re-time the beats from it (the check reads the new transcript). An asset with rig.ready true is the user\'s own character drawn in layers for the prepared rig: read kit/mascot.md, place it with <div id="a-name" data-rig-src="its file name"></div> (it becomes the inline SVG with its data-rig-part layers whenever the page is checked or rendered) and animate blinks, gaze, small head moves and mouth shapes with WyvMascot.attach on that id; never redraw or swap its artwork, move the whole figure with an outer wrapper, and use bought clips for speech. plan.asks are real things the user was asked for: one with a file is the user\'s own upload (in assets by that name), so use it on its beat instead of a rebuilt or illustrative version, and inspect it first; one marked skipped, or with no file, uses its fallback. plan.reference_decisions lists each moment of the reference to keep or change and the beat that carries it (moment ids are asset:m#, with times in the reference): build every kept or changed moment in its beat, and inspect the reference at that moment first to see the technique (a sticker, a counter, text typing on, a quick zoom). A dropped moment\'s carried_by names what now does its job; build that too. plan.reference_systems are the reference\'s recurring elements (a Step card, a UI panel, a caption style), each with one spec and the beats where it appears, plus how the reference version looks, enters, holds and leaves: build each kept or adapted system once as a reusable piece (one class or function) and use it at every listed beat, so every occurrence looks and moves the same. A system\'s move (and a kept moment\'s move in plan.reference_decisions) names the wyv-motion.js recipe that reproduces the reference\'s motion: iris is WM.iris, through is WM.through, device is WM.device, write_on is WM.writeOn, words is WM.words, and toss, pop, fly, stamp, type, count, cursor, press, morph, whip, wipe and push are WM functions of the same name. Load wyv-motion.js after gsap.min.js and build that element with its recipe (kit/motion-kit.md, pinned in your guidance when the plan names moves); kit/reference-moves.html is a worked 15-second example of every reference move, for when one does not work. A named move the composition never calls is sent back at finish. An asset with face_kit is a character\'s talking face: put an empty element with a width where the character appears chest-up and call WyvMascot.face(tl, element, {kit: that face_kit, src: \'\', at, duration, words, expressions}) after loading wyv-mascot.js; take words from narrationTiming (seconds relative to at) so the mouth follows the voice, add a wink, smile or laugh on the beats that call for one, and read kit/mascot.md for the rest. Use the attached body poses (by title) for gestures and come back to the talking face. When plan.mascot3d is present, the character is that 3D mascot and nothing else: draw it with W3D.mascot on a canvas in each slot where it appears (plan.mascot3d.spec as given), with the narration\'s words for its mouth and its expressions on the planned beats (kit/three.md, pinned in your guidance; worked example kit/three-example.html, for when something does not work); in the look stage include a turnaround still of it at three angles (yaw -0.6, 0, 0.6) so the user approves the character. It takes precedence over any character image (a master preview, a pose, an inherited asset): never place a flat image of the character instead. plan.props3d are 3D objects to model in code with W3D.prop on a canvas in their slot (kit/three.md): write each one\'s build function from its looks line, in the mascot\'s finish (or the reference treatment), and start its spin (spin true) when its card or slot lands. When plan.reference_match is exact, the video is the reference moment for moment: plan.reference_layout lists every moment to copy; at its at time (seconds, the same timeline as the reference) build each of its elements in its box [x, y, width, height] (fractions of the frame) with the user\'s content, and mark each element with data-ref=\"<moment>:<element index>\" (several separated by spaces when one element fills slots in several moments). Look at the reference at each moment\'s time before building its beat (inspect_reference, mode frames, up to eight times per call). A layout check measures every marked element and sends back moments not looked at, elements not marked, missing or out of their slot. Build every element that fills a reference slot as HTML in the composition (not drawn inside a larger rendered clip, which cannot be measured); a 3D mascot or prop canvas (W3D) is HTML and carries its slot\'s data-ref; other clips are for effects that fill no slot. In the look stage of an exact copy, the stills are one per reference moment, each held at that moment\'s at time, so every moment is reviewed against the reference. When a page capture or product screenshot is attached, rebuild its real screens with their labels, layout and copy; never placeholder fields, grey bars or empty tiles. previousReview, when present, contains unresolved findings from the previous result. Address these alongside the latest brief; do not claim they are fixed without checking the new output. Storyboard approval does not waive these findings. colourTreatment, when present, is the approved palette: use roles for backgrounds, text and accents, preserve every locked role exactly, and apply usage across beats. It overrides saved palette defaults but never recolours supplied product media. Preserve it on unrelated edits. plan.scenes[].uses names the registry items chosen for that beat: mount each of them (catalog with its exact name first, for its entry and variables; kit/registry.md for the wiring); if you leave one out, say why in your summary. styleNotes, when present, are the user\'s own verdicts on earlier videos in this style: do what they liked and avoid what they did not. When lookOnly is true this run makes THE LOOK, not the motion: one still per beat, exactly each beat\'s state_out with its layout and field, held for the whole beat; every read visible; the character at its hero scale; no tweens and no audio clips; preview it and finish, because the user approves these frames before the motion is built. When fromLook is true the base version is the approved look: preserve the approved direction except for changes requested in the latest brief and unresolved previousReview findings, and add only the motion, audio and transitions called for by the approved intent; intentional still scenes remain still; you may rewrite the files. Each beat in plan.scenes may carry layout (grid, placement, scale) and field (its background colour): build exactly that. If plan.signature_move is present and fits the approved intent, implement it on its beat (on an edit, only when the version already has it or the latest brief asks). No signature move is required for other formats. When the contact sheet has two rows, the bottom row is the reference video at the same moments: compare scale, framing, density and energy against it, and score with it in mind; never copy its content. When scaffold is present the project already holds the composition\'s skeleton, built from the reference layout: index.html has every reference element as a positioned slot (data-ref kept) in its beat\'s section, the voice and music clips placed, and main.js the beats\' timing, one commented line per moment with its move, and the 3D mascot and prop stubs with the narration\'s word times. Fill it in: give each slot its content and look, write each moment\'s motion on its line, model each prop, and merge or reshape slots only when the reference element really is one piece; keep every data-ref, never start over. When preparedAudio is present the sound is already done: place preparedAudio.narration as the one narration clip at data-start 0.3 (narrationTiming is measured on that file) and preparedAudio.music, when present, as one clip from 0 for the whole video at data-volume 1 (it is already ducked under the voice and faded); never run media ops on them and do not place the original voice or music files. When resumed is present, this build continues an earlier build of the same plan that stopped before it finished: its draft is already in the project, so check and preview it first, then finish what is missing; never start over. When baseFiles is present it is the complete current source of the version you are editing: do not read those files first, go straight to patch actions; a derived file the version already uses (for example ducked music) is in the assets, so do not make it again. When planMedia has narration (voiceover or cloned_voiceover) and music: after placing the narration clip, run media op duck on the music file with {voice: the narration file, voice_start: the narration clip data-start} and use its output as the music clip at data-volume about 0.35 (not the original file; the check fails music playing under the voice without ducking). When planMedia has a talking_shot or talking_take with speech_mode native, its own audio is the approved speech: keep it audible (data-has-audio true), never mute or dub it. A native talking_take supplies the entire script; do not add a second voiceover. A native talking_shot speaks the hook; any separately generated voiceover contains only the remaining lines and starts after the hook finishes. Duck music under the speaking video using it as the voice source. For audio_driven talking_shot or talking_take, use the supplied cloned narration exactly once, synchronized with the lip-sync footage. If a talking item failed, say that the requested speaking performance is missing; do not claim a still pose is a talking performance. plan.scenes is the director\'s beat sheet: each beat has state_in, state_out and reads (what the viewer must understand, in order). Build in stages: first write every beat\'s key frame as a still (state_out of each beat, at its end time) and preview them to confirm the frames read; then add the motion between them. Keep every read in its beat and in order, one at a time, each held long enough to be read; the opening communicates its purpose; movement is required only if the brief calls for it. When musicBeats is present, it is the beat grid of the music bed: put hard cuts, scene changes and UI pops on its beats, the biggest moments on bars or strong_hits, and keep held moments a whole number of beats long. Passing checks may list pacing findings: reading_time means a headline leaves the screen before it can be read (hold it longer or say less), blank_frames means an empty screen, still_stretch means nothing on screen moves or changes for over 1.5 s (a deliberate hold is marked data-hold, up to 3 s), small_text means sentence text a phone cannot read, and mostly_empty means the content is too small for the frame; fix the clear ones in the same round of fixes; they are notes for the user, never a reason for another round. slow_drift is advisory: inspect it in context, preserve intentional still holds and do not add movement solely to satisfy the warning. When planMedia has character_variants, their files are labelled poses of the approved character: use the appropriate supplied pose on each beat and inspect them together for identity and style consistency. When planMedia has only a character_poses master preview (and no plan.mascot3d), reuse that one image across the storyboard; do not invent extra poses or mix earlier designs. Register window.__timelines in an inline script at the end of index.html even when the animation code lives in main.js; the check reads index.html. Keep every write under about 6,000 characters so it is never cut off: put markup in index.html, styles in style.css and animation code in main.js as separate files linked from index.html. A web page screenshot is reference evidence. Use only the relevant screens or facts the approved brief calls for; do not automatically rebuild several screens or turn every task into a product demo. If a UI card accompanies a spoken phrase, align it to that phrase using data-spoken. Numbers on screen (prices, percentages, counts) must come from the approved facts, copy or script; otherwise use a neutral placeholder such as Your price. Supplied product screenshots are the most faithful UI when present. Sound mix: a music file from planMedia is the bed, so place it as one audio clip for the whole video, with volume about 0.18 under narration (0.35 with no voice) and a fade over the last second. An sfx file is a sheet of cues with start and end times in planMedia cues: play each cue with its own audio clip using data-media-start at the cue start and a slot as long as the cue, volume about 0.5, on the beat it belongs to (a card landing, a cut, a button press); use cues only when they help the approved motion and sound direction and never let them cover a spoken word. For per-word or per-letter kinetic type, wrap each animated line in one container with data-layout-allow-overlap so the layout check accepts the intended overlap. Audio edges are checked: every audio clip must start and stop where its file is quiet. Keep the narration as one continuous clip and move the pictures to the words; when the narration is shorter than the video or a beat needs the voice to wait, lengthen its own pauses with media op space (call transcript on the result) instead of splitting it or building audio with ffmpeg; if you must split, cut only inside a pause, never at a rounded second. End the music with a fade (media op fade with fade_out about 1, or a volume lane reaching 0) instead of stopping it while it plays. When planMedia includes narration (a voiceover file), it is the approved script: place it as an audio clip that starts near 0.3 s, call transcript on that file, and tag each on-screen line or UI card that should land on a spoken phrase with data-spoken set to those exact words so it appears as it is said; choose emphasis and type treatment appropriate to the approved reference and brand. When houseStyle is set it is a default only: follow the explicit brief and approved plan first, preserve explicit brand locks, and use the saved palette only where no override was requested. Do not force dark backgrounds or the WyvStudio application palette. Vary background balance and accent coverage when the approved treatment calls for it. planMedia lists what was bought for this plan: use each succeeded file (by its file name in assets) where the plan said; apply brand colours and fonts when given; for a failed item, work around it without inventing a substitute and mention it in your summary. Assets with renderable false and a reference field are style guides: follow their reference notes for pacing, structure, look and motion, but never copy their characters, logos, on-screen text, speech or footage. For a reference-led request to make it like the sample, preserve its visual baseline and change only what the user and approved plan replace. A brand-page screenshot is not authority to switch a bright reference to a dark brand template. When supplied footage has speech, call transcript on it first and time on-screen text and visuals to the spoken words. Make text and colours editable without you: declare data-composition-variables on <html> for every on-screen line (string; ids headline, line_1, line_2 ..., cta) and the main colours (color; ids color_background, color_accent, color_text), bind colours with var(--id) and read text once at init with window.__hyperframes.getVariables(), keeping the authored text as fallback. When a plan is present, treat plan.requirements and plan.character_style as acceptance criteria. A character_poses item under approved-master-v2 is ONE master preview: use that same image across storyboard beats, without synthesizing extra expressions or mixing inherited character assets. Additional character_variants are produced only after master approval; compare their face proportions, clothing and rendering treatment together and flag drift for repair rather than calling it complete. Inspect character assets before composing: preserve identity but do not preserve photoreal texture when stylisation is requested. A texture overlay alone is not a character transformation. If the supplied pose is wrong, report the unmet requirement and request asset correction; do not call the output complete or purchase replacements silently. When a plan is present it is the user-approved direction: use plan.on_screen_copy exactly as the on-screen words (they are also approved facts), follow plan.choices, keep every plan.kept_as_is item unchanged, follow the scene order and timing unless the render requires a small adjustment, and use app catalogue tools only within the approved media ceiling and stage; talking clips require the approved character images. Do not silently replace a failed requirement; free media edits to supplied footage (the media action) are allowed when they improve the result. Before implementing a reference-led brief, use inspect_reference on the attached reference: inspect the opening, the distinctive transition and any requested character texture or interface detail. Use its timestamped evidence to choose registry components, authored motion or generated media; flag any conflict with the approved plan instead of silently changing it. Reference-only attachments are context, not footage. Preserve source identities. Ask for clarification when claims are unsupported. Use only approved facts and user supplied copy. Do not invent prices, guarantees, endorsements, narration or captions. Original audio is kept unless settings.audio is silent. Supplied caption_text is exact. If a requested feature needs new media, propose_media rather than fake it. For a new brief replace the sample entirely; it is unrelated to the brief. For an edit read the existing composition and change only what was asked: a planned move or transition the version does not already have is not part of the edit unless the latest brief asks for it. Use preview to check your work, look at its snapshots once, then finish: the user reviews the result, so do not polish in rounds. Each preview takes about 20 seconds: write whole files rather than many small patches, batch related fixes into one round, and preview once per round, not after every patch.',{lookOnly:input.look_first===true,fromLook:input.from_look===true,styleNotes:!!(Array.isArray(input.style_notes)&&input.style_notes.length),baseFiles:!!(input.base_bundle||input.resume),resumed:!!input.resume,preparedAudio:!!preparedAudio,rawAudio:!preparedAudio,scaffold:!!scaffold,musicBeats:!!musicBeats,narrationTiming:!!narrationTiming,talking:planMedia.some(m=>['talking_shot','talking_take'].includes(m.kind)),poses:planMedia.some(m=>['character_poses','character_variants'].includes(m.kind)),houseStyle:!!input.style,page:!!page,sfx:planMedia.some(m=>m.kind==='sfx'),narration:planMedia.some(m=>['voiceover','cloned_voiceover'].includes(m.kind)),music:planMedia.some(m=>m.kind==='music')&&!preparedAudio?.music,captions:!!settings.caption_text,speech:manifest.some(f=>f.purpose==='source'&&['video','audio'].includes(f.asset_type)),plan:!!input.plan})},
  // Shared craft rules apply to every build, whatever its style route.
  skills:(await loadCoreGuidance(guidanceDirectory))+'\n\n'+await readFile(guidanceDirectory+'/../craft.md','utf8')+(cards.text?'\n\n'+cards.text:'')+await pinnedKits(guidanceDirectory,input.plan),signal,requireVisualReview:thoroughReview,
  // Unlimited local testing (frozen in the approved policy): limits sit far past any expected build so trajectories show real needs.
  limits:unlimited?{reviewReserveMs:critic?300000:0,repairs:1000,runs:1000,inspections:100,resultBytes:64000,usesPerTurn:20,criticCalls:critic?(thoroughReview?Math.max(0,Number(criticPolicy.max_calls)||0):Math.min(1,Math.max(0,Number(criticPolicy.max_calls)||0))):0,calls:input.execution_policy.agent.max_calls,budgetUsd:input.execution_policy.agent.max_calls*input.execution_policy.agent.cost_limit_microusd/1e6,contextBytes:Number(input.execution_policy.agent.context_bytes)||600000,maxOutputTokens:Number(input.execution_policy.agent.max_output_tokens)||32000,totalOutputTokenAllowance:input.execution_policy.agent.max_calls*(Number(input.execution_policy.agent.max_output_tokens)||32000),elapsedMs:4*3600000}:{// Paid builds get the room unlimited testing had (owner, 2026-10-06); the budget, the no-progress guard and the calls stop them.
   ...(paid?{repairs:1000,runs:1000,inspections:100,resultBytes:64000,usesPerTurn:20}:{repairs:2}),reviewReserveMs:critic?300000:0,criticCalls:critic?(thoroughReview?Math.max(0,Number(criticPolicy.max_calls)||0):Math.min(1,Math.max(0,Number(criticPolicy.max_calls)||0))):0,calls:input.execution_policy?.agent?.max_calls??0,budgetUsd:paid?(Number(input.execution_policy?.agent?.total_credits)>0?input.execution_policy.agent.total_credits*0.004:(input.execution_policy?.agent?.max_calls??8)*(input.execution_policy?.agent?.cost_limit_microusd??300000)/1e6):0,spendBudget:paid&&Number(input.execution_policy?.agent?.total_credits)>0,contextBytes:paid?Math.max(96000,Number(input.execution_policy?.agent?.context_bytes)||0):200000,maxOutputTokens:Math.min(32000,Math.max(256,input.execution_policy?.agent?.max_output_tokens??4096)),totalOutputTokenAllowance:Math.max(98304,(input.execution_policy?.agent?.max_calls??12)*Math.min(32000,input.execution_policy?.agent?.max_output_tokens??4096)),elapsedMs:paid?5400000:600000},
  tools:{...(transcribe?{transcript:args=>transcribe(args)}:{}),inspect_reference:args=>invoke('inspect_reference',args),media:args=>invoke('media',args),run:args=>invoke('run',args),...(catalog.length?{catalog:catalogTool}:{}),...(art?.items?.length?{art:artTool}:{}),...(critic?{critic,strip:args=>invoke('strip',args),detail:args=>invoke('detail',args)}:{}),...(buy?{buy:async args=>{
   // Stage what was bought into the project so the composition can use it at once.
   const r=await buy(args);if(!r?.ok)return r;
   const files=[];for(const f of r.files||[]){await copyFile(directory+'/inputs/source/'+f.name,directory+'/project/'+f.name).catch(()=>{});files.push({path:f.name,sha256:f.sha256});}
   return {...r,files};}}:{}),check:args=>invoke('check',args),layout:args=>invoke('layout',args),compare:args=>invoke('compare',args),snapshot:args=>invoke('snapshot',args),timeline:args=>invoke('timeline',args),guidance:name=>{
   // The style pack's worked example, frozen with the run: readable, never part of the bundle.
   if(name==='kit/motion-kit.md')return readFile(guidanceDirectory+'/../motion-kit.md','utf8');
   // A worked example of every reference move on one timeline (the offline fixture the moves are proven on).
   if(name==='kit/reference-moves.html')return readFile(guidanceDirectory+'/../../fixtures/reference-moves/index.html','utf8');
   if(name==='kit/remotion.md')return readFile(guidanceDirectory+'/../remotion-kit.md','utf8');
   // 3D drawn inside the composition, and a full worked example (a reference copied with a 3D mascot and props).
   if(name==='kit/three.md')return readFile(guidanceDirectory+'/../three-kit.md','utf8');
   if(name==='kit/three-example.html')return readFile(guidanceDirectory+'/../../fixtures/wyv-promo/index.html','utf8');
   if(name==='kit/mascot.md')return readFile(guidanceDirectory+'/../mascot-kit.md','utf8');
   if(name==='kit/barty.md')return readFile(guidanceDirectory+'/../barty-kit.md','utf8');
   if(name==='kit/registry.md')return readFile(guidanceDirectory+'/../registry-kit.md','utf8');
   if(name.startsWith('skills/'))return readSkill(guidanceDirectory,name);
   if(name.startsWith('cards/'))return readCard(guidanceDirectory,name.slice(6).replace(/\.md$/,''));
   if(name.startsWith('style-example/')){const text=input.style_pack?.example?.[name.slice(14)];if(typeof text!=='string')throw Error('No such example file');return text;}
   return readGuidanceReference(guidanceDirectory,name);}}});
 const bundle={};
 for(const name of (await readdir(directory+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)).sort())bundle[name]=await readFile(directory+'/project/'+name,'utf8');
 // Files the sandbox derived during this run, with where they came from.
 return {state,bundle,derived:workspace.assets.filter(a=>a.derivedFrom||a.operation==='run'||a.operation==='library')};
}

// Explicit offline contract probe, not a generative model. It exercises reads,
// a source-preserving edit, checks, snapshots and finish through the real runner.
/** The source of the version an edit changes, for checks that hold the edit to what was asked; null for a new build, a
 * resumed build of the same plan, or the motion pass over an approved look (which adds the moves). */
export function editBaseSources(input={}){
 if(!input.base_bundle||input.from_look===true||input.resume)return null;
 return Object.entries(input.base_bundle).filter(([n])=>/\.(html|js|css)$/.test(n)&&!/^(gsap|wyv-|barty-)/.test(n)).map(([,t])=>String(t)).join('\n');
}

export function offlineContractProvider(baseBundle=null,manifest=[]){
 const previous=baseBundle?.['index.html'];
 const from=previous?.includes('Local edit proof')?'Local edit proof':previous?'Local agent proof':'Explore the collection';
 const to=from==='Local agent proof'?'Local edit proof':'Local agent proof';
 let i=0;
 const responses=[{type:'read',path:'index.html'},
  // The button text lives in the cta variable; the render shows the variable.
  {type:'patch',path:'index.html',before:'"default":"'+from+'"',after:'"default":"'+to+'"'},
  // With a supplied video, also exercise the sandbox media path end to end.
  ...manifest.filter(f=>f.purpose==='source'&&f.asset_type==='video').slice(0,1).map(f=>({type:'media',op:'trim',input:f.name,params:{start:0,end:Math.min(3,Math.max(.5,(f.duration_seconds??3)))}})),
  {type:'check'},{type:'snapshot',times:[1,12]},
  {type:'finish',summary:'Offline agent contract sample; not AI-generated creative.'}];
 return {id:'offline-contract-v1',maxCallUsd:0,complete:async()=>({text:JSON.stringify(responses[i++])})};
}
