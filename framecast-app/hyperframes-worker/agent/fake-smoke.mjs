// Scripted provider, real upstream tools and renderer. NOT evidence of model creativity.
import {randomUUID} from 'node:crypto';
import {mkdir,copyFile,readFile,writeFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {Workspace,digest} from './workspace.mjs';import {runAgent} from './runner.mjs';
import {loadGuidance,loadCoreGuidance,readGuidanceReference} from './context.mjs';import {commandFor} from '../scripts/lib/commands.mjs';import {renderRun} from '../scripts/lib/render-run.mjs';
const exec=promisify(execFile),root='/tmp/agent-smoke',out='/output/agent-smoke/'+randomUUID();
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});await mkdir(process.env.HOME,{recursive:true});
for(const file of ['index.html','product.svg'])await copyFile('/opt/worker/fixtures/'+file,root+'/'+file);
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
const assets=await Promise.all(['product.svg','gsap.min.js','font.ttf'].map(async file=>({path:file,sha256:digest(await readFile(root+'/'+file))})));
const skills=await loadGuidance({skillsRoot:'/opt/worker/node_modules/hyperframes/dist/skills',manifestPath:'/opt/worker/runtime/skills-manifest.json'});
const original=await readFile(root+'/index.html','utf8');
const lockedSourceFragments=[original.match(/<img\b[^>]*>/)[0]];
const core=await loadCoreGuidance('/opt/worker/agent/guidance');
await readGuidanceReference('/opt/worker/agent/guidance','references/determinism-rules.md');
const responses=[{type:'write',path:'index.html',content:'<h1>Incorrectly removed source</h1>'},{type:'read',path:'index.html'},{type:'patch',path:'index.html',before:'Explore the collection',after:'Meet your new favourite'},{type:'check'},{type:'snapshot',times:[1,12]},{type:'finish',summary:'CTA updated; source unchanged'}];let index=0;
const invoke=async(operation,times,signal)=>{
 const c=await commandFor(root,operation,times);
 try {const {stdout,stderr}=await exec(c.executable,c.args,{cwd:c.cwd,signal,timeout:120000,maxBuffer:16000000});await writeFile(out+'/'+operation+'.log',stdout+stderr);return {ok:true,output:stdout.slice(0,12000)};}
 catch(e){await writeFile(out+'/'+operation+'.log',(e.stdout||'')+(e.stderr||''));return {ok:false,error:'Upstream check failed; see local log'};}
};
const state=await runAgent({stateFile:out+'/state.json',context:{brief:'Change only the CTA',baseRevision:'fixture-v1',approvedFacts:[],lockedSourceFragments},workspace:new Workspace(root,assets),skills:skills+'\n'+core,provider:{id:'scripted-fixture-v1',maxCallUsd:0,complete:async()=>({text:JSON.stringify(responses[index++])})},tools:{check:({signal})=>invoke('check',[],signal),snapshot:({times,signal})=>invoke('snapshot',times,signal)}});
if(state.status!=='preview_ready'||state.repairs!==1)throw Error(JSON.stringify(state));
const expected=original.replace('Explore the collection','Meet your new favourite');
if(await readFile(root+'/index.html','utf8')!==expected)throw Error('Unexpected source change beyond CTA');
const render=await renderRun({project:root,outputRoot:out+'/renders',expected:{width:1080,height:1920,duration:15}});
if(render.status!=='ready')throw Error(JSON.stringify(render));
console.log(JSON.stringify({status:state.status,calls:state.calls,repairs:state.repairs,sourcePreservation:'Exact source diff: only CTA changed after rejected destructive edit',providerCostUsd:0,render},null,2));
