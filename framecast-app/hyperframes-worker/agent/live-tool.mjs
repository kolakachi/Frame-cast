import {mkdir,readdir,copyFile,lstat,writeFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {inspectionReport} from './inspection-report.mjs';
import {renderRun} from '../scripts/lib/render-run.mjs';
const [id,operation,times='1,6,12']=process.argv.slice(2);
if(!/^[a-z0-9-]+$/.test(id)||!['check','snapshot','render','timeline'].includes(operation))throw Error('Invalid local job');
const source='/output/live/'+id,root='/tmp/live-project',out=source+'/'+operation;
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});await mkdir(process.env.HOME,{recursive:true});
for(const file of await readdir(source+'/project')){
 if(!/^[a-zA-Z0-9_.-]+\.(html|css|js|png|svg|ttf|mp4|wav)$/.test(file)||(await lstat(source+'/project/'+file)).isSymbolicLink())throw Error('Invalid staged file');
 await copyFile(source+'/project/'+file,root+'/'+file);
}
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
let result;
if(operation==='render')result=await renderRun({project:root,outputRoot:out,expected:{width:1080,height:1920,duration:15}});
else {
 if(!/^\d+(\.\d+)?(,\d+(\.\d+)?){0,4}$/.test(times)||times.split(',').some(t=>Number(t)>30))throw Error('Invalid timestamps');
 const args=operation==='timeline'?['timeline','--json']:operation==='check'?['check',root,'--json']:['snapshot',root,'--at',times,'--no-end','--describe','false','--output',out];
 try {const {stdout,stderr}=await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs',...args],{cwd:root,timeout:120000,maxBuffer:16000000});await writeFile(out+'/command.log',stdout+stderr);result={ok:operation==='check'?JSON.parse(stdout).ok===true:true,diagnostics:operation==='timeline'?JSON.parse(stdout):operation==='check'?inspectionReport(stdout):'Snapshots captured'};}
 catch(e){await writeFile(out+'/command.log',(e.stdout||'')+(e.stderr||''));result={ok:false,diagnostics:operation==='check'?inspectionReport(e.stdout||e.stderr||e.message):(e.stdout||e.stderr||e.message).slice(0,12000)};}
}
await writeFile(out+'/result.json',JSON.stringify(result,null,2));
