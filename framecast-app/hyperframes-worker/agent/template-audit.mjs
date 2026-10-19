// Template audit (2026-10-17): can a copy of this version be made for another product by changing its fields only?
// The page is loaded twice with the HyperFrames runtime and seeked through time:
// - short markers (ZQ1, ZQ2, ...) in every text field: any other word still on screen is written into the code and
//   would stay on every copy (the owner's free ad kept "wyvstudio.com"); a field whose marker never shows does nothing;
// - long values (about twice each default): text that runs off the frame or out of its clipping box would break
//   when a user writes more than the sample did;
// - one unbreakable word per field (2026-10-19): a brand name or a web address cannot wrap the way "fresh daily goods"
//   does, so a headline set big for "VOLT RUSH" cut "WicksNWax" and "MAPLE" off at its edge (OUTREACH-5). The word is as
//   long as the field may be written (PartSwap::maxChars: twice a one-word default, at most 12 letters where the
//   default has spaces, a 10-character price in a price field), and must stay inside its box.
// Run in the sandbox: node agent/template-audit.mjs <id>, reading /output/audit/<id> (a create:version export) and
// writing result.json there. Text drawn on a canvas or baked into a picture is not seen (pictures are slots anyway).
import {readFile,writeFile,mkdir,copyFile,readdir,cp} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {serve} from './reads-check.mjs';

const PATTERN=/(<html\b[^>]*?\sdata-composition-variables=)('([^']*)'|"([^"]*)")/is;
const DIMS={'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350],'9:16':[1080,1920]};
const decode=s=>s.replace(/&quot;/g,'"').replace(/&#0?39;|&apos;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
const encode=s=>s.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

/** The page's composition variables. */
export function declarations(html){
 const m=String(html||'').match(PATTERN);if(!m)return [];
 try{const list=JSON.parse(decode(m[3]??m[4]??''));return Array.isArray(list)?list.filter(d=>d&&typeof d.id==='string'):[];}catch{return [];}
}

/** The page with new default values for the given fields. */
export function withValues(html,values){
 const next=declarations(html).map(d=>Object.hasOwn(values,d.id)?{...d,default:values[d.id]}:d);
 return String(html).replace(PATTERN,(_,head)=>head+"'"+encode(JSON.stringify(next))+"'");
}

/** The code's hash, ignoring asset ids (same as TemplateAudit::bundleHash in the API). */
export function bundleHash(bundle){
 const names=Object.keys(bundle||{}).filter(n=>typeof bundle[n]==='string').sort((a,b)=>a<b?-1:a>b?1:0);
 return createHash('sha256').update(names.map(n=>n+'\n'+bundle[n].replace(/asset-\d+-([0-9a-f]{64})/g,'asset-$1')).join('\n\0\n'),'utf8').digest('hex');
}

/** A number or price field ("3", "$2.49", "20%"): tested with a price, not a word (PartSwap::isNumber). */
export const isNumber=v=>/^[\s$€£¥₦₹+\-]*\d[\d.,\s]*[%x×]?$/i.test(String(v??'').trim());

/** The longest single word a field must hold (PartSwap::maxChars, SWAP-SAFE 2026-10-19): twice a one-word default (a
 *  web address, a brand name), and no more than a 12-letter name where the default is several words. */
export function wordLength(value){
 const v=String(value??'').trim();
 return Math.max(6,/\s/.test(v)?Math.min(12,Math.ceil(v.length*1.5)):Math.min(30,v.length*2));
}

/** Short markers, long values and one long word for every text field. */
export function values(decls){
 const text=decls.filter(d=>d.type==='string'),short={},long={},word={};
 // Real letters, wide ones included: the marker leads so the field can be told apart.
 // A number field gets its marker only (as the short pass): a sale price is not a brand name.
 // A number field gets a price in another currency, as long as it may be written ("Rs 2,700" where "$45" stood).
 text.forEach((d,i)=>{const id='ZQ'+(i+1);word[d.id]=isNumber(d.default)?(id+'Rs12,345,678').slice(0,Math.max(10,2*String(d.default??'').trim().length)):(id+'Wicksnwaxbourbonmaplemontysevenwax').slice(0,Math.max(id.length+2,wordLength(d.default)));});
 text.forEach((d,i)=>{const id='ZQ'+(i+1);short[d.id]=id;
  const want=isNumber(d.default)?0:Math.max(8,Math.min(60,Math.round(String(d.default??'').length*1.5)));let v=id;const words=['fresh','daily','goods','made','simple','better','store'];for(let i=0;v.length<want;i++)v+=' '+words[i%words.length];long[d.id]=v.slice(0,want);});
 return {short,long,word,markers:Object.fromEntries(text.map((d,i)=>['ZQ'+(i+1),d.id]))};
}

/** Words on screen that are not markers: a word of two letters or more, or a price or percentage. */
export function leftovers(texts){
 const out=new Set();
 // A word that is part of a longer leftover ("best" in "The best coffee") is the same finding.
 const keep=list=>list.filter(x=>!list.some(y=>y!==x&&y.length>x.length&&y.includes(x)));
 for(const t of texts){const rest=t.replace(/Z\s*Q\s*\d+/g,' ').replace(/\s+/g,' ').trim();
  // Letter-spaced words ("N E W · D R O P") count too: read with the spaces between single letters taken out.
  const joined=rest.replace(/(?<=(?:^|\s)\p{L})\s(?=\p{L}(?:\s|$))/gu,'');
  if(/[A-Za-zÀ-ɏ]{2,}/.test(joined)||/[$€£¥₦₹]\s*\d|\d\s*%/.test(joined))out.add(rest.slice(0,80));}
 return keep([...out]);
}

/**
 * Long text that runs out of its box: out in the long pass for at least three samples in a row, at moments when the
 * same element's short marker was inside (an entrance or exit move takes text out of its box at any length).
 * Element by element: a template that keeps hidden copies of a scene for a transition has the field's marker out of
 * its box in those copies all the time, which excused the real headline (the WicksNWax ad, 2026-10-19).
 */
export function overflowing(shortOut,longOut,step){
 const id=t=>(t.match(/Z\s*Q\s*(\d+)/)||[])[1];
 const key=o=>o.path?o.path+'|'+id(o.text):id(o.text);
 const shortAt=new Set(shortOut.filter(o=>id(o.text)).map(o=>key(o)+'@'+o.at));
 const byField=new Map();
 for(const o of longOut){const f=id(o.text);if(!f||shortAt.has(key(o)+'@'+o.at))continue;const k=key(o);(byField.get(k)??byField.set(k,[]).get(k)).push(o);}
 const found=[];
 for(const [,list] of byField){const times=[...new Set(list.map(o=>o.at))].sort((a,b)=>a-b);
  // Three samples in a row (about 0.75 s): an entrance or exit move passes through a box edge faster than that.
  const run=times.findIndex((t,i)=>i>1&&Math.abs(t-times[i-1]-step)<1e-6&&Math.abs(times[i-1]-times[i-2]-step)<1e-6);
  if(run>1)found.push({text:list.find(o=>o.at===times[run-2]).text,at:times[run-2]});}
 return found;
}

// Runs in the page: every piece of text a viewer could see now, and text boxes that leave the frame or their clip.
function sample(){
 const W=innerWidth,H=innerHeight,texts=[],out=[];
 const shown=el=>{let o=1;for(let e=el;e&&e.nodeType===1;e=e.parentElement){const s=getComputedStyle(e);if(s.display==='none'||s.visibility==='hidden')return 0;o*=parseFloat(s.opacity);}return o;};
 // The box text must stay in: the nearest element (itself included) that clips, or a visible panel around it (its own
 // background, picture or border, smaller than the frame): a card's or button's words belong inside it (the same rule
 // as WM.fitFields, which sets field text smaller to fit; 2026-10-19).
 const clipOf=(el,panels)=>{for(let e=el;e&&e!==document.body;e=e.parentElement){const s=getComputedStyle(e);if(/hidden|clip/.test(s.overflow+s.overflowX+s.overflowY))return e;if(!panels)continue;
  const m=s.backgroundColor.match(/rgba?\(([^)]+)\)/),alpha=m?(m[1].split(',')[3]===undefined?1:parseFloat(m[1].split(',')[3])):0;
  if((alpha>=0.5||s.backgroundImage!=='none'||parseFloat(s.borderLeftWidth)>=1||parseFloat(s.borderTopWidth)>=1)&&e.getBoundingClientRect().width<W*0.95&&e.getBoundingClientRect().width>=2)return e;}return null;};
 const measured=new Set();
 // Down the stack at a point: transparent layers (grain, vignettes, empty wrappers) are looked through; an opaque
 // panel or picture above the text covers it.
 // A cut-out photo (PNG, WebP, SVG) is mostly transparent around the product: only solid pictures cover.
 const covers=e=>{if(['VIDEO','CANVAS'].includes(e.tagName)||(e.tagName==='IMG'&&/\.jpe?g(\?|$)/i.test(e.currentSrc||e.src)))return shown(e)>=0.5;if(e.tagName==='IMG')return false;const st=getComputedStyle(e);const m=st.backgroundColor.match(/rgba?\(([^)]+)\)/);
  const alpha=m?(m[1].split(',')[3]===undefined?1:parseFloat(m[1].split(',')[3])):0;return (alpha>=0.5||st.backgroundImage!=='none')&&shown(e)>=0.5;};
 const seenAt=(el,[x,y])=>{for(const hit of document.elementsFromPoint(x,y)){if(hit===el||el.contains(hit)||hit.contains(el))return true;if(covers(hit))return false;}return true;};
 for(const el of document.body.querySelectorAll('*')){
  if(['SCRIPT','STYLE','NOSCRIPT','TITLE'].includes(el.tagName))continue;
  let own=[...el.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent).join('').replace(/\s+/g,' ').trim();
  const pseudo=['::before','::after'].map(p=>getComputedStyle(el,p).content).filter(c=>c&&/^["'].*["']$/.test(c)).map(c=>c.slice(1,-1)).join(' ').trim();
  if(!own&&!pseudo)continue;
  // Letters split into their own spans (kinetic type) are read as the word they make (each letter may sit in its own
  // wrapper: climb until there is more than a letter or two).
  let host=el,word=own;
  for(let up=el.parentElement,i=0;word&&word.length<=2&&up&&up!==document.body&&i<4;up=up.parentElement,i++){host=up;word=(up.innerText||up.textContent||'').replace(/\s+/g,' ').trim();}
  // Does the text fit its box? Measured as the whole word, judged on the part a viewer could see (inside its clipping
  // box and the frame): letters pushed wholly past the edge were judged covered one by one and never measured (the
  // WicksNWax headline, 2026-10-19).
  if(word&&!measured.has(host)&&shown(host)>=0.2){
   // The box the words take, spaces left out: a wrapped line's trailing space hangs past the edge without showing.
   const r=host.getBoundingClientRect();let tb=null;{const walk=document.createTreeWalker(host,4),rg=document.createRange();let node;
    while((node=walk.nextNode()))for(const m of node.nodeValue.matchAll(/\S+/g)){rg.setStart(node,m.index);rg.setEnd(node,m.index+m[0].length);const q=rg.getBoundingClientRect();if(q.width<1&&q.height<1)continue;
     tb=tb?{left:Math.min(tb.left,q.left),top:Math.min(tb.top,q.top),right:Math.max(tb.right,q.right),bottom:Math.max(tb.bottom,q.bottom)}:{left:q.left,top:q.top,right:q.right,bottom:q.bottom};}
    if(tb){tb.width=tb.right-tb.left;tb.height=tb.bottom-tb.top;}}
   const b=tb&&tb.width>=2&&tb.height>=2?tb:r;
   // Two boxes: what clips it (the long pass, as since 2026-10-17), and that or the card around it (the long word).
   const boxOf=e=>{const c=e?e.getBoundingClientRect():{left:0,top:0,right:W,bottom:H};return {left:Math.max(0,c.left),top:Math.max(0,c.top),right:Math.min(W,c.right),bottom:Math.min(H,c.bottom)};};
   const box=boxOf(clipOf(host,false)),pbox=boxOf(clipOf(host,true));
   const vis={left:Math.max(b.left,box.left),top:Math.max(b.top,box.top),right:Math.min(b.right,box.right),bottom:Math.min(b.bottom,box.bottom)};
   if(b.width>=2&&b.height>=2&&vis.right-vis.left>=1&&vis.bottom-vis.top>=1&&[0.25,0.5,0.75].some(f=>seenAt(host,[vis.left+(vis.right-vis.left)*f,(vis.top+vis.bottom)/2]))){
    measured.add(host);
    // Up and down, the text's box holds the font's whole line height, taller than its letters in display faces (Anton):
    // a reveal mask cut to the letters reads as cutting them (the Jersey dish name). Within 30% of the font size is
    // that; a hidden second line is more.
    const dy=Math.max(2,parseFloat(getComputedStyle(el).fontSize)*0.3);
     const dx=2;
    const outOf=x=>b.left<x.left-dx||b.top<x.top-dy||b.right>x.right+dx||b.bottom>x.bottom+dy,clipped=outOf(box),panel=outOf(pbox);
    if(clipped||panel){
     // Where the element sits in the page, the same in every pass (only the words differ).
     const path=[];for(let e=host;e&&e!==document.body;e=e.parentElement)path.unshift(e.tagName+(e.id?'#'+e.id:'')+':'+[...(e.parentElement?.children??[])].indexOf(e));
     out.push({text:word.slice(0,40),path:path.join('>'),clipped,panel});}}
  }
  // What a viewer could read now, letter by letter as before.
  const r=el.getBoundingClientRect();if(r.width<1||r.height<1||r.right<0||r.bottom<0||r.left>W||r.top>H)continue;
  if(shown(el)<0.2)continue;
  // Covered text is not seen: a panel on top hides it (the "Describe it." promo keeps an old word under its next scene).
  {const rg=document.createRange();rg.selectNodeContents(el);const b=rg.getBoundingClientRect(),box=b.width>=2&&b.height>=2?b:r;
   const pts=[0.25,0.5,0.75].map(f=>[box.left+box.width*f,box.top+box.height/2]).filter(([x,y])=>x>=0&&y>=0&&x<W&&y<H);
   // The words themselves must be in the frame (a wide wipe panel can overlap it while its text is off to the side).
   if(!pts.length||!pts.some(p=>seenAt(el,p)))continue;}
  if(word)texts.push(word);if(pseudo)texts.push(pseudo);
 }
 return {texts,out};
}

async function scan({root,width,height,duration,step,browserPath,shotAt=null,shotPath=null}){
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 // Audio is not looked at: leaving it out keeps the page from decoding WAVs before it counts as ready.
 const server=await serve(root,runtime,html=>html.replace(/<audio\b[^>]*>(?:[\s\S]*?<\/audio>)?/gi,''));
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu','--font-render-hinting=none']});
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  // A script error leaves the page blank: nothing would be seen, so it is reported rather than read as a pass.
  const errors=[];page.on('pageerror',e=>errors.push(String(e.message||e).slice(0,200)));
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  // What is under a point is asked of the browser, which skips layers with pointer-events:none: a frame of labels drawn
  // above the scenes read as covered, so its fixed words were never seen (the skincare template's "Dewlane · Glow Serum",
  // found in OUTREACH-5, 2026-10-19). Every element answers that question here; nothing is clicked.
  await page.addStyleTag({content:'*{pointer-events:auto !important}'});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  await page.evaluate(()=>{window.__player.enableRenderMode?.();return document.fonts?.ready;});
  // Pages often build their timeline once fonts are ready; seeking before that shows an empty page.
  await page.waitForFunction(()=>window.__timelines&&Object.keys(window.__timelines).length>0,{timeout:10000,polling:100}).catch(()=>{});
  // The runtime adopts the timeline a moment after it is registered: settle, then seek to the end and back once.
  await new Promise(r=>setTimeout(r,1000));
  for(const at of [duration,0])await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},at);
  // A picture of one moment, to look at what the audit reported.
  if(shotAt!==null){await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},shotAt);await page.screenshot({path:shotPath});return null;}
  const texts=new Map(),out=[];
  for(let t=0;t<=duration+1e-6;t+=step){
   const at=Math.round(t*100)/100;
   await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},at);
   const s=await page.evaluate(sample);s.texts.forEach(x=>{if(!texts.has(x))texts.set(x,at);});s.out.forEach(o=>out.push({...o,at}));
  }
  return {texts:[...texts.keys()],firstAt:Object.fromEntries(texts),out,errors:[...new Set(errors)]};
 }finally{await browser.close();server.close();}
}

/** The audit of a staged project: {passed, leftovers, unseen, overflow}. */
export async function audit({source,width,height,duration,step=0.25,browserPath=process.env.HYPERFRAMES_BROWSER_PATH,shot=null}){
 const html=await readFile(source+'/index.html','utf8'),decls=declarations(html),v=values(decls);
 const run=async(vals,name,extra={})=>{const root='/tmp/audit-'+name;await mkdir(root,{recursive:true});
  // The whole page folder (registry items sit in subfolders); the renderer's own cache folders are left out.
  await cp(source,root,{recursive:true,filter:src=>!/\/\.[^/]+$/.test(src)||src===source});
  await writeFile(root+'/index.html',withValues(html,vals));
  // The same runtime files the render stages (GSAP, motion scripts, fonts, registry items), or nothing moves.
  const {stageRuntime}=await import('./stage-runtime.mjs');await stageRuntime(root);
  return scan({root,width,height,duration,step,browserPath,...extra});};
 if(shot){await run(v[shot.which]??v.long,'shot',{shotAt:shot.at,shotPath:shot.path});return null;}
 const short=await run(v.short,'short'),long=await run(v.long,'long'),word=await run(v.word,'word');
 const seen=new Set(short.texts.flatMap(t=>[...t.matchAll(/Z\s*Q\s*(\d+)/g)].map(m=>'ZQ'+m[1])));
 const unseen=Object.entries(v.markers).filter(([m])=>!seen.has(m)).map(([,id])=>id);
 const left=leftovers(short.texts).map(text=>({text,at:short.firstAt[short.texts.find(t=>t.includes(text))]??null}));
 const clippedOut=l=>l.filter(o=>o.clipped!==false),panelOut=l=>l.filter(o=>o.panel!==false);
 const overflow=overflowing(clippedOut(short.out),clippedOut(long.out),step);
 const fieldOf=t=>v.markers['ZQ'+((t.match(/Z\s*Q\s*(\d+)/)||[])[1])]??null;
 const tooWide=overflowing(panelOut(short.out),panelOut(word.out),step).map(o=>({...o,field:fieldOf(o.text),word:v.word[fieldOf(o.text)]??null}))
  .filter((o,i,all)=>all.findIndex(x=>x.field===o.field)===i);
 const errors=[...new Set([...short.errors,...long.errors,...word.errors])];
 return {passed:!left.length&&!unseen.length&&!overflow.length&&!tooWide.length&&!errors.length&&decls.some(d=>d.type==='string'),errors,fields:decls.filter(d=>d.type==='string').map(d=>d.id),leftovers:left,unseen,overflow,too_wide:tooWide,
  seen:short.texts.slice(0,300)};
}

if(import.meta.url===`file://${process.argv[1]}`){
 // node agent/template-audit.mjs <id> [shot <seconds> [short|long]]: the audit, or a picture of one moment.
 const [id,mode,at,which='long']=process.argv.slice(2);if(!/^[a-zA-Z0-9_-]{1,80}$/.test(id||''))throw Error('Usage: template-audit.mjs <id> [shot <seconds> [short|long]]');
 const dir='/output/audit/'+id,manifest=JSON.parse(await readFile(dir+'/manifest.json','utf8'));
 const settings=manifest.metadata?.settings??manifest.input?.settings??{};
 const [width,height]=DIMS[settings.aspect_ratio]??DIMS['9:16'];
 const source='/tmp/audit-source';await mkdir(source,{recursive:true});
 for(const [name,text] of Object.entries(manifest.bundle??{})){if(!/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(name))throw Error('Invalid bundle file');await writeFile(source+'/'+name,text);}
 for(const f of await readdir(dir+'/files').catch(()=>[]))if(/^[a-zA-Z0-9_.-]+$/.test(f)&&!/\.(wav|mp3)$/.test(f))await copyFile(dir+'/files/'+f,source+'/'+f);
 for(const f of await readdir(dir+'/fonts').catch(()=>[]))if(/^brand-[a-z0-9-]+\.ttf$/.test(f))await copyFile(dir+'/fonts/'+f,source+'/'+f);
 if(mode==='shot'){const t=Number(at);if(!Number.isFinite(t)||!['short','long','word'].includes(which))throw Error('shot <seconds> [short|long|word]');
  await audit({source,width,height,duration:Number(settings.duration_seconds)||15,shot:{at:t,which,path:dir+'/shot-'+which+'-'+t+'.png'}});console.log(dir+'/shot-'+which+'-'+t+'.png');process.exit(0);}
 const result=await audit({source,width,height,duration:Number(settings.duration_seconds)||15});
 const hash=bundleHash(manifest.bundle);
 await writeFile(dir+'/result.json',JSON.stringify({...result,revision_id:manifest.revision_id,bundle_hash:hash,at:new Date().toISOString()},null,1));
 console.log(JSON.stringify({passed:result.passed,leftovers:result.leftovers.length,unseen:result.unseen.length,overflow:result.overflow.length,too_wide:result.too_wide.length}));
}
