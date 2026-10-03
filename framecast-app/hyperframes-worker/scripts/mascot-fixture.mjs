// Offline prepared-artwork proof. No model call or customer asset is used.
import {mkdir,copyFile,writeFile,readFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {createRequire} from 'node:module';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {renderRun} from './lib/render-run.mjs';
const exec=promisify(execFile);
const puppeteer=createRequire('/opt/worker/node_modules/hyperframes/package.json')('puppeteer-core');
const root='/tmp/mascot-proof';await mkdir(root,{recursive:true});
await copyFile('/opt/worker/fixtures/mascot/index.html',root+'/index.html');
await copyFile('/opt/worker/runtime/wyv-mascot.js',root+'/wyv-mascot.js');
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,protocolTimeout:30000,args:['--no-sandbox']});
const checks={};
const sharp=createRequire('/opt/worker/node_modules/hyperframes/package.json')('sharp');
const rasterChecks=[];
const sameFrame=async(a,b,label)=>{
 const x=await sharp(a).removeAlpha().raw().toBuffer(),y=await sharp(b).removeAlpha().raw().toBuffer();
 assert.equal(x.length,y.length);let sum=0,changed=0;
 for(let i=0;i<x.length;i++){const d=Math.abs(x[i]-y[i]);sum+=d;if(d)changed++;}
 const mean=sum/x.length,fraction=changed/x.length;
 rasterChecks.push({label,mean,fraction});
 assert.ok(mean<.01&&fraction<.001,label+': visual drift '+mean+' / '+fraction);
};
try{
 const page=await browser.newPage();await page.setViewport({width:1280,height:720});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('file://'+root+'/index.html');
 const seek=async t=>{
  await page.evaluate(t=>{window.__timelines.main.seek(t,true);},t);
  return page.evaluate(()=>{
   const p=n=>document.querySelector('[data-rig-part="'+n+'"]');
   return {eye:Number(gsap.getProperty(p('left-eye'),'scaleY')),tilt:Number(gsap.getProperty(p('head'),'rotation')),gaze:Number(gsap.getProperty(p('left-pupil'),'x')),
    mouths:Object.fromEntries([...document.querySelectorAll('[data-rig-mouth]')].map(n=>[n.dataset.rigMouth,Number(gsap.getProperty(n,'opacity'))])),
    outer:document.querySelector('#mascot-wrap').getBoundingClientRect().toJSON()};
  });
 };
 const neutral=await seek(.4);const original=await page.screenshot();await writeFile('/output/initial.png',original);await writeFile('/output/initial.html',await page.content());
 const blink=await seek(.908);assert.ok(blink.eye<.1);assert.equal(neutral.eye,1);
 await writeFile('/output/blink.png',await page.screenshot());
 const surprised=await seek(2.2);assert.equal(surprised.mouths.round,1);assert.equal(surprised.mouths.rest,0);assert.equal(surprised.tilt,-6);assert.equal(surprised.gaze,-5);
 const smile=await seek(3.8);assert.equal(smile.mouths.smile,1);assert.equal(smile.tilt,0);
 await writeFile('/output/smile.png',await page.screenshot());
 const open=await seek(4.5);assert.equal(open.mouths.open,1);
 assert.deepEqual(neutral.outer,open.outer,'The whole mascot stays fixed while its face changes');
 const restored=await seek(.4);await writeFile('/output/restored.png',await page.screenshot());await writeFile('/output/restored.html',await page.content());await writeFile('/output/seek-debug.json',JSON.stringify({neutral,restored},null,2));assert.deepEqual(restored,neutral,'Backward seek restores exact layer state');await sameFrame(original,await page.screenshot(),'Backward seek');
 await new Promise(r=>setTimeout(r,300));await sameFrame(original,await page.screenshot(),'No autonomous playback');
 await writeFile('/output/neutral.png',original);
 for(const t of [0,1.6,2.6,6,2.6,0]){
  const before=await seek(t);
  const a=await page.screenshot();await seek(5.8);const after=await seek(t);assert.deepEqual(after,before,'Boundary layer state '+t);await sameFrame(a,await page.screenshot(),'Boundary seek '+t);
 }
 assert.deepEqual(errors,[]);
 checks.browser={passed:true,neutral,blink,surprised,smile,open,seekSuppressedEvents:true,rasterChecks};
}finally{await browser.close();}
const rendered=await renderRun({project:root,outputRoot:'/output/render',expected:{width:1280,height:720,duration:6,audio:false}});
assert.equal(rendered.status,'ready',JSON.stringify(rendered));
await copyFile(rendered.directory+'/video.mp4','/output/mascot-proof.mp4');
// Compare just the eyes in the actual encoded output; composition position/text
// cannot make this assertion pass. FFmpeg renders at 24 fps for this fixture.
const crop=async t=>{
 const {stdout}=await exec('ffmpeg',['-v','error','-ss',String(t),'-i','/output/mascot-proof.mp4','-frames:v','1','-vf','crop=240:106:824:260','-f','rawvideo','-pix_fmt','rgb24','pipe:1'],{encoding:'buffer',maxBuffer:1000000});return stdout;
};
const a=await crop(.4),b=await crop(.9167);assert.equal(a.length,240*106*3);assert.equal(b.length,a.length);
const difference=a.reduce((n,v,i)=>n+Math.abs(v-b[i]),0)/a.length;
assert.ok(difference>2,'Encoded face region must show the blink');
checks.encoded={passed:true,meanAbsoluteEyeDifference:difference,artifact:'mascot-proof.mp4',sha256:createHash('sha256').update(await readFile('/output/mascot-proof.mp4')).digest('hex')};
checks.scope={paidUsd:0,character:'original fixture, not Maya',speech:false,automaticRigging:false,creativeAcceptance:false};
await writeFile('/output/verification.json',JSON.stringify(checks,null,2));
console.log(JSON.stringify({passed:true,artifact:'/output/mascot-proof.mp4',checks:Object.keys(checks),paidUsd:0}));
