import {readFile,mkdir,copyFile} from 'node:fs/promises';import path from 'node:path';import {fileURLToPath} from 'node:url';import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {loadCoreGuidance,readGuidanceReference} from './context.mjs';
import {Workspace,digest} from './workspace.mjs';import {runAgent} from './runner.mjs';import {ReplicateProvider} from './replicate.mjs';import {TestBudget} from './budget.mjs';
const worker=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const [id,model='sonnet',mode='new',style='restrained']=process.argv.slice(2);if(!/^[a-z0-9-]+$/.test(id)||!['sonnet','opus'].includes(model)||!['new','edit','continue','verify'].includes(mode))throw Error('Invalid test');
const out=worker+'/artifacts/live/'+id,root=out+'/project';await mkdir(root,{recursive:true});
if(mode==='new'){await copyFile(worker+'/fixtures/index.html',root+'/index.html');await copyFile(worker+'/artifacts/real-inputs/product.png',root+'/product.png');}
const line=(await readFile(path.resolve(worker,'../api/.env'),'utf8')).split('\n').find(s=>s.startsWith('REPLICATE_API_TOKEN='));
const token=line?.slice(line.indexOf('=')+1).trim().replace(/^['"]|['"]$/g,'');if(!token)throw Error('Missing configured token');
const contract=JSON.parse(await readFile(worker+'/agent/contracts/'+model+'.json','utf8'));const provider=new ReplicateProvider({contract,token,enabled:true,maxCallUsd:1});
let cached=process.env.HF_RECONCILED_RESPONSE?JSON.parse(await readFile(process.env.HF_RECONCILED_RESPONSE,'utf8')):null;
const budget=new TestBudget(worker+'/artifacts/live/budget.json',5),complete=provider.complete.bind(provider);
provider.complete=async request=>{if(cached){const response=cached;cached=null;if(response.status!=='succeeded')throw Error('Reconciled call not successful');return {text:response.output.join(''),predictionId:response.id,metrics:response.metrics};}const settle=await budget.reserve({...request,model:contract.model});const result=await complete(request);await settle(result);return result;};
const exec=promisify(execFile);
async function tool(operation,times,signal){
 await exec('/Applications/Docker.app/Contents/Resources/bin/docker',['compose','-f',worker+'/compose.local.yml','run','--rm','smoke','node','agent/live-tool.mjs',id,operation,...(times?[times.join(',')]:[])],{signal,timeout:160000,maxBuffer:2000000,env:{PATH:'/Applications/Docker.app/Contents/Resources/bin:/usr/local/bin:/usr/bin:/bin'}});
 const result=JSON.parse(await readFile(out+'/'+operation+'/result.json','utf8'));
 if(operation==='snapshot'&&result.ok)result.providerImage='data:image/jpeg;base64,'+(await readFile(out+'/snapshot/contact-sheet.jpg')).toString('base64');
 return result;
}
const skills=await loadCoreGuidance(worker+'/agent/guidance');
const brief=mode==='verify'?'Finish the current draft. It already passed technical validation. Read it once if needed, check, snapshot at 1,7,13 seconds, visually review, and finish. Do not modify anything unless a blocking error or clear visual defect occurs. Ignore non-blocking warnings.':mode!=='edit'?'Create an elegant 15-second portrait product teaser using product.png. Dark warm background, bold readable typography, purposeful restrained motion, distinct opening, product reveal and final CTA. Approved copy only: A closer look. Your everyday bottle. Explore the details. No price, guarantees, endorsements or invented specifications. Use the actual photo unchanged with object-fit contain. Completely redesign the starting fixture; remove product.svg references. Use local gsap.min.js and font.ttf. Keep root 1080x1920 duration 15.':'Keep everything else, but simplify the opening. Preserve product photo, all later beats, duration and CTA. Read the existing draft and make a targeted patch.';
const workspace=new Workspace(root,[{path:'product.png',sha256:digest(await readFile(root+'/product.png'))}]);
const state=await runAgent({stateFile:out+'/'+mode+'-state.json',context:{brief:brief+(style==='educational'?' Use a clean educational visual hierarchy with distinct sequential sections, but add no facts beyond the approved copy.':style==='energetic'?' Use bold orange graphic shapes and energetic social typography, preserving clear reading pauses and approved copy.':''),baseRevision:mode,assets:workspace.assets},workspace,provider,skills,requireVisualReview:true,initialImage:'data:image/png;base64,'+(await readFile(root+'/product.png')).toString('base64'),limits:{calls:10,elapsedMs:600000,maxOutputTokens:4096,totalOutputTokenAllowance:40960,budgetUsd:10},tools:{guidance:p=>readGuidanceReference(worker+'/agent/guidance',p),check:({signal})=>tool('check',null,signal),snapshot:({times,signal})=>tool('snapshot',times,signal)}});
console.log(JSON.stringify({status:state.status,reason:state.reason,calls:state.calls,summary:state.summary}));
if(state.status==='preview_ready')console.log(JSON.stringify(await tool('render')));
