import {mkdir,readdir,copyFile,lstat,writeFile,readFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {inspectionReport} from './inspection-report.mjs';
import {renderRun} from '../scripts/lib/render-run.mjs';
const [id,operation,times='1,6,12']=process.argv.slice(2);
if(!/^[a-z0-9-]+$/.test(id)||!['check','snapshot','render','timeline','media'].includes(operation))throw Error('Invalid local job');
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
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
let result;
let settings={aspect_ratio:'9:16',duration_seconds:15};
try{settings=JSON.parse(await readFile(source+'/output-settings.json','utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
const dims=({'9:16':[1080,1920],'16:9':[1920,1080],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio];
if(!dims||!Number.isInteger(settings.duration_seconds)||settings.duration_seconds<5||settings.duration_seconds>30)throw Error('Invalid output contract');
if(operation==='render')result=await renderRun({project:root,outputRoot:out,expected:{width:dims[0],height:dims[1],duration:settings.duration_seconds}});
else {
 if(!/^\d+(\.\d+)?(,\d+(\.\d+)?){0,4}$/.test(times)||times.split(',').some(t=>Number(t)>30))throw Error('Invalid timestamps');
 const args=operation==='timeline'?['timeline','--json']:operation==='check'?['check',root,'--json']:['snapshot',root,'--at',times,'--no-end','--describe','false','--output',out];
 try {const {stdout,stderr}=await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs',...args],{cwd:root,timeout:120000,maxBuffer:16000000});await writeFile(out+'/command.log',stdout+stderr);result={ok:operation==='check'?JSON.parse(stdout).ok===true:true,diagnostics:operation==='timeline'?JSON.parse(stdout):operation==='check'?inspectionReport(stdout):'Snapshots captured'};}
 catch(e){await writeFile(out+'/command.log',(e.stdout||'')+(e.stderr||''));result={ok:false,diagnostics:operation==='check'?inspectionReport(e.stdout||e.stderr||e.message):(e.stdout||e.stderr||e.message).slice(0,12000)};}
}
if(operation==='snapshot'&&result.ok){
 const shots=(await readdir(out)).filter(n=>n.endsWith('.png')).sort().slice(0,5);
 if(!shots.length)throw Error('Snapshot returned no images');
 const args=shots.flatMap(n=>['-i',out+'/'+n]);
 const filters=shots.map((_,i)=>`[${i}:v]scale=270:-2,pad=270:480:0:(oh-ih)/2:black[s${i}]`).join(';')+';'+shots.map((_,i)=>`[s${i}]`).join('')+`hstack=inputs=${shots.length}[sheet]`;
 await promisify(execFile)('ffmpeg',['-y',...args,'-filter_complex',filters,'-map','[sheet]','-frames:v','1',out+'/contact-sheet.jpg'],{timeout:30000,maxBuffer:1000000});
}
await writeFile(out+'/result.json',JSON.stringify(result,null,2));
