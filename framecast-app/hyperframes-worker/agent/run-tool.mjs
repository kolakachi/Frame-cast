// Sandbox program runner: one allowlisted program, an argv list, inside the
// run's own work folder. The model never supplies a shell line, a path outside
// the run, a URL, or a node flag. Protected project files are restored if the
// program touches them; new files must be media the renderer accepts.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {mkdir,readdir,lstat,readFile,copyFile,unlink,rm,symlink,realpath} from 'node:fs/promises';
import path from 'node:path';import {createHash} from 'node:crypto';
import {checkRunArgs} from './protocol.mjs';
const exec=promisify(execFile);
export const OUTPUT_NAME=/^[a-zA-Z0-9_.-]+\.(png|jpg|webp|svg|mp4|mp3|wav)$/;
const sha=async f=>createHash('sha256').update(await readFile(f)).digest('hex');
const clip=(s,n=6000)=>{s=String(s??'');return s.length>n?s.slice(0,n)+'\n…['+(s.length-n)+' more characters]':s;};

// ffmpeg reports progress as it works; one that falls silent this long has stalled (a filter graph waiting on
// itself, for example) and is stopped instead of holding the build until the time limit.
export const STALL_MS=90000;
export async function runOp({runDir,request,hyperframesBin=process.env.HYPERFRAMES_BIN??'/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs',timeoutMs=90000,stallMs=STALL_MS}){
 const {cmd,args}=request;checkRunArgs(cmd,args);
 const project=path.join(runDir,'project'),work=path.join(runDir,'work'),backup=path.join(runDir,'.run-backup');
 await mkdir(work,{recursive:true});await mkdir(project,{recursive:true});
 // project/ inside the work folder is the composition's files, by a relative link.
 try{await symlink('../project',path.join(work,'project'));}catch(e){if(e.code!=='EEXIST')throw e;}
 // Every project file is backed up; whatever the program changes is put back.
 await rm(backup,{recursive:true,force:true});await mkdir(backup,{recursive:true});
 const before=new Map();
 for(const name of await readdir(project)){
  const st=await lstat(path.join(project,name));if(!st.isFile())continue;
  await copyFile(path.join(project,name),path.join(backup,name));before.set(name,await sha(path.join(project,name)));
 }
 const [realWork,realProject]=await Promise.all([realpath(work),realpath(project)]);
 const program=({
  ffmpeg:['ffmpeg',['-nostdin','-hide_banner',...args]],
  ffprobe:['ffprobe',['-hide_banner',...args]],
  'fc-list':['fc-list',args],
  // Node runs under its permission model: the work and project folders only, no child processes.
  node:[process.execPath,['--permission','--allow-fs-read='+realWork,'--allow-fs-read='+realProject,'--allow-fs-write='+realWork,'--allow-fs-write='+realProject,...args]],
  hyperframes:[process.execPath,[hyperframesBin,...args]],
  remotion:[process.execPath,['/opt/worker/scripts/remotion-tool.mjs',realProject,...args]],
 })[cmd];
 const env={PATH:'/usr/local/bin:/usr/bin:/bin',HOME:process.env.HOME||'/tmp',HYPERFRAMES_BROWSER_PATH:process.env.HYPERFRAMES_BROWSER_PATH||'',HYPERFRAMES_NO_TELEMETRY:'1',NO_COLOR:'1'};
 const started=Date.now();let exit=0,stdout='',stderr='';
 let child;
 let stalled=false,watch=null;
 try{const pending=exec(program[0],program[1],{cwd:work,env,timeout:timeoutMs,detached:cmd==='remotion',maxBuffer:8*1024*1024,killSignal:'SIGKILL'});child=pending.child;
  if(cmd==='ffmpeg'){let last=Date.now();const seen=()=>{last=Date.now();};child.stdout?.on('data',seen);child.stderr?.on('data',seen);
   // Growing output files also count as progress, for commands run with quiet logging.
   let bytes=-1;const size=async()=>{let n=0;for(const d of [work,project])for(const f of await readdir(d).catch(()=>[])){const st=await lstat(path.join(d,f)).catch(()=>null);if(st?.isFile())n+=st.size;}return n;};
   watch=setInterval(async()=>{const now=await size();if(now!==bytes){bytes=now;seen();}if(Date.now()-last>stallMs){stalled=true;try{child.kill('SIGKILL');}catch{}}},Math.min(5000,stallMs));}
  ({stdout,stderr}=await pending);}
 catch(e){if(cmd==='remotion'&&child?.pid)try{process.kill(-child.pid,'SIGKILL');}catch{}
 exit=e.killed||e.signal?124:(Number.isInteger(e.code)?e.code:1);stdout=e.stdout||'';stderr=(e.stderr||'')+(stalled?'\n[stopped: no progress for '+Math.round(stallMs/1000)+' s; the command had stalled]':e.killed||e.signal?'\n[stopped after '+Math.round(timeoutMs/1000)+' s]':e.code==='ENOENT'?'\n[program not available]':'');}
 finally{if(watch)clearInterval(watch);}
 // Sort out the project folder: restore protected files, drop what the renderer cannot stage, list what is new.
 const outputs=[],removed=[],restored=[];
 for(const name of await readdir(project)){
  const file=path.join(project,name),st=await lstat(file);
  if(before.has(name)){
   if(st.isFile()&&await sha(file)===before.get(name))continue;
   await rm(file,{recursive:true,force:true});await copyFile(path.join(backup,name),file);restored.push(name);continue;
  }
  if(!st.isFile()||!OUTPUT_NAME.test(name)||st.size===0||st.size>200*1024*1024){await rm(file,{recursive:true,force:true});removed.push(name);continue;}
  if(cmd==='remotion'&&exit!==0){await rm(file);removed.push(name);continue;}
  outputs.push({path:name,sha256:await sha(file),bytes:st.size});
 }
 for(const [name] of before)try{await lstat(path.join(project,name));}catch{await copyFile(path.join(backup,name),path.join(project,name));restored.push(name);}
 await rm(backup,{recursive:true,force:true});
 const scratch=(await readdir(work)).filter(n=>n!=='project').sort().slice(0,40);
 return {ok:exit===0&&!restored.length,cmd,exit,elapsed_ms:Date.now()-started,stdout:clip(stdout),stderr:clip(stderr),outputs,scratch,
  ...(restored.length?{restored,error:'Protected project files are never changed by run; they were put back: '+restored.join(', ')+'. Write results under new names.'}:{}),
  ...(removed.length?{removed,note:'Only png, jpg, webp, svg, mp4, mp3 and wav files may be added to project/; keep scripts and scratch in the work folder: '+removed.join(', ')}:{})};
}
