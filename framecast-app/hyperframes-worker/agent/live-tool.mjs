import {mkdir,readdir,copyFile,lstat,writeFile,readFile,unlink} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {inspectionReport} from './inspection-report.mjs';
import {renderRun} from '../scripts/lib/render-run.mjs';
const [id,operation,times='1,6,12']=process.argv.slice(2);
if(!/^[a-z0-9-]+$/.test(id)||!['check','snapshot','render','timeline','media','delivery','run','strip','inspect_reference','detail','layout','compare'].includes(operation))throw Error('Invalid local job');
if(operation==='inspect_reference'){
 const {inspectReference}=await import('./reference-inspection.mjs');
 const dir='/output/live/'+id;
 await mkdir(dir+'/inspect_reference',{recursive:true});
 let result;
 try{result=await inspectReference({runDir:dir,request:JSON.parse(await readFile(dir+'/inspect_reference-request.json','utf8'))});}
 catch(e){result={ok:false,error:String(e.message).slice(0,600)};}
 await writeFile(dir+'/inspect_reference/result.json',JSON.stringify(result,null,2));
 process.exit(0);
}
if(operation==='run'){
 // One allowlisted program in the run's work folder; the request was written by the host.
 const {runOp}=await import('./run-tool.mjs');
 const dir='/output/live/'+id;
 await mkdir(dir+'/run',{recursive:true});
 let result;
 try{const req=JSON.parse(await readFile(dir+'/run-request.json','utf8'));const timeoutMs=Number.isFinite(req.timeout_ms)?Math.min(900000,Math.max(10000,req.timeout_ms)):90000;delete req.timeout_ms;result=await runOp({runDir:dir,request:req,timeoutMs});}
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
// Repeated reviews must not mix timestamps from earlier revisions or durations.
if(operation==='strip'||operation==='snapshot')for(const file of await readdir(out)){
 if(/^frame-\d+-at-[0-9.]+s\.png$/.test(file)||/^s-\d+\.png$/.test(file))await unlink(out+'/'+file);
}
for(const file of await readdir(source+'/project')){
 if(!/^[a-zA-Z0-9_.-]+\.(html|css|js|png|jpg|webp|svg|ttf|mp4|mp3|wav)$/.test(file)||(await lstat(source+'/project/'+file)).isSymbolicLink())throw Error('Invalid staged file');
 await copyFile(source+'/project/'+file,root+'/'+file);
}
// Prepared character rigs named by placeholder are placed inline before anything reads the page.
{
 const {placeRigs}=await import('./rig-place.mjs');const {readFileSync}=await import('node:fs');
 const html=await readFile(root+'/index.html','utf8').catch(()=>null);
 if(html&&html.includes('data-rig-src')){const placed=placeRigs(html,f=>readFileSync(root+'/'+f,'utf8'));if(placed!==html)await writeFile(root+'/index.html',placed);}
}
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
for(const f of ['barty-motion.js','barty-hyperframes.js','wyv-mascot.js','three-wyv.js','wyv-3d.js'])await copyFile('/opt/worker/runtime/'+f,root+'/'+f);
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
else if(operation==='detail'){
 // Close inspection: crops of text and pictures at key moments, sequences across character actions.
 const req=JSON.parse(await readFile(source+'/detail-request.json','utf8'));
 const num=v=>Number.isFinite(Number(v))&&Number(v)>=0&&Number(v)<=30;
 const times=(Array.isArray(req.times)?req.times:[]).filter(num).map(Number).slice(0,6);
 const sequences=(Array.isArray(req.sequences)?req.sequences:[]).filter(s=>Array.isArray(s)&&num(s[0])&&num(s[1])&&Number(s[1])>Number(s[0])).map(s=>[Number(s[0]),Math.min(Number(s[1]),Number(s[0])+2.5),String(s[2]||'action').slice(0,40)]).slice(0,3);
 const {detailSheet}=await import('./detail-review.mjs');
 result=await detailSheet({root,width:dims[0],height:dims[1],times,sequences,out});
}
else if(operation==='compare'){
 // Every moment of an exact copy beside the reference's frame at the same time, for the reviewer.
 const req=JSON.parse(await readFile(source+'/compare-request.json','utf8'));
 if(!/^[A-Za-z0-9._-]+$/.test(String(req.reference||'')))throw Error('Invalid reference');
 const {compareSheet}=await import('./compare-review.mjs');
 result=await compareSheet({root,width:dims[0],height:dims[1],reference:source+'/inputs/reference/'+req.reference,moments:Array.isArray(req.moments)?req.moments:[],out});
}
else if(operation==='layout'){
 // Where every marked element (data-ref) renders at each requested time, for copying a reference exactly.
 const req=JSON.parse(await readFile(source+'/layout-request.json','utf8'));
 const times=[...new Set((Array.isArray(req.times)?req.times:[]).map(Number).filter(t=>Number.isFinite(t)&&t>=0&&t<=30))].slice(0,40);
 const {measureLayout}=await import('./layout-check.mjs');
 result={ok:true,measured:await measureLayout({root,width:dims[0],height:dims[1],times})};
}
else if(operation==='render')result=await renderRun({project:root,outputRoot:out,motionBlur:settings.motion_blur===true,expected:{width:dims[0],height:dims[1],duration:settings.duration_seconds}});
else {
 if(!/^\d+(\.\d+)?(,\d+(\.\d+)?){0,4}$/.test(times)||times.split(',').some(t=>Number(t)>30))throw Error('Invalid timestamps');
 // Keep the 30-frame budget but cover the end of longer videos as well.
 const stripTimes=operation==='strip'?(await import('./review-sampling.mjs')).reviewSampling(settings.duration_seconds).times.join(','):null;
 const args=operation==='timeline'?['timeline','--json']:operation==='check'?['check',root,'--json']:['snapshot',root,'--at',stripTimes??times,'--no-end','--describe','false','--output',out];
 try {const {stdout,stderr}=await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs',...args],{cwd:root,timeout:120000,maxBuffer:16000000,env:{...process.env,PRODUCER_PAGE_NAVIGATION_TIMEOUT_MS:'45000'}});await writeFile(out+'/command.log',stdout+stderr);const report=operation==='check'?inspectionReport(stdout):null;result={ok:operation==='check'?report.ok:true,diagnostics:operation==='timeline'?JSON.parse(stdout):operation==='check'?report:'Snapshots captured'};}
 catch(e){await writeFile(out+'/command.log',(e.stdout||'')+(e.stderr||''));
  // The check exits non-zero on any error; layout, contrast and motion alone are notes, so the build continues.
  const report=operation==='check'?inspectionReport(e.stdout||e.stderr||e.message):null;result={ok:operation==='check'?report.ok:false,diagnostics:operation==='check'?report:(e.stdout||e.stderr||e.message).slice(0,12000)};}
}
// Reading time, blank frames and slow drift: advisory for the agent, shown to the user at delivery.
if(operation==='check'&&result.ok)result.pacing=await pacing();
if(operation==='strip'&&result.ok)try{
 // One image-sequence input and the tile filter: thirty separate inputs exhaust the sandbox's thread limit.
 const shots=(await readdir(out)).filter(n=>/^frame-\d+-at-[0-9.]+s\.png$/.test(n)).sort();
 if(!shots.length)throw Error('Strip returned no images');
 for(const [i,n] of shots.entries())await copyFile(out+'/'+n,out+'/s-'+String(i).padStart(2,'0')+'.png');
 const cell=dims[0]>dims[1]?[192,108]:dims[0]===dims[1]?[144,144]:[108,192],cols=10,rows=Math.ceil(shots.length/cols);
 await promisify(execFile)('ffmpeg',['-y','-threads','1','-framerate','1','-i',out+'/s-%02d.png','-vf',`scale=${cell[0]}:${cell[1]}:force_original_aspect_ratio=decrease,pad=${cell[0]}:${cell[1]}:(ow-iw)/2:(oh-ih)/2:black,tile=${cols}x${rows}:padding=2:color=black`,'-frames:v','1','-q:v','4',out+'/strip.jpg'],{timeout:60000,maxBuffer:1000000});
 const sampling=(await import('./review-sampling.mjs')).reviewSampling(settings.duration_seconds);
 const actualTimes=shots.map(n=>Number(n.match(/at-([0-9.]+)s/)[1]));
 if(actualTimes.length!==sampling.times.length||actualTimes.some((t,i)=>Math.abs(t-sampling.times[i])>.01))throw Error('Review strip frame coverage did not match requested timestamps');
 result={ok:true,frames:shots.length,every_seconds:sampling.every_seconds,columns:cols,coverage:{...sampling,times:actualTimes}};
}catch(e){result={ok:false,error:'The review images could not be assembled; take the snapshot again.',diagnostics:String(e.stderr||e.message).slice(-1200)};}
if(operation==='snapshot'&&result.ok)try{
 const shots=(await readdir(out)).filter(n=>n.endsWith('.png')).sort().slice(0,5);
 if(!shots.length)throw Error('Snapshot returned no images');
 const args=shots.flatMap(n=>['-i',out+'/'+n]);
 // Cells follow the output aspect, so a landscape frame is reviewed at full width, not letterboxed into a portrait cell.
 const cell=dims[0]>dims[1]?[480,270]:dims[0]===dims[1]?[360,360]:[270,480],fit=`scale=${cell[0]}:${cell[1]}:force_original_aspect_ratio=decrease,pad=${cell[0]}:${cell[1]}:(ow-iw)/2:(oh-ih)/2:black`;
 // hstack needs two inputs or more: a single frame is simply fitted.
 const filters=shots.length===1?`[0:v]${fit}[sheet]`:shots.map((_,i)=>`[${i}:v]${fit}[s${i}]`).join(';')+';'+shots.map((_,i)=>`[s${i}]`).join('')+`hstack=inputs=${shots.length}[sheet]`;
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
}catch(e){result={ok:false,error:'The review images could not be assembled; take the snapshot again.',diagnostics:String(e.stderr||e.message).slice(-1200)};}
await writeFile(out+'/result.json',JSON.stringify(result,null,2));
