// The sound pass: before the render, every motion-kit move's cue (WM.cueTimes, recorded as the timeline is built)
// gets its effect from the built-in library (runtime/sounds, scripts/make-sounds.mjs), lined up so the sound's
// measured peak lands on the hit, and quieter under the voice. Host-side and invisible to the builder; a
// composition opts out with data-sounds="off" on its root, and a move with opts.sound:false.
import {readFile,writeFile,copyFile} from 'node:fs/promises';
import {serve} from './reads-check.mjs';

export const RULES={gap:0.25,nearEffect:0.3,effectMax:2.5,underVoice:0.6,perSecond:1.2};
// When two cues are too close, the one the eye expects to hear wins.
const PRIORITY={thud:3,pop:3,click:3,chime:3,'whoosh-big':2,whoosh:2,'whoosh-fast':2,blip:2,swish:1,slide:1,keys:1,tick:0};
const num=v=>v!=null&&v!==''&&Number.isFinite(Number(v))?Number(v):null;

function clips(html){
 const out=[];
 for(const m of String(html).matchAll(/<(audio|video)\b([^>]*)>/gi)){
  const a={};for(const x of m[2].matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("([^"]*)"|'([^']*)'))?/g))a[x[1].toLowerCase()]=x[3]??x[4]??'';
  const start=num(a['data-start']),dur=num(a['data-duration']);
  if(start===null||dur===null)continue;
  out.push({tag:m[1].toLowerCase(),start,end:start+dur,dur,muted:'muted' in a||num(a['data-volume'])===0,name:(a.id||'')+' '+(a.src||''),track:num(a['data-track-index'])??0});
 }
 return out;
}

/** Adds the effects to the page: {html, placed:[{sound,t,start,volume}], skipped}. Pure; the files are copied by soundPass. */
export function placeSounds({html,cues,library,duration}){
 html=String(html);
 if(/\bdata-sounds\s*=\s*["']off["']/i.test(html))return {html,placed:[],skipped:'off'};
 const root=html.match(/<[a-z][a-z0-9-]*\b[^>]*\bdata-composition-id\b[^>]*>/i);
 if(!root)return {html,placed:[],skipped:'no root'};
 const existing=clips(html);
 // The builder's own short effects keep their place; long clips that are not music carry a voice.
 const effects=existing.filter(c=>c.tag==='audio'&&c.dur<=RULES.effectMax).map(c=>c.start);
 const voice=existing.filter(c=>c.dur>RULES.effectMax&&!c.muted&&!/music|duck|bed|song|beat/i.test(c.name));
 const wanted=(cues||[]).filter(c=>library[c.sound]&&Number.isFinite(c.t)&&c.t>=0.05&&c.t<=duration-0.05)
  .sort((a,b)=>(PRIORITY[b.sound]??1)-(PRIORITY[a.sound]??1)||a.t-b.t);
 const max=Math.max(1,Math.round(duration*RULES.perSecond)),kept=[];
 for(const c of wanted){
  if(kept.length>=max)break;
  if(kept.some(k=>Math.abs(k.t-c.t)<RULES.gap)||effects.some(t=>Math.abs(t-c.t)<RULES.nearEffect))continue;
  const s=library[c.sound],start=s.align==='start'?c.t:c.t-s.peak;
  if(start<-0.15)continue;
  const at=Math.max(0,start),dur=Math.min(c.len?c.len+0.1:s.seconds,s.seconds,duration-at);
  if(dur<0.05)continue;
  const voiced=voice.some(v=>v.start<at+dur&&v.end>at);
  kept.push({sound:c.sound,t:c.t,start:+at.toFixed(3),dur:+dur.toFixed(3),volume:+(s.level*(voiced?RULES.underVoice:1)).toFixed(2),file:s.file});
 }
 kept.sort((a,b)=>a.start-b.start);
 // Each effect on its own track above the page's, reusing a track once its last effect has finished.
 const base=Math.max(0,...existing.map(c=>c.track)),tracks=[];
 const tags=kept.map((k,i)=>{
  let n=tracks.findIndex(end=>end<=k.start);if(n<0){n=tracks.length;tracks.push(0);}tracks[n]=k.start+k.dur;
  return `<audio id="wm-sfx-${i+1}" class="clip" data-sfx="${k.sound}" src="${k.file}" data-start="${k.start}" data-duration="${k.dur}" data-track-index="${base+1+n}" data-volume="${k.volume}"></audio>`;
 });
 if(!tags.length)return {html,placed:[],skipped:cues?.length?'none fit':'no cues'};
 return {html:html.slice(0,root.index+root[0].length)+'\n'+tags.join('\n')+html.slice(root.index+root[0].length),placed:kept.map(({file,...k})=>k)};
}

/** The cues of the page as built, read in the same runtime the renderer uses (the copy it loads has no audio). */
export async function readCues({root,width,height,browserPath=process.env.HYPERFRAMES_BROWSER_PATH}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 const server=await serve(root,runtime,html=>html.replace(/<audio\b[^>]*>(?:[\s\S]*?<\/audio>)?/gi,''));
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu']});
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  return await page.evaluate(()=>window.WM&&typeof window.WM.cueTimes==='function'?window.WM.cueTimes():[]);
 }finally{await browser.close();server.close();}
}

/** The motion-kit moves that ran when the page built its timeline: [{move, calls}] (evidence for the final check). */
export async function readMoves({root,width,height,browserPath=process.env.HYPERFRAMES_BROWSER_PATH}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 const server=await serve(root,runtime,html=>html.replace(/<audio\b[^>]*>(?:[\s\S]*?<\/audio>)?/gi,''));
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu']});
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  return await page.evaluate(()=>window.WM&&typeof window.WM.movesUsed==='function'?window.WM.movesUsed():[]);
 }finally{await browser.close();server.close();}
}

/** Runs the pass on a project folder in place; returns what it placed. Never throws past a note: sound is not worth a failed render. */
export async function soundPass({root,width,height,duration,library='/opt/worker/runtime/sounds'}){
 try{
  const html=await readFile(root+'/index.html','utf8');
  if(!/wyv-motion\.js/.test(html))return {placed:[],skipped:'no motion kit'};
  const lib=JSON.parse(await readFile(library+'/sounds.json','utf8'));
  const cues=await readCues({root,width,height});
  const out=placeSounds({html,cues,library:lib,duration});
  if(out.placed.length){
   for(const f of new Set(out.placed.map(p=>lib[p.sound].file)))await copyFile(library+'/'+f,root+'/'+f);
   await writeFile(root+'/index.html',out.html);
  }
  return {cues:cues.length,placed:out.placed,skipped:out.skipped};
 }catch(e){return {placed:[],error:String(e.message).slice(0,300)};}
}
