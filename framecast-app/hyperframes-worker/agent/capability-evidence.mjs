import {safeDiagnostic} from './limitations.mjs';

// Observed tool use is separate from model explanations and from visual quality.
export function recordCapability(state,action,result) {
  let name;
  if(action.type==='read' && /^(skills\/|cards\/|kit\/)/.test(action.path))name='read:'+action.path;
  else if(action.type==='catalog')name='catalog:'+safeDiagnostic(action.query||'');
  else if(action.type==='run')name='run:'+action.cmd;
  else if(['inspect_reference','media','buy','transcript'].includes(action.type))name=action.type+':'+(action.op||action.kind||'');
  else return;
  state.capability_evidence??=[];
  const ok=!(result?.error||result?.ok===false),old=state.capability_evidence.find(x=>x.name===name&&x.ok===ok);
  if(old){old.count++;old.last_revision=state.revision;return;}
  if(state.capability_evidence.length>=100)return;
  state.capability_evidence.push({name:safeDiagnostic(name),ok,count:1,first_call:state.calls,last_revision:state.revision,evidence:'observed_tool_result'});
}
