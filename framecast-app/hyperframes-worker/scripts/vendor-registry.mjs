// Vendors the HyperFrames registry (Apache-2.0, heygen-com/hyperframes) for the
// no-network sandbox: every block and component whose scripts we can serve
// locally is copied with its assets, CDN scripts are pointed at local files,
// Google Fonts links become @font-face rules for the fonts we ship, and a
// catalogue is written for the planner and the agent (name, what it is, tags,
// size, duration, variables, how it mounts, and its usage header).
//
//   node scripts/vendor-registry.mjs --source <hyperframes/registry> --out runtime/registry --catalog runtime/registry-catalog.json
import {readFile,writeFile,mkdir,cp,rm,readdir,stat} from 'node:fs/promises';
import path from 'node:path';import {createHash} from 'node:crypto';

// Libraries the sandbox serves at the project root (see live-tool.mjs).
const LOCAL={
 'gsap.min.js':'/gsap.min.js','CustomEase.min.js':'/gsap/CustomEase.min.js','MotionPathPlugin.min.js':'/gsap/MotionPathPlugin.min.js',
 'lottie_light.min.js':'/lottie_light.min.js',
};
// Fonts shipped in runtime/fonts, by the family name Google Fonts uses.
export const FONTS={'Inter':['inter.ttf','100 900'],'Bebas Neue':['bebas-neue.ttf','400'],'Space Grotesk':['space-grotesk.ttf','300 700'],'Playfair Display':['playfair.ttf','400 900'],'Anton':['anton.ttf','400'],'Caveat':['caveat.ttf','400 700']};
const UNSUPPORTED=/cdn\.jsdelivr\.net\/npm\/(three|d3|d3-delaunay|topojson-client|us-atlas|world-atlas|es-atlas|clipper-lib)@/;

export function itemDeps(html){
 const deps=new Set();
 for(const m of html.matchAll(/cdn\.jsdelivr\.net\/npm\/([a-z0-9-]+)@/g))deps.add(m[1]);
 return [...deps].sort();
}
function familiesIn(url){
 return [...decodeURIComponent(url.replace(/\+/g,' ')).matchAll(/family=([^:&]+)/g)].map(m=>m[1].trim());
}
// Rewrites one HTML file for the sandbox. Returns the text and what it needed.
export function rewriteHtml(html){
 const notes=[];let out=html;
 out=out.replace(/https:\/\/cdn\.jsdelivr\.net\/npm\/(gsap|lottie-web)@[0-9.]+\/(?:dist|build\/player)\/([A-Za-z_.]+)/g,(all,pkg,file)=>{
  if(LOCAL[file])return LOCAL[file];
  notes.push('unknown '+pkg+' file '+file);return all;
 });
 const families=new Set();
 out=out.replace(/<link[^>]+href="(https:\/\/fonts\.googleapis\.com\/css2?\?[^"]+)"[^>]*>/g,(all,url)=>{for(const f of familiesIn(url))families.add(f);return '';});
 out=out.replace(/<link[^>]+rel="preconnect"[^>]+>/g,'');
 out=out.replace(/@import\s+url\((['"]?)(https:\/\/fonts\.googleapis\.com\/[^)'"]+)\1\);?/g,(all,q,url)=>{for(const f of familiesIn(url))families.add(f);return '';});
 const faces=[...families].filter(f=>FONTS[f]).map(f=>`@font-face{font-family:"${f}";src:url(/${FONTS[f][0]});font-weight:${FONTS[f][1]}}`);
 const missing=[...families].filter(f=>!FONTS[f]);
 if(faces.length)out=out.replace(/<head>/i,'<head><style data-vendored-fonts>'+faces.join('')+'</style>');
 if(missing.length)notes.push('fonts not shipped (fallback): '+missing.join(', '));
 return {html:out,notes,families:[...families]};
}
// The usage header: the first comment of the file, which registry authors use for concept, variables and envelope.
export function headerDoc(html,max=1600){
 // The first comment that says more than the registry marker.
 for(const m of html.matchAll(/<!--([\s\S]*?)-->/g)){
  const text=m[1].replace(/^\s*hyperframes-registry-item:.*$/m,'').replace(/[ \t]+\n/g,'\n').trim();
  if(text.length>20)return text.length>max?text.slice(0,max)+'…':text;
 }
 return '';
}
export function mountKind(type,html){
 if(type==='block')return 'sub-composition';
 return /<template>/.test(html)&&/data-composition-id=/.test(html)?'sub-composition':'snippet';
}
const sha=b=>createHash('sha256').update(b).digest('hex');

export async function vendorRegistry({source,out,catalog}){
 await rm(out,{recursive:true,force:true});await mkdir(out,{recursive:true});
 const items=[],excluded=[],files={};
 for(const kind of ['blocks','components']){
  const dir=path.join(source,kind);
  for(const name of (await readdir(dir)).sort()){
   const itemDir=path.join(dir,name);
   let meta;try{meta=JSON.parse(await readFile(path.join(itemDir,'registry-item.json'),'utf8'));}catch{continue;}
   const type=kind==='blocks'?'block':'component';
   const entries=(meta.files||[]).filter(f=>f.path&&f.target);
   const main=entries.find(f=>f.type==='hyperframes:composition')||entries[0];
   if(!main){excluded.push({name,reason:'no composition file'});continue;}
   const htmls=entries.filter(f=>f.path.endsWith('.html'));
   let deps=new Set(),bad=false;
   const texts={};
   for(const f of htmls){const t=await readFile(path.join(itemDir,f.path),'utf8');texts[f.path]=t;for(const d of itemDeps(t))deps.add(d);if(UNSUPPORTED.test(t))bad=true;}
   if(bad){excluded.push({name,reason:'needs '+[...deps].filter(d=>!['gsap','lottie-web'].includes(d)).join(', ')});continue;}
   // An item whose listed files are not all in the source (media kept elsewhere) would break at render: skip it.
   const absent=[];for(const f of entries)try{await stat(path.join(itemDir,f.path));}catch{absent.push(f.path);}
   if(absent.length){excluded.push({name,reason:'missing files: '+absent.slice(0,3).join(', ')+(absent.length>3?' …':'')});continue;}
   // Anything still fetched from the network after the rewrite (a CDN font in CSS) would fail offline.
   const leftover=Object.values(texts).map(t=>rewriteHtml(t).html).find(t=>/https:\/\/(cdn\.jsdelivr\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|unpkg\.com)\//.test(t));
   if(leftover){excluded.push({name,reason:'network asset'});continue;}
   const notes=[];
   for(const f of entries){
    const target=path.join(out,f.target);await mkdir(path.dirname(target),{recursive:true});
    if(texts[f.path]!==undefined){const r=rewriteHtml(texts[f.path]);notes.push(...r.notes);await writeFile(target,r.html);files[f.target]=sha(r.html);}
    else {await cp(path.join(itemDir,f.path),target);files[f.target]=sha(await readFile(target));}
   }
   const mainHtml=texts[main.path]||'';
   items.push({name,type,title:meta.title||name,description:meta.description||'',tags:meta.tags||[],license:meta.license||'Apache-2.0',
    dimensions:meta.dimensions||null,duration:meta.duration??null,variables:(meta.variables||[]).map(v=>({id:v.id,type:v.type,label:v.label,default:v.default,...(v.options?{options:v.options.map(o=>o.value??o)}:{}),...(v.maxLength?{maxLength:v.maxLength}:{})})),
    entry:main.target,files:entries.map(f=>f.target),mount:mountKind(type,mainHtml),deps:[...deps].sort(),doc:headerDoc(mainHtml),...(notes.length?{notes:[...new Set(notes)]}:{})});
  }
 }
 const manifest={source:'https://github.com/heygen-com/hyperframes (registry/, Apache-2.0)',vendored_at:new Date().toISOString().slice(0,10),items:items.length,excluded,files};
 await writeFile(path.join(out,'manifest.json'),JSON.stringify(manifest,null,1));
 await writeFile(catalog,JSON.stringify({source:manifest.source,items},null,0));
 return {items,excluded};
}

if(process.argv[1]&&path.resolve(process.argv[1])===path.resolve(new URL(import.meta.url).pathname)){
 const arg=k=>{const i=process.argv.indexOf('--'+k);return i>0?process.argv[i+1]:null;};
 const source=arg('source'),out=arg('out')||'runtime/registry',catalog=arg('catalog')||'runtime/registry-catalog.json';
 if(!source)throw Error('--source <hyperframes/registry> is required');
 const r=await vendorRegistry({source,out,catalog});
 console.log('vendored',r.items.length,'items; excluded',r.excluded.length,r.excluded.map(e=>e.name+' ('+e.reason+')').join('; '));
}
