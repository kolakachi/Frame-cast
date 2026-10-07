// Brand fonts a brief or brand kit names ("DM Sans for words and Space Mono for labels") are fetched from Google Fonts
// before the build, so the video uses them instead of falling back to a bundled face (GTM-1 #5 used Inter for DM Sans).
// Only exact Google Fonts family names count; the bundled faces are never fetched again; at most three families.
import {mkdir,readFile,stat,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';

export const BUNDLED=['Inter','Anton','Bebas Neue','Playfair Display','Space Grotesk','Caveat'];
const LIST_URL='https://fonts.google.com/metadata/fonts',CSS_URL='https://fonts.googleapis.com/css2';
const DAY=86400000,MAX_FAMILIES=3,MAX_BYTES=2_000_000;

/** Google Fonts family names, cached for a day on the worker. */
export async function googleFamilies({fetchImpl=fetch,cacheFile=tmpdir()+'/wyv-google-fonts.json'}={}){
 try{const s=await stat(cacheFile);if(Date.now()-s.mtimeMs<DAY)return JSON.parse(await readFile(cacheFile,'utf8'));}catch{/* fetch it */}
 const r=await fetchImpl(LIST_URL,{signal:AbortSignal.timeout(20000)});
 if(!r.ok)throw Error('Google Fonts list '+r.status);
 const text=await r.text(),json=JSON.parse(text.slice(text.indexOf('{')));
 const names=(json.familyMetadataList||[]).map(f=>f.family).filter(n=>typeof n==='string');
 await writeFile(cacheFile,JSON.stringify(names)).catch(()=>{});
 return names;
}

/** Families named in the text or the brand kit, as written (exact case, whole words), longest names first. */
export function namedFamilies(text,brandFonts=[],families=[]){
 const known=new Set(families),found=[];
 for(const f of brandFonts)if(known.has(f)&&!found.includes(f))found.push(f);
 const t=' '+String(text||'')+' ';
 for(const f of [...families].sort((a,b)=>b.length-a.length)){
  if(found.includes(f)||f.length<4)continue;
  const re=new RegExp('(^|[^A-Za-z0-9])'+f.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'(?![A-Za-z0-9])');
  // A family inside a longer one already found ("Space Mono" within "Space Mono Bold") is not a second family.
  if(re.test(t)&&!found.some(g=>g.includes(f)))found.push(f);
 }
 return found.filter(f=>!BUNDLED.includes(f)).slice(0,MAX_FAMILIES);
}

const slug=f=>f.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');

/** Downloads each family's regular and bold TTFs into dir: [{path, family, weight, purpose}]. A family that fails is skipped. */
export async function fetchBrandFonts(families,dir,{fetchImpl=fetch}={}){
 await mkdir(dir,{recursive:true});
 const out=[];
 for(const family of families){
  try{
   const css=await (await fetchImpl(CSS_URL+'?family='+encodeURIComponent(family).replace(/%20/g,'+')+':wght@400;700&display=swap',{signal:AbortSignal.timeout(20000)})).text();
   for(const block of css.split('@font-face').slice(1)){
    const weight=(block.match(/font-weight:\s*(\d+)/)||[])[1]||'400',url=(block.match(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.ttf)\)/)||[])[1];
    if(!url||/font-style:\s*italic/.test(block))continue;
    const path='brand-'+slug(family)+'-'+weight+'.ttf';
    if(out.some(o=>o.path===path))continue;
    const r=await fetchImpl(url,{signal:AbortSignal.timeout(30000)});
    const bytes=Buffer.from(await r.arrayBuffer());
    if(!r.ok||bytes.length<1000||bytes.length>MAX_BYTES)continue;
    await writeFile(dir+'/'+path,bytes);
    out.push({path,family,weight:Number(weight),purpose:family+' '+weight+': the brand font the brief names. Load it with @font-face from this file and use it as the brief says.'});
   }
  }catch{/* a font that cannot be fetched falls back to the bundled faces, as before */}
 }
 return out;
}
