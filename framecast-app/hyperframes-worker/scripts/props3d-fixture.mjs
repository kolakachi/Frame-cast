// Renders the 3D props proof (fixtures/props3d) to /output/props3d: each object in three finishes, the spin
// over time as a strip, and the row of three cards landing in turn as a clip.
import {mkdir,copyFile} from 'node:fs/promises';
import {bundle} from '@remotion/bundler';
import {selectComposition,renderMedia,renderStill} from '@remotion/renderer';
const work='/opt/worker/.props3d',out='/output/props3d';await mkdir(work,{recursive:true});await mkdir(out,{recursive:true});
await copyFile('/opt/worker/fixtures/props3d/index.jsx',work+'/index.jsx');await copyFile('/opt/worker/runtime/wyv-mascot3d.js',work+'/wyv-mascot3d.js');
const serveUrl=await bundle({entryPoint:work+'/index.jsx',outDir:'/tmp/props3d-bundle',enableCaching:false});
const common={serveUrl,browserExecutable:'/usr/bin/chromium',logLevel:'error',timeoutInMilliseconds:60000,chromiumOptions:{gl:'swangle'},
 onBrowserLog:l=>{if(l.type==='error')console.error('[browser]',l.text.slice(0,300));}};
const still=async(id,props,name,frame)=>{const c=await selectComposition({...common,id,inputProps:props});await renderStill({...common,composition:c,inputProps:props,frame,output:`${out}/${name}.png`,imageFormat:'png'});};
for(const o of ['laptop','books','blocks'])for(const f of ['dither','clay','toon'])await still('Card',{object:o,at:0,finish:f},`${o}-${f}`,149);
for(const [i,t] of [0,0.08,0.16,0.25,0.35,0.5,0.7,0.9,1.4,2.4].entries())await still('Card',{object:'laptop',at:0,finish:'dither'},`spin-${String(i).padStart(2,'0')}`,Math.round(t*60));
if(process.env.STILLS_ONLY){console.log('done');process.exit(0);}
const row=await selectComposition({...common,id:'Row'});
await renderMedia({...common,composition:row,codec:'h264',outputLocation:`${out}/row.mp4`,concurrency:1,crf:18});
console.log('done');
