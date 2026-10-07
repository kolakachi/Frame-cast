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
// - Slideshow: at least CARDS of the video is text on a plain background (no
//   picture, video or drawing on screen) and those stretches barely change
//   (under CHANGE of their steps): cards cut one after another. Kinetic type
//   that keeps moving is not a slideshow.
import http from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';

// Short-form reading speed: about 17 characters a second plus a second to find the words.
export const RULES={cps:17,pad:1,min:1,blank:0.3,step:0.1,fps:24,slack:0.15,still:1.5,hold:3,endHold:3,small:0.03,empty:1.5,sparse:0.15,cards:0.6,change:0.25,side:0.06,top:0.04,rest:0.5,overlap:0.25,offCentre:0.18,lopsided:1.5};
const TYPES={'.html':'text/html','.css':'text/css','.js':'text/javascript','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.svg':'image/svg+xml','.ttf':'font/ttf','.mp4':'video/mp4','.mp3':'audio/mpeg','.wav':'audio/wav'};

// transform: an optional edit of index.html as served (the sound pass serves it without its audio).
export function serve(root,runtime,transform=html=>html){
 return new Promise(resolve=>{
  const server=http.createServer(async(req,res)=>{
   try{
    const name=decodeURIComponent(new URL(req.url,'http://x').pathname).replace(/^\/+/,'')||'index.html';
    if(name==='__hf_runtime.js'){res.writeHead(200,{'content-type':'text/javascript'});res.end(runtime);return;}
    if(!/^[a-zA-Z0-9_.-]+$/.test(name)){res.writeHead(404);res.end();return;}
    let body=await readFile(path.join(root,name));
    // Same runtime the renderer uses, loaded first, so clips appear and seek exactly as rendered.
    if(name==='index.html')body=Buffer.from(transform(String(body)).replace(/<head[^>]*>/i,m=>m+'<script src="/__hf_runtime.js"></script>'));
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
  // The words' own box (a heading's element box spans the full width even when its words do not).
  let words=null;if(own){const rg=document.createRange();rg.selectNodeContents(el);const b=rg.getBoundingClientRect();if(b.width>=2&&b.height>=2)words={x:b.left,y:b.top,w:b.width,h:b.height};}
  things.push({...item,w:r.width,h:r.height,media,text:!!own,words,label:own.slice(0,30),bleed:!!el.closest('[data-bleed]'),sig:[Math.round(r.left),Math.round(r.top),Math.round(r.width),Math.round(r.height),Math.round(o*20),own.slice(0,40)].join(','),live,bg});
  if(own&&inside&&parseFloat(getComputedStyle(el).fontSize)>=minFont)texts.push({...item,text:(el.innerText||own).replace(/\s+/g,' ').trim(),words});
 }
 // An accent word inside a headline is part of that headline, not a block of its own.
 const top=texts.filter(x=>!texts.some(y=>y!==x&&y.el.contains(x.el)));
 // Separate blocks of words lying over each other (not an accent inside its headline).
 const overlaps=[];
 for(let i=0;i<top.length;i++)for(let j=i+1;j<top.length;j++){const a=top[i].words,b=top[j].words;if(!a||!b||top[i].el.contains(top[j].el)||top[j].el.contains(top[i].el))continue;
  const ix=Math.max(0,Math.min(a.x+a.w,b.x+b.w)-Math.max(a.x,b.x)),iy=Math.max(0,Math.min(a.y+a.h,b.y+b.h)-Math.max(a.y,b.y));
  const share=ix*iy/Math.max(1,Math.min(a.w*a.h,b.w*b.h));if(share>0)overlaps.push({pair:top[i].id+'-'+top[j].id,share,labels:[top[i].text.slice(0,24),top[j].text.slice(0,24)]});}
 const strip=({el,...x})=>x;
 return {texts:top.map(strip),things:things.map(strip),small,held,W,H,overlaps};
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
 // Slideshow: text on a plain background for most of the video, and those stretches barely change.
 const card=f=>!f.things.some(x=>x.live||x.media)&&f.things.some(x=>x.text);
 const cards=frames.filter(card);
 if(frames.length>=20&&cards.length/frames.length>=rules.cards){
  let changes=0;for(let i=1;i<frames.length;i++)if(card(frames[i])&&card(frames[i-1])&&sig(frames[i])!==sig(frames[i-1]))changes++;
  const ratio=changes/Math.max(1,cards.length-1);
  if(ratio<rules.change)out.push({code:'slideshow',severity:'error',time:cards[0].t,
   message:`About ${Math.round(cards.length/frames.length*100)}% of the video is text on a plain background that barely moves: it reads as a slideshow of cards.`,
   fixHint:'Give the beats a picture or motion: a product shot, a 3D object or icon from the art library, a UI panel, words building on the voice, a camera push, or one element that carries from beat to beat.'});
 }
 out.push(...spacing(frames,rules));
 return out;
}

/** Finishing touches, measured on words at rest (an entrance or exit passing an edge is motion, not layout):
 *  words within the side or top margin or cut by the frame edge, blocks of words lying over each other, and a held
 *  frame whose content sits far off-centre. A word meant to bleed off the frame carries data-bleed. */
export function spacing(frames,rules=RULES){
 const out=[],near=new Map(),over=new Map(),still=new Map(),pairs=new Map();let lop=null,lopRun=0;
 const W=frames[0]?.W||1080,H=frames[0]?.H||1920,side=Math.round(W*rules.side),top=Math.round(H*rules.top);
 for(const f of frames){
  const seen=new Set();
  for(const x of f.things||[]){
   if(!x.text||!x.words||x.bleed||x.bg)continue;seen.add(x.id);
   const box=[x.words.x,x.words.y,x.words.w,x.words.h].map(Math.round).join(','),prev=still.get(x.id);
   const run=prev&&prev.box===box?prev.run+rules.step:rules.step;still.set(x.id,{box,run});
   const margin=Math.round(Math.min(x.words.x,W-(x.words.x+x.words.w),x.words.y));
   if(run>=rules.rest-1e-9&&(x.words.x<side||x.words.x+x.words.w>W-side||x.words.y<top)&&!near.has(x.id))near.set(x.id,{label:x.label,t:f.t,margin});
  }
  for(const id of [...still.keys()])if(!seen.has(id))still.delete(id);
  const live=new Set();
  for(const o of f.overlaps||[]){if(o.share<rules.overlap)continue;live.add(o.pair);const run=(pairs.get(o.pair)||0)+rules.step;pairs.set(o.pair,run);if(run>=rules.rest-1e-9&&!over.has(o.pair))over.set(o.pair,{labels:o.labels,t:f.t});}
  for(const k of [...pairs.keys()])if(!live.has(k))pairs.delete(k);
  // Held content far from the centre: the union of what is on screen (words by their own box).
  const boxes=(f.things||[]).filter(x=>!x.bg&&(x.media||x.words)).map(x=>x.words||x);
  if(boxes.length){const y0=Math.min(...boxes.map(b=>b.y)),y1=Math.max(...boxes.map(b=>b.y+b.h));const c=(y0+y1)/2/H;
   const off=(y1-y0)<H*0.5&&Math.abs(c-0.47)>rules.offCentre;
   if(off){lopRun+=rules.step;if(lopRun>=rules.lopsided-1e-9&&!lop)lop={t:+(f.t-lopRun+rules.step).toFixed(1),where:c<0.5?'top':'bottom'};}else lopRun=0;}
 }
 const items=[...near.values()];
 if(items.length)out.push({code:'edge_margin',severity:'error',time:items[0].t,
  message:`Words sit against the frame edge (keep at least ${side} px at the sides and ${top} px at the top): ${items.slice(0,4).map(x=>`"${x.label}" ${Math.max(0,x.margin)} px at ${x.t.toFixed(1)} s`).join('; ')}${items.length>4?` and ${items.length-4} more`:''}.`,
  fixHint:'Fit the words inside the margins (smaller size, tighter tracking or a line break), or mark a deliberate full-bleed word with data-bleed.'});
 const pairsOut=[...over.values()];
 if(pairsOut.length)out.push({code:'text_overlap',severity:'error',time:pairsOut[0].t,
  message:`Words lie over other words: ${pairsOut.slice(0,3).map(p=>`"${p.labels[0]}" over "${p.labels[1]}" at ${p.t.toFixed(1)} s`).join('; ')}.`,
  fixHint:'Give each block its own space (spacing, line height, or move one), unless the overlap is the design and both stay readable.'});
 if(lop)out.push({code:'lopsided',severity:'warning',time:lop.t,
  message:`From ${lop.t.toFixed(1)} s the content is held in the ${lop.where} of the frame with the rest empty.`,
  fixHint:'Centre the group optically (a little above the middle), scale it up, or give the empty part a job.'});
 return out;
}
