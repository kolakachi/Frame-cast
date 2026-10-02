// The vendored HyperFrames registry: a searchable catalogue for the planner and
// the agent, and the staging that puts a wired item's files beside the
// composition before the sandbox checks or renders it.
import {readFile,mkdir,cp,access} from 'node:fs/promises';
import path from 'node:path';

export async function loadCatalog(file){
 const data=JSON.parse(await readFile(file,'utf8'));
 return Array.isArray(data.items)?data.items:[];
}
const words=s=>String(s||'').toLowerCase().match(/[a-z0-9]+/g)||[];
// Ranked search over name, title, tags and description. tag and type narrow; query ranks.
export function searchCatalog(items,{query='',tag='',type='',limit=20}={}){
 const q=words(query),t=String(tag||'').toLowerCase().trim(),ty=String(type||'').toLowerCase().trim();
 const scored=[];
 for(const it of items){
  if(ty&&it.type!==ty)continue;
  if(t&&!(it.tags||[]).some(x=>x.toLowerCase()===t))continue;
  let score=0;
  if(q.length){
   const name=words(it.name),title=words(it.title),tags=(it.tags||[]).flatMap(words),desc=words(it.description),doc=words(it.doc).slice(0,120);
   for(const w of q){
    if(name.includes(w))score+=6;else if(name.some(n=>n.startsWith(w)))score+=3;
    if(title.includes(w))score+=4;
    if(tags.includes(w))score+=4;else if(tags.some(x=>x.startsWith(w)))score+=2;
    if(desc.includes(w))score+=2;
    if(doc.includes(w))score+=1;
   }
   if(!score)continue;
  }
  scored.push({score,it});
 }
 scored.sort((a,b)=>b.score-a.score||a.it.name.localeCompare(b.it.name));
 return scored.slice(0,Math.max(1,Math.min(40,limit))).map(({it})=>({name:it.name,type:it.type,title:it.title,description:it.description,tags:(it.tags||[]).slice(0,8),
  dimensions:it.dimensions,duration:it.duration,mount:it.mount,entry:it.entry,variables:it.variables||[]}));
}
// One item in full, for the agent to read before wiring it.
export function catalogItem(items,name){
 const it=items.find(i=>i.name===name);
 return it?{...it}:null;
}
// Names of the vendored items a composition wires with data-composition-src="compositions/...".
export function referencedItems(html,items){
 const found=new Map();
 for(const m of String(html).matchAll(/data-composition-src\s*=\s*["']([^"']+)["']/g)){
  const src=m[1].replace(/^\.?\//,'');
  const it=items.find(i=>i.entry===src||i.files.includes(src));
  if(it)found.set(it.name,it);else found.set(src,null);
 }
 return [...found.entries()].map(([ref,item])=>({ref,item}));
}
// Copies every wired item's files (and the libraries they load) into the staged project root.
export async function stageRegistryFiles({root,registryRoot,html,items,libs}){
 const staged=[],missing=[];
 for(const {ref,item} of referencedItems(html,items)){
  if(!item){missing.push(ref);continue;}
  for(const f of item.files){
   const from=path.join(registryRoot,f),to=path.join(root,f);
   try{await access(from);}catch{missing.push(f);continue;}
   await mkdir(path.dirname(to),{recursive:true});await cp(from,to);staged.push(f);
  }
 }
 for(const [name,from] of Object.entries(libs||{})){
  try{await access(from);await mkdir(path.dirname(path.join(root,name)),{recursive:true});await cp(from,path.join(root,name),{recursive:true});}catch{/* a library that is not installed is reported by the check */}
 }
 return {staged,missing};
}
