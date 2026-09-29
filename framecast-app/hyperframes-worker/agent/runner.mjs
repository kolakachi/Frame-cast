import {readFile,writeFile,rename} from 'node:fs/promises';
import {parseAction,hostPolicy} from './protocol.mjs';
import {briefGate,assertLockedSource} from './brief-guard.mjs';
import {promptHistory,primitives} from './prompt-context.mjs';
import {digest} from './workspace.mjs';

// One owner per local run. Production locking/leases belong to E2.
export async function runAgent({stateFile,context,workspace,provider,tools,skills='',limits={},signal,requireVisualReview=false,initialImage}) {
  const cap={calls:12,repairs:2,elapsedMs:180000,contextBytes:200000,maxOutputTokens:8192,totalOutputTokenAllowance:98304,budgetUsd:0,...limits};
  if (![cap.calls,cap.repairs,cap.elapsedMs,cap.contextBytes,cap.maxOutputTokens,cap.totalOutputTokenAllowance,cap.budgetUsd].every(Number.isFinite) || cap.calls<1 || cap.repairs<0 || cap.budgetUsd<0) throw Error('Invalid limits');
  const identity=digest(JSON.stringify({context,skills,cap,provider:provider.id,requireVisualReview}));
  let state;
  try {state=JSON.parse(await readFile(stateFile,'utf8'));} catch(e){if(e.code!=='ENOENT')throw e;}
  if(state && state.identity!==identity)throw Error('Run context changed; start a new run');
  if(state?.bundleHash && state.bundleHash!==await workspace.fingerprint())throw Error('Source bundle changed outside this run');
  if(state && state.status!=='running'){await workspace.verifyAssets();return state;}
  state ??= {identity,status:'running',calls:0,repairs:0,reservedUsd:0,reservedOutputTokens:0,elapsedMs:0,messages:[],revision:0,checkedRevision:-1,snapshotRevision:-1,reviewedRevision:-1,pending:null};
  state.bundleHash ??= await workspace.fingerprint();
  const started=Date.now(),previousElapsed=state.elapsedMs;
  const save=async()=>{state.elapsedMs=previousElapsed+Date.now()-started;await writeFile(stateFile+'.tmp',JSON.stringify(state,null,2),{mode:0o600});await rename(stateFile+'.tmp',stateFile);};
  if(state.pending){state.status='needs_attention';state.reason='Interrupted action: reconcile before retrying';await save();return state;}
  const gate=briefGate(context);if(gate){Object.assign(state,gate);await save();return state;}
  const timeout=AbortSignal.timeout(Math.max(1,cap.elapsedMs-state.elapsedMs));
  const boundedSignal=signal?AbortSignal.any([signal,timeout]):timeout;
  const bounded = work => new Promise((resolve,reject)=>{
    const abort=()=>reject(Error('Run cancelled or deadline exceeded'));
    boundedSignal.addEventListener('abort',abort,{once:true});
    Promise.resolve().then(()=>{boundedSignal.throwIfAborted();return work();}).then(resolve,reject).finally(()=>boundedSignal.removeEventListener('abort',abort));
  });
  try {
    while(state.calls<cap.calls) {
      boundedSignal.throwIfAborted();
      await workspace.verifyAssets();
      const prompt=JSON.stringify({context,attachedSnapshot:state.reviewImage ? {revision:state.snapshotRevision,instruction:'The attached image is the current contact sheet. Inspect it now and return visual_review. Do not request another snapshot unless you need different timestamps.'} : null,remainingCalls:cap.calls-state.calls,revision:state.revision,history:promptHistory(state.messages)});
      if(Buffer.byteLength(prompt)+Buffer.byteLength(skills)>cap.contextBytes)throw Error('Context limit reached');
      const reservation=provider.maxCallUsd;
      if(!Number.isFinite(reservation)||reservation<0||state.reservedUsd+reservation>cap.budgetUsd)throw Error('Model budget exhausted');
      if(state.reservedOutputTokens+cap.maxOutputTokens>cap.totalOutputTokenAllowance)throw Error('Output token allowance exhausted');
      state.reservedOutputTokens+=cap.maxOutputTokens;
      state.reservedUsd+=reservation;state.calls++;state.pending={kind:'provider',call:state.calls};await save();
      const reviewImage=state.reviewImage;
      const callStarted=Date.now();
      const response=await bounded(()=>provider.complete({prompt,system:hostPolicy+'\nPinned guidance:\n'+skills,maxTokens:cap.maxOutputTokens,image:reviewImage||initialImage,signal:boundedSignal,onPrediction:async id=>{if(state.pending){state.pending.predictionId=id;await save();}}}));
      boundedSignal.throwIfAborted();
      state.usage??=[];state.usage.push({call:state.calls,predictionId:response.predictionId,promptBytes:Buffer.byteLength(prompt),systemBytes:Buffer.byteLength(hostPolicy+skills),elapsedMs:Date.now()-callStarted,metrics:response.metrics});
      // Persist returned output before dispatch. Never repeat an uncertain paid create.
      state.pending=null;state.messages.push({role:'assistant',content:response.text});await save();
      let action;
      try {action=parseAction(response.text);} catch(e) {
        if(++state.repairs>cap.repairs)throw Error('Action repair limit reached');
        state.messages.push({role:'tool',content:{error:e.message}});await save();continue;
      }
      state.pending={kind:'tool',action};await save();
      let result;
      if(action.type==='read')result={text:action.path.startsWith('references/')&&tools.guidance?await tools.guidance(action.path):await workspace.read(action.path)};
      else if(action.type==='write'||action.type==='patch') {
        try {
          let text=action.content;
          if(action.type==='patch') {
            const old=await workspace.read(action.path);
            if(old.split(action.before).length!==2)throw Object.assign(Error('Patch must match exactly once. Read the current source before retrying.'),{code:'AUTHORING_REJECTED'});
            text=old.replace(action.before,action.after);
          }
          if(action.path==='index.html')assertLockedSource(context,text);
          await workspace.write(action.path,text);state.bundleHash=await workspace.fingerprint();state.revision++;result={revision:state.revision};
        } catch(e) {
          // Only known pre-write authoring rejections are repairable. Filesystem,
          // sandbox and uncertain write failures still stop the run.
          if(e.code!=='AUTHORING_REJECTED')throw e;
          if(++state.repairs>cap.repairs)throw Error('Authoring repair limit reached');
          result={error:e.message,sourceUnchanged:true,remainingRepairs:cap.repairs-state.repairs};
        }
      } else if(action.type==='assets') result=workspace.assets;
      else if(action.type==='primitives') result=primitives;
      else if(action.type==='timeline') {if(!tools.timeline)throw Error('Timeline tool not installed');result=await bounded(()=>tools.timeline({signal:boundedSignal}));}
      else if(action.type==='preview') {
        result=await bounded(()=>tools.check({signal:boundedSignal}));
        if(result.ok){state.checkedRevision=state.revision;result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;result={...result,providerImage:undefined};}}
        else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
      }
      else if(action.type==='check') {
        result=await bounded(()=>tools.check({signal:boundedSignal}));
        if(result.ok)state.checkedRevision=state.revision;
        else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
      } else if(action.type==='snapshot') {
        if(state.checkedRevision!==state.revision)throw Error('Check the current draft before snapshots');
        result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));
        if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;result={...result,providerImage:undefined};}
      } else if(action.type==='visual_review') {
        if(!reviewImage || state.snapshotRevision!==state.revision)throw Error('Visual review requires current host-provided snapshot');
        state.reviewImage=null;
        if(action.decision==='pass'){state.reviewedRevision=state.revision;if(requireVisualReview){state.status='preview_ready';state.summary=action.findings;}}
        else {state.reviewedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Visual repair limit reached');}
        result={decision:action.decision,findings:action.findings};
      } else if(action.type==='finish') {
        if(requireVisualReview && state.reviewedRevision!==state.revision)throw Error('Visual review is required');
        if(state.checkedRevision!==state.revision||state.snapshotRevision!==state.revision)throw Error('Current draft requires check and snapshots');
        state.status='preview_ready';state.summary=action.summary;
      } else if(action.type==='needs_input') {state.status='needs_input';state.question=action.question;}
      else if(action.type==='propose_media') {state.status='awaiting_media_approval';state.proposal=action.description;}
      await workspace.verifyAssets();boundedSignal.throwIfAborted();
      if(result && action.type!=='read' && Buffer.byteLength(JSON.stringify(result))>16000)result={truncated:true,summary:JSON.stringify(result).slice(0,12000)};
      state.pending=null;state.messages.push({role:'tool',content:result??{status:state.status}});await save();
      if(state.status!=='running')return state;
    }
    throw Error('Model call limit reached');
  } catch(e) {
    state.status=state.pending?.kind==='provider'?'needs_attention':boundedSignal.aborted?'cancelled':'failed';
    if(e.code==='BUDGET_EXHAUSTED'){state.pending=null;state.status='budget_exhausted';state.reason=e.message;await save();return state;}
    state.failureDetail=e.message;
    state.reason=state.pending?.kind==='provider'?'Provider outcome needs reconciliation; do not resubmit automatically':e.message;
    await save();return state;
  }
}
