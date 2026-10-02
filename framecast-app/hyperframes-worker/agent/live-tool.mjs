import {mkdir,readdir,copyFile,lstat,writeFile,readFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {inspectionReport} from './inspection-report.mjs';
import {renderRun} from '../scripts/lib/render-run.mjs';
const [id,operation,times='1,6,12']=process.argv.slice(2);
if(!/^[a-z0-9-]+$/.test(id)||!['check','snapshot','render','timeline','media','delivery','run','strip'].includes(operation))throw Error('Invalid local job');
if(operation==='run'){
 // One allowlisted program in the run's work folder; the request was written by the host.
 const {runOp}=await import('./run-tool.mjs');
 const dir='/output/live/'+id;
 await mkdir(dir+'/run',{recursive:true});
 let result;
 try{result=await runOp({runDir:dir,request:JSON.parse(await readFile(dir+'/run-request.json','utf8'))});}
 catch(e){result={ok:false,error:String(e.message).slice(0,600)};}
 await writeFile(dir+'/run/result.json',JSON.stringify(result,null,2));
 process.exit(0);
}
if(operation==='media'){
 // Operates on the run's own project folder; the request was written by the host.
 const {mediaOp,nextNameFactory}=await import('./media-tool.mjs');
 const dir='/output/live/'+id,project=dir+'/project';
 await mkdir(dir+'/media',{recursive:true});
 let result;
 try{result=await mediaOp({projectDir:project,request:JSON.parse(await readFile(dir+'/media-request.json','utf8')),nextName:await nextNameFactory(project)});}
 catch(e){result={ok:false,error:String(e.message).slice(0,600)};}
 await writeFile(dir+'/media/result.json',JSON.stringify(result,null,2));
 process.exit(0);
}
const source='/output/live/'+id,root='/tmp/live-project',out=source+'/'+operation;
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});await mkdir(process.env.HOME,{recursive:true});
for(const file of await readdir(source+'/project')){
 if(!/^[a-zA-Z0-9_.-]+\.(html|css|js|png|jpg|webp|svg|ttf|mp4|mp3|wav)$/.test(file)||(await lstat(source+'/project/'+file)).isSymbolicLink())throw Error('Invalid staged file');
 await copyFile(source+'/project/'+file,root+'/'+file);
}
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
// Licensed display and text fonts (SIL OFL), vendored in runtime/fonts.
for(const f of ['inter.ttf','anton.ttf','bebas-neue.ttf','playfair.ttf','space-grotesk.ttf','caveat.ttf'])await copyFile('/opt/worker/runtime/fonts/'+f,root+'/'+f);
// Registry items the composition wires (data-composition-src) are staged from the vendored registry, with the libraries they load.
{
 const {loadCatalog,stageRegistryFiles}=await import('./registry.mjs');
 const items=await loadCatalog('/opt/worker/runtime/registry-catalog.json').catch(()=>[]);
 const html=await readFile(root+'/index.html','utf8').catch(()=>'');
 const staged=await stageRegistryFiles({root,registryRoot:'/opt/worker/runtime/registry',html,items,libs:{'lottie_light.min.js':'/opt/worker/node_modules/lottie-web/build/player/lottie_light.min.js','gsap/CustomEase.min.js':'/opt/worker/node_modules/gsap/dist/CustomEase.min.js','gsap/MotionPathPlugin.min.js':'/opt/worker/node_modules/gsap/dist/MotionPathPlugin.min.js'}});
 if(staged.missing.length)await writeFile(out+'/registry-missing.json',JSON.stringify(staged.missing));
}
let result;
let settings={aspect_ratio:'9:16',duration_seconds:15};
try{settings=JSON.parse(await readFile(source+'/output-settings.json','utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
const dims=({'9:16':[1080,1920],'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio];
if(!dims||!Number.isInteger(settings.duration_seconds)||settings.duration_seconds<5||settings.duration_seconds>30)throw Error('Invalid output contract');
async function pacing(){
 try{const {readsCheck}=await import('./reads-check.mjs');return await readsCheck({root,width:dims[0],height:dims[1],duration:settings.duration_seconds});}
 catch(e){return [{code:'pacing_check_failed',severity:'warning',message:String(e.message).slice(0,200)}];}
}
if(operation==='delivery'){
 // Delivery checks on the final composition: text inside the band each
 // platform's own interface covers, content breaching the frame edge, and
 // WCAG AA contrast. Reported to the user; they never block the render.
 const band=({'9:16':'x0=0;y0=.8;x1=1;y1=1','4:5':'x0=0;y0=.86;x1=1;y1=1','1:1':'x0=0;y0=.88;x1=1;y1=1','16:9':'x0=0;y0=.88;x1=1;y1=1'})[settings.aspect_ratio];
 let raw={};
 try{const {stdout}=await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs','check',root,'--json','--frame-check','severity=error','--caption-zone',band+';severity=error'],{cwd:root,timeout:150000,maxBuffer:16000000});raw=JSON.parse(stdout);}
 catch(e){try{raw=JSON.parse(e.stdout||'{}');}catch{raw={};}}
 const findings=Object.values(raw).flatMap(s=>s?.findings??[]).map(({code,message,selector,time,severity})=>({code,message,selector,time,severity}));
 const pick=re=>findings.filter(f=>re.test(f.code||'')).slice(0,12);
 result={ok:true,band,safe_area:pick(/caption_zone/),edges:pick(/frame|offscreen|overflow|clip/),contrast:pick(/contrast/),pacing:await pacing()};
}
else if(operation==='render')result=await renderRun({project:root,outputRoot:out,motionBlur:settings.motion_blur===true,expected:{width:dims[0],height:dims[1],duration:settings.duration_seconds}});
else {
 if(!/^\d+(\.\d+)?(,\d+(\.\d+)?){0,4}$/.test(times)||times.split(',').some(t=>Number(t)>30))throw Error('Invalid timestamps');
 // The strip: a frame every half second across the whole video, for the critic's view of pacing and motion.
 const stripTimes=operation==='strip'?Array.from({length:Math.min(30,Math.floor(settings.duration_seconds*2))},(_,i)=>(i*0.5+0.25).toFixed(2)).join(','):null;
 const args=operation==='timeline'?['timeline','--json']:operation==='check'?['check',root,'--json']:['snapshot',root,'--at',stripTimes??times,'--no-end','--describe','false','--output',out];
 try {const {stdout,stderr}=await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs',...args],{cwd:root,timeout:120000,maxBuffer:16000000});await writeFile(out+'/command.log',stdout+stderr);result={ok:operation==='check'?JSON.parse(stdout).ok===true:true,diagnostics:operation==='timeline'?JSON.parse(stdout):operation==='check'?inspectionReport(stdout):'Snapshots captured'};}
 catch(e){await writeFile(out+'/command.log',(e.stdout||'')+(e.stderr||''));result={ok:false,diagnostics:operation==='check'?inspectionReport(e.stdout||e.stderr||e.message):(e.stdout||e.stderr||e.message).slice(0,12000)};}
}
// Reading time, blank frames and slow drift: advisory for the agent, shown to the user at delivery.
if(operation==='check'&&result.ok)result.pacing=await pacing();
if(operation==='strip'&&result.ok){
 const shots=(await readdir(out)).filter(n=>n.endsWith('.png')).sort();
 if(!shots.length)throw Error('Strip returned no images');
 const cell=dims[0]>dims[1]?[192,108]:dims[0]===dims[1]?[144,144]:[108,192],cols=10,rows=Math.ceil(shots.length/cols);
 const inputs=shots.flatMap(n=>['-i',out+'/'+n]);
 const filters=shots.map((_,i)=>`[${i}:v]scale=${cell[0]}:${cell[1]}:force_original_aspect_ratio=decrease,pad=${cell[0]}:${cell[1]}:(ow-iw)/2:(oh-ih)/2:black,drawtext=text='${shots[i].replace(/^.*at-([0-9.]+)s.*$/,'$1')}':fontcolor=white:fontsize=14:x=3:y=3[c${i}]`).join(';')
  +';'+Array.from({length:rows},(_,r)=>shots.slice(r*cols,r*cols+cols).map((_,i)=>`[c${r*cols+i}]`).join('')+`hstack=inputs=${Math.min(cols,shots.length-r*cols)}[r${r}]`).join(';')
  +(rows>1?';'+Array.from({length:rows},(_,r)=>`[r${r}]`).join('')+`vstack=inputs=${rows}[strip]`:';[r0]copy[strip]');
 try{await promisify(execFile)('ffmpeg',['-y',...inputs,'-filter_complex',filters,'-map','[strip]','-frames:v','1',out+'/strip.jpg'],{timeout:60000,maxBuffer:1000000});}
 catch{ // uneven last row or no drawtext: fall back to a plain grid without labels
  const f2=shots.map((_,i)=>`[${i}:v]scale=${cell[0]}:${cell[1]}:force_original_aspect_ratio=decrease,pad=${cell[0]}:${cell[1]}:(ow-iw)/2:(oh-ih)/2:black[c${i}]`).join(';')+';'+shots.map((_,i)=>`[c${i}]`).join('')+`hstack=inputs=${shots.length}[strip]`;
  await promisify(execFile)('ffmpeg',['-y',...inputs,'-filter_complex',f2,'-map','[strip]','-frames:v','1',out+'/strip.jpg'],{timeout:60000,maxBuffer:1000000});
 }
 result={ok:true,frames:shots.length,every_seconds:0.5};
}
if(operation==='snapshot'&&result.ok){
 const shots=(await readdir(out)).filter(n=>n.endsWith('.png')).sort().slice(0,5);
 if(!shots.length)throw Error('Snapshot returned no images');
 const args=shots.flatMap(n=>['-i',out+'/'+n]);
 // Cells follow the output aspect, so a landscape frame is reviewed at full width, not letterboxed into a portrait cell.
 const cell=dims[0]>dims[1]?[480,270]:dims[0]===dims[1]?[360,360]:[270,480],fit=`scale=${cell[0]}:${cell[1]}:force_original_aspect_ratio=decrease,pad=${cell[0]}:${cell[1]}:(ow-iw)/2:(oh-ih)/2:black`;
 const filters=shots.map((_,i)=>`[${i}:v]${fit}[s${i}]`).join(';')+';'+shots.map((_,i)=>`[s${i}]`).join('')+`hstack=inputs=${shots.length}[sheet]`;
 await promisify(execFile)('ffmpeg',['-y',...args,'-filter_complex',filters,'-map','[sheet]','-frames:v','1',out+'/contact-sheet.jpg'],{timeout:30000,maxBuffer:1000000});
 // A studied reference video gets a second row: its frames at the same moments, scaled to our length, so the review compares against it.
 try{
  const ref=(await readdir(source+'/inputs/reference').catch(()=>[])).find(n=>/\.(mp4|mov|webm)$/.test(n));
  if(ref){
   const file=source+'/inputs/reference/'+ref;
   const {stdout}=await promisify(execFile)('ffprobe',['-v','error','-show_entries','format=duration','-of','csv=p=0',file],{timeout:20000});
   const refDur=Number(stdout)||0,ours=settings.duration_seconds;
   const at=shots.map(n=>Number((n.match(/at-([0-9.]+)s/)||[])[1])).filter(Number.isFinite);
   if(refDur>0&&at.length===shots.length){
    const inputs=at.flatMap(t=>['-ss',String(Math.min(refDur-0.05,t/ours*refDur)),'-i',file]);
    const f2=at.map((_,i)=>`[${i}:v]${fit}[r${i}]`).join(';')+';'+at.map((_,i)=>`[r${i}]`).join('')+`hstack=inputs=${at.length}[row]`;
    await promisify(execFile)('ffmpeg',['-y',...inputs,'-filter_complex',f2,'-map','[row]','-frames:v','1',out+'/reference-row.jpg'],{timeout:60000,maxBuffer:1000000});
    await promisify(execFile)('ffmpeg',['-y','-i',out+'/contact-sheet.jpg','-i',out+'/reference-row.jpg','-filter_complex','[0:v][1:v]vstack=inputs=2[both]','-map','[both]','-frames:v','1',out+'/contact-sheet.jpg'],{timeout:30000,maxBuffer:1000000});
    result={...result,reference_row:'The bottom row of the contact sheet is the reference video at the same moments.'};
   }
  }
 }catch(e){result={...result,reference_row_error:String(e.message).slice(0,200)};/* the review proceeds without the reference row */}
}
await writeFile(out+'/result.json',JSON.stringify(result,null,2));
