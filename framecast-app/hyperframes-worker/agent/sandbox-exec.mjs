import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
const exec=promisify(execFile);
export const sandboxQueueWaitMs=600000;

// Start the execution clock only when the entrypoint confirms acquisition.
// A missing acquisition marker is uncertain, never proof that work did not start.
export function sandboxExec(command,args,{onQueue=()=>{},timeout=180000,queueTimeout=sandboxQueueWaitMs+30000,signal,...options}={}){
 if(![timeout,queueTimeout].every(n=>Number.isFinite(n)&&n>0))throw Error('Invalid sandbox timeout');
 return new Promise((resolve,reject)=>{
  let acquired=false,waiting=false,timedOut=null,timer;
  const controller=new AbortController();
  const child=execFile(command,args,{...options,signal:signal?AbortSignal.any([signal,controller.signal]):controller.signal},(error,stdout,stderr)=>{
   clearTimeout(timer);
   if(!error){resolve({stdout,stderr});return;}
   const exitCode=error.code;
   if(exitCode===75&&waiting&&!acquired){
    error.code='SANDBOX_QUEUE_TIMEOUT';error.sandboxNotStarted=true;
    error.message='Render capacity stayed busy for the queue wait limit. Saved assets are safe; no sandbox command was started.';
   }else if(timedOut){
    error.code=timedOut;
    error.message=acquired?'The sandbox command exceeded its execution time limit.':'Sandbox startup or capacity acquisition was not confirmed before its deadline.';
   }
   error.sandboxDiagnostic={exit_code:exitCode??null,signal:error.signal??null,killed:error.killed===true,acquired,not_started:error.sandboxNotStarted===true,stderr:String(stderr??'').slice(-2000)};
   reject(error);
  });
  const arm=(ms,code)=>{clearTimeout(timer);timer=setTimeout(()=>{timedOut=code;controller.abort();},ms);};
  arm(queueTimeout,'SANDBOX_START_TIMEOUT');
  let pending='';
  child.stderr?.on('data',chunk=>{
   pending+=chunk.toString();
   const lines=pending.split('\n');pending=lines.pop().slice(-2000);
   for(const line of lines){
    const state=line.trim()==='WYV_SANDBOX_WAITING'&&!waiting&&!acquired?'waiting':line.trim()==='WYV_SANDBOX_ACQUIRED'&&!acquired?'acquired':null;
    if(state==='waiting')waiting=true;
    if(state==='acquired'){acquired=true;arm(timeout,'SANDBOX_EXECUTION_TIMEOUT');}
    if(state)try{onQueue(state);}catch{/* status reporting must not stop a command */}
   }
  });
 });
}

export async function confirmSandboxStopped(docker,names,{execute=exec}={}){
 if(!names.length||names.some(n=>!/^wyv-create-[a-zA-Z0-9-]+$/.test(n)))throw Error('Invalid sandbox container name');
 try{await execute(docker,['rm','-f',...names],{timeout:15000});return true;}catch{
  try{
   const {stdout}=await execute(docker,['ps','-a','--filter','name=^/('+names.join('|')+')$','--format','{{.Names}}'],{timeout:15000});
   return !stdout.trim();
  }catch{return false;}
 }
}

// Every failure must confirm termination before a caller may recover a saved draft.
export async function managedSandboxExec(command,args,options,{execute=sandboxExec,stop=confirmSandboxStopped}={}){
 const index=args.indexOf('--name'),name=index>=0?args[index+1]:null;
 if(!/^wyv-create-[a-zA-Z0-9-]+$/.test(name??''))throw Error('Named sandbox required');
 try{return await execute(command,args,options);}catch(error){
  let stopped=false;try{stopped=await stop(command,[name]);}catch{/* unconfirmed */}
  if(!stopped){
   error.sandboxDiagnostic={...error.sandboxDiagnostic,original_code:error.code??null,cleanup_confirmed:false};
   error.code='SANDBOX_STOP_UNCONFIRMED';error.sandboxNotStarted=false;
   error.message='The sandbox could not be confirmed stopped. Recovery is required before continuing.';
  }
  throw error;
 }
}

// The agent may hit its own deadline before a tool has finished Docker cleanup.
// Drain those tools before publishing, starting another render or closing the run.
export function createSandboxSupervisor(execute=managedSandboxExec){
 const tasks=new Set();let cleanupFailure=null;
 return {
  run(...args){
   const task=Promise.resolve().then(()=>execute(...args));tasks.add(task);
   task.then(()=>tasks.delete(task),error=>{tasks.delete(task);if(error.code==='SANDBOX_STOP_UNCONFIRMED')cleanupFailure=error;});
   return task;
  },
  async drain(){await Promise.allSettled([...tasks]);if(cleanupFailure)throw cleanupFailure;},
 };
}
