import {copyFile,readFile,readdir,writeFile} from 'node:fs/promises';
import {Workspace,digest} from './workspace.mjs';
import {runAgent} from './runner.mjs';
import {accountedCall} from './accounted-call.mjs';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';

// Dependencies are host-owned. Neither a prompt nor a tool result chooses the
// provider, accounting policy, filesystem root or executable.
export async function executeCompositionAgent({directory,input,manifest,provider,begin,settle,bindPrediction,receipt,invoke,transcribe,guidanceDirectory,signal}) {
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
 const state=await runAgent({stateFile:directory+'/agent-state.json',workspace,provider:accountedProvider,
  context:{brief:messages.at(-1).content,messages,baseRevision:input.base_revision_id,
   assets:manifest.map(({storage_path,path,...file})=>({...file,renderable:file.purpose==='source'})),
   plan:input.plan??null,
   variantDirection:input.variant_direction??null,approvedFacts:[...(settings.approved_facts??[]),...(input.plan?.on_screen_copy??[])],settings,output:{width:dims[0],height:dims[1],durationSeconds:settings.duration_seconds??15},
   runtimeFiles:[{path:'gsap.min.js',purpose:'Local GSAP runtime'},{path:'font.ttf',purpose:'Local DejaVuSans font'}],
   instruction:'When supplied footage has speech, call transcript on it first and time on-screen text and visuals to the spoken words. Make text and colours editable without you: declare data-composition-variables on <html> for every on-screen line (string; ids headline, line_1, line_2 ..., cta) and the main colours (color; ids color_background, color_accent, color_text), bind colours with var(--id) and read text once at init with window.__hyperframes.getVariables(), keeping the authored text as fallback. When a plan is present it is the user-approved direction: use plan.on_screen_copy exactly as the on-screen words (they are also approved facts), follow plan.choices, keep every plan.kept_as_is item unchanged, follow the scene order and timing unless the render requires a small adjustment, and do not add new generated or paid media the plan does not list; free media edits to supplied footage (the media action) are allowed when they improve the result. Reference-only attachments are context, not footage. Preserve source identities. Ask for clarification when claims are unsupported. Use only approved facts and user supplied copy. Do not invent prices, guarantees, endorsements, narration or captions. Original audio is kept unless settings.audio is silent. Supplied caption_text is exact. If a requested feature needs new media, propose_media rather than fake it. For a new brief replace the sample entirely; it is unrelated to the brief. For an edit read the existing composition and change only what was asked. Use preview to check and inspect your work before finishing.'},
  skills:await loadCoreGuidance(guidanceDirectory),signal,requireVisualReview:paid,
  limits:{repairs:paid?4:2,calls:input.execution_policy?.agent?.max_calls??0,budgetUsd:paid?2.4:0,contextBytes:paid?60000:200000,maxOutputTokens:Math.min(8192,Math.max(256,input.execution_policy?.agent?.max_output_tokens??4096)),elapsedMs:600000},
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
