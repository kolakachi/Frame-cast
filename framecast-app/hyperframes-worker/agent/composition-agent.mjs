import {copyFile,readFile,readdir,writeFile} from 'node:fs/promises';
import {Workspace,digest} from './workspace.mjs';
import {runAgent} from './runner.mjs';
import {accountedCall} from './accounted-call.mjs';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';

// Dependencies are host-owned. Neither a prompt nor a tool result chooses the
// provider, accounting policy, filesystem root or executable.
export async function executeCompositionAgent({directory,input,manifest,planMedia=[],provider,begin,settle,bindPrediction,receipt,invoke,transcribe,guidanceDirectory,signal}) {
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
 const messages=input.messages??[];
 if(!messages.length||!messages.every(m=>['user','assistant'].includes(m.role)&&typeof m.content==='string'))throw Error('Invalid frozen conversation');
 let call=0;
 const accountedProvider={id:provider.id,maxCallUsd:provider.maxCallUsd,complete:async args=>{
  if(provider.prepareImage && args.image)args={...args,image:await provider.prepareImage(args.image,args.signal)};
  // A failed reservation means no call was started: nothing to reconcile.
  if(provider.reserve)try{await provider.reserve();}catch(e){if(e.code!=='BUDGET_EXHAUSTED')e.code='NOT_STARTED';throw e;}
  return accountedCall({
  key:'agent-'+(++call),kind:'agent',input:{prompt:args.prompt,system:args.system,maxTokens:args.maxTokens,image:args.image??null},begin,settle,
  execute:attemptId=>provider.complete({...args,attemptId,recordPrediction:args.onPrediction,onPrediction:async id=>{
   // Record the provider identity in both app accounting and the local journal.
   if(!bindPrediction)throw Error('Prediction recorder is required');
   await bindPrediction(attemptId,id);await args.onPrediction(id);
  }}),receipt,
 });}};
 const paid=input.mode==='agent',settings=input.settings??{};
 const dims=({'9:16':[1080,1920],'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio??'9:16'];
 const workspace=new Workspace(directory+'/project',assets);
 // A captured web page is shown to the agent as its first image, so it can rebuild the brand's real screens.
 let initialImage;
 const page=manifest.find(f=>f.purpose==='reference'&&f.asset_type==='image'&&f.reference?.from==='page');
 if(page){try{const bytes=await readFile(directory+'/inputs/'+page.path);if(bytes.length<=1000000)initialImage='data:image/jpeg;base64,'+bytes.toString('base64');}catch{/* the notes still describe it */}}
 const state=await runAgent({initialImage,stateFile:directory+'/agent-state.json',workspace,provider:accountedProvider,
  context:{brief:messages.at(-1).content,messages,baseRevision:input.base_revision_id,
   // An edit of an existing version changes it with patches; only a redesign may rewrite it.
   editOnly:!!input.base_bundle&&!/\b(redesign|start over|from scratch|completely new|brand new|totally different)\b/i.test(String(messages.at(-1)?.content||'')),
   assets:manifest.map(({storage_path,path,...file})=>({...file,renderable:file.purpose==='source'})),
   plan:input.plan??null,
   planMedia,
   houseStyle:input.style??null,
   variantDirection:input.variant_direction??null,approvedFacts:[...(settings.approved_facts??[]),...(input.plan?.on_screen_copy??[])],settings,output:{width:dims[0],height:dims[1],durationSeconds:settings.duration_seconds??15},
   runtimeFiles:[{path:'gsap.min.js',purpose:'Local GSAP runtime'},{path:'font.ttf',purpose:'DejaVu Sans, plain fallback'},
    {path:'inter.ttf',purpose:'Inter, variable weight 100-900: clean modern sans for body and bold headlines'},
    {path:'anton.ttf',purpose:'Anton, heavy condensed display: punchy ad headlines'},
    {path:'bebas-neue.ttf',purpose:'Bebas Neue, tall all-caps display'},
    {path:'playfair.ttf',purpose:'Playfair Display, variable weight 400-900: editorial serif'},
    {path:'space-grotesk.ttf',purpose:'Space Grotesk, variable weight 300-700: techy geometric sans'},
    {path:'caveat.ttf',purpose:'Caveat, variable weight 400-700: handwritten accents'}],
   fontNote:'Load fonts with @font-face using these exact file names; declare font-weight ranges for variable fonts (for example font-weight:100 900 for Inter) so bold renders truly bold.',
   instruction:'When planMedia has a character_poses item, its files are one character in labelled poses on transparent backgrounds: use that character as the narrator across the video, switching poses on the beats they fit (talking while the voice speaks, pointing at a UI card as it lands, surprised on a reveal), always the same character; give it a print texture with a CSS dot-screen overlay (radial-gradient dots with mix-blend-mode) when the style calls for halftone. Register window.__timelines in an inline script at the end of index.html even when the animation code lives in main.js; the check reads index.html. Keep every write under about 6,000 characters so it is never cut off: put markup in index.html, styles in style.css and animation code in main.js as separate files linked from index.html. When a web page reference is attached, its screenshot is your first image: rebuild two to four of its real screens (hero, a feature, the product UI, pricing or dashboard) as HTML cards in the brand look, and build each card on the beat its feature is spoken (data-spoken). Numbers on screen (prices, percentages, counts) must come from the approved facts, copy or script; otherwise use a neutral placeholder such as Your price. Supplied product screenshots are the most faithful UI when present. Sound mix: a music file from planMedia is the bed, so place it as one audio clip for the whole video, with volume about 0.18 under narration (0.35 with no voice) and a fade over the last second. An sfx file is a sheet of cues with start and end times in planMedia cues: play each cue with its own audio clip using data-media-start at the cue start and a slot as long as the cue, volume about 0.5, on the beat it belongs to (a card landing, a cut, a button press); use at least three cues when you have them and never let them cover a spoken word. For per-word or per-letter kinetic type, wrap each animated line in one container with data-layout-allow-overlap so the layout check accepts the intended overlap. When planMedia includes narration (a voiceover file), it is the approved script: place it as an audio clip that starts near 0.3 s, call transcript on that file, and tag each on-screen line or UI card that should land on a spoken phrase with data-spoken set to those exact words so it appears as it is said; you may set one accent word per line in a contrasting style (for example italic Playfair). When houseStyle is set it is the saved house look of this workspace: use its palette, type, motion and pacing unless the brief or plan says otherwise. planMedia lists what was bought for this plan: use each succeeded file (by its file name in assets) where the plan said; apply brand colours and fonts when given; for a failed item, work around it without inventing a substitute and mention it in your summary. Assets with renderable false and a reference field are style guides: follow their reference notes for pacing, structure, look and motion, but never copy their characters, logos, on-screen text, speech or footage. When supplied footage has speech, call transcript on it first and time on-screen text and visuals to the spoken words. Make text and colours editable without you: declare data-composition-variables on <html> for every on-screen line (string; ids headline, line_1, line_2 ..., cta) and the main colours (color; ids color_background, color_accent, color_text), bind colours with var(--id) and read text once at init with window.__hyperframes.getVariables(), keeping the authored text as fallback. When a plan is present it is the user-approved direction: use plan.on_screen_copy exactly as the on-screen words (they are also approved facts), follow plan.choices, keep every plan.kept_as_is item unchanged, follow the scene order and timing unless the render requires a small adjustment, and do not add new generated or paid media the plan does not list; free media edits to supplied footage (the media action) are allowed when they improve the result. Reference-only attachments are context, not footage. Preserve source identities. Ask for clarification when claims are unsupported. Use only approved facts and user supplied copy. Do not invent prices, guarantees, endorsements, narration or captions. Original audio is kept unless settings.audio is silent. Supplied caption_text is exact. If a requested feature needs new media, propose_media rather than fake it. For a new brief replace the sample entirely; it is unrelated to the brief. For an edit read the existing composition and change only what was asked. Use preview to check and inspect your work before finishing.'},
  skills:await loadCoreGuidance(guidanceDirectory),signal,requireVisualReview:paid,
  limits:{repairs:paid?4:2,calls:input.execution_policy?.agent?.max_calls??0,budgetUsd:paid?(input.execution_policy?.agent?.max_calls??8)*(input.execution_policy?.agent?.cost_limit_microusd??300000)/1e6:0,contextBytes:paid?60000:200000,maxOutputTokens:Math.min(8192,Math.max(256,input.execution_policy?.agent?.max_output_tokens??4096)),totalOutputTokenAllowance:Math.max(98304,(input.execution_policy?.agent?.max_calls??12)*Math.min(8192,input.execution_policy?.agent?.max_output_tokens??4096)),elapsedMs:600000},
  tools:{...(transcribe?{transcript:args=>transcribe(args)}:{}),media:args=>invoke('media',args),check:args=>invoke('check',args),snapshot:args=>invoke('snapshot',args),timeline:args=>invoke('timeline',args),guidance:name=>readGuidanceReference(guidanceDirectory,name)}});
 const bundle={};
 for(const name of (await readdir(directory+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)).sort())bundle[name]=await readFile(directory+'/project/'+name,'utf8');
 // Files the sandbox derived during this run, with where they came from.
 return {state,bundle,derived:workspace.assets.filter(a=>a.derivedFrom)};
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
