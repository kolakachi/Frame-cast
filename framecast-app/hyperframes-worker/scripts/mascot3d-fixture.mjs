// Renders the parametric 3D mascot proof (fixtures/mascot3d) to /output/mascot3d: performance stills and clip,
// a turnaround in two finishes with expressions, and a second character from another spec.
import {mkdir,copyFile} from 'node:fs/promises';
import {bundle} from '@remotion/bundler';
import {selectComposition,renderMedia,renderStill} from '@remotion/renderer';
const work='/opt/worker/.mascot3d',out='/output/mascot3d';await mkdir(work,{recursive:true});await mkdir(out,{recursive:true});
await copyFile('/opt/worker/fixtures/mascot3d/index.jsx',work+'/index.jsx');await copyFile('/opt/worker/runtime/wyv-mascot3d.js',work+'/wyv-mascot3d.js');
const serveUrl=await bundle({entryPoint:work+'/index.jsx',outDir:'/tmp/mascot3d-bundle',enableCaching:false});
const common={serveUrl,browserExecutable:'/usr/bin/chromium',logLevel:'error',timeoutInMilliseconds:60000,chromiumOptions:{gl:'swangle'},
 onBrowserLog:l=>{if(l.type==='error')console.error('[browser]',l.text.slice(0,300));}};
const still=async(id,props,name,frame=0)=>{const c=await selectComposition({...common,id,inputProps:props});await renderStill({...common,composition:c,inputProps:props,frame,output:`${out}/${name}.png`,imageFormat:'png'});};
for(const [i,y] of [-0.9,-0.45,0,0.45,0.9].entries())await still('Turnaround',{yaw:y,finish:'dither'},`turn-dither-${i}`);
for(const [i,y] of [-0.6,0,0.6].entries())await still('Turnaround',{yaw:y,finish:'clay'},`turn-clay-${i}`);
for(const fin of ['clay','dither','toon','halftone','plush','ceramic'])await still('Turnaround',{yaw:0.3,finish:fin},`finish-${fin}`);
for(const f of ['smile','surprised','laugh','wink'])await still('Turnaround',{yaw:0,finish:'dither',face:f},`face-${f}`);
for(const [i,y] of [-0.5,0,0.5].entries())await still('Variant',{yaw:y},`variant-${i}`);
await still('Variant',{yaw:0,face:'surprised'},'variant-surprised');
if(process.env.STILLS_ONLY){console.log('done');process.exit(0);}
const perform=await selectComposition({...common,id:'Perform'});
for(const t of [0.2,0.45,0.9,1.3,1.6,1.95,2.2,2.85])await renderStill({...common,composition:perform,frame:Math.round(t*60),output:`${out}/perform-${t.toFixed(2)}.png`,imageFormat:'png'});
await renderMedia({...common,composition:perform,codec:'h264',outputLocation:`${out}/perform.mp4`,concurrency:1,crf:18});
console.log('done');
