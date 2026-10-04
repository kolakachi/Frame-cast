// Offline proof of the reference moves (wyv-motion.js): a 15 s composition that
// follows a reference shot for shot. No model call. Character art is optional:
// files mounted at /maya (maya-peek.png, maya-wink.png, maya-neutral.png,
// maya-point.png) are used when present, plain placeholders otherwise.
import {mkdir,copyFile,writeFile,access} from 'node:fs/promises';
import {createRequire} from 'node:module';
import assert from 'node:assert/strict';
import {renderRun} from './lib/render-run.mjs';
const req=createRequire('/opt/worker/node_modules/hyperframes/package.json');
const puppeteer=req('puppeteer-core'),sharp=req('sharp');
const root='/tmp/reference-moves',out='/output/reference-moves';
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});
await copyFile('/opt/worker/fixtures/reference-moves/index.html',root+'/index.html');
await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
for(const f of ['inter.ttf','playfair.ttf'])await copyFile('/opt/worker/runtime/fonts/'+f,root+'/'+f);
for(const pose of ['peek','wink','neutral','point']){
 const name='maya-'+pose+'.png';
 try{await access('/maya/'+name);await copyFile('/maya/'+name,root+'/'+name);}
 catch{await sharp({create:{width:1024,height:1024,channels:4,background:{r:0,g:0,b:0,alpha:0}}})
  .composite([{input:Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024"><circle cx="512" cy="330" r="190" fill="#bbb"/><rect x="262" y="540" width="500" height="480" rx="200" fill="#999"/></svg>')}]).png().toFile(root+'/'+name);}
}

const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,protocolTimeout:60000,args:['--no-sandbox']});
const report={checks:{},frames:[]};
try{
 const page=await browser.newPage();await page.setViewport({width:1920,height:1080});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const log=(...a)=>process.env.TRACE&&console.error(...a);
 await page.goto('file://'+root+'/index.html',{waitUntil:'load'});log('loaded');
 await page.evaluate(()=>document.fonts.ready);log('fonts');
 const seek=t=>page.evaluate(t=>{window.__timelines.main.seek(t,true);},t);
 const state=t=>(log('state',t),page.evaluate(t=>{
  window.__timelines.main.seek(t,true);
  const g=(s,p)=>{const el=document.querySelector(s);return el?gsap.getProperty(el,p):null;};
  const vis=s=>{const el=document.querySelector(s);return el?getComputedStyle(el).visibility!=='hidden'&&Number(getComputedStyle(el).opacity)>0.01:false;};
  return {t,sceneAClip:document.querySelector('#sceneA').style.clipPath,sceneA:vis('#sceneA'),s1:vis('#s1'),s4:vis('#s4'),s5:vis('#s5'),s6:vis('#s6'),
   rings:[...document.querySelectorAll('[data-wm="iris-ring"]')].filter(r=>getComputedStyle(r).visibility!=='hidden'&&Number(getComputedStyle(r).opacity)>0.01).length,
   c1:{rotation:g('#c1','rotation'),alpha:g('#c1','autoAlpha'),x:g('#c1','x')},panel:{width:document.querySelector('#panel').offsetWidth,left:document.querySelector('#panel').offsetLeft},
   sceneAScale:g('#sceneA','scale'),contentScale:g('#panelContent','scale'),contentAlpha:g('#panelContent','autoAlpha'),s4Scale:g('#s4','scale'),total:document.querySelector('#total').textContent,
   sellClip:document.querySelector('#sell').style.clipPath,underline:g('#u1','scaleX'),stamp:g('#stamp','scale'),mark:document.querySelector('#markText').textContent,
   words:[...document.querySelectorAll('#h1 .w')].map(w=>Number(gsap.getProperty(w,'autoAlpha')))};
 },t));
 const at={};
 for(const t of [0,1.0,1.5,2.0,2.35,2.75,3.2,4.2,4.7,5.3,9.3,6.6,6.85,7.1,7.15,7.6,7.8,10.3,10.9,11.25,12.0,13.0,13.45,14.0,14.9])at[t]=await state(t);
 report.states=at;const c=report.checks;
 c.start_clean=at[0].rings===0&&!at[0].sceneA&&at[0].words.every(a=>a===0);
 c.words_build=at[1.0].words[0]===0&&at[1.5].words.slice(0,3).every(a=>a>0.9)&&at[1.5].words[3]===0;
 c.write_on=/inset\(-30% 100%/.test(at[1.5].sellClip)&&/inset\(-30% -8%/.test(at[2.35].sellClip)&&at[2.35].underline>0.9;
 c.iris_grows=/circle\(\d/.test(at[2.35].sceneAClip)&&at[2.35].rings>0&&at[2.75].sceneAClip==='none'&&!at[2.75].s1;
 c.toss_lands=at[1.0].c1.alpha===0&&at[4.2].c1.alpha===1&&Math.abs(at[4.2].c1.rotation+4)<0.5&&Math.abs(at[4.2].c1.x)<1;
 c.device_morph=at[4.2].panel.width===1920&&at[5.3].panel.width===470&&at[5.3].panel.left===1180&&at[4.7].contentScale<0.6&&at[4.7].contentAlpha===1;
 c.push_through=at[6.85].sceneAScale>1.2&&at[7.1].s4&&!at[7.1].sceneA&&at[7.1].s4Scale>4&&at[7.8].s4Scale===1;
 c.fly_and_count=at[9.3].total==='12'&&at[10.9].total==='23';
 c.stamp=Math.abs(at[10.9].stamp-1)<0.25;
 c.iris_to_black=at[12.0].s5&&!at[12.0].s4;
 c.lockup=at[14.9].mark==='WyvStudio'&&at[14.9].s6&&!at[14.9].s5;
 // Seeking is exact: a frame looks the same however the playhead got there.
 const shot=async t=>{log('shot',t);await seek(t);return page.screenshot({type:'png'});};
 const same=async(a,b)=>{const x=await sharp(a).removeAlpha().raw().toBuffer(),y=await sharp(b).removeAlpha().raw().toBuffer();let d=0;for(let i=0;i<x.length;i++)d+=Math.abs(x[i]-y[i]);return d/x.length;};
 // Warm up: decode every image once before comparing frames.
 for(const t of [0.5,3,8,12,14.5])await shot(t);
 const drift=[];
 for(const t of [2.4,4.8,6.9,7.2,10.2,11.2,13.4]){const a=await shot(t);await seek(14.5);await seek(0.2);const b=await shot(t);if(process.env.TRACE){await writeFile(out+'/drift-a-'+t+'.png',a);await writeFile(out+'/drift-b-'+t+'.png',b);}drift.push({t,mean:+(await same(a,b)).toFixed(4)});}
 report.drift=drift;c.seek_exact=drift.every(d=>d.mean<0.05);
 c.no_page_errors=errors.length===0;report.errors=errors;
 // Frames at the reference's moments, for the side-by-side.
 for(const t of [0.3,0.8,1.6,2.1,2.3,2.5,3.0,3.9,4.6,5.6,6.6,6.95,7.1,7.4,8.6,9.6,10.2,10.8,11.1,11.6,12.9,13.4,13.8,14.6]){
  await seek(t);const name='f-'+String(t.toFixed(2)).padStart(5,'0')+'.png';await writeFile(out+'/'+name,await page.screenshot({type:'png'}));report.frames.push({t,name});
 }
}finally{await browser.close();}
await writeFile(out+'/report.json',JSON.stringify(report,null,2));
const failed=Object.entries(report.checks).filter(([,ok])=>!ok).map(([k])=>k);
if(failed.length){console.error('FAILED',failed.join(', '));console.error(JSON.stringify(report.errors));}
if(process.env.RENDER!=='0'){
 const rendered=await renderRun({project:root,outputRoot:out+'/render',timeoutMs:600000,expected:{width:1920,height:1080,duration:15,audio:false}});
 assert.equal(rendered.status,'ready',JSON.stringify(rendered));
 await copyFile(rendered.directory+'/video.mp4',out+'/reference-moves.mp4');
}
console.log(JSON.stringify({checks:report.checks,drift:report.drift},null,1));
assert.deepEqual(failed,[]);
