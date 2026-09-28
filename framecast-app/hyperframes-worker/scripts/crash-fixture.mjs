import {mkdir,copyFile,writeFile} from 'node:fs/promises';
import {renderRun} from './lib/render-run.mjs';
const root='/tmp/crash-fixture';await mkdir(root,{recursive:true});await mkdir(process.env.HOME,{recursive:true});
for(const file of ['index.html','product.svg'])await copyFile('/opt/worker/fixtures/'+file,root+'/'+file);
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
await renderRun({project:root,outputRoot:'/output/crash-proof',expected:{width:1080,height:1920,duration:15},onStage:stage=>{if(stage==='render')writeFile('/output/crash-proof/render-started','yes');}});
