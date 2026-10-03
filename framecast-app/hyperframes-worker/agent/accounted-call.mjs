import {digest} from './workspace.mjs';

// Neither a retry wrapper nor a provider-cost estimator. A lost receipt pauses
// the caller; reconciliation, not a fresh prediction, is the next action.
export async function accountedCall({key,kind,input,begin,settle,execute,receipt}) {
 const attempt=await begin({attempt_key:key,kind,request_hash:digest(JSON.stringify(input))});
 if(!attempt.may_execute)throw Error('Attempt already recorded; reconcile instead of executing again');
 let output;
 try{output=await execute(attempt.id);}
 catch(error){
  if(kind==='render' && error.code==='LOCAL_RENDER_FAILED') {
   try { await settle(attempt.id,{status:'failed',cost_microusd:0}); }
   catch (settlementError) { settlementError.code='ATTEMPT_NEEDS_ATTENTION'; throw settlementError; }
   throw error;
  }
  if(error.code==='NOT_SENT')throw error; // settled as not sent by the app; nothing to reconcile
  try{await settle(attempt.id,{status:'unknown'});}catch{/* durable started receipt still retains the hold */}
  error.code='ATTEMPT_NEEDS_ATTENTION';
  throw error;
 }
 let result;
 try {result=receipt(output);await settle(attempt.id,result);}
 catch(error){error.code='ATTEMPT_NEEDS_ATTENTION';throw error;} // Do not execute again if this acknowledgement is lost.
 return output;
}
