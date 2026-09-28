import {copyFile,readFile,readdir,writeFile} from 'node:fs/promises';
import {Workspace,digest} from './workspace.mjs';
import {runAgent} from './runner.mjs';
import {accountedCall} from './accounted-call.mjs';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';

// Dependencies are host-owned. Neither a prompt nor a tool result chooses the
// provider, accounting policy, filesystem root or executable.
export async function executeCompositionAgent({directory,input,manifest,provider,begin,settle,receipt,invoke,guidanceDirectory,signal}) {
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
 const accountedProvider={id:provider.id,maxCallUsd:provider.maxCallUsd,complete:args=>accountedCall({
  key:'agent-'+(++call),kind:'agent',input:{prompt:args.prompt,system:args.system,maxTokens:args.maxTokens,image:args.image??null},begin,settle,
  execute:()=>provider.complete(args),receipt,
 })};
 const state=await runAgent({stateFile:directory+'/agent-state.json',workspace:new Workspace(directory+'/project',assets),provider:accountedProvider,
  context:{brief:messages.at(-1).content,messages,baseRevision:input.base_revision_id,
   assets:manifest.map(({storage_path,path,...file})=>({...file,renderable:file.purpose==='source'})),
   approvedFacts:[],output:{width:1080,height:1920,durationSeconds:15},
   instruction:'Reference-only attachments are context, not footage. Preserve source identities. Ask for clarification when claims are unsupported.'},
  skills:await loadCoreGuidance(guidanceDirectory),signal,
  limits:{calls:input.execution_policy?.agent?.max_calls??0,budgetUsd:0,elapsedMs:600000},
  tools:{check:args=>invoke('check',args),snapshot:args=>invoke('snapshot',args),timeline:args=>invoke('timeline',args),guidance:name=>readGuidanceReference(guidanceDirectory,name)}});
 const bundle={};
 for(const name of (await readdir(directory+'/project')).filter(n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n)).sort())bundle[name]=await readFile(directory+'/project/'+name,'utf8');
 return {state,bundle};
}

// Explicit offline contract probe, not a generative model. It exercises reads,
// a source-preserving edit, checks, snapshots and finish through the real runner.
export function offlineContractProvider(baseBundle=null){
 const previous=baseBundle?.['index.html'];
 const from=previous?.includes('Local edit proof')?'Local edit proof':previous?'Local agent proof':'Explore the collection';
 const to=from==='Local agent proof'?'Local edit proof':'Local agent proof';
 let i=0;
 const responses=[{type:'read',path:'index.html'},
  {type:'patch',path:'index.html',before:from,after:to},
  {type:'check'},{type:'snapshot',times:[1,12]},
  {type:'finish',summary:'Offline agent contract sample; not AI-generated creative.'}];
 return {id:'offline-contract-v1',maxCallUsd:0,complete:async()=>({text:JSON.stringify(responses[i++])})};
}
