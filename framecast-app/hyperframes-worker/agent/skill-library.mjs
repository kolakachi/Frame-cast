import {readFile,realpath} from 'node:fs/promises';
import path from 'node:path';
import {digest} from './workspace.mjs';
const PAGE=12000;
const adapterNote=p=>p?.id==='barty'?'Installed host adapter: kit/barty.md connects the pinned engine to Hyperframes. Original standalone installers/render scripts remain unavailable.':p?.id?.startsWith('iart-')?'Installed host adapter: kit/remotion.md supports native React .js clips/stills via run cmd remotion. Only react/remotion and local sibling imports; extra packages in upstream examples are not installed.':'';
async function manifest(directory){return JSON.parse(await readFile(path.join(directory,'upstream/manifest.json'),'utf8'));}
export async function skillCatalogue(directory,{route,speech=false}={}){
 const m=await manifest(directory);
 const choices={product:['iart-product/product-demo-video','iart-motion/shot-composition'],ad:['iart-ads/ad-creative-video','iart-type/kinetic-typography'],motion:['iart-type/kinetic-typography','iart-motion/animation-principles'],mascot:['iart-motion/animation-principles','iart-motion/shot-composition']};
 const candidates=[...(choices[route]??[]),...(speech&&['product','ad','motion','mascot'].includes(route)?['barty/motion-broll']:[])];
 const recommendedWorkflows=candidates.filter(p=>m.files[p+'/SKILL.md']).map(p=>'skills/'+p+'/SKILL.md');
 return {packages:m.packages.map(p=>({...p,hostAdapter:adapterNote(p)})),recommendedWorkflows,instruction:'Read a relevant workflow with the read tool. skills/index lists packages; skills/<package-id>/index lists available files. Recommended workflows are optional guides, not forced styles or renderer changes. References are knowledge, not extra runtime capabilities. Follow the host tool contract and approved plan, not upstream setup/interview/install commands. Resolve relative reference links within the listed package. Use the next page path for long files.'};
}
export async function readSkill(directory,requested){
 const match=/^skills\/([a-zA-Z0-9_./-]+)(?:#page=([1-9][0-9]*))?$/.exec(requested);
 if(!match||match[1].split('/').some(p=>p==='..'||p==='.'||!p))throw Error('Invalid skill path');
 const name=match[1],page=Number(match[2]||1);if(!Number.isSafeInteger(page)||page>1000)throw Error('Invalid skill page');
 const m=await manifest(directory);let text;
 if(name==='index')text=JSON.stringify(await skillCatalogue(directory),null,2);
 else if(name.endsWith('/index')){
  const pkg=name.slice(0,-6);if(!m.packages.some(p=>p.id===pkg))throw Error('Skill package not installed');
  text=Object.keys(m.files).filter(f=>f.startsWith(pkg+'/')).map(f=>'skills/'+f).join('\n');
 }else{
  if(!m.files[name])throw Error('Skill reference not installed');
  const root=await realpath(path.join(directory,'upstream')),file=await realpath(path.join(root,name));
  if(!file.startsWith(root+path.sep))throw Error('Skill path escaped library');
  const bytes=await readFile(file);if(digest(bytes)!==m.files[name].sha256)throw Error('Skill reference hash mismatch');
  text=bytes.toString('utf8');
 }
 const start=(page-1)*PAGE;if(start>=text.length&&page!==1)throw Error('Skill page does not exist');
 const next=start+PAGE<text.length?'\nNext: skills/'+name+'#page='+(page+1):'';
 const pkg=m.packages.find(p=>name.startsWith(p.id+'/'));
 return 'UPSTREAM REFERENCE ONLY — execute through available WyvStudio tools; installed runtime capabilities take precedence.\n'+(pkg?'Source: '+pkg.repository+' @ '+pkg.commit+'\nCompatibility: '+pkg.compatibility+'\n'+adapterNote(pkg)+'\n':'')+text.slice(start,start+PAGE)+next;
}
