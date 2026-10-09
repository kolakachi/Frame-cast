// Offline proof of the collage moves (wyv-motion.js: tear, sticker, cards, grade, sunburst, confetti, circleText):
// a 7 s KICKS-style square. Pictures are drawn here. No model call.
import {mkdir,copyFile,writeFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
import assert from 'node:assert/strict';
import {renderRun} from './lib/render-run.mjs';
const req=createRequire('/opt/worker/node_modules/hyperframes/package.json');
const puppeteer=req('puppeteer-core'),sharp=req('sharp');
const root='/tmp/collage',out='/output/collage';
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});
await copyFile('/opt/worker/fixtures/collage/index.html',root+'/index.html');
await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
await copyFile('/opt/worker/runtime/fonts/inter.ttf',root+'/inter.ttf');
// A "photo": a lit gradient with a shape, so silver, ink, duotone and halftone have tones to work on.
const photo=(w,h,a,b,shape)=>sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><defs><radialGradient id="g" cx="35%" cy="30%" r="90%"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></radialGradient><linearGradient id="s" x1="0" x2="1"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#333"/></linearGradient></defs><rect width="${w}" height="${h}" fill="url(#g)"/>${shape}</svg>`)).png();
await photo(800,600,'#f2e2c9','#3a2a20','<ellipse cx="420" cy="330" rx="250" ry="120" fill="url(#s)"/><rect x="200" y="380" width="460" height="60" rx="30" fill="#222"/>').toFile(root+'/poster.png');
await sharp(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="480" height="480"><ellipse cx="240" cy="270" rx="200" ry="150" fill="#ff9ec7"/><ellipse cx="240" cy="330" rx="210" ry="60" fill="#fff"/><circle cx="185" cy="240" r="34" fill="#fff"/><circle cx="295" cy="240" r="34" fill="#fff"/><circle cx="192" cy="246" r="16" fill="#222"/><circle cx="288" cy="246" r="16" fill="#222"/><path d="M200 300 Q240 340 280 300" stroke="#222" stroke-width="10" fill="none" stroke-linecap="round"/></svg>')).png().toFile(root+'/mascot.png');
const hues=[['#ffd27f','#7a3b12'],['#9fd8ff','#123a5a'],['#ffe0e0','#5a1220'],['#d7ffd2','#1d4a16'],['#ffffff','#202020'],['#ffd6f0','#4a1240'],['#fff2b3','#5a4a10'],['#e0e7ff','#202a5a']];
for(const [k,[a,b]] of hues.entries())await photo(700,700,a,b,`<circle cx="${250+k*20}" cy="${330-k*10}" r="${150+k*8}" fill="url(#s)"/>`).toFile(root+`/card-${k+1}.png`);

const report={checks:{}};
const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,protocolTimeout:60000,args:['--no-sandbox']});
try{
 const pg=await browser.newPage();await pg.setViewport({width:1080,height:1080});
 const errors=[];pg.on('pageerror',e=>errors.push(e.message));
 await pg.goto('file://'+root+'/index.html',{waitUntil:'load'});
 const st=t=>pg.evaluate(t=>{window.__timelines.main.seek(t,true);
  const a=s=>Number(gsap.getProperty(document.querySelector(s),'autoAlpha'));
  const cards=[...document.querySelectorAll('#deck .wm-card')].map(c=>Number(gsap.getProperty(c,'autoAlpha')));
  const tearX=[...document.querySelectorAll('#tear1 > div')].map(d=>Math.round(gsap.getProperty(d,'xPercent')));
  const conf=[...document.querySelectorAll('#conf > div')].map(d=>Math.round(gsap.getProperty(d,'y')));
  return {t,a:a('#a'),b:a('#b'),c:a('#c'),d:a('#d'),fast:a('#fast'),fastRot:Math.round(gsap.getProperty('#fast','rotation')),mascot:a('#mascot'),kicks:a('#kicks'),cards,tearX,confMoved:conf.filter(y=>y!==0).length,ring:Math.round(gsap.getProperty('#ring','rotation')),cues:WM.cueTimes().length};},t);
 const at={};for(const t of [0.3,1.2,1.65,2.2,2.6,3.7,3.8,3.9,6.6])at[t]=await st(t);
 report.states=at;const c=report.checks;
 c.sticker_hidden_then_lands=at[0.3].fast===0&&at[1.2].fast===1&&at[1.2].fastRot===-6;
 c.tear_covers_then_clears=at[1.65].tearX.every(x=>Math.abs(x)<5)&&at[2.6].tearX.every(x=>Math.abs(x)>100);
 c.scene_switches_under_tear=at[1.2].a===1&&at[2.2].b===1&&at[2.2].a===0;
 c.confetti_flies=at[2.6].confMoved>20;
 c.ring_turns=at[2.6].ring>0;
 c.cards_one_at_a_time=[3.7,3.8,3.9].every(t=>at[t].cards.filter(x=>x>0.5).length===1)&&new Set([3.7,3.8,3.9].map(t=>at[t].cards.findIndex(x=>x>0.5))).size===3;
 c.final_sticker=at[6.6].d===1&&at[6.6].kicks===1;
 c.sounds_cued=at[0.3].cues>=6;
 c.no_page_errors=errors.length===0;report.errors=errors;
 for(const t of [0.9,1.45,1.6,2.5,3.35,3.7,3.85,5.1,6.5]){await pg.evaluate(t=>{window.__timelines.main.seek(t,true);},t);await writeFile(out+'/f-'+t.toFixed(2)+'.png',await pg.screenshot({type:'png'}));}
}finally{await browser.close();}
await writeFile(out+'/report.json',JSON.stringify(report,null,2));
const failed=Object.entries(report.checks).filter(([,ok])=>!ok).map(([k])=>k);
if(process.env.RENDER!=='0'&&!failed.length){
 const rendered=await renderRun({project:root,outputRoot:out+'/render',timeoutMs:600000,expected:{width:1080,height:1080,duration:7,audio:false}});
 assert.equal(rendered.status,'ready',JSON.stringify(rendered));
 await copyFile(rendered.directory+'/video.mp4',out+'/collage.mp4');
}
console.log(JSON.stringify({checks:report.checks,errors:report.errors},null,1));
assert.deepEqual(failed,[]);
