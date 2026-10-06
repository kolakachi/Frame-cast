import {safeDiagnostic} from './limitations.mjs';

export const sandboxStopUnconfirmed=error=>error?.code==='SANDBOX_STOP_UNCONFIRMED';
export function savedFailure(error){
 return {failureCode:typeof error.code==='string'?error.code:null,
  sandboxNotStarted:error.sandboxNotStarted===true,
  sandboxDiagnostic:error.sandboxDiagnostic?{
   ...Object.fromEntries(['exit_code','signal','killed','acquired','not_started','cleanup_confirmed','original_code'].filter(k=>k in error.sandboxDiagnostic).map(k=>[k,error.sandboxDiagnostic[k]])),
   stderr:safeDiagnostic(error.sandboxDiagnostic.stderr),
  }:null};
}
export function agentFailure(state){
 const code=state.failureCode?.startsWith('SANDBOX_')?state.failureCode:state.status==='needs_attention'?'ATTEMPT_NEEDS_ATTENTION':'AGENT_STOPPED';
 return Object.assign(Error(state.reason??'Agent needs input before rendering'),{code,sandboxDiagnostic:state.sandboxDiagnostic??null,sandboxNotStarted:state.sandboxNotStarted===true});
}
