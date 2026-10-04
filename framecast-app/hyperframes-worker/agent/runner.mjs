import {actionEvent} from './trajectory.mjs';
import {recordCapability} from './capability-evidence.mjs';
import {recordLimitation,observeToolResult,observeStop} from './limitations.mjs';
import {readFile,writeFile,rename} from 'node:fs/promises';
import {preflight} from './preflight.mjs';
import {criticLine} from './critic.mjs';
import {parseAction,hostPolicy,toolHostPolicy,toolDefinitions,actionFromToolUse} from './protocol.mjs';
import {chainFor,mapThrough,compact,suggestCuts,removedWords,tightenRanges} from './transcript-map.mjs';
import {rowsOf,timingFindings,duckingFindings,audioEdges,audioEdgeFindings,clipUsageFindings} from './timing-check.mjs';
import {beatFindings} from './narration-timing.mjs';
import {moveFindings} from './move-check.mjs';
import {layoutFindings} from './layout-check.mjs';
import {numberFindings} from './grounding-check.mjs';
import {briefGate,assertLockedSource} from './brief-guard.mjs';
import {promptHistory,primitives} from './prompt-context.mjs';
import {digest} from './workspace.mjs';

// One owner per local run. Production locking/leases belong to E2.
export async function runAgent({stateFile,context,workspace,provider,tools,skills='',limits={},signal,stopRequested=()=>false,initialTranscripts={},requireVisualReview=false,initialImage,onProgress=()=>{},onTrace=async()=>{}}) {
  const cap={calls:12,repairs:2,runs:24,inspections:8,resultBytes:16000,usesPerTurn:8,criticCalls:0,elapsedMs:180000,contextBytes:200000,maxOutputTokens:8192,totalOutputTokenAllowance:98304,budgetUsd:0,...limits};
  if (![cap.calls,cap.repairs,cap.elapsedMs,cap.contextBytes,cap.maxOutputTokens,cap.totalOutputTokenAllowance,cap.budgetUsd].every(Number.isFinite) || cap.calls<1 || cap.repairs<0 || cap.budgetUsd<0) throw Error('Invalid limits');
  const identity=digest(JSON.stringify({context,skills,cap,provider:provider.id,requireVisualReview}));
  let state;
  try {state=JSON.parse(await readFile(stateFile,'utf8'));} catch(e){if(e.code!=='ENOENT')throw e;}
  if(state && state.identity!==identity)throw Error('Run context changed; start a new run');
  if(state?.bundleHash && state.bundleHash!==await workspace.fingerprint())throw Error('Source bundle changed outside this run');
  if(state && state.status!=='running'){await workspace.verifyAssets();return state;}
  state ??= {identity,status:'running',calls:0,repairs:0,runs:0,reservedUsd:0,reservedOutputTokens:0,elapsedMs:0,messages:[],revision:0,checkedRevision:-1,snapshotRevision:-1,reviewedRevision:-1,pending:null};
  state.bundleHash ??= await workspace.fingerprint();
  // Transcripts made before the run (the bought narration) are known from the first call.
  state.transcripts={...initialTranscripts,...(state.transcripts||{})};
  const started=Date.now(),previousElapsed=state.elapsedMs;
  const save=async()=>{state.elapsedMs=previousElapsed+Date.now()-started;await writeFile(stateFile+'.tmp',JSON.stringify(state,null,2),{mode:0o600});await rename(stateFile+'.tmp',stateFile);
    // A derived owner diagnostic, separate from render assets and model context.
    // The authoritative records are in state; a report-file error must not stop paid work.
    const reportFile=stateFile.replace(/\.json$/,'')+'.limitations.json';
    try{await writeFile(reportFile+'.tmp',JSON.stringify({schema:1,status:state.status,calls:state.calls,revision:state.revision,
      capabilities:state.capability_evidence??[],records:state.limitations??[],overflow:state.limitation_overflow??0,authorization_changed:false},null,2),{mode:0o600});await rename(reportFile+'.tmp',reportFile);}
    catch{state.limitation_report_write_failed=true;}
  };
  // What the agent is doing and what it has spent so far, for the chat's activity line.
  const spentUsd=()=>(state.usage||[]).reduce((n,u)=>n+(Number(u.costUsd)||0),0);
  const progress=(doing)=>{try{onProgress({doing,call:state.calls,calls:cap.calls,spentUsd:spentUsd(),revision:state.revision});}catch{/* reporting never stops a build */}};
  if(state.pending){state.status='needs_attention';state.reason='Interrupted action: reconcile before retrying';observeStop(state,state.reason);await save();return state;}
  const gate=briefGate(context);if(gate){Object.assign(state,gate);observeStop(state,gate.question||gate.reason||gate.proposal||'Brief requires clarification');await save();return state;}
  const timeout=AbortSignal.timeout(Math.max(1,cap.elapsedMs-state.elapsedMs));
  const boundedSignal=signal?AbortSignal.any([signal,timeout]):timeout;
  // The last draft that passed every check and was snapshotted, kept so a run
  // that hits its deadline or call limit mid-repair still delivers a valid video.
  const keepGood=async()=>{if(state.checkedRevision!==state.revision||state.snapshotRevision!==state.revision)return;const files={};for(const f of await workspace.sourceFiles()){try{files[f]=await workspace.read(f);}catch{/* not every draft has every file */}}state.lastGood={revision:state.revision,files};};
  // What the user reads about a delivered version: plain words, never scores, rounds or that a reviewer exists.
  // The technical reason is kept in internalNote for the trajectory and for us.
  const SAY={ready:'Your video is ready.',best:'Here is the best version so far. You can keep improving it.',stopped:'Stopped at your request. This is the last finished version.'};
  const deliverGood=async why=>{
    await workspace.restoreSources(state.lastGood.files);
    state.bundleHash=await workspace.fingerprint();state.revision=state.lastGood.revision;state.checkedRevision=state.snapshotRevision=state.revision;
    const last=state.scores?' Last review scores: '+state.scores.map(x=>x.time+'s '+x.score).join(', ')+'.':'';
    state.internalNote=('Draft, review incomplete. '+why+last).slice(0,1900);
    state.recoveredDraft=true;state.status='preview_ready';state.summary=SAY.best;
  };
  // The user pressed Stop: the step in progress has finished, so nothing is left in doubt. Keep the last
  // version that passed every check; with none, the build ends cancelled.
  // The storyboard is one still per beat on purpose: stillness is not a finding there.
  const ownStage=list=>(list||[]).filter(f=>!(context.lookOnly&&f.code==='still_stretch'));
  // Every composition file except the kit's own runtime, for checks that read the source.
  const compositionSources=async()=>{let out=await workspace.read('index.html').catch(()=>'');for(const f of await workspace.sourceFiles().catch(()=>[]))if(f!=='index.html'&&!/^(gsap|wyv-|barty-)/.test(f))out+='\n'+await workspace.read(f).catch(()=>'');return out;};
  // Findings sent back once at finish (fix, or finish again saying why): pacing, plus reference moves the plan names but the build never uses.
  // Copying exactly: every moment looked at in the reference first, and every marked element in its slot at its time.
  const exactLayout=async()=>{
   const layout=context.plan?.reference_match==='exact'?(context.plan.reference_layout||[]):[];
   if(!layout.length||!tools.layout)return [];
   const times=[...new Set(layout.map(m=>Number(m.at)).filter(Number.isFinite))];
   const r=await bounded(()=>tools.layout({params:{times},signal:boundedSignal})).catch(e=>({ok:false,error:String(e.message)}));
   if(!r?.ok)return [];
   return layoutFindings({layout,measured:r.measured,inspectedTimes:state.inspectedTimes||[]});
  };
  const softFindings=async list=>[...ownStage(list),...(context.lookOnly||!context.plan?[]:moveFindings({plan:context.plan,sources:await compositionSources()})),...await exactLayout()];
  const stopNow=async()=>{
    if(!stopRequested())return false;
    if(state.lastGood){
      await deliverGood('');
      state.stoppedByUser=true;state.summary=SAY.stopped;
    } else {state.status='cancelled';state.reason='Stopped at your request before any version passed its checks.';}
    observeStop(state,'Stopped at your request');await save();return true;
  };
  const bounded = work => new Promise((resolve,reject)=>{
    const abort=()=>reject(Error('Run cancelled or deadline exceeded'));
    boundedSignal.addEventListener('abort',abort,{once:true});
    Promise.resolve().then(()=>{boundedSignal.throwIfAborted();return work();}).then(resolve,reject).finally(()=>boundedSignal.removeEventListener('abort',abort));
  });
  // A host-owned final review cannot be skipped by an author that keeps editing.
  // It uses the already-approved critic allowance, never an extra author call.
  // The latest frames of the current revision: unreviewed ones first, else the ones the author already reviewed.
  const finalImage=()=>state.reviewImage||(state.reviewedImageRevision===state.revision?state.reviewedImage:null)||null;
  // Close inspection for the reviewer: text and pictures at each beat's key moment, and frames across each
  // requested character action that has a time.
  // Copying exactly: every moment as a pair, the reference's frame beside ours at the same time.
  const compareLook=async()=>{
    const layout=context.plan?.reference_match==='exact'?(context.plan.reference_layout||[]):[];
    const ref=(context.assets||[]).find(f=>f.purpose==='reference'&&f.asset_type==='video');
    if(!layout.length||!ref||!tools.compare)return {};
    const r=await bounded(()=>tools.compare({params:{reference:ref.name,moments:layout.map(m=>({id:m.moment,at:Number(m.at)}))},signal:boundedSignal})).catch(()=>null);
    return r?.ok&&r.providerImage?{compare:r.providerImage,compareCells:r.cells||[]}:{};
  };
  const closeLook=async()=>{
    if(context.lookOnly||!tools.detail)return compareLook();
    const scenes=context.plan?.scenes||[];
    const times=scenes.map(s=>+((Number(s.start)+Number(s.end))/2).toFixed(2)).filter(Number.isFinite).slice(0,6);
    const sequences=(context.plan?.character_performance||[]).filter(p=>Number.isFinite(Number(p.start))&&Number.isFinite(Number(p.end))).map(p=>[Number(p.start),Number(p.end),String(p.action||p.id||'action')]).slice(0,3);
    if(!times.length&&!sequences.length)return {};
    const r=await bounded(()=>tools.detail({params:{times,sequences},signal:boundedSignal})).catch(()=>null);
    return {...(r?.ok&&r.providerImage?{detail:r.providerImage,detailCells:r.cells||[]}:{}),...await compareLook()};
  };
  const finalReview=async()=>{
    if(!requireVisualReview||!tools.critic||!cap.reviewReserveMs||state.pending||
       (state.criticCalls??0)>=cap.criticCalls||state.checkedRevision!==state.revision||
       state.snapshotRevision!==state.revision||!finalImage())return false;
    await workspace.verifyAssets();
    let strip=null,stripEvidence=null;
    if(!context.lookOnly&&tools.strip){const captured=await bounded(()=>tools.strip({signal:boundedSignal}));strip=captured?.providerImage??null;stripEvidence=captured?.coverage??null;}
    state.criticCalls=(state.criticCalls??0)+1;
    state.pending={kind:'provider',purpose:'final_review',revision:state.revision};await save();
    progress('Taking a final look');
    const close=await closeLook();
    const verdict=await bounded(()=>tools.critic({sheet:{image:finalImage(),reference:!!state.lastSnapshot?.reference_row},strip,stripEvidence,...close,
      authorScores:state.scores??[],findings:'Final review before the authoring allowance ends.',round:state.criticCalls,signal:boundedSignal}));
    state.pending=null;state.critic=verdict;state.criticRevision=state.revision;
    state.reviewedRevision=verdict.verdict==='pass'?state.revision:-1;
    if(verdict.verdict==='revise')observeToolResult(state,{type:'critic'},{critic:verdict});
    state.status='preview_ready';
    state.internalNote=(verdict.verdict==='pass'?'Final creative review completed. ':'Draft, review incomplete: the critic requests changes. ')+criticLine(verdict);
    state.summary=verdict.verdict==='pass'?SAY.ready:SAY.best;
    await save();return true;
  };
  // When a repair leaves the same findings in place, say so plainly; the usual
  // cause is intentional layering (animated words, stacked cards) that must be
  // declared rather than rewritten again.
  const repeated=result=>{
    const errs=result?.diagnostics?.errors;if(!Array.isArray(errs)||!errs.length)return result;
    const key=JSON.stringify(errs.map(e=>[e.code,e.selector]).sort());
    const again=state.lastFindings===key;state.lastFindings=key;state.findingsRepeats=again?(state.findingsRepeats??1)+1:1;
    if(!again)return result;
    const overlap=errs.some(e=>/overlap|occlu/.test(e.code||''));
    // Layout findings that survive two repairs stop blocking: the review and the critic see the frames and judge them.
    const LAYOUT=/^(content_overlap|text_occluded|text_box_overflow)$/;
    if(state.findingsRepeats>=3&&errs.every(e=>LAYOUT.test(e.code||''))){
      state.layoutAdvisories=errs;
      return {...result,ok:true,diagnostics:{ok:true,advisory:errs,note:'These layout findings survived two repairs and no longer block: they are advisory now. Judge them in the frames; fix what the review shows is really unreadable.'}};
    }
    return {...result,diagnostics:{...result.diagnostics,repeated:true,note:'These exact findings survived your last repair. Do not rewrite the same code again.'+(overlap?' If the overlap is intended (per-word or per-letter animation, stacked layers), add data-layout-allow-overlap (or data-layout-allow-occlusion) to the containing element instead.':' Change approach or remove the element.')}};
  };
  // Words the user approved or wrote; numbers on screen must come from here.
  const allowedText=()=>[context.brief,...(context.messages||[]).filter(m=>m.role==='user').map(m=>m.content),...(context.approvedFacts||[]),
    ...(context.plan?.on_screen_copy||[]),...(context.plan?.narration||[]),context.settings?.caption_text||''].join('\n');
  // Audio clips must start and stop where their file is quiet: narration in a pause, music and effects faded.
  const audioEdgeCheck=async(rows,html)=>{
    if(!tools.media)return [];
    const asset=p=>workspace.assets.find(a=>a.path===p);
    const roots=new Set((context.planMedia||[]).filter(m=>['voiceover','cloned_voiceover'].includes(m.kind)&&m.file).map(m=>m.file));
    const isVoice=src=>{let a=src;for(let i=0;i<8&&a;i++){if(roots.has(a))return true;const x=asset(a);a=x?.derivedFrom??x?.origin??null;}return false;};
    const made=op=>new Set(workspace.assets.filter(a=>(Array.isArray(op)?op:[op]).includes(a.operation)).map(a=>a.path));
    const clips=audioEdges({rows,html,durations:state.durations||{},derived:made(['trim','cut','remove_silence','run']),faded:made('fade')}).filter(c=>asset(c.src));
    state.levels??={};state.pauses??={};
    const voices=new Set(clips.map(c=>c.src).filter(isVoice));
    for(const src of new Set(clips.map(c=>c.src))){
      const have=state.levels[src]??={};
      const at=[...new Set(clips.filter(c=>c.src===src).flatMap(c=>c.edges.map(e=>e.probe)))].filter(t=>!(t in have)).slice(0,60);
      if(at.length){const r=await bounded(()=>tools.media({op:'levels',input:src,params:{at},signal:boundedSignal})).catch(()=>null);for(const [t,db] of r?.levels||[])have[t]=db;for(const t of at)if(!(t in have))have[t]=null;}
      if(voices.has(src)&&!state.pauses[src]){const r=await bounded(()=>tools.media({op:'silences',input:src,params:{noise_db:-35,min_silence:.1},signal:boundedSignal})).catch(()=>null);state.pauses[src]=r?.silences||[];}
    }
    return audioEdgeFindings({clips,levels:state.levels,voices,pauses:state.pauses,transcripts:state.transcripts||{}});
  };
  // Timing rules the renderer cannot see: spoken cues and media shorter than its slot.
  const timing=async()=>{
    if(!tools.timeline)return {ok:true};
    const html=await workspace.read('index.html').catch(()=>'');
    // Every source file counts as using a bought file (a script or stylesheet may set it).
    let sources=html;for(const f of await workspace.sourceFiles().catch(()=>[]))if(f!=='index.html'&&!/^(gsap|wyv-|barty-)/.test(f))sources+='\n'+await workspace.read(f).catch(()=>'');
    if(!/<(video|audio)\b|data-spoken/.test(html)){const n=[...numberFindings(html,allowedText()),...(context.lookOnly?[]:clipUsageFindings({planMedia:context.planMedia||[],html:sources,rows:[],limitations:state.limitations||[]}))];return n.length?{ok:false,diagnostics:{ok:false,errors:n}}:{ok:true};}
    const tl=await bounded(()=>tools.timeline({signal:boundedSignal}));
    if(!tl?.ok)return {ok:true};
    const rows=rowsOf(tl.diagnostics);
    state.durations??={};
    for(const r of rows)if(['video','audio'].includes(r.kind)&&r.src&&state.durations[r.src]===undefined&&tools.media&&workspace.assets.some(a=>a.path===r.src)){
      const p=await bounded(()=>tools.media({op:'probe',input:r.src,params:{},signal:boundedSignal})).catch(()=>null);
      state.durations[r.src]=Number(p?.info?.duration)||null;
    }
    const errors=timingFindings({rows,html,durations:state.durations,transcripts:state.transcripts||{}});
    errors.push(...duckingFindings({rows,planMedia:context.planMedia||[]}));
    errors.push(...await audioEdgeCheck(rows,html));
    // Everything bought with a picture is in the video; talking clips play (nearly) in full. Not on the storyboard.
    if(!context.lookOnly){
      for(const r of rows)if(r.kind==='video'&&r.src&&state.durations[r.src]===undefined&&tools.media&&workspace.assets.some(a=>a.path===r.src)){
        const p=await bounded(()=>tools.media({op:'probe',input:r.src,params:{},signal:boundedSignal})).catch(()=>null);state.durations[r.src]=Number(p?.info?.duration)||null;
      }
      errors.push(...clipUsageFindings({planMedia:context.planMedia||[],html:sources,rows,durations:state.durations||{},limitations:state.limitations||[]}));
    }
    // Beats start when their words are said (plan.scenes[].starts_on), measured on the narration as placed.
    if(!context.lookOnly&&context.plan?.scenes?.some(sc=>sc.starts_on)){
      const asset=p=>workspace.assets.find(a=>a.path===p);
      const roots=new Set((context.planMedia||[]).filter(m=>['voiceover','cloned_voiceover'].includes(m.kind)&&m.file).map(m=>m.file));
      const isVoice=src=>{let a=src;for(let i=0;i<8&&a;i++){if(roots.has(a))return true;const x=asset(a);a=x?.derivedFrom??x?.origin??null;}return false;};
      const voiceFiles=new Set(rows.filter(r=>r.kind==='audio'&&r.src&&isVoice(r.src)).map(r=>r.src));
      errors.push(...beatFindings({rows,html,scenes:context.plan.scenes,voiceFiles,transcripts:state.transcripts||{},videoSeconds:Number(context.settings?.duration_seconds)||null}));
    }
    errors.push(...numberFindings(html,allowedText()));
    return errors.length?{ok:false,diagnostics:{ok:false,errors}}:{ok:true};
  };
  // A plain label for an action, for the activity line.
  // What the user sees while the build works: plain and calm, never a file, tool or command name.
  const describe=a=>({read:'Looking over the work',write:'Shaping the scenes',patch:'Refining the scenes',check:'Checking the details',preview:'Checking how it looks',snapshot:'Checking how it looks',
    report_limitation:'Making a note',timeline:'Checking the timing',primitives:'Getting organised',assets:'Getting organised',visual_review:'Reviewing the frames',finish:'Wrapping up',needs_input:'Preparing a question for you',propose_media:'Suggesting extra media',
    inspect_reference:'Studying your reference',catalog:'Choosing design pieces',media:'Preparing your media',transcript:'Listening to the narration',buy:'Getting media for your video',run:'Preparing your media'})[a.type]||'Working on your video';
  // One action against the draft and the sandbox; shared by the JSON protocol and tool mode.
  const MISUSE=/requires current host-provided snapshot|Check the current draft before snapshots|Visual review is required|requires check and snapshots|not installed/;
  const executeAction=async(action,reviewImage)=>{
    let result;
      if(action.type==='read')result={text:(action.path.startsWith('skills/')||action.path.startsWith('references/')||action.path.startsWith('style-example/')||action.path==='kit/motion-kit.md'||action.path==='kit/reference-moves.html'||action.path==='kit/registry.md'||action.path==='kit/barty.md'||action.path==='kit/mascot.md'||action.path==='kit/remotion.md'||action.path.startsWith('cards/'))&&tools.guidance?await tools.guidance(action.path):await workspace.read(action.path)};
    else if(action.type==='report_limitation') {
      const item=recordLimitation(state,{...action,source:'agent_report',code:action.category});
      result={recorded:item.recorded!==false,id:item.id,assessment:item.assessment,authorization_changed:false,
        next:'This report is for review. Continue useful work with existing tools and approvals; disclose any unmet requirement in your result.'};
    }
    else if(action.type==='inspect_reference') {
      if(!tools.inspect_reference)result={error:'Reference inspection is not installed'};
      else if(!(context.assets??[]).some(f=>f.name===action.input&&f.purpose==='reference'&&['video','image'].includes(f.asset_type)))result={error:'Inspection requires a reference image or video from context.assets'};
      else if((state.referenceInspections??0)>=cap.inspections)result={error:'Reference inspection limit reached ('+cap.inspections+' per run); use the evidence already collected'};
      else {
        state.referenceInspections=(state.referenceInspections??0)+1;
        // Which reference times were looked at, for copying exactly (every moment must be seen before it is built).
        {const p=action.params||{};const ts=Array.isArray(p.times)?p.times:Number.isFinite(p.start)&&Number.isFinite(p.end)?[p.start,(p.start+p.end)/2,p.end]:[];
         state.inspectedTimes=[...(state.inspectedTimes||[]),...ts.map(Number).filter(Number.isFinite)].slice(-400);}
        result=await bounded(()=>tools.inspect_reference({input:action.input,params:action.params,signal:boundedSignal}));
        const {providerImage,...metadata}=result;
        // A reference image must never satisfy the rendered-draft review gate.
        state.inspectionImage=result.ok?providerImage:null;
        state.inspectionEvidence=result.ok?metadata:null;
        if(result.ok)state.inspectionHistory=[...(state.inspectionHistory??[]),metadata].slice(-8);
        result=metadata;
      }
    }
    else if(action.type==='catalog') {
      if(!tools.catalog)throw Error('The registry catalogue is not available in this run');
      result=await tools.catalog({query:action.query});
    }
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
        // Pre-flight: fix what has one right answer, name the rest, before a check call is spent.
        const flight=action.path.startsWith('work/')?{text,fixed:[],warnings:[]}:preflight({path:action.path,text,assets:workspace.assets.map(a=>a.path),audible:(context.planMedia||[]).filter(m=>['talking_shot','talking_take'].includes(m.kind)&&m.file).map(m=>m.file)});
        text=flight.text;
        await workspace.write(action.path,text);
        if(action.path.startsWith('work/'))result={written:action.path,note:'Scratch file; run it with the run action.'};
        else {state.bundleHash=await workspace.fingerprint();state.revision++;result={revision:state.revision,...(flight.fixed.length?{fixed:flight.fixed}:{}),...(flight.warnings.length?{warnings:flight.warnings}:{})};}
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
    else if(action.type==='run') {
      if(!tools.run)throw Error('The run tool is not available in this run');
      if((state.runs=(state.runs??0)+1)>cap.runs)result={ok:false,error:'Run limit reached for this build ('+cap.runs+'); finish with what you have.'};
      else {
        result=await bounded(()=>tools.run({cmd:action.cmd,args:action.args,signal:boundedSignal}));
        // New files join the protected assets with how they were made; the first project file named in args is their origin.
        const origin=action.args.map(a=>a.replace(/^project\//,'')).find(a=>workspace.assets.some(x=>x.path===a))??null;
        for(const f of result?.outputs??[])if(!workspace.assets.some(a=>a.path===f.path))workspace.assets.push({path:f.path,sha256:f.sha256,operation:'run',origin,params:{cmd:action.cmd,args:action.args.join(' ').slice(0,1500)}});
        if(!result?.ok&&++state.repairs>cap.repairs)throw Error('Run repair limit reached');
      }
    }
    else if(action.type==='buy') {
      if(!tools.buy)throw Error('Purchases are not available in this run');
      result=await bounded(()=>tools.buy({kind:action.kind,description:action.description,requirement_ids:action.requirement_ids??[],signal:boundedSignal}));
      // Bought files join the protected assets and the plan media the checks know about.
      if(result?.ok){for(const f of result.files||[])if(!workspace.assets.some(a=>a.path===f.path))workspace.assets.push({path:f.path,sha256:f.sha256});(context.planMedia??=[]).push({task_id:result.task_id??null,requirement_ids:result.requirement_ids??[],kind:action.kind,description:action.description,status:'succeeded',file:result.files?.[0]?.path,charged_credits:result.charged_credits});}
      else if(++state.repairs>cap.repairs)throw Error('Purchase repair limit reached');
    }
    else if(action.type==='preview') {
      result=await bounded(()=>tools.check({signal:boundedSignal}));
      if(result.ok){const t=await timing();if(!t.ok)result=t;}
      if(!result.ok)result=repeated(result);else {state.lastFindings=null;state.layoutAdvisories=[];}
      if(result.ok){state.checkedRevision=state.revision;const pacing=await softFindings(result.pacing);state.pacing={revision:state.revision,findings:pacing};result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;state.lastSnapshot={reference_row:!!result.reference_row};await keepGood();result={...result,providerImage:undefined,...(pacing?.length?{pacing}:{})};}}
      else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
    }
    else if(action.type==='check') {
      result=await bounded(()=>tools.check({signal:boundedSignal}));
      if(result.ok){const t=await timing();if(!t.ok)result=t;}
      if(!result.ok)result=repeated(result);else {state.lastFindings=null;state.layoutAdvisories=[];}
      if(result.ok){state.checkedRevision=state.revision;result={...result,pacing:await softFindings(result.pacing)};state.pacing={revision:state.revision,findings:result.pacing};}
      else {state.checkedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Composition repair limit reached');}
    } else if(action.type==='snapshot') {
      if(state.checkedRevision!==state.revision)throw Error('Check the current draft before snapshots');
      result=await bounded(()=>tools.snapshot({times:action.times,signal:boundedSignal}));
      if(result.ok){state.snapshotRevision=state.revision;state.reviewImage=result.providerImage;state.lastSnapshot={reference_row:!!result.reference_row};await keepGood();result={...result,providerImage:undefined};}
    } else if(action.type==='visual_review') {
      if(!reviewImage || state.snapshotRevision!==state.revision)throw Error('Visual review requires current host-provided snapshot');
      // The author has seen these frames; the host's final review may still need them for the same revision.
      state.reviewedImage=state.reviewImage;state.reviewedImageRevision=state.snapshotRevision;state.reviewImage=null;
      state.scores=action.scores.map(x=>({time:x.time,score:x.score,problems:x.problems.slice(0,3)}));
      const scoreLine=' Review scores: '+state.scores.map(x=>x.time+'s '+x.score).join(', ')+'.';
      if(action.decision==='pass'){
        state.reviewedRevision=state.revision;
        // The critic has the last word: a separate reviewer with the frames and, for a motion build, the strip across the video.
        let verdict=null;
        if(requireVisualReview&&tools.critic&&(state.criticCalls??0)<cap.criticCalls){
          state.criticCalls=(state.criticCalls??0)+1;progress('Taking a second look');
          let strip=null,stripEvidence=null;if(!context.lookOnly&&tools.strip){const captured=await bounded(()=>tools.strip({signal:boundedSignal})).catch(()=>null);strip=captured?.providerImage??null;stripEvidence=captured?.coverage??null;}
          const close=await closeLook();
          verdict=await bounded(()=>tools.critic({sheet:{image:reviewImage,reference:!!state.lastSnapshot?.reference_row},strip,stripEvidence,...close,authorScores:state.scores,findings:action.findings,round:state.criticCalls,signal:boundedSignal}));
          state.critic=verdict;state.criticRevision=state.revision;
        }
        // Convergence: two critic rounds in a row without the mean improving by 0.3 (an unreadable reply counts as no
        // improvement) end the build with this draft and the critic's open notes, however many calls remain.
        let stalled=false;
        if(verdict&&verdict.verdict==='revise'){
          const mean=verdict.ok&&Number.isFinite(verdict.mean)?verdict.mean:null;
          const improved=mean!==null&&(state.criticBest==null||mean>=state.criticBest+0.3);
          if(mean!==null&&(state.criticBest==null||mean>state.criticBest))state.criticBest=mean;
          state.criticStall=improved?0:(state.criticStall??0)+1;
          stalled=state.criticStall>=(state.criticBest==null?3:2);
        }
        if(verdict&&verdict.verdict==='revise'&&stalled){
          state.reviewedRevision=-1;state.status='preview_ready';
          state.internalNote=('The review stopped improving after '+state.criticCalls+' rounds. '+criticLine(verdict)+' Open notes: '+(verdict.directives||[]).filter(d=>!/^Unmet requirement/.test(d)).slice(0,3).join(' ')).slice(0,1900);
          state.summary=SAY.best;
          observeStop(state,'Review stopped improving');
          result={decision:'pass',scores:state.scores,critic:{scores:verdict.scores,directives:verdict.directives,note:verdict.note},next:'Delivered: the review stopped improving.'};
        } else if(verdict&&verdict.verdict==='revise'){
          state.reviewedRevision=-1;
          result={decision:'pass',scores:state.scores,critic:{scores:verdict.scores,directives:verdict.directives,note:verdict.note},next:'The critic asks for changes before this can finish: address each directive with patches, then preview and review again.'+((state.criticCalls??0)>=cap.criticCalls?' This was the last critic round; the next passing review finishes.':'')};
        } else if(requireVisualReview){state.status='preview_ready';state.internalNote=(action.findings+scoreLine+(verdict?' '+criticLine(verdict):state.critic?' '+criticLine(state.critic)+' Last directives not all confirmed.':'')).slice(0,1900);state.summary=verdict?.verdict==='pass'?SAY.ready:SAY.best;}
      }
      else {state.reviewedRevision=-1;if(++state.repairs>cap.repairs)throw Error('Visual repair limit reached');}
      if(!result)result={decision:action.decision,findings:action.findings,scores:state.scores,...(action.decision==='repair'?{next:'Fix the lowest-scoring frames first: '+state.scores.filter(x=>x.score<8).sort((a,b)=>a.score-b.score).flatMap(x=>x.problems).slice(0,3).join('; ')}:{})};
    } else if(action.type==='finish') {
      if(requireVisualReview && state.reviewedRevision!==state.revision)throw Error('Visual review is required');
      if(state.checkedRevision!==state.revision||state.snapshotRevision!==state.revision)throw Error('Current draft requires check and snapshots');
      // Open pacing errors on this draft (stillness, small text, empty frames, reading time, blank frames) are sent back once:
      // fix them, or finish again with a summary that says why each one is intentional.
      const open=state.pacing?.revision===state.revision?(state.pacing.findings||[]).filter(f=>f.severity==='error'):[];
      const key=state.revision+':'+open.map(f=>f.code+'@'+f.time).join(',');
      if(open.length&&state.pacingNoticed!==key){
        state.pacingNoticed=key;
        result={ok:false,error:'Not finished: this draft still has these findings. Fix them and check again, or finish again with a summary that says why each one is intentional.',findings:open.map(f=>({code:f.code,time:f.time,message:f.message,fixHint:f.fixHint}))};
      } else {
        if(open.length)state.pacingAccepted=open.map(f=>f.code);
        state.status='preview_ready';state.summary=action.summary;
      }
    } else if(action.type==='needs_input') {
      recordLimitation(state,{source:'runtime',category:'input',code:'clarification_requested',tool:'needs_input',summary:'The agent requested clarification',evidence:action.question,impact:'The run needs user input or must disclose unfinished work.',workaround:'Clarify the missing requirement; assess whether an existing asset or reasonable default was overlooked.',requested_change:''});
      // On the last call, a draft that passed every check is delivered with the open issues, not held back.
      if(state.calls>=cap.calls-1&&state.checkedRevision===state.revision&&state.snapshotRevision===state.revision&&state.revision>0){
        state.recoveredDraft=true;state.status='preview_ready';state.internalNote=('Draft, review incomplete. Call limit reached; open issues: '+action.question).slice(0,1900);state.summary=SAY.best;
      } else if(state.calls>=cap.calls-1&&state.lastGood){await deliverGood('The call limit was reached during a repair; open issues from the last review: '+action.question);}
      else {state.status='needs_input';state.question=action.question;}
    }
    else if(action.type==='propose_media') {state.status='awaiting_media_approval';state.proposal=action.description;recordLimitation(state,{source:'runtime',category:'budget',code:'media_approval_requested',tool:'propose_media',summary:'Additional media approval requested',evidence:'The agent paused with a media proposal.',impact:'New media cannot be purchased without approval.',workaround:'Reuse approved assets where suitable or obtain approval for a revised quote.',requested_change:''});}
    recordCapability(state,action,result);
    if(action.type!=='report_limitation')observeToolResult(state,action,result);
    await workspace.verifyAssets();boundedSignal.throwIfAborted();
    if(result && action.type!=='read' && Buffer.byteLength(JSON.stringify(result))>cap.resultBytes)result={truncated:true,summary:JSON.stringify(result).slice(0,Math.floor(cap.resultBytes*0.75))};
    return result??{status:state.status};
  };
  // Trace records describe observable actions; reporting cannot authorize or stop generation.
  const trace=async event=>{try{await onTrace(event);}catch{state.trajectory_write_failed=true;}};
  const dispatch=async(action,reviewImage)=>{
    const started=Date.now();
    await trace(actionEvent(action,null,{status:'started',call:state.calls,revision:state.revision}));
    try {
      const result=await executeAction(action,reviewImage);
      const failed=!!result?.error||result?.ok===false||result?.critic?.verdict==='revise'||!!result?.critic?.directives?.length;
      await trace(actionEvent(action,result,{status:failed?'failed':'succeeded',call:state.calls,revision:state.revision,durationMs:Date.now()-started}));
      return result;
    } catch(e) {
      await trace(actionEvent(action,{error:e.message},{status:'failed',call:state.calls,revision:state.revision,durationMs:Date.now()-started}));
      throw e;
    }
  };
  // Tool mode: the conversation is a real message history; a turn may carry several tool calls.
  const toolMode=context.toolMode===true;
  const turns=()=>{state.turns??=[{role:'user',content:[...(initialImage&&/^data:image\/(png|jpeg);base64,/.test(initialImage)?[{type:'image',source:{type:'base64',media_type:initialImage.startsWith('data:image/png')?'image/png':'image/jpeg',data:initialImage.split(',')[1]}}]:[]),{type:'text',text:JSON.stringify({context}),cache_control:{type:'ephemeral'}}]}];return state.turns;};
  // Keep the history inside the context budget: only the latest frames stay as an image, old tool results shrink.
  const compactTurns=()=>{
    const t=turns();let lastImage=-1;
    t.forEach((m,k)=>{if(m.role==='user'&&k>0)for(const b of m.content)if(b.type==='tool_result'&&Array.isArray(b.content)&&b.content.some(x=>x.type==='image'))lastImage=k;});
    t.forEach((m,k)=>{if(m.role==='user'&&k>0&&k!==lastImage)for(const b of m.content)if(b.type==='tool_result'&&Array.isArray(b.content))b.content=b.content.map(x=>x.type==='image'?{type:'text',text:'[earlier frames omitted]'}:x);});
    // A file written in an earlier turn is on disk: its text leaves the history (the model reads it back if it needs it).
    // Measured as text: the one kept image is sent as an image, so its base64 does not count against the text budget.
    const over=()=>Buffer.byteLength(JSON.stringify(t,(k,v)=>k==='data'&&typeof v==='string'&&v.length>512?'[image]':v))+Buffer.byteLength(toolHostPolicy+skills)>cap.contextBytes;
    const lastAssistant=t.map(m=>m.role).lastIndexOf('assistant');
    const shrinkWrites=(m)=>{for(const b of m.content)if(b.type==='tool_use'&&b.input&&typeof b.input==='object'){
      if(b.name==='write'&&typeof b.input.content==='string'&&b.input.content.length>200)b.input={path:b.input.path,content:'[written earlier, '+Buffer.byteLength(b.input.content)+' bytes; read the file to see it]'};
      if(b.name==='patch'&&(String(b.input.before).length+String(b.input.after).length)>300)b.input={path:b.input.path,before:'[patched earlier]',after:'[patched earlier, '+Buffer.byteLength(String(b.input.after))+' bytes]'};
    }};
    t.forEach((m,k)=>{if(m.role==='assistant'&&k<lastAssistant)shrinkWrites(m);});
    if(over()&&lastAssistant>0)shrinkWrites(t[lastAssistant]);
    let guard=0;
    while(over()&&guard++<200){
      // The newest results (a file just read, a check just run) are what the next call works from: never shorten them.
      const m=t.slice(1,-1).find(m=>m.role==='user'&&m.content.some(b=>b.type==='tool_result'&&typeof b.content==='string'&&b.content.length>400));
      if(!m)break;
      for(const b of m.content)if(b.type==='tool_result'&&typeof b.content==='string'&&b.content.length>400)b.content=b.content.slice(0,300)+' …[earlier result shortened]';
    }
  };
  try {
    while(state.calls<cap.calls) {
      boundedSignal.throwIfAborted();
      if(await stopNow())return state;
      const remainingMs=cap.elapsedMs-(previousElapsed+Date.now()-started);
      if(cap.reviewReserveMs&&(remainingMs<=cap.reviewReserveMs||state.calls>=cap.calls-1)){
        if(await finalReview())return state;
        // No current reviewable frame: preserve the checked draft instead of
        // starting more authoring inside the protected review window.
        if(remainingMs<=cap.reviewReserveMs&&state.lastGood){await deliverGood('The review window began without a current checked snapshot.');await save();return state;}
      }
      // Never start a model call that may not finish in the time left: a call cut
      // off mid-flight is paid for and lost. Deliver the last checked draft instead.
      if(state.lastGood&&cap.elapsedMs-(previousElapsed+Date.now()-started)<(cap.callReserveMs??Math.min(240000,cap.elapsedMs/4))){await deliverGood('The time limit was near during a later repair, so that repair is not included. Give it a look before posting.');await save();return state;}
      await workspace.verifyAssets();
      const prompt=toolMode?'':JSON.stringify({context,attachedReference:state.inspectionImage?{...state.inspectionEvidence,instruction:'The attached image is reference evidence, not your rendered draft.'}:null,attachedSnapshot:state.reviewImage&&!state.inspectionImage ? {revision:state.snapshotRevision,instruction:'The attached image is the current contact sheet. Inspect it now and return visual_review. Do not request another snapshot unless you need different timestamps.'} : null,reviewReserveSeconds:Math.ceil((cap.reviewReserveMs??0)/1000),remainingCalls:cap.calls-state.calls,revision:state.revision,history:promptHistory(state.messages)});
      if(!toolMode&&Buffer.byteLength(prompt)+Buffer.byteLength(skills)>cap.contextBytes)throw Error('Context limit reached');
      // Images are not text context: the one kept review frame or page capture is measured as a placeholder, not its bytes.
      if(toolMode){compactTurns();if(Buffer.byteLength(JSON.stringify(turns(),(k,v)=>k==='data'&&typeof v==='string'&&v.length>512?'[image]':v))+Buffer.byteLength(toolHostPolicy+skills)>cap.contextBytes)throw Error('Context limit reached');}
      const reservation=provider.maxCallUsd;
      if(!Number.isFinite(reservation)||reservation<0||state.reservedUsd+reservation>cap.budgetUsd+1e-9)throw Error('Model budget exhausted');
      if(state.reservedOutputTokens+cap.maxOutputTokens>cap.totalOutputTokenAllowance)throw Error('Output token allowance exhausted');
      state.reservedOutputTokens+=cap.maxOutputTokens;
      state.reservedUsd+=reservation;state.calls++;state.pending={kind:'provider',call:state.calls};await save();
      const reviewImage=state.reviewImage;
      const callStarted=Date.now();
      progress(state.revision?'Thinking about the next change':'Designing the first draft');
      if(toolMode){
        compactTurns();
        const history=turns();
        // The last user turn carries the call budget so the model paces itself.
        for(const m of history)if(m.role==='user')m.content=m.content.filter(b=>!(b.type==='text'&&/^Remaining calls:/.test(b.text||'')));
        const last=history.at(-1);if(last.role==='user')last.content.push({type:'text',text:'Remaining calls: '+(cap.calls-state.calls)+'. Revision: '+state.revision+'. The final '+Math.ceil((cap.reviewReserveMs??0)/1000)+' seconds are reserved for review; prepare a checked preview before then.'});
        const response=await bounded(()=>provider.complete({prompt:'tool-mode call '+state.calls,system:toolHostPolicy+'\nPinned guidance:\n'+skills,maxTokens:cap.maxOutputTokens,messages:structuredClone(history),tools:toolDefinitions,signal:boundedSignal,onPrediction:async id=>{if(state.pending){state.pending.predictionId=id;await save();}}}));
        boundedSignal.throwIfAborted();
        state.usage??=[];state.usage.push({call:state.calls,predictionId:response.predictionId,promptBytes:Buffer.byteLength(JSON.stringify(history)),systemBytes:Buffer.byteLength(toolHostPolicy+skills),elapsedMs:Date.now()-callStarted,metrics:response.metrics,costUsd:Number(response.actualCostUsd)||0});
        const content=(Array.isArray(response.content)&&response.content.length?response.content:[{type:'text',text:response.text||''}]).map(b=>b.type==='tool_use'&&(!b.input||typeof b.input!=='object'||Array.isArray(b.input))?{...b,input:{}}:b);
        state.pending=null;history.push({role:'assistant',content});state.messages.push({role:'assistant',content:JSON.stringify(content.map(b=>b.type==='tool_use'?{tool:b.name,input:b.input}:{text:(b.text||'').slice(0,400)}))});await save();
        const uses=content.filter(b=>b.type==='tool_use').slice(0,cap.usesPerTurn);
        if(!uses.length){
          if(++state.repairs>cap.repairs)throw Error('Action repair limit reached');
          const cut=response.stopReason==='max_tokens';
          history.push({role:'user',content:[{type:'text',text:cut?'Your reply was cut off by the output limit (thinking counts toward it). Call the tools with smaller writes: index.html, then style.css and main.js separately, each under 6,000 characters.':'Use the tools; a reply without a tool call does nothing.'}]});await save();continue;
        }
        const results=[];
        for(const u of uses){
          let action,result;
          try{action=actionFromToolUse(u);}catch(e){
            if(++state.repairs>cap.repairs)throw Error('Action repair limit reached');
            await trace(actionEvent({type:'invalid_action'},{error:e.message},{status:'failed',call:state.calls,revision:state.revision}));
            observeToolResult(state,{type:'invalid_action'},{error:e.message});
            results.push({type:'tool_result',tool_use_id:u.id,is_error:true,content:JSON.stringify({error:e.message})});continue;
          }
          state.pending={kind:'tool',action};await save();progress(describe(action));
          const before=state.reviewImage;
          try{result=await dispatch(action,reviewImage);}
          catch(e){
            if(!MISUSE.test(String(e.message))||boundedSignal.aborted)throw e;
            if(++state.repairs>cap.repairs)throw Error('Action repair limit reached');
            result={error:e.message};observeToolResult(state,action,result);
          }
          state.pending=null;state.messages.push({role:'tool',content:result});await save();
          // New frames travel back as an image in the tool result, for the next turn's review.
          const candidate=action.type==='inspect_reference'?state.inspectionImage:state.reviewImage&&state.reviewImage!==before?state.reviewImage:null;
          const img=/^data:image\/(png|jpeg);base64,/.test(candidate??'')?candidate:null;
          state.inspectionImage=null;
          results.push({type:'tool_result',tool_use_id:u.id,...(result?.error?{is_error:true}:{}),content:img?[{type:'text',text:JSON.stringify(result)},{type:'image',source:{type:'base64',media_type:img.startsWith('data:image/png')?'image/png':'image/jpeg',data:img.split(',')[1]}}]:JSON.stringify(result)});
          if(state.status!=='running')break;
          // Stop: finish this step, skip the rest of the turn; the loop top keeps the last checked version.
          if(stopRequested())break;
        }
        history.push({role:'user',content:results});await save();
        if(state.status!=='running')return state;
        continue;
      }
      const response=await bounded(()=>provider.complete({prompt,system:hostPolicy+'\nPinned guidance:\n'+skills,maxTokens:cap.maxOutputTokens,image:state.inspectionImage||reviewImage||initialImage,signal:boundedSignal,onPrediction:async id=>{if(state.pending){state.pending.predictionId=id;await save();}}}));
      state.inspectionImage=null;
      boundedSignal.throwIfAborted();
      state.usage??=[];state.usage.push({call:state.calls,predictionId:response.predictionId,promptBytes:Buffer.byteLength(prompt),systemBytes:Buffer.byteLength(hostPolicy+skills),elapsedMs:Date.now()-callStarted,metrics:response.metrics,costUsd:Number(response.actualCostUsd)||0});
      // Persist returned output before dispatch. Never repeat an uncertain paid create.
      state.pending=null;state.messages.push({role:'assistant',content:response.text});await save();
      let action;
      try {action=parseAction(response.text);} catch(e) {
        if(++state.repairs>cap.repairs)throw Error('Action repair limit reached');
        const cut=response.stopReason==='max_tokens'||/Unterminated|Unexpected end/i.test(e.message);
        await trace(actionEvent({type:'invalid_action'},{error:e.message},{status:'failed',call:state.calls,revision:state.revision}));
            observeToolResult(state,{type:'invalid_action'},{error:e.message});
        state.messages.push({role:'tool',content:{error:e.message,...(cut?{hint:'Your reply was cut off by the output limit (thinking counts toward it). Split the work into smaller writes: index.html with markup only, then style.css and main.js as separate write actions, each under 6,000 characters, linked from index.html.'}:{})}});await save();continue;
      }
      state.pending={kind:'tool',action};await save();progress(describe(action));
      const result=await dispatch(action,reviewImage);
      state.pending=null;state.messages.push({role:'tool',content:result});await save();
      if(state.status!=='running')return state;
      if(await stopNow())return state;
    }
    if(await finalReview())return state;
    observeStop(state,'Model call limit reached');
    // Out of calls right after a draft passed every check and was snapshotted:
    // deliver it rather than discard a valid video, and say review was skipped.
    if(state.checkedRevision===state.revision&&state.snapshotRevision===state.revision&&state.revision>0&&!state.pending){
      state.status='preview_ready';
      state.recoveredDraft=true;
      state.internalNote='Draft, review incomplete. The call limit was reached before the final visual review.';state.summary=SAY.best;
      await save();return state;
    }
    if(state.lastGood&&!state.pending){await deliverGood('The call limit was reached during a later repair, so that repair is not included. Give it a look before posting.');await save();return state;}
    throw Error('Model call limit reached');
  } catch(e) {
    if(e.code==='NOT_STARTED'||e.code==='NOT_SENT')state.pending=null;
    // Stop pressed while waiting out a busy model: nothing was sent, so keep the last checked version.
    if(e.code==='NOT_SENT'&&!signal?.aborted&&stopRequested())try{if(await stopNow())return state;}catch{/* fall through */}
    // Out of time (not cancelled by the user) with no paid call in doubt: deliver the last checked draft.
    // Running out of something (time, calls, context, output, repairs, the model itself), with no paid
    // call in doubt and not the user's own cancel, delivers the last checked draft. Rule breaks still fail.
    const exhausted=timeout.aborted||e.code==='NOT_SENT'||/^(Context limit reached|Model call limit reached|Output token allowance exhausted|Model budget exhausted|Composition repair limit reached|Action repair limit reached|Visual repair limit reached)$/.test(e.message);
    if(exhausted&&!signal?.aborted&&state.lastGood&&state.pending?.kind!=='provider'){
      const why=e.code==='NOT_SENT'?'The model was unavailable':timeout.aborted?'The time limit was reached':'The build stopped ('+String(e.message).slice(0,80)+')';
      state.pending=null;try{await deliverGood(why+' during a later repair, so that repair is not included. Give it a look before posting.');observeStop(state,why);await save();return state;}catch{/* fall through to the failure below */}
    }
    state.status=state.pending?.kind==='provider'?'needs_attention':boundedSignal.aborted?'cancelled':'failed';
    if(e.code==='BUDGET_EXHAUSTED'){state.pending=null;state.status='budget_exhausted';state.reason=e.message;observeStop(state,state.reason);await save();return state;}
    state.failureDetail=e.message;
    state.reason=state.pending?.kind==='provider'?'Provider outcome needs reconciliation; do not resubmit automatically':e.message;
    observeStop(state,state.reason);
    await save();return state;
  }
}
