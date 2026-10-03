// Reading-time, blank-frame and slow-drift checks on a composition.
// The page is loaded with the HyperFrames runtime and seeked through time; at
// each step we record which text blocks are fully on screen, whether anything
// is on screen at all, and where each text or media element sits.
// - Reading time: a headline-sized block must stay fully visible for its
//   length at CPS characters a second plus PAD seconds (never under MIN).
// - Blank frames: a stretch of at least BLANK seconds with nothing to look at.
// - Slow drift: an element moving under about 1 px per frame for a second or
//   more stutters on whole pixels instead of gliding.
// - Still stretch: nothing on screen moves, fades, scales or changes for STILL
//   seconds (playing video and canvas count as change). A beat marked data-hold
//   may hold up to HOLD seconds; a final hold at the very end is allowed too.
// - Small text: a sentence-sized line smaller than SMALL of the frame's short
//   side, which a phone cannot read.
// - Mostly empty: for EMPTY seconds the content covers under SPARSE of the frame
//   and nothing spans half of it (full-frame backgrounds are not content).
import http from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';

// Short-form reading speed: about 17 characters a second plus a second to find the words.
export const RULES={cps:17,pad:1,min:1,blank:0.3,step:0.1,fps:24,slack:0.15,still:1.5,hold:3,endHold:3,small:0.03,empty:1.5,sparse:0.15};
const TYPES={'.html':'text/html','.css':'text/css','.js':'text/javascript','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.svg':'image/svg+xml','.ttf':'font/ttf','.mp4':'video/mp4','.mp3':'audio/mpeg','.wav':'audio/wav'};

function serve(root,runtime){
 return new Promise(resolve=>{
  const server=http.createServer(async(req,res)=>{
   try{
    const name=decodeURIComponent(new URL(req.url,'http://x').pathname).replace(/^\/+/,'')||'index.html';
    if(name==='__hf_runtime.js'){res.writeHead(200,{'content-type':'text/javascript'});res.end(runtime);return;}
    if(!/^[a-zA-Z0-9_.-]+$/.test(name)){res.writeHead(404);res.end();return;}
    let body=await readFile(path.join(root,name));
    // Same runtime the renderer uses, loaded first, so clips appear and seek exactly as rendered.
    if(name==='index.html')body=Buffer.from(String(body).replace(/<head[^>]*>/i,m=>m+'<script src="/__hf_runtime.js"></script>'));
    res.writeHead(200,{'content-type':TYPES[path.extname(name)]||'application/octet-stream'});res.end(body);
   }catch{res.writeHead(404);res.end();}
  });
  server.listen(0,'127.0.0.1',()=>resolve(server));
 });
}

// Runs in the page: what is on screen right now.
function sample(minFont,smallFont){
 const W=innerWidth,H=innerHeight,ids=window.__rcIds||(window.__rcIds=new WeakMap());let next=window.__rcNext||0;
 const key=el=>{if(!ids.has(el)){ids.set(el,++next);window.__rcNext=next;}return ids.get(el);};
 const shown=el=>{let o=1;for(let e=el;e&&e.nodeType===1;e=e.parentElement){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden')return 0;o*=parseFloat(s.opacity);}return o;};
 const texts=[],things=[],small=[];let held=false;
 for(const el of document.body.querySelectorAll('*')){
  if(['SCRIPT','STYLE'].includes(el.tagName))continue;
  const own=[...el.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent).join('').replace(/\s+/g,' ').trim();
  const media=['IMG','SVG','CANVAS','VIDEO','svg'].includes(el.tagName);
  const r=el.getBoundingClientRect();if(r.width<2||r.height<2)continue;
  // A filled or outlined shape is something to look at, even with no text.
  let shape=false;if(!own&&!media&&r.width*r.height>=W*H*0.01){const s=getComputedStyle(el);shape=(s.backgroundColor&&!/rgba\(\d+, \d+, \d+, 0\)|transparent/.test(s.backgroundColor))||s.backgroundImage!=='none'||parseFloat(s.borderTopWidth)>0;}
  if(!own&&!media&&!shape)continue;
  const o=shown(el);if(o<0.5)continue;
  const inside=r.left>=-1&&r.top>=-1&&r.right<=W+1&&r.bottom<=H+1;
  if(r.right<0||r.bottom<0||r.left>W||r.top>H)continue;
  const item={id:key(el),x:r.left,y:r.top,el};
  // What the eye can see change: place, size, visibility and the words themselves; playing pictures always change.
  const live=['VIDEO','CANVAS'].includes(el.tagName);
  const bg=!own&&!media&&r.width*r.height>=W*H*0.9;
  if(el.closest('[data-hold]'))held=true;
  const scale=el.offsetHeight?r.height/el.offsetHeight:1,size=own?parseFloat(getComputedStyle(el).fontSize)*scale:0;
  if(own&&inside&&size<smallFont&&(own.split(' ').length>=4||own.length>=20))small.push({id:key(el),text:own.slice(0,60),size:Math.round(size)});
  things.push({...item,w:r.width,h:r.height,sig:[Math.round(r.left),Math.round(r.top),Math.round(r.width),Math.round(r.height),Math.round(o*20),own.slice(0,40)].join(','),live,bg});
  if(own&&inside&&parseFloat(getComputedStyle(el).fontSize)>=minFont)texts.push({...item,text:(el.innerText||own).replace(/\s+/g,' ').trim()});
 }
 // An accent word inside a headline is part of that headline, not a block of its own.
 const top=texts.filter(x=>!texts.some(y=>y!==x&&y.el.contains(x.el)));
 const strip=({el,...x})=>x;
 return {texts:top.map(strip),things:things.map(strip),small,held,W,H};
}

export async function readsCheck({root,width,height,duration,browserPath=process.env.HYPERFRAMES_BROWSER_PATH,rules=RULES}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 const server=await serve(root,runtime);
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu','--font-render-hinting=none']});
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  // The player API seeks the same way the renderer does (render mode: no playback, exact frames).
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  await page.evaluate(()=>{window.__player.enableRenderMode?.();return document.fonts?.ready;});
  const minFont=Math.round(height*0.022),smallFont=Math.round(Math.min(width,height)*rules.small),frames=[];
  for(let t=0;t<=duration+1e-6;t+=rules.step){
   const at=Math.round(t*100)/100;
   await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},at);
   frames.push({t:at,...await page.evaluate(sample,minFont,smallFont)});
  }
  return findings(frames,duration,rules);
 }finally{await browser.close();server.close();}
}

export function findings(frames,duration,rules=RULES){
 const out=[];
 // Reading time: per element, the longest unbroken stretch fully on screen.
 const runs=new Map();
 for(const f of frames){
  const now=new Set(f.texts.map(x=>x.id));
  for(const x of f.texts){const r=runs.get(x.id)||{text:x.text,best:0,start:null,first:f.t};if(r.start===null)r.start=f.t;if(x.text.length>r.text.length)r.text=x.text;r.last=f.t;runs.set(x.id,r);}
  for(const [id,r] of runs)if(!now.has(id)&&r.start!==null){r.best=Math.max(r.best,r.last-r.start+rules.step);r.start=null;}
 }
 for(const r of runs.values()){
  if(r.start!==null)r.best=Math.max(r.best,r.last-r.start+rules.step);
  // The last block on screen runs to the end of the video; it only needs to be there.
  const endsVideo=r.last>=duration-rules.step*1.5;
  const need=Math.max(rules.min,r.text.replace(/\s/g,'').length/rules.cps+rules.pad);
  if(!endsVideo&&r.best+rules.slack<need)out.push({code:'reading_time',severity:'error',time:r.first,
   message:`"${r.text.slice(0,60)}" is fully on screen for ${r.best.toFixed(1)} s; it needs ${need.toFixed(1)} s to be read.`,
   fixHint:'Hold it longer, shorten the words, or move the next beat later.'});
 }
 // Blank frames: nothing to look at for a stretch (the first and last moments excepted).
 let blankFrom=null;
 for(const f of frames){
  const empty=!f.things.length&&f.t>0.05&&f.t<duration-0.05;
  if(empty&&blankFrom===null)blankFrom=f.t;
  if((!empty||f===frames.at(-1))&&blankFrom!==null){const len=f.t-blankFrom;if(len>=rules.blank)out.push({code:'blank_frames',severity:'error',time:blankFrom,message:`Nothing is on screen from ${blankFrom.toFixed(1)} s for ${len.toFixed(1)} s.`,fixHint:'Overlap the next beat with the end of the last one, so a transition never shows an empty frame.'});blankFrom=null;}
 }
 // Slow drift: per element, steps moving under about 1 px a frame for 1 s or more.
 const per=rules.step*rules.fps,track=new Map(),drifts=[];
 for(let i=1;i<frames.length;i++){
  const prev=new Map(frames[i-1].things.map(x=>[x.id,x]));
  for(const x of frames[i].things){
   const p=prev.get(x.id);if(!p)continue;
   const v=Math.hypot(x.x-p.x,x.y-p.y)/per;
   const s=track.get(x.id)||{len:0,from:null,done:false};
   if(v>0.05&&v<0.9){if(s.from===null)s.from=frames[i-1].t;s.len+=rules.step;}else{s.len=0;s.from=null;}
   if(s.len>=1&&!s.done){s.done=true;drifts.push(s.from);}
   track.set(x.id,s);
  }
 }
 out.push(...visualFindings(frames,duration,rules));
 // One finding per moment, however many elements drift together.
 const moments=[...new Set(drifts.map(t=>Math.round(t)))].sort((a,b)=>a-b);
 if(moments.length)out.push({code:'slow_drift',severity:'warning',time:moments[0],message:`Elements drift under 1 px a frame around ${moments.map(t=>t+' s').join(', ')}; they will step on whole pixels instead of gliding.`,fixHint:'Move them further or faster, or carry the hold with scale, opacity or a counter.'});
 return out.slice(0,16);
}

// Still stretches, small text and mostly empty frames, from the same samples.
export function visualFindings(frames,duration,rules=RULES){
 const out=[];
 // Still: the same visible picture, frame after frame.
 const sig=f=>f.things.some(x=>x.live)?null:f.things.map(x=>x.id+':'+x.sig).sort().join('|');
 let from=0;
 const flushStill=i=>{
  const a=frames[from],b=frames[i-1];if(!a||!b)return;
  const len=b.t-a.t+rules.step,heldAll=frames.slice(from,i).every(f=>f.held),toEnd=b.t>=duration-rules.step*1.5;
  const allowed=toEnd?rules.endHold:heldAll?rules.hold:rules.still;
  if(sig(a)!==null&&len>=allowed+1e-6)out.push({code:'still_stretch',severity:'error',time:a.t,
   message:`Nothing on screen moves or changes from ${a.t.toFixed(1)} s to ${(a.t+len).toFixed(1)} s (${len.toFixed(1)} s).`,
   fixHint:'Give the beat life: build its lines in on the words, push in slowly, pop a highlight, count a number, or cut sooner. If the stillness is the point, mark the beat data-hold (up to '+rules.hold+' s).'});
 };
 for(let i=1;i<=frames.length;i++){
  if(i<frames.length&&sig(frames[i])!==null&&sig(frames[i])===sig(frames[from]))continue;
  flushStill(i);from=i;
 }
 // Small text: one finding listing the lines, with when each first shows.
 const seen=new Map();
 for(const f of frames)for(const x of f.small||[])if(!seen.has(x.id))seen.set(x.id,{...x,t:f.t,n:0});
 for(const f of frames)for(const x of f.small||[])seen.get(x.id).n++;
 const small=[...seen.values()].filter(x=>x.n*rules.step>=0.5);
 if(small.length){const min=Math.round(Math.min(frames[0]?.W||1080,frames[0]?.H||1920)*rules.small);
  out.push({code:'small_text',severity:'error',time:small[0].t,message:`Too small to read on a phone (under ${min} px): ${small.slice(0,4).map(x=>`"${x.text}" ${x.size} px at ${x.t.toFixed(1)} s`).join('; ')}${small.length>4?` and ${small.length-4} more`:''}.`,
   fixHint:`Set sentence text to at least ${min} px, or cut it to a short label.`});}
 // Mostly empty: content covers little of the frame and nothing is big.
 const sparse=f=>{
  const W=f.W||1080,H=f.H||1920,content=f.things.filter(x=>!x.bg);
  if(!content.length)return false;
  if(content.some(x=>x.w>=W*0.5||x.h>=H*0.5))return false;
  const g=12,cells=new Set();
  for(const x of content)for(let i=Math.max(0,Math.floor(x.x/W*g));i<=Math.min(g-1,Math.floor((x.x+x.w)/W*g));i++)for(let j=Math.max(0,Math.floor(x.y/H*g));j<=Math.min(g-1,Math.floor((x.y+x.h)/H*g));j++)cells.add(i+','+j);
  return cells.size/(g*g)<rules.sparse;
 };
 let start=null;
 for(let i=0;i<=frames.length;i++){
  const f=frames[i],on=f&&sparse(f);
  if(on&&start===null)start=f.t;
  if(!on&&start!==null){const len=frames[i-1].t-start+rules.step;if(len>=rules.empty)out.push({code:'mostly_empty',severity:'error',time:start,message:`From ${start.toFixed(1)} s for ${len.toFixed(1)} s the content fills under ${Math.round(rules.sparse*100)}% of the frame and nothing spans half of it.`,fixHint:'Scale the main element up so it fills at least half the width, or bring the next element in sooner.'});start=null;}
 }
 return out;
}
