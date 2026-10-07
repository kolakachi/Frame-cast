// SIGTERM drains one coordinator: finish an already claimed run, then exit without another claim.
// SIGINT requests the existing immediate-stop path, which must still confirm sandbox termination.
export function createWorkerShutdown({notify=()=>{}}={}) {
 let draining=false,stopRequested=false;
 const waiters=new Set();
 return {
  get draining(){return draining;},
  get stopRequested(){return stopRequested;},
  signal(signal){
   if(!['SIGTERM','SIGINT'].includes(signal))throw Error('Unsupported worker signal');
   draining=true;stopRequested||=signal==='SIGINT';
   notify({event:'create.worker_shutdown',mode:stopRequested?'stop':'drain'});
   for(const wake of waiters)wake();
  },
  wait(ms){
   if(draining)return Promise.resolve();
   return new Promise(resolve=>{
    const wake=()=>{clearTimeout(timer);waiters.delete(wake);resolve();};
    const timer=setTimeout(wake,ms);waiters.add(wake);
   });
  },
 };
}

export async function runWorkerLoop({shutdown,claim,execute,once=false,onError=()=>{}}) {
 let exitCode=0;
 while(!shutdown.draining){
  let capacityBlocked=false;
  try{
   const admission=await claim();capacityBlocked=admission.blocked;
   if(capacityBlocked&&once)exitCode=75;
   // A claim accepted while SIGTERM was in flight still owns a lease and must finish/report it.
   if(admission.run)await execute(admission.run);
  }catch(e){onError(e);if(once)exitCode=1;}
  if(once)break;
  await shutdown.wait(capacityBlocked?30000:2000);
 }
 return exitCode;
}
