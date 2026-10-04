import {skillCatalogue,readSkill} from './skill-library.mjs';
import {copyFile,readFile,readdir,writeFile} from 'node:fs/promises';
import {Workspace,digest} from './workspace.mjs';
import {runAgent} from './runner.mjs';
import {accountedCall} from './accounted-call.mjs';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';
import {loadCatalog,searchCatalog,catalogItem} from './registry.mjs';
import {cardRoute,loadCards,readCard,availableCards} from './cards.mjs';
import {criticSystem,criticMessages,parseCriticVerdict} from './critic.mjs';
import {voiceTiming} from './narration-timing.mjs';

// The instruction only carries the sentences this run can use: a sentence about a thing the run does not have is context spent on nothing.
const INSTRUCTION_GATES=[[/\bnarrationTiming\b/,'narrationTiming'],[/\blookOnly\b/,'lookOnly'],[/\bfromLook\b/,'fromLook'],[/\bstyleNotes\b/,'styleNotes'],[/\bbaseFiles\b/,'baseFiles'],[/\bmusicBeats\b/,'musicBeats'],[/talking_shot|talking_take/,'talking'],[/character_poses/,'poses'],[/\bhouseStyle\b/,'houseStyle'],[/web page reference/,'page'],[/\bsfx\b/,'sfx'],[/narration \(|includes narration|has narration/,'narration'],[/music file|Sound mix/,'music'],[/caption_text/,'captions'],[/footage has speech/,'speech'],[/When a plan is present/,'plan']];
// A model that is busy or briefly unreachable (the app confirms nothing was sent or charged) is waited out
// and asked again, instead of ending the build. Any other failure is passed on unchanged.
export const BUSY_WAITS_MS=[20000,60000,120000];
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
export async function executeCompositionAgent({directory,input,manifest,planMedia=[],provider,begin,settle,bindPrediction,receipt,invoke,transcribe,buy,guidanceDirectory,signal,stopRequested=()=>false,onProgress,onTrace}) {
 const assets=[];
 for(const file of manifest){
  // Reference-only media is described in context, never made renderable.
  if(file.purpose!=='source')continue;
  await copyFile(directory+'/inputs/'+file.path,directory+'/project/'+file.name);
  assets.push({path:file.name,sha256:file.sha256});
 }
 if(input.base_bundle)for(const [name,text] of Object.entries(input.base_bundle))await writeFile(directory+'/project/'+name,text,{mode:0o600});
 // Fixed runtime assets are protected alongside uploaded source bytes.
 for(const name of (await readdir(directory+'/project')).filter(n=>/\.(svg|png|jpg|webp|mp4|mp3|wav|ttf)$/.test(n))){
  if(!assets.some(a=>a.path===name))assets.push({path:name,sha256:digest(await readFile(directory+'/project/'+name))});
 }
 // Bought music gets a beat grid up front, so cuts and pops can land on the beat.
 let musicBeats=null;
 const music=planMedia.find(m=>m.kind==='music'&&m.status==='succeeded'&&m.file);
 if(music&&invoke)try{const r=await invoke('media',{op:'beats',input:music.file,signal});if(r?.ok&&r.beats)musicBeats={file:music.file,...r.beats};}catch{/* the build works without it */}
 // Kept beside the run so it is clear afterwards whether the agent had a grid.
 if(music)await writeFile(directory+'/music-beats.json',JSON.stringify(musicBeats??{error:'no grid'},null,1)).catch(()=>{});
 // The bought narration, transcribed up front: beats are timed by its words, and a voice that ends early gets its
 // own pauses lengthened instead of a long silent hold at the end.
 let narrationTiming=null;const initialTranscripts={};
 const voice=planMedia.find(m=>['voiceover','cloned_voiceover'].includes(m.kind)&&m.status==='succeeded'&&m.file);
 if(voice&&transcribe&&input.look_first!==true&&input.plan?.scenes?.length)try{
  const t=await transcribe({input:voice.file,signal});
  const words=(t?.words||[]).map(w=>[w.text,Number(w.start),Number(w.end)]);
  if(words.length){
   initialTranscripts[voice.file]=words;
   narrationTiming={file:voice.file,...voiceTiming({scenes:input.plan.scenes,lines:input.plan.narration||[],words,clipStart:0.3,videoSeconds:Number(input.settings?.duration_seconds)||30})};
   await writeFile(directory+'/narration-timing.json',JSON.stringify(narrationTiming,null,1)).catch(()=>{});
  }
 }catch{/* the build works without it */}
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
 // The last progress report, so a wait for a busy model can say so without losing the spend shown.
 let lastProgress={doing:'',spentUsd:0};const relay=p=>{lastProgress=p;onProgress?.(p);};
 const busyNote=()=>{try{onProgress?.({...lastProgress,doing:'The model is busy, trying again in a moment'});}catch{/* reporting never stops a build */}};
 let busy=0;
 const critic=paid&&criticPolicy&&provider.complete?async({sheet,strip,stripEvidence,detail,detailCells,authorScores,findings,round,signal})=>{busy=0;
  const messages=criticMessages({brief:messages0.at(-1).content,plan:planWithFiles,lookOnly:input.look_first===true,route,sheet,strip,stripEvidence,detail,detailCells,authorScores,findings,fingerprint:input.style_pack?.fingerprint??null,round});
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
  key:'agent-'+(++call),kind:'agent',input:{prompt:args.prompt,system:args.system,maxTokens:args.maxTokens,image:args.image??null,...(args.messages?{messagesJson:JSON.stringify(args.messages),toolsJson:JSON.stringify(args.tools??[])}:{})},begin,settle,
  execute:attemptId=>provider.complete({...args,attemptId,recordPrediction:args.onPrediction,onPrediction:async id=>{
   // Record the provider identity in both app accounting and the local journal.
   if(!bindPrediction)throw Error('Prediction recorder is required');
   await bindPrediction(attemptId,id);await args.onPrediction(id);
  }}),receipt,
 });},{signal:args.signal,release:provider.release,onWait:busyNote,stop:stopRequested});}};
 const dims=({'9:16':[1080,1920],'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio??'9:16'];
 const unlimited=input.execution_policy?.agent?.unlimited===true;
 const workspace=new Workspace(directory+'/project',assets,directory+'/work',unlimited?1_000_000:128_000);
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
 // A captured web page is shown to the agent as its first image, so it can rebuild the brand's real screens.
 let initialImage;
 const page=manifest.find(f=>f.purpose==='reference'&&f.asset_type==='image'&&f.reference?.from==='page');
 if(page){try{const bytes=await readFile(directory+'/inputs/'+page.path);if(bytes.length<=1000000)initialImage='data:image/jpeg;base64,'+bytes.toString('base64');}catch{/* the notes still describe it */}}
 const state=await runAgent({initialImage,stopRequested,initialTranscripts,onProgress:relay,onTrace,stateFile:directory+'/agent-state.json',workspace,provider:accountedProvider,
  context:{renderAdapters:[{engine:'remotion',tool:'run',guide:'kit/remotion.md',purpose:'Native React motion clips/stills with editable .js source; integrate outputs into the existing Hyperframes composition. No automatic HTML conversion.'}],skillLibrary:await skillCatalogue(guidanceDirectory,{route,speech:planMedia.some(m=>['voiceover','cloned_voiceover','talking_take','talking_shot'].includes(m.kind))||manifest.some(f=>f.purpose==='source'&&f.asset_type==='audio')}),colourTreatment:input.plan?.colour_treatment??null,brief:messages.at(-1).content,messages,previousReview:input.base_review??null,baseRevision:input.base_revision_id,
   // An edit starts with the current source in hand, so no calls go on reading it (bounded; larger bundles are read on demand).
   baseFiles:input.base_bundle&&Object.values(input.base_bundle).reduce((n,t)=>n+Buffer.byteLength(String(t)),0)<=30000?input.base_bundle:null,
   // An edit of an existing version changes it with patches; only a redesign may rewrite it.
   editOnly:!!input.base_bundle&&!input.from_look&&!/\b(redesign|start over|from scratch|completely new|brand new|totally different)\b/i.test(String(messages.at(-1)?.content||'')),
   // Design first: a look run makes one still per beat for approval; the approved look is then the base for the motion build.
   lookOnly:input.look_first===true,fromLook:input.from_look===true,
   toolMode:input.execution_policy?.agent?.provider!=='replicate'&&(input.execution_policy?.agent?.tool_mode===true||process.env.CREATE_TOOL_MODE==='1'),
   styleNotes:Array.isArray(input.style_notes)&&input.style_notes.length?input.style_notes.slice(0,10):null,
   assets:manifest.map(({storage_path,path,...file})=>({...file,renderable:file.purpose==='source'})),
   plan:planWithFiles,
   intentPolicy:'Infer the intended output from the full brief and approved plan.creative_intent, not topic keywords. Education about UGC does not request an avatar. Motion recipes, source skills and style packs are subordinate to intent; static slides, calm footage, stable talking heads and simple cuts are valid. For timing-only edits preserve narration, voice, copy and existing media, and align scene visibility and animation to measured speech without stacking timeline remappers.',
   characterPerformancePolicy:'plan.character_performance lists active character actions and their times. Implement and inspect each action; moving a still image is not facial/body articulation. The user explicitly left out plan.omitted_character_performance, which overrides earlier brief/requirement wording for those actions only. Do not buy media solely to restore omitted actions. In a storyboard defer motion honestly; in a full video, report missing or unverified performance instead of claiming it is complete.',
   planMedia,
   musicBeats,narrationTiming,
   houseStyle:input.style??null,
   stylePack:input.style_pack?{route:input.style_pack.route,name:input.style_pack.name,rules:input.style_pack.rules,fingerprint:input.style_pack.fingerprint||null,
    exampleFiles:Object.keys(input.style_pack.example??{}).map(f=>'style-example/'+f),
    howToUse:'This is the craft the build starts from, not a template. Follow its rules. If exampleFiles are listed, read style-example/index.html once to learn its technique; never copy its layout wholesale. The fingerprint describes the example on six points: structure, opening, signature shot, camera path, score shape and ending. Use the fingerprint to identify techniques the brief asks to preserve and content it asks to replace; do not impose a numerical divergence quota. In your final visual_review identify which techniques you retained and how you changed the content. Explicit user colour/type direction and the approved plan take precedence over saved defaults; respect explicit brand locks and ask about conflicts. Borrowing reference motion does not automatically mean borrowing its palette.'}:null,
   variantDirection:input.variant_direction??null,approvedFacts:[...(settings.approved_facts??[]),...(input.plan?.on_screen_copy??[])],settings,output:{width:dims[0],height:dims[1],durationSeconds:settings.duration_seconds??15},
   registry:catalog.length?{items:catalog.length,howToUse:'Search with the catalog action before hand-building any named visual; read kit/registry.md once before wiring an item.'}:null,
   cards:{route,pinned:cards.names,onDemand:otherCards.map(n=>'cards/'+n+'.md')},
   runtimeFiles:[{path:'wyv-mascot.js',purpose:'Optional prepared layered-SVG mascot rig: deterministic blinks, gaze, head tilt and mouth expressions on the Hyperframes timeline. Read kit/mascot.md first. Not an image-to-rig converter, not automatic lip-sync, no full-body actions. Never substitute the fixture for an approved character.'},{path:'barty-motion.js',purpose:'Optional pinned Barty spring/shape engine. Load with barty-hyperframes.js after GSAP; read kit/barty.md first. One full-frame scene; no standalone render commands.'},{path:'barty-hyperframes.js',purpose:'Barty to Hyperframes timeline bridge; approved colours remain authored inputs.'},{path:'gsap.min.js',purpose:'Local GSAP runtime'},{path:'wyv-motion.js',purpose:'WyvStudio motion kit, load after gsap.min.js: spring eases, cursor, button press, typing, toggle, counter, shape morph and scene transitions (whip, push, wipe, light leak). Read kit/motion-kit.md for the API before using it.'},{path:'font.ttf',purpose:'DejaVu Sans, plain fallback'},
    {path:'inter.ttf',purpose:'Inter, variable weight 100-900: clean modern sans for body and bold headlines'},
    {path:'anton.ttf',purpose:'Anton, heavy condensed display: punchy ad headlines'},
    {path:'bebas-neue.ttf',purpose:'Bebas Neue, tall all-caps display'},
    {path:'playfair.ttf',purpose:'Playfair Display, variable weight 400-900: editorial serif'},
    {path:'space-grotesk.ttf',purpose:'Space Grotesk, variable weight 300-700: techy geometric sans'},
    {path:'caveat.ttf',purpose:'Caveat, variable weight 400-700: handwritten accents'}],
   fontNote:'Load fonts with @font-face using these exact file names; declare font-weight ranges for variable fonts (for example font-weight:100 900 for Inter) so bold renders truly bold.',
   instruction:trimInstruction('narrationTiming, when present, is the beat sheet timed by the bought narration placed at 0.3 s: start each beat at its voiced time, and mark each beat\'s container with data-beat set to its scene label and that data-start, because the check measures beats against the words they start on (plan.scenes[].starts_on). plan.reference_pacing, when present, is the reference\'s rhythm: hold each shot about average_shot_seconds (about cuts_per_10_seconds changes every 10 s), show text about text_to_speech_delay_seconds after its words are said, and when cuts_on_beat is 0.5 or more land the changes on the music\'s beats. If narrationTiming.suggestion is present the voice ends well before the video: run that media op space on the narration file, call transcript on the result and use it as the one narration clip, then re-time the beats from it (the check reads the new transcript). An asset with rig.ready true is the user\'s own character drawn in layers for the prepared rig: read kit/mascot.md, place it with <div id="a-name" data-rig-src="its file name"></div> (it becomes the inline SVG with its data-rig-part layers whenever the page is checked or rendered) and animate blinks, gaze, small head moves and mouth shapes with WyvMascot.attach on that id; never redraw or swap its artwork, move the whole figure with an outer wrapper, and use bought clips for speech. plan.asks are real things the user was asked for: one with a file is the user\'s own upload (in assets by that name), so use it on its beat instead of a rebuilt or illustrative version, and inspect it first; one marked skipped, or with no file, uses its fallback. plan.reference_decisions lists each moment of the reference to keep or change and the beat that carries it (moment ids are asset:m#, with times in the reference): build every kept or changed moment in its beat, and inspect the reference at that moment first to see the technique (a sticker, a counter, text typing on, a quick zoom). A dropped moment\'s carried_by names what now does its job; build that too. plan.reference_systems are the reference\'s recurring elements (a Step card, a UI panel, a caption style), each with one spec and the beats where it appears, plus how the reference version looks, enters, holds and leaves: build each kept or adapted system once as a reusable piece (one class or function) and use it at every listed beat, so every occurrence looks and moves the same. When a page capture or product screenshot is attached, rebuild its real screens with their labels, layout and copy; never placeholder fields, grey bars or empty tiles. previousReview, when present, contains unresolved findings from the previous result. Address these alongside the latest brief; do not claim they are fixed without checking the new output. Storyboard approval does not waive these findings. colourTreatment, when present, is the approved palette: use roles for backgrounds, text and accents, preserve every locked role exactly, and apply usage across beats. It overrides saved palette defaults but never recolours supplied product media. Preserve it on unrelated edits. plan.scenes[].uses names the registry items chosen for that beat: mount each of them (catalog with its exact name first, for its entry and variables; kit/registry.md for the wiring); if you leave one out, say why in your summary. styleNotes, when present, are the user\'s own verdicts on earlier videos in this style: do what they liked and avoid what they did not. When lookOnly is true this run makes THE LOOK, not the motion: one still per beat, exactly each beat\'s state_out with its layout and field, held for the whole beat; every read visible; the character at its hero scale; no tweens and no audio clips; preview it, review it with scores and finish, because the user approves these frames before the motion is built. When fromLook is true the base version is the approved look: preserve the approved direction except for changes requested in the latest brief and unresolved previousReview findings, and add only the motion, audio and transitions called for by the approved intent; intentional still scenes remain still; you may rewrite the files. Each beat in plan.scenes may carry layout (grid, placement, scale) and field (its background colour): build exactly that. If plan.signature_move is present and fits the approved intent, implement it on its beat. No signature move is required for other formats. When the contact sheet has two rows, the bottom row is the reference video at the same moments: compare scale, framing, density and energy against it, and score with it in mind; never copy its content. When baseFiles is present it is the complete current source of the version you are editing: do not read those files first, go straight to patch actions; a derived file the version already uses (for example ducked music) is in the assets, so do not make it again. When planMedia has narration (voiceover or cloned_voiceover) and music: after placing the narration clip, run media op duck on the music file with {voice: the narration file, voice_start: the narration clip data-start} and use its output as the music clip at data-volume about 0.35 (not the original file; the check fails music playing under the voice without ducking). When planMedia has a talking_shot or talking_take with speech_mode native, its own audio is the approved speech: keep it audible (data-has-audio true), never mute or dub it. A native talking_take supplies the entire script; do not add a second voiceover. A native talking_shot speaks the hook; any separately generated voiceover contains only the remaining lines and starts after the hook finishes. Duck music under the speaking video using it as the voice source. For audio_driven talking_shot or talking_take, use the supplied cloned narration exactly once, synchronized with the lip-sync footage. If a talking item failed, say that the requested speaking performance is missing; do not claim a still pose is a talking performance. plan.scenes is the director\'s beat sheet: each beat has state_in, state_out and reads (what the viewer must understand, in order). Build in stages: first write every beat\'s key frame as a still (state_out of each beat, at its end time) and preview them to confirm the frames read; then add the motion between them. Keep every read in its beat and in order, one at a time, each held long enough to be read; the opening communicates its purpose; movement is required only if the brief calls for it. When musicBeats is present, it is the beat grid of the music bed: put hard cuts, scene changes and UI pops on its beats, the biggest moments on bars or strong_hits, and keep held moments a whole number of beats long. Passing checks may list pacing findings: reading_time means a headline leaves the screen before it can be read (hold it longer or say less), blank_frames means an empty screen, still_stretch means nothing on screen moves or changes for over 1.5 s (a deliberate hold is marked data-hold, up to 3 s), small_text means sentence text a phone cannot read, and mostly_empty means the content is too small for the frame; fix them before finishing, and finish is refused once while any is open. slow_drift is advisory: inspect it in context, preserve intentional still holds and do not add movement solely to satisfy the warning. When planMedia has character_variants, their files are labelled poses of the approved character: use the appropriate supplied pose on each beat and inspect them together for identity and style consistency. When planMedia has only a character_poses master preview, reuse that one image across the storyboard; do not invent extra poses or mix earlier designs. Register window.__timelines in an inline script at the end of index.html even when the animation code lives in main.js; the check reads index.html. Keep every write under about 6,000 characters so it is never cut off: put markup in index.html, styles in style.css and animation code in main.js as separate files linked from index.html. A web page screenshot is reference evidence. Use only the relevant screens or facts the approved brief calls for; do not automatically rebuild several screens or turn every task into a product demo. If a UI card accompanies a spoken phrase, align it to that phrase using data-spoken. Numbers on screen (prices, percentages, counts) must come from the approved facts, copy or script; otherwise use a neutral placeholder such as Your price. Supplied product screenshots are the most faithful UI when present. Sound mix: a music file from planMedia is the bed, so place it as one audio clip for the whole video, with volume about 0.18 under narration (0.35 with no voice) and a fade over the last second. An sfx file is a sheet of cues with start and end times in planMedia cues: play each cue with its own audio clip using data-media-start at the cue start and a slot as long as the cue, volume about 0.5, on the beat it belongs to (a card landing, a cut, a button press); use cues only when they help the approved motion and sound direction and never let them cover a spoken word. For per-word or per-letter kinetic type, wrap each animated line in one container with data-layout-allow-overlap so the layout check accepts the intended overlap. Audio edges are checked: every audio clip must start and stop where its file is quiet. Keep the narration as one continuous clip and move the pictures to the words; when the narration is shorter than the video or a beat needs the voice to wait, lengthen its own pauses with media op space (call transcript on the result) instead of splitting it or building audio with ffmpeg; if you must split, cut only inside a pause, never at a rounded second. End the music with a fade (media op fade with fade_out about 1, or a volume lane reaching 0) instead of stopping it while it plays. When planMedia includes narration (a voiceover file), it is the approved script: place it as an audio clip that starts near 0.3 s, call transcript on that file, and tag each on-screen line or UI card that should land on a spoken phrase with data-spoken set to those exact words so it appears as it is said; choose emphasis and type treatment appropriate to the approved reference and brand. When houseStyle is set it is a default only: follow the explicit brief and approved plan first, preserve explicit brand locks, and use the saved palette only where no override was requested. Do not force dark backgrounds or the WyvStudio application palette. Vary background balance and accent coverage when the approved treatment calls for it. planMedia lists what was bought for this plan: use each succeeded file (by its file name in assets) where the plan said; apply brand colours and fonts when given; for a failed item, work around it without inventing a substitute and mention it in your summary. Assets with renderable false and a reference field are style guides: follow their reference notes for pacing, structure, look and motion, but never copy their characters, logos, on-screen text, speech or footage. For a reference-led request to make it like the sample, preserve its visual baseline and change only what the user and approved plan replace. A brand-page screenshot is not authority to switch a bright reference to a dark brand template. When supplied footage has speech, call transcript on it first and time on-screen text and visuals to the spoken words. Make text and colours editable without you: declare data-composition-variables on <html> for every on-screen line (string; ids headline, line_1, line_2 ..., cta) and the main colours (color; ids color_background, color_accent, color_text), bind colours with var(--id) and read text once at init with window.__hyperframes.getVariables(), keeping the authored text as fallback. When a plan is present, treat plan.requirements and plan.character_style as acceptance criteria. A character_poses item under approved-master-v2 is ONE master preview: use that same image across storyboard beats, without synthesizing extra expressions or mixing inherited character assets. Additional character_variants are produced only after master approval; compare their face proportions, clothing and rendering treatment together and flag drift for repair rather than calling it complete. Inspect character assets before composing: preserve identity but do not preserve photoreal texture when stylisation is requested. A texture overlay alone is not a character transformation. If the supplied pose is wrong, report the unmet requirement and request asset correction; do not call the output complete or purchase replacements silently. When a plan is present it is the user-approved direction: use plan.on_screen_copy exactly as the on-screen words (they are also approved facts), follow plan.choices, keep every plan.kept_as_is item unchanged, follow the scene order and timing unless the render requires a small adjustment, and use app catalogue tools only within the approved media ceiling and stage; talking clips require the approved character images. Do not silently replace a failed requirement; free media edits to supplied footage (the media action) are allowed when they improve the result. Before implementing a reference-led brief, use inspect_reference on the attached reference: inspect the opening, the distinctive transition and any requested character texture or interface detail. Use its timestamped evidence to choose registry components, authored motion or generated media; flag any conflict with the approved plan instead of silently changing it. Reference-only attachments are context, not footage. Preserve source identities. Ask for clarification when claims are unsupported. Use only approved facts and user supplied copy. Do not invent prices, guarantees, endorsements, narration or captions. Original audio is kept unless settings.audio is silent. Supplied caption_text is exact. If a requested feature needs new media, propose_media rather than fake it. For a new brief replace the sample entirely; it is unrelated to the brief. For an edit read the existing composition and change only what was asked. Use preview to check and inspect your work before finishing.',{lookOnly:input.look_first===true,fromLook:input.from_look===true,styleNotes:!!(Array.isArray(input.style_notes)&&input.style_notes.length),baseFiles:!!input.base_bundle,musicBeats:!!musicBeats,narrationTiming:!!narrationTiming,talking:planMedia.some(m=>['talking_shot','talking_take'].includes(m.kind)),poses:planMedia.some(m=>['character_poses','character_variants'].includes(m.kind)),houseStyle:!!input.style,page:!!page,sfx:planMedia.some(m=>m.kind==='sfx'),narration:planMedia.some(m=>['voiceover','cloned_voiceover'].includes(m.kind)),music:planMedia.some(m=>m.kind==='music'),captions:!!settings.caption_text,speech:manifest.some(f=>f.purpose==='source'&&['video','audio'].includes(f.asset_type)),plan:!!input.plan})},
  // Shared craft rules apply to every build, whatever its style route.
  skills:(await loadCoreGuidance(guidanceDirectory))+'\n\n'+await readFile(guidanceDirectory+'/../craft.md','utf8')+(cards.text?'\n\n'+cards.text:''),signal,requireVisualReview:paid,
  // Unlimited local testing (frozen in the approved policy): limits sit far past any expected build so trajectories show real needs.
  limits:unlimited?{reviewReserveMs:critic?300000:0,repairs:1000,runs:1000,inspections:100,resultBytes:64000,usesPerTurn:20,criticCalls:critic?Math.max(0,Number(criticPolicy.max_calls)||0):0,calls:input.execution_policy.agent.max_calls,budgetUsd:input.execution_policy.agent.max_calls*input.execution_policy.agent.cost_limit_microusd/1e6,contextBytes:Number(input.execution_policy.agent.context_bytes)||600000,maxOutputTokens:Number(input.execution_policy.agent.max_output_tokens)||32000,totalOutputTokenAllowance:input.execution_policy.agent.max_calls*(Number(input.execution_policy.agent.max_output_tokens)||32000),elapsedMs:4*3600000}:{reviewReserveMs:critic?300000:0,repairs:paid?8:2,criticCalls:critic?Math.max(0,Number(criticPolicy.max_calls)||0):0,calls:input.execution_policy?.agent?.max_calls??0,budgetUsd:paid?(input.execution_policy?.agent?.max_calls??8)*(input.execution_policy?.agent?.cost_limit_microusd??300000)/1e6:0,contextBytes:paid?Math.max(96000,Number(input.execution_policy?.agent?.context_bytes)||0):200000,maxOutputTokens:Math.min(16384,Math.max(256,input.execution_policy?.agent?.max_output_tokens??4096)),totalOutputTokenAllowance:Math.max(98304,(input.execution_policy?.agent?.max_calls??12)*Math.min(16384,input.execution_policy?.agent?.max_output_tokens??4096)),elapsedMs:paid?1800000:600000},
  tools:{...(transcribe?{transcript:args=>transcribe(args)}:{}),inspect_reference:args=>invoke('inspect_reference',args),media:args=>invoke('media',args),run:args=>invoke('run',args),...(catalog.length?{catalog:catalogTool}:{}),...(critic?{critic,strip:args=>invoke('strip',args),detail:args=>invoke('detail',args)}:{}),...(buy?{buy:async args=>{
   // Stage what was bought into the project so the composition can use it at once.
   const r=await buy(args);if(!r?.ok)return r;
   const files=[];for(const f of r.files||[]){await copyFile(directory+'/inputs/source/'+f.name,directory+'/project/'+f.name).catch(()=>{});files.push({path:f.name,sha256:f.sha256});}
   return {...r,files};}}:{}),check:args=>invoke('check',args),snapshot:args=>invoke('snapshot',args),timeline:args=>invoke('timeline',args),guidance:name=>{
   // The style pack's worked example, frozen with the run: readable, never part of the bundle.
   if(name==='kit/motion-kit.md')return readFile(guidanceDirectory+'/../motion-kit.md','utf8');
   if(name==='kit/remotion.md')return readFile(guidanceDirectory+'/../remotion-kit.md','utf8');
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
 return {state,bundle,derived:workspace.assets.filter(a=>a.derivedFrom||a.operation==='run')};
}

// Explicit offline contract probe, not a generative model. It exercises reads,
// a source-preserving edit, checks, snapshots and finish through the real runner.
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
