import {randomUUID} from 'node:crypto';

const uuid=/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;

// Optional during staged rollout. Configure a unique, stable ID for each coordinator host.
export function workerIdentity(env=process.env) {
 const id=env.CREATE_WORKER_ID;
 if(!id){
  if(env.CREATE_WORKER_SLOT)throw Error('CREATE_WORKER_SLOT requires CREATE_WORKER_ID');
  return null;
 }
 const slot=env.CREATE_WORKER_SLOT??'render-1';
 if(!/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/.test(id)||!/^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$/.test(slot))throw Error('Invalid Create worker ID or slot');
 return {worker_id:id,instance_id:randomUUID(),slot};
}

export function verifyClaimAssignment(run,identity) {
 if(!run||!identity)return run;
 const a=run.assignment;
 if(!a||!uuid.test(a.id)||a.worker_id!==identity.worker_id||a.instance_id!==identity.instance_id||a.slot!==identity.slot)
  throw Error('Claim assignment does not match this worker instance; keep the run for reconciliation');
 return run;
}
