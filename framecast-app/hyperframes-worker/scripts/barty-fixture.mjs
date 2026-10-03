// Offline adapter acceptance in the existing Hyperframes image. No providers/network.
import {mkdir,copyFile,writeFile,readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {renderRun} from './lib/render-run.mjs';
import {createRequire} from 'node:module';
const require=createRequire('/opt/worker/node_modules/hyperframes/package.json');
const puppeteer=require('puppeteer-core');
const root='/tmp/barty-fixture';await mkdir(root,{recursive:true});
await copyFile('/opt/worker/fixtures/barty/index.html',root+'/index.html');
for(const f of ['barty-motion.js','barty-hyperframes.js'])await copyFile('/opt/worker/runtime/'+f,root+'/'+f);
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
try{
 const page=await browser.newPage();await page.setViewport({width:1920,height:1080});
 await page.goto('file://'+root+'/index.html');
 const frame=async t=>{await page.evaluate(t=>{window.__timelines.main.seek(t,true);},t);return page.screenshot();};
 const a=await frame(1);await frame(5);const b=await frame(1);assert.deepEqual(a,b,'Backward seek must reproduce the frame');
 await new Promise(r=>setTimeout(r,300));assert.deepEqual(b,await page.screenshot(),'No autonomous animation');
 const colour=await page.$eval('#stage',n=>getComputedStyle(n).backgroundColor);assert.equal(colour,'rgb(234, 244, 242)');
 for(const t of [1,3,5])await writeFile('/output/barty-'+t+'.png',await frame(t));
 const state=await page.evaluate(()=>({duration:window.__timelines.main.duration(),paused:window.__timelines.main.paused()}));assert.deepEqual(state,{duration:6,paused:true});
 await writeFile('/output/barty-seek.json',JSON.stringify({passed:true,...state,colour,frameHash:createHash('sha256').update(a).digest('hex')},null,2));
}finally{await browser.close();}
const rendered=await renderRun({project:root,outputRoot:'/output/barty-render',expected:{width:1920,height:1080,duration:6,audio:false}});
console.log(JSON.stringify(rendered));
