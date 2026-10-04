// Copying a reference exactly: every kept moment's elements sit in the reference's slots. The builder marks
// each built element with data-ref="<moment id>:<element index>" (plan.reference_layout); this measures where
// each one actually renders at its moment's key time, in the same runtime the renderer uses, and compares
// it with the reference's box. Also: every moment must have been looked at in the reference first.
import {readFile} from 'node:fs/promises';
import {serve} from './reads-check.mjs';

export const TOLERANCE={centre:0.08,size:0.5};

/** Rendered boxes of [data-ref] elements at each time, as fractions of the frame: {t: [{ref, box, visible}]}. */
export async function measureLayout({root,width,height,times,browserPath=process.env.HYPERFRAMES_BROWSER_PATH}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 const server=await serve(root,runtime);
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu','--font-render-hinting=none']});
 const out={};
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  await page.evaluate(()=>{window.__player.enableRenderMode?.();return document.fonts?.ready;});
  for(const t of times){
   await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},t);
   out[t]=await page.evaluate(()=>{
    const W=innerWidth,H=innerHeight;
    const shown=el=>{for(let e=el;e&&e.nodeType===1;e=e.parentElement){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden'||parseFloat(s.opacity)<0.2)return false;}return true;};
    // One element can fill a slot in several moments: data-ref holds a space-separated list.
    return [...document.querySelectorAll('[data-ref]')].flatMap(el=>{const r=el.getBoundingClientRect(),box=[r.left/W,r.top/H,r.width/W,r.height/H].map(v=>+v.toFixed(3)),visible=shown(el)&&r.width>0&&r.height>0;
     return el.getAttribute('data-ref').trim().split(/\s+/).map(ref=>({ref,box,visible}));});
   });
  }
 }finally{await browser.close();server.close();}
 return out;
}

const centre=b=>[b[0]+b[2]/2,b[1]+b[3]/2];
/** How far a rendered box is from the reference's: centre distance and size ratio (as fractions of the frame). */
export function boxOff(want,got){
 const [a,b]=[centre(want),centre(got)];
 const dist=Math.hypot(a[0]-b[0],a[1]-b[1]);
 const size=Math.max(Math.abs(Math.log((got[2]||1e-3)/(want[2]||1e-3))),Math.abs(Math.log((got[3]||1e-3)/(want[3]||1e-3))));
 return {dist:+dist.toFixed(3),size:+size.toFixed(3)};
}

/** Moments of plan.reference_layout never inspected in the reference (within 0.6 s of their key time). */
export function uninspected(layout,inspectedTimes){
 return (layout||[]).filter(m=>Number.isFinite(Number(m.at))&&!(inspectedTimes||[]).some(t=>Math.abs(t-Number(m.at))<=0.6)).map(m=>m.moment);
}

const label=(m,i)=>(m.elements?.[i]?.label||m.elements?.[i]?.role||'element')+' ('+m.moment+')';
/**
 * Findings for an exact copy: moments not looked at, elements not marked, missing at their time, or out of
 * their slot. measured: measureLayout's result at each moment's key time; at: the key time in the composition.
 */
export function layoutFindings({layout,measured,inspectedTimes,shift=0}){
 const out=[];
 const notSeen=uninspected(layout,inspectedTimes);
 if(notSeen.length)out.push({code:'reference_moment_not_inspected',severity:'error',time:null,
  message:`${notSeen.length} reference moment${notSeen.length>1?'s were':' was'} never looked at in the reference: ${notSeen.slice(0,8).join(', ')}.`,
  fixHint:'Inspect the reference at each listed moment\'s time (inspect_reference, mode frames, up to eight times per call) and match its layout slot for slot.'});
 for(const m of layout||[]){
  const t=Number(m.at)+shift,rows=measured?.[Number(m.at)]??measured?.[t]??[];
  (m.elements||[]).forEach((e,i)=>{
   if(!Array.isArray(e.box)||e.box.length!==4)return;
   const ref=m.moment+':'+i,got=rows.filter(r=>r.ref===ref);
   if(!got.length){out.push({code:'reference_element_unmarked',severity:'error',time:t,message:`The ${label(m,i)} is not marked, so its slot cannot be checked.`,fixHint:`Put data-ref="${ref}" on the element that fills this slot (several refs on one element are separated by spaces).`});return;}
   const shown=got.filter(r=>r.visible);
   if(!shown.length){out.push({code:'reference_element_missing',severity:'error',time:t,message:`The ${label(m,i)} is not on screen at ${t.toFixed(2)} s, where the reference has it.`,fixHint:`Show it at ${t.toFixed(2)} s in the reference's slot [${e.box.join(', ')}] (x, y, width, height as fractions of the frame).`});return;}
   const best=shown.map(r=>({r,off:boxOff(e.box,r.box)})).sort((a,b)=>a.off.dist-b.off.dist)[0];
   if(best.off.dist>TOLERANCE.centre||best.off.size>TOLERANCE.size)out.push({code:'reference_element_off_slot',severity:'error',time:t,
    message:`The ${label(m,i)} sits at [${best.r.box.join(', ')}] at ${t.toFixed(2)} s; the reference has it at [${e.box.join(', ')}].`,
    fixHint:`Move and size it to x ${e.box[0]}, y ${e.box[1]}, width ${e.box[2]}, height ${e.box[3]} of the frame at that time.`});
  });
 }
 return out.slice(0,24);
}
