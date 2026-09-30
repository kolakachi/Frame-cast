import {readFile,writeFile,rename} from 'node:fs/promises';
import {parseAction,hostPolicy} from './protocol.mjs';
import {chainFor,mapThrough,compact,suggestCuts,removedWords,tightenRanges} from './transcript-map.mjs';
import {rowsOf,timingFindings} from './timing-check.mjs';
import {numberFindings} from './grounding-check.mjs';
import {briefGate,assertLockedSource} from './brief-guard.mjs';
import {promptHistory,primitives} from './prompt-context.mjs';
import {digest} from './workspace.mjs';

// One owner per local run. Production locking/leases belong to E2.
export async function runAgent({stateFile,context,workspace,provider,tools,skills='',limits={},signal,requireVisualReview=false,initialImage}) {
  const cap={calls:12,repairs:2,elapsedMs:180000,callReserveMs:240000,contextBytes:200000,maxOutputTokens:8192,totalOutputTokenAllowance:98304,budgetUsd:0,...limits};
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
  // The last draft that passed every check and was snapshotted, kept so a run
  // that hits its deadline or call limit mid-repair still delivers a valid video.
  const keepGood=async()=>{if(state.checkedRevision!==state.revision||state.snapshotRevision!==state.revision)return;const files={};for(const f of ['index.html','style.css','main.js']){try{files[f]=await workspace.read(f);}catch{/* not every draft has every file */}}state.lastGood={revision:state.revision,files};};
  const deliverGood=async why=>{
    for(const [f,t] of Object.entries(state.lastGood.files))await workspace.write(f,t);
    state.bundleHash=await workspace.fingerprint();state.revision=state.lastGood.revision;state.checkedRevision=state.snapshotRevision=state.revision;
    state.status='preview_ready';state.summary=('This is the last version that passed every automated check (layout, timing, contrast and grounded numbers). '+why).slice(0,1900);
  };
  const bounded = work => new Promise((resolve,reject)=>{
    const abort=()=>reject(Error('Run cancelled or deadline exceeded'));
    boundedSignal.addEventListener('abort',abort,{once:true});
    Promise.resolve().then(()=>{boundedSignal.throwIfAborted();return work();}).then(resolve,reject).finally(()=>boundedSignal.removeEventListener('abort',abort));
  });
  // When a repair leaves the same findings in place, say so plainly; the usual
  // cause is intentional layering (animated words, stacked cards) that must be
  // declared rather than rewritten again.
  const repeated=result=>{
    const errs=result?.diagnostics?.errors;if(!Array.isArray(errs)||!errs.length)return result;
    const key=JSON.stringify(errs.map(e=>[e.code,e.selector]).sort());
    const again=state.lastFindings===key;state.lastFindings=key;
    if(!again)return result;
    const overlap=errs.some(e=>/overlap|occlu/.test(e.code||''));
    return {...result,diagnostics:{...result.diagnostics,repeated:true,note:'These exact findings survived your last repair. Do not rewrite the same code again.'+(overlap?' If the overlap is intended (per-word or per-letter animation, stacked layers), add data-layout-allow-overlap (or data-layout-allow-occlusion) to the containing element instead.':' Change approach or remove the element.')}};
  };
  // Words the user approved or wrote; numbers on screen must come from here.
  const allowedText=()=>[context.brief,...(context.messages||[]).filter(m=>m.role==='user').map(m=>m.content),...(context.approvedFacts||[]),
    ...(context.plan?.on_screen_copy||[]),...(context.plan?.narration||[]),context.settings?.caption_text||''].join('\n');
  // Timing rules the renderer cannot see: spoken cues and media shorter than its slot.
  const timing=async()=>{
    if(!tools.timeline)return {ok:true};
    const html=await workspace.read('index.html').catch(()=>'');
    if(!/<(video|audio)\b|data-spoken/.test(html)){const n=numberFindings(html,allowedText());return n.length?{ok:false,diagnostics:{ok:false,errors:n}}:{ok:true};}
    const tl=await bounded(()=>tools.timeline({signal:boundedSignal}));
    if(!tl?.ok)return {ok:true};
    const rows=rowsOf(tl.diagnostics);
    state.durations??={};
    for(const r of rows)if(['video','audio'].includes(r.kind)&&r.src&&state.durations[r.src]===undefined&&tools.media&&workspace.assets.some(a=>a.path===r.src)){
      const p=await bounded(()=>tools.media({op:'probe',input:r.src,params:{},signal:boundedSignal})).catch(()=>null);
      state.durations[r.src]=Number(p?.info?.duration)||null;
    }
    const errors=timingFindings({rows,html,durations:state.durations,transcripts:state.transcripts||{}});
    errors.push(...numberFindings(html,allowedText()));
    return errors.length?{ok:false,diagnostics:{ok:false,errors}}:{ok:true};
  };
  try {
    while(state.calls<cap.calls) {
      boundedSignal.throwIfAborted();
      // Never start a model call that may not finish in the time left: a call cut
      // off mid-flight is paid for and lost. Deliver the last checked draft instead.
      if(state.lastGood&&cap.elapsedMs-(previousElapsed+Date.now()-started)<(cap.callReserveMs??240000)){await deliverGood('The time limit was near during a later repair, so that repair is not included. Give it a look before posting.');await save();return state;}
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
        const cut=response.stopReason==='max_tokens'||/Unterminated|Unexpected end/i.test(e.message);
        state.messages.push({role:'tool',content:{error:e.message,...(cut?{hint:'Your reply was cut off by the output limit (thinking counts toward it). Split the work into smaller writes: index.html with markup only, then style.css and main.js as separate write actions, each under 6,000 characters, linked from index.html.'}:{})}});await save();continue;
      }
      state.pending={kind:'tool',action};await save();
      let result;
      if(action.type==='read')result={text:(action.path.startsWith('references/')||action.path.startsWith('style-example/'))&&tools.guidance?await tools.guidance(action.path):await workspace.read(action.path)};
      else if(action.type==='write'||action.type==='patch') {
        try {
          let text=action.content;
          // Editing an existing version: change it with patches, not a full rewrite,
          // unless the request is a redesign. A rewrite costs as much as a first build.
          if(action.type==='write'&&context.editOnly&&await workspace.read(action.path).then(()=>true,()=>false))
            throw Object.assign(Error('This is an edit of an existing version. Change it with patch actions (several small patches are fine); write would rebuild the whole file.'),{code:'AUTHORING_REJECTED'});
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
      } else if(action.type==='media') {
        if(!tools.media)throw Error('Media tool not installed');
        if(!workspace.assets.some(a=>a.path===action.input))result={ok:false,error:'Input is not a file in this project. Call assets to list them.'};
        else if(action.op==='tighten'&&!state.transcripts?.[action.input]){
          result={ok:false,error:'Call transcript on this file first; tighten cuts from its word timings.'};
          if(++state.repairs>cap.repairs)throw Error('Media repair limit reached');
        } else {
          // tighten: the transcript's filler and false-start cuts, applied as one cut.
          if(action.op==='tighten'){
            const probe=await bounded(()=>tools.media({op:'probe',input:action.input,params:{},signal:boundedSignal}));
            const plan=tightenRanges(state.transcripts[action.input].map(([text,start,end])=>({text,start,end})),Number(probe?.info?.duration)||0,{pauses:action.params?.pauses===true});
            action={...action,op:plan.removed?'cut':'probe',params:plan.removed?{keep:plan.keep}:{}};
          }
          result=await bounded(()=>tools.media({op:action.op,input:action.input,params:action.params,signal:boundedSignal}));
          // A derived file is protected from later changes like any supplied asset.
          if(result.ok && result.output)workspace.assets.push({path:result.output,sha256:result.sha256,derivedFrom:action.input,operation:action.op,params:action.params,...(result.source_map?{sourceMap:result.source_map}:{})});
          // Cutting transcribed speech: say exactly which words went, flag any that were not filler or a repeat, and check sync.
          if(result.ok && result.output && result.source_map && state.transcripts?.[action.input]){
            const words=state.transcripts[action.input],step={operation:action.op,sourceMap:result.source_map};
            const {removed,content}=removedWords(words,step);
            state.transcripts[result.output]=mapThrough(words.map(([text,start,end])=>({text,start,end})),[step]).map(w=>[w.text,w.start,w.end]);
            const expected=result.source_map.reduce((t,m)=>t+(m.src_end-m.src_start),0),actual=Number(result.info?.duration);
            result={...result,removed_words:removed,...(content.length?{content_removed:content,warning:'These words are not filler or repeats; removing them may change the meaning. Adjust the cut or say so in your summary.'}:{}),
              ...(Number.isFinite(actual)&&Math.abs(actual-expected)>0.15?{sync_warning:`Output is ${actual.toFixed(2)} s but the kept ranges total ${expected.toFixed(2)} s; audio and video may drift.`}:{})};
            if(removed.length)(state.edits??=[]).push({input:action.input,output:result.output,op:action.op,removed,content_removed:content});
          }
          if(!result.ok && ++state.repairs>cap.repairs)throw Error('Media repair limit reached');
        }
      } else if(action.type==='transcript') {
        if(!tools.transcript)throw Error('Transcript tool not installed');
        try {
          // Transcribe the original once; carry its times through this run's edits.
          const {root,steps}=chainFor(action.input,workspace.assets);
          const t=await bounded(()=>tools.transcript({input:root,signal:boundedSignal}));
          const mapped={words:mapThrough(t.words,steps),segments:mapThrough(t.segments,steps)};
          state.transcripts={...(state.transcripts||{}),[action.input]:mapped.words.map(w=>[w.text,w.start,w.end])};
          result={ok:true,input:action.input,timeline:steps.length?'mapped from '+root+' through '+steps.map(s=>s.operation).join(', '):'original',...compact(mapped),suggested_cuts:suggestCuts(mapped.words).map(({indices,...c})=>c)};
        } catch(e) {
          if(boundedSignal.aborted)throw e;
          result={ok:false,error:e.message};
          if(++state.repairs>cap.repairs)throw Error('Transcript repair limit reached');
        }
      } else if(action.type==='assets') result=workspace.assets.map(({sourceMap,...a})=>a);
      else if(action.type==='primitives') result=primitives;
      else if(action.type==='timeline') {if(!tools.timeline)throw Error('Timeline tool not installed');result=await bounded(()=>tools.timeline({signal:boundedSignal}));}
      else if(action.type==='preview') {
        result=await bounded(()=>tools.check({signal:boundedSignal}));
        if(result.ok){const t=await timing();if(!t.ok)result=t;}
        if(!result.ok)result=repeated(result);else state.lastFindings=null;
        if(result.ok){state.checkedRevision=state.revision;result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;await keepGood();result={...result,providerImage:undefined};}}
        else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
      }
      else if(action.type==='check') {
        result=await bounded(()=>tools.check({signal:boundedSignal}));
        if(result.ok){const t=await timing();if(!t.ok)result=t;}
        if(!result.ok)result=repeated(result);else state.lastFindings=null;
        if(result.ok)state.checkedRevision=state.revision;
        else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
      } else if(action.type==='snapshot') {
        if(state.checkedRevision!==state.revision)throw Error('Check the current draft before snapshots');
        result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));
        if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;await keepGood();result={...result,providerImage:undefined};}
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
      } else if(action.type==='needs_input') {
        // On the last call, a draft that passed every check is delivered with the open issues, not held back.
        if(state.calls>=cap.calls&&state.checkedRevision===state.revision&&state.snapshotRevision===state.revision&&state.revision>0){
          state.status='preview_ready';state.summary=('Draft delivered at the call limit. It passes every automated check; open issues from the last review: '+action.question).slice(0,1900);
        } else if(state.calls>=cap.calls&&state.lastGood){await deliverGood('The call limit was reached during a repair; open issues from the last review: '+action.question);}
        else {state.status='needs_input';state.question=action.question;}
      }
      else if(action.type==='propose_media') {state.status='awaiting_media_approval';state.proposal=action.description;}
      await workspace.verifyAssets();boundedSignal.throwIfAborted();
      if(result && action.type!=='read' && Buffer.byteLength(JSON.stringify(result))>16000)result={truncated:true,summary:JSON.stringify(result).slice(0,12000)};
      state.pending=null;state.messages.push({role:'tool',content:result??{status:state.status}});await save();
      if(state.status!=='running')return state;
    }
    // Out of calls right after a draft passed every check and was snapshotted:
    // deliver it rather than discard a valid video, and say review was skipped.
    if(state.checkedRevision===state.revision&&state.snapshotRevision===state.revision&&state.revision>0&&!state.pending){
      state.status='preview_ready';
      state.summary='This version passed every automated check (layout, timing, contrast and grounded numbers). The call limit was reached before the final visual review, so give it a look before posting.';
      await save();return state;
    }
    if(state.lastGood&&!state.pending){await deliverGood('The call limit was reached during a later repair, so that repair is not included. Give it a look before posting.');await save();return state;}
    throw Error('Model call limit reached');
  } catch(e) {
    if(e.code==='NOT_STARTED'||e.code==='NOT_SENT')state.pending=null;
    // Out of time (not cancelled by the user) with no paid call in doubt: deliver the last checked draft.
    if(timeout.aborted&&!signal?.aborted&&state.lastGood&&state.pending?.kind!=='provider'){
      state.pending=null;try{await deliverGood('The time limit was reached during a later repair, so that repair is not included. Give it a look before posting.');await save();return state;}catch{/* fall through to the failure below */}
    }
    state.status=state.pending?.kind==='provider'?'needs_attention':boundedSignal.aborted?'cancelled':'failed';
    if(e.code==='BUDGET_EXHAUSTED'){state.pending=null;state.status='budget_exhausted';state.reason=e.message;await save();return state;}
    state.failureDetail=e.message;
    state.reason=state.pending?.kind==='provider'?'Provider outcome needs reconciliation; do not resubmit automatically':e.message;
    await save();return state;
  }
}
