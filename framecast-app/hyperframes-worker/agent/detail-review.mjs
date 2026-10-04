// Close inspection for the reviewer: full-resolution crops of the largest text, logos and pictures at each
// beat's key moment, and frame-by-frame sequences across requested character actions. Taken from the
// composition in the same runtime the renderer uses, so what is inspected is what renders.
import {readFile,mkdir,readdir,unlink} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {serve} from './reads-check.mjs';
const run=promisify(execFile);
export const LIMITS={times:6,perTime:2,sequences:3,perSequence:10,cells:30};

// Runs in the page: the biggest readable text blocks and pictures on screen now.
function candidates(minFont){
 const W=innerWidth,H=innerHeight,out=[];
 const shown=el=>{for(let e=el;e&&e.nodeType===1;e=e.parentElement){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden'||parseFloat(s.opacity)<0.5)return false;}return true;};
 for(const el of document.body.querySelectorAll('*')){
  if(['SCRIPT','STYLE'].includes(el.tagName))continue;
  const r=el.getBoundingClientRect();if(r.width<8||r.height<8||r.right<0||r.bottom<0||r.left>W||r.top>H)continue;
  const own=[...el.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent).join(' ').replace(/\s+/g,' ').trim();
  const media=['IMG','svg','SVG','VIDEO','CANVAS'].includes(el.tagName);
  const text=own&&parseFloat(getComputedStyle(el).fontSize)>=minFont;
  if(!(text||(media&&r.width*r.height>=W*H*0.02))||!shown(el))continue;
  if(el.closest('svg')&&el.tagName!=='svg'&&el.tagName!=='SVG')continue;
  out.push({what:text?'text "'+own.slice(0,40)+'"':el.tagName.toLowerCase()+(el.getAttribute('src')?' '+el.getAttribute('src').slice(0,40):''),x:Math.max(0,r.left-12),y:Math.max(0,r.top-12),
   w:Math.min(W,r.right+12)-Math.max(0,r.left-12),h:Math.min(H,r.bottom+12)-Math.max(0,r.top-12),area:r.width*r.height,text:!!text});
 }
 // Text first (legibility is the common miss), then the largest pictures; nested duplicates collapse to the outer box.
 return out.sort((a,b)=>(b.text-a.text)||(b.area-a.area));
}

export async function detailSheet({root,width,height,times=[],sequences=[],out,browserPath=process.env.HYPERFRAMES_BROWSER_PATH}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 await mkdir(out,{recursive:true});for(const f of await readdir(out))if(/^(d-\d+|cell-\d+)\.png$/.test(f))await unlink(out+'/'+f);
 const server=await serve(root,runtime);
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu','--font-render-hinting=none']});
 const cells=[];
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  await page.evaluate(()=>{window.__player.enableRenderMode?.();return document.fonts?.ready;});
  const seek=t=>page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},t);
  const shot=async(clip,what,t)=>{if(cells.length>=LIMITS.cells)return;const file=`${out}/d-${String(cells.length).padStart(2,'0')}.png`;await page.screenshot({path:file,clip});cells.push({t:+t.toFixed(2),what,file});};
  for(const t of times.slice(0,LIMITS.times)){
   await seek(t);
   const found=await page.evaluate(candidates,Math.round(height*0.03));
   // The best text (a real word or more) and the best picture, then anything else; nested boxes count once.
   const inside=(c,k)=>c.x>=k.x-4&&c.y>=k.y-4&&c.x+c.w<=k.x+k.w+4&&c.y+c.h<=k.y+k.h+4;
   const pool=found.filter(c=>!c.text||c.what.replace(/^text "|"$/g,'').trim().length>=3);
   const kept=[];
   for(const c of [pool.find(c=>c.text),pool.find(c=>!c.text),...pool].filter(Boolean)){if(kept.length>=LIMITS.perTime)break;if(kept.includes(c)||kept.some(k=>inside(c,k)||inside(k,c)))continue;kept.push(c);}
   for(const c of kept)await shot({x:c.x,y:c.y,width:Math.max(8,c.w),height:Math.max(8,c.h)},c.what,t);
  }
  for(const [start,end,label] of sequences.slice(0,LIMITS.sequences)){
   const n=Math.max(2,Math.min(LIMITS.perSequence,Math.ceil((end-start)/0.15)+1));
   for(let i=0;i<n;i++){const t=start+(end-start)*i/(n-1);await seek(+t.toFixed(3));await shot({x:0,y:0,width,height},`${label} ${i+1}/${n}`,t);}
  }
 }finally{await browser.close();server.close();}
 if(!cells.length)return {ok:false,error:'Nothing to inspect closely'};
 // One sheet: every crop fitted into a landscape cell, six across.
 // Each cell is fitted to the same size first: an image sequence of mixed sizes loses frames when tiled directly.
 for(const [i,c] of cells.entries())await run('ffmpeg',['-y','-v','error','-i',c.file,'-vf','scale=480:270:force_original_aspect_ratio=decrease,pad=480:270:(ow-iw)/2:(oh-ih)/2:0x202020','-frames:v','1',`${out}/cell-${String(i).padStart(2,'0')}.png`],{timeout:30000});
 const rows=Math.ceil(cells.length/6);
 await run('ffmpeg',['-y','-threads','1','-framerate','1','-i',out+'/cell-%02d.png','-vf',`tile=6x${rows}:padding=4:color=black`,'-frames:v','1','-q:v','4',out+'/detail.jpg'],{timeout:60000,maxBuffer:4000000});
 return {ok:true,cells:cells.map(({file,...c})=>c)};
}
