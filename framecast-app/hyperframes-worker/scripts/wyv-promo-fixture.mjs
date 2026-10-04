// Renders the WyvStudio promo proof (fixtures/wyv-promo): one composition built moment for moment on the
// Pocketsflow reference, with the 3D mascot and props drawn on canvases from the timeline.
// Mounts: /vo (timing.json, narration.wav from scripts/wyv-promo-voice.py), /ref/ref.mp4 (the reference).
// Writes /output/wyv-promo: a still per reference moment beside the reference frame, and the final MP4.
import {mkdir,copyFile,writeFile,readFile,readdir} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {createRequire} from 'node:module';
import {renderRun} from './lib/render-run.mjs';
const run=promisify(execFile),req=createRequire('/opt/worker/node_modules/hyperframes/package.json'),puppeteer=req('puppeteer-core');
const src='/opt/worker/fixtures/wyv-promo',root='/tmp/wyv-promo',out='/output/wyv-promo';
for(const d of [root,out,out+'/stills'])await mkdir(d,{recursive:true});
// The 3D runtime as one browser script (three.js with the mascot and prop builders; no React or Remotion),
// built the same way as the image's runtime/three-wyv.js.
await run('/opt/worker/node_modules/.bin/esbuild',['/opt/worker/runtime/three/entry.js','--bundle','--format=iife','--minify','--alias:react=/opt/worker/runtime/three/shim-react.js','--alias:remotion=/opt/worker/runtime/three/shim-remotion.js','--outfile='+root+'/three-wyv.js','--log-level=warning']);
await copyFile(src+'/index.html',root+'/index.html');await copyFile('/opt/worker/runtime/wyv-3d.js',root+'/wyv-3d.js');
await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
for(const f of ['inter.ttf','playfair.ttf'])await copyFile('/opt/worker/runtime/fonts/'+f,root+'/'+f);
await writeFile(root+'/timing.js','window.TIMING='+await readFile('/vo/timing.json','utf8')+';');
// One still per reference moment (late in the moment, once its elements have arrived), beside the reference.
const MOMENTS=[0.4,0.95,2.0,2.3,2.7,3.2,3.6,4.3,4.6,6.5,6.98,7.06,7.3,7.9,9.3,10.5,10.7,11.1,13.0,13.4,13.8,14.8];
const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,protocolTimeout:120000,
 args:['--no-sandbox','--enable-webgl','--ignore-gpu-blocklist','--use-angle=swiftshader','--enable-unsafe-swiftshader']});
const errors=[];
try{
 const page=await browser.newPage();await page.setViewport({width:1920,height:1080});
 page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
 await page.goto('file://'+root+'/index.html',{waitUntil:'load'});await page.evaluate(()=>document.fonts.ready);
 for(const [i,t] of MOMENTS.entries()){
  await page.evaluate(t=>{window.__timelines.main.seek(t,true);},t);
  await page.screenshot({path:`${out}/stills/o-${String(i+1).padStart(2,'0')}.png`});
 }
}finally{await browser.close();}
const font='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
for(const [i,t] of MOMENTS.entries()){
 const n=String(i+1).padStart(2,'0');
 await run('ffmpeg',['-v','error','-y','-ss',String(t),'-i','/ref/ref.mp4','-frames:v','1','-vf','scale=640:360',`${out}/stills/r-${n}.png`]);
 await run('ffmpeg',['-v','error','-y','-i',`${out}/stills/r-${n}.png`,'-i',`${out}/stills/o-${n}.png`,'-filter_complex',
  `[1]scale=640:360[o];[0][o]hstack,pad=iw+4:ih+30:2:0:black,drawtext=fontfile=${font}:text='m${i+1}  ${t}s   reference | ours':x=10:y=h-24:fontsize=18:fontcolor=white`,`${out}/stills/p-${n}.png`]);
}
for(const [k,start] of [['a',1],['b',12]])await run('ffmpeg',['-v','error','-y','-framerate','1','-start_number',String(start),'-i',`${out}/stills/p-%02d.png`,'-frames:v','11','-vf','tile=2x6:padding=4:color=gray','-frames:v','1',`${out}/compare-${k}.jpg`]);
console.log(JSON.stringify({errors:errors.slice(0,10)}));
if(process.env.STILLS_ONLY)process.exit(0);
const rendered=await renderRun({project:root,outputRoot:out+'/render',timeoutMs:1800000,expected:{width:1920,height:1080,duration:15,audio:false}});
if(rendered.status!=='ready')throw Error('Render failed: '+JSON.stringify(rendered));
const video=`${out}/render/${rendered.id}/video.mp4`;
await run('ffmpeg',['-v','error','-y','-i',video,'-i','/vo/narration.wav','-map','0:v','-map','1:a','-c:v','copy','-c:a','aac','-b:a','192k','-shortest',`${out}/wyv-promo.mp4`]);
console.log('done',out+'/wyv-promo.mp4');
