// CHECK-GUARD (2026-10-16): our own checks can be wrong. In 30 days four builds stopped with nothing delivered and the
// user charged while the builder obeyed a check that was mistaken (beat markers without ids, a speech cue, layout). A
// finding of ours that survives two real repairs (the same finding seen at three different revisions) is disputed: it
// stops blocking, the user is told plainly what could not be confirmed, and the API records it for us to review. A
// check we then mark wrong refunds the builds it stopped.
//
// HyperFrames' own render checks are never disputed (if they are wrong the render fails anyway), and neither is a
// number nobody approved: removing or rewording a number is always possible, and an invented figure in an ad is worse
// than a stop.

export const DISPUTABLE=new Set(['weak_opening','text_repeats_narration','text_overlap','talking_clip_cut_short','still_stretch',
 'spoken_phrase_not_found','spoken_cue_without_transcript','spoken_cue_off_time','small_text','slow_drift','slideshow','repeated_move',
 'reference_move_unverified','reference_move_not_run','reference_move_missing','reference_move_kit_missing','reference_moment_not_inspected',
 'reference_element_unmarked','reference_element_off_slot','reference_element_missing','reading_time','pacing_check_failed','music_not_ducked',
 'mostly_empty','media_shorter_than_slot','lopsided','loop_not_set','edge_margin','constant_speed','bought_media_unused','blank_frames',
 'beats_unmarked','beat_off_voice','ambience_too_loud']);

// The same finding is the same check on the same element; its wording may carry times that move with each repair.
export const findingKey=e=>String(e?.code||'')+'|'+String(e?.selector||'');

/**
 * Splits a failed check's findings. Returns {block, disputed, advisory}: block still stops the preview; disputed are the
 * findings newly disputed now; advisory are all findings that no longer block (disputed now or earlier). Mutates
 * state.findingSeen (key -> distinct revisions it was seen at) and state.disputes (what the user and the API are told).
 */
export function guardFindings(state,errs,revision){
 state.findingSeen??={};state.findingMsg??={};state.disputes??=[];
 const earlier=new Set(state.disputes.map(findingKey));
 const block=[],disputed=[],advisory=[];
 for(const e of errs){
  const k=findingKey(e);
  if(earlier.has(k)){advisory.push(e);continue;}
  const seen=state.findingSeen[k]??=[];state.findingMsg[k]=String(e.message||e.code).slice(0,300);
  if(seen[seen.length-1]!==revision)seen.push(revision);
  if(seen.length>5)seen.splice(0,seen.length-5);
  if(DISPUTABLE.has(e.code)&&seen.length>=3){
   const d={code:e.code,selector:e.selector??null,message:String(e.message||e.code).slice(0,300),revision,seen_at:[...seen]};
   state.disputes.push(d);disputed.push(d);advisory.push(e);
  }
  else block.push(e);
 }
 return {block,disputed,advisory};
}

/** A failed preview or check after the guard: unchanged, narrowed to what still blocks, or passed with advisories. */
export function guardResult(state,result,revision){
 const errs=result?.diagnostics?.errors;
 if(result?.ok||!Array.isArray(errs)||!errs.length)return result;
 const {block,disputed,advisory}=guardFindings(state,errs,revision);
 if(!advisory.length)return result;
 const note=disputed.length?'These findings survived two of your repairs, so they are now disputed: they no longer block, the user is told they could not be confirmed, and the check is reviewed. Do not keep rewriting for them; carry on with the video.':'Findings disputed earlier no longer block.';
 if(!block.length)return {...result,ok:true,diagnostics:{ok:true,advisory,disputed,note}};
 return {...result,diagnostics:{...result.diagnostics,errors:block,advisory,disputed,note}};
}

/** For the version note: one plain line, or ''. */
export function disputeLine(disputes){
 const list=(disputes||[]).map(d=>String(d.message||d.code).replace(/\.$/,''));
 if(!list.length)return '';
 const shown=list.slice(0,2).join('; ')+(list.length>2?`; and ${list.length-2} more`:'');
 return `Not confirmed: ${shown}. One of our checks kept flagging this after two fixes, so the video was finished rather than stopped; give that part a look before posting. We review every check like this.`;
}

/** For a run that failed: the findings that kept repeating, so the API can record them for review (and refund). */
export function repeatingFindings(state){
 const out=[];
 for(const [k,seen] of Object.entries(state?.findingSeen||{}))if(seen.length>=2){const [code,selector]=k.split('|');out.push({code,selector:selector||null,seen_at:seen,message:state.findingMsg?.[k]??null});}
 for(const d of state?.disputes||[])if(!out.some(x=>x.code===d.code&&x.selector===d.selector))out.push({code:d.code,selector:d.selector,seen_at:d.seen_at,message:d.message});
 return out.slice(0,8);
}
