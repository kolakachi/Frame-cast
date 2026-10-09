// Offline proof of the document helpers (wyv-motion.js: book, slides, pageFocus): an open book whose pages turn,
// a push-in on a page's chart, then a deck's slides. Pages and slides are drawn here. No model call.
import {mkdir,copyFile,writeFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
import assert from 'node:assert/strict';
import {renderRun} from './lib/render-run.mjs';
const req=createRequire('/opt/worker/node_modules/hyperframes/package.json');
const puppeteer=req('puppeteer-core'),sharp=req('sharp');
const root='/tmp/documents',out='/output/documents';
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});
await copyFile('/opt/worker/fixtures/documents/index.html',root+'/index.html');
await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
const lines=(y0,n)=>Array.from({length:n},(_,i)=>`<rect x="70" y="${y0+i*22}" width="${560-(i*37%120)}" height="9" rx="4" fill="#c9c4bb"/>`).join('');
const page=(n,body)=>`<svg xmlns="http://www.w3.org/2000/svg" width="700" height="940"><rect width="700" height="940" fill="#fbf8f2"/><text x="70" y="110" font-family="sans-serif" font-size="40" font-weight="700" fill="#1d2b53">${['Disaster recovery plan','Contents','Who to call','Recovery time','Backups','Testing'][n-1]}</text>${body}<text x="630" y="900" font-family="sans-serif" font-size="18" fill="#8a8579" text-anchor="end">${n}</text></svg>`;
const chart=`<g transform="translate(70,420)">${[120,190,260,330].map((h,i)=>`<rect x="${30+i*130}" y="${340-h}" width="80" height="${h}" fill="#ff6b35"/>`).join('')}<line x1="0" y1="340" x2="560" y2="340" stroke="#1d2b53" stroke-width="3"/></g>`;
for(let n=1;n<=6;n++)await sharp(Buffer.from(page(n,n===4?lines(160,9)+chart:lines(160,30)))).png().toFile(root+`/page-${n}.png`);
const slide=(title,sub,bg,fg,fig)=>`<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080"><rect width="1920" height="1080" fill="${bg}"/><text x="140" y="420" font-family="sans-serif" font-size="96" font-weight="700" fill="${fg}">${title}</text><text x="140" y="520" font-family="sans-serif" font-size="44" fill="${fg}" opacity=".75">${sub}</text>${fig||''}</svg>`;
await sharp(Buffer.from(slide('Reporting eats your week','Q4 · Agency Pro','#1d2b53','#fff'))).png().toFile(root+'/slide-1.png');
await sharp(Buffer.from(slide('6 hours back','every week','#fff','#1d2b53','<circle cx="1420" cy="540" r="230" fill="#ff6b35"/><text x="1420" y="575" font-family="sans-serif" font-size="110" font-weight="700" fill="#fff" text-anchor="middle">6h</text>'))).png().toFile(root+'/slide-2.png');
await sharp(Buffer.from(slide('3 months for the price of 2','Until December 31','#ff6b35','#1a0d06'))).png().toFile(root+'/slide-3.png');

const report={checks:{}};
const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,protocolTimeout:60000,args:['--no-sandbox']});
try{
 const pg=await browser.newPage();await pg.setViewport({width:1920,height:1080});
 const errors=[];pg.on('pageerror',e=>errors.push(e.message));
 await pg.goto('file://'+root+'/index.html',{waitUntil:'load'});
 const state=t=>pg.evaluate(t=>{
  window.__timelines.main.seek(t,true);
  const leaf=id=>gsap.getProperty(document.querySelector(id).parentNode,'rotationY');
  const a=id=>Number(gsap.getProperty(document.querySelector(id),'autoAlpha'));
  return {t,leaf1:leaf('#p2'),leaf2:leaf('#p4'),content:gsap.getProperty('#content','scale'),s1:a('#sl1'),s2:a('#sl2'),s3:a('#sl3'),s2x:gsap.getProperty('#sl2','xPercent'),deck:gsap.getProperty('#deckContent','scale'),cues:WM.cueTimes()};
 },t);
 const log=(...a)=>process.env.TRACE&&console.error(...a);
 const at={};for(const t of [0.5,1.65,2.3,3.5,4.6,5.2,6.0,7.5,7.9,8.6,9.3,10.2,11.5]){log('state',t);at[t]=await state(t);}
 report.states=at;const c=report.checks;
 c.book_closed_at_start=at[0.5].leaf1===0&&at[0.5].leaf2===0;
 c.first_turn_midway=at[1.65].leaf1<-45&&at[1.65].leaf1>-135;
 c.first_turn_done=at[2.3].leaf1===-180&&at[2.3].leaf2===0;
 c.focus_in_and_out=at[3.5].content>1.2&&at[5.2].content===1;
 c.second_turn_done=at[6.0].leaf2===-180;
 c.slides_push=at[7.5].s1===1&&at[7.9].s2===1&&at[7.9].s2x>0&&at[8.6].s2x===0;
 c.slide_focus=at[9.3].deck>1.2&&at[11.5].deck===1;
 c.last_slide=at[11.5].s3===1;
 c.page_sounds=at[0.5].cues.filter(q=>q.sound==='page').length===2;
 c.no_page_errors=errors.length===0;report.errors=errors;
 for(const t of [0.5,1.5,1.75,2.3,3.6,4.9,6.0,7.9,8.3,9.4,11.0]){log('shot',t);await pg.evaluate(t=>{window.__timelines.main.seek(t,true);},t);await writeFile(out+'/f-'+t.toFixed(2)+'.png',await pg.screenshot({type:'png'}));}
}finally{await browser.close();}
await writeFile(out+'/report.json',JSON.stringify(report,null,2));
const failed=Object.entries(report.checks).filter(([,ok])=>!ok).map(([k])=>k);
if(process.env.RENDER!=='0'&&!failed.length){
 const rendered=await renderRun({project:root,outputRoot:out+'/render',timeoutMs:600000,expected:{width:1920,height:1080,duration:12,audio:false}});
 assert.equal(rendered.status,'ready',JSON.stringify(rendered));
 await copyFile(rendered.directory+'/video.mp4',out+'/documents.mp4');
}
console.log(JSON.stringify({checks:report.checks,errors:report.errors},null,1));
assert.deepEqual(failed,[]);
