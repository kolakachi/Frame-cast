import {statfs} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';

function number(value,fallback,min,max){
 const n=value===undefined?fallback:Number(value);
 if(!Number.isFinite(n)||n<min||n>max)throw Error('Invalid Create worker disk-capacity configuration');
 return n;
}
export function diskPolicy(root,env=process.env){
 const extra=JSON.parse(env.CREATE_WORKER_EXTRA_DISK_PATHS??'[]');
 if(!Array.isArray(extra)||extra.some(p=>typeof p!=='string'||!path.isAbsolute(p)))throw Error('CREATE_WORKER_EXTRA_DISK_PATHS must be a JSON array of absolute mount paths');
 return {paths:[...new Set([path.join(root,'artifacts'),os.tmpdir(),...extra])],strictPaths:extra,
  minFreeBytes:number(env.CREATE_WORKER_MIN_FREE_BYTES,8*1024**3,0,Number.MAX_SAFE_INTEGER),
  minFreeRatio:number(env.CREATE_WORKER_MIN_FREE_RATIO,.1,0,1)};
}

export async function diskCapacity(policy,{read=statfs}={}){
 const volumes=[];
 for(const requested of policy.paths){
  let directory=requested,info;
  try{
   for(;;){
    try{info=await read(directory);break;}catch(e){
     if(e.code!=='ENOENT'||policy.strictPaths?.includes(requested)||path.dirname(directory)===directory)throw e;
     directory=path.dirname(directory);
    }
   }
   const free=Number(info.bavail)*Number(info.bsize),total=Number(info.blocks)*Number(info.bsize);
   const required=Math.max(policy.minFreeBytes,Math.ceil(total*policy.minFreeRatio));
   volumes.push({path:requested,free_bytes:free,total_bytes:total,required_bytes:required,
    ok:Number.isFinite(free)&&Number.isFinite(total)&&free>=required&&total>0});
  }catch{volumes.push({path:requested,ok:false,error:'Disk capacity unavailable'});}
 }
 return {ok:volumes.length>0&&volumes.every(v=>v.ok),volumes};
}

export function createDiskGate(policy,{read=statfs,notify=()=>{}}={}){
 let previous;
 async function check(){
  const status=await diskCapacity(policy,{read});
  if(previous!==status.ok){notify({event:'create.worker_disk_capacity',state:status.ok?'available':'waiting',...status});previous=status.ok;}
  return status.ok;
 }
 return {check,async require(){if(!await check())throw Object.assign(Error('Waiting for worker disk capacity; existing work is retained.'),{code:'CREATE_DISK_CAPACITY'});},
  async claim(claim){return await check()?{blocked:false,run:await claim()}:{blocked:true,run:null};}};
}
