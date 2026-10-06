// The art library the builder can use instead of drawing or generating generic visuals: line and filled icons
// (Lucide, Tabler) as SVG to inline, recolour and animate, and Microsoft's 3D emoji as transparent PNGs. Fetched by
// scripts/fetch-art-packs.mjs into runtime/art-packs/ with their licenses (all allow commercial use).
import {readFile,copyFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import path from 'node:path';

const words=s=>String(s||'').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
const STYLES=['line','filled','3d'];

export async function loadArt(dir){
  const data=JSON.parse(await readFile(path.join(dir,'index.json'),'utf8'));
  return {dir,packs:data.packs||{},items:(data.items||[]).map(i=>({...i,name:i.id.split(':').at(-1)}))};
}

/** Best matches for the words, optionally of one style (line, filled or 3d). */
export function searchArt(art,{query='',style=null,limit=12}={}){
  const q=words(query).filter(w=>!STYLES.includes(w)||!style),wantStyle=style||STYLES.find(s=>words(query).includes(s))||null;
  if(!q.length)return [];
  const scored=[];
  for(const item of art.items){
    if(wantStyle&&item.style!==wantStyle)continue;
    const name=words(item.name);let score=0,hit=0;
    for(const w of q){
      if(name.includes(w)){score+=5;hit++;}
      else if(item.words.includes(w)){score+=3;hit++;}
      else if(w.length>3&&item.words.some(x=>x.startsWith(w)||w.startsWith(x)&&x.length>3)){score+=1;hit++;}
    }
    if(!hit)continue;
    if(hit===q.length)score+=4;
    if(name.join(' ')===q.join(' '))score+=6;
    scored.push({item,score:score-name.length*0.05});
  }
  scored.sort((a,b)=>b.score-a.score);
  return scored.slice(0,limit).map(({item})=>({id:item.id,style:item.style,kind:item.kind,...(item.glyph?{glyph:item.glyph}:{}),tags:item.words.slice(0,6)}));
}

/**
 * One item to use. An SVG icon comes back as markup to place inline (its strokes and fills follow CSS color);
 * a 3D PNG is copied into the project and returned as a file to reference and protect like any input.
 */
export async function useArt(art,id,projectDir){
  const item=art.items.find(i=>i.id===id);
  if(!item)throw Error('No art item '+id+'; search first and use an id from the results');
  const source=path.join(art.dir,item.file);
  if(item.kind==='svg'){
    const svg=(await readFile(source,'utf8')).replace(/<!--[\s\S]*?-->/g,'').trim();
    return {id,kind:'svg',svg,how:'Place this <svg> inline in the HTML. Size it with width/height or CSS; its colour follows CSS color (stroke="currentColor"), so animate it like any element.',license:art.packs[item.pack]?.license||null};
  }
  const name='art-'+item.id.replace(/[^a-z0-9]+/gi,'-').toLowerCase()+path.extname(item.file);
  await copyFile(source,path.join(projectDir,name));
  const sha256=createHash('sha256').update(await readFile(path.join(projectDir,name))).digest('hex');
  return {id,kind:'png',file:{path:name,sha256},how:`Use it as <img src="${name}"> (transparent, 256 px square: keep it at or below about 260 px on screen).`,license:art.packs[item.pack]?.license||null};
}
