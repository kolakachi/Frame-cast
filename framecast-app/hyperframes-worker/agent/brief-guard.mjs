// Structured host facts, never extracted by trusting instructions inside uploads.
export function briefGate(context) {
  if(context.productIdentityConflict && !context.separateProductsApproved)
    return {status:'needs_input',question:'The presenter footage and product photo show different products. Should I keep them separate, or would you like to supply matching footage?'};
  if(context.requestedClaims?.some(claim=>!context.approvedFacts?.includes(claim)))
    return {status:'needs_input',question:'Please confirm evidence for the requested claims, or let me use only the approved product information.'};
  if(context.requestedNewSpeech)
    return {status:'awaiting_media_approval',proposal:'New spoken audio requires a separate approved voice-generation operation. The original speech will remain unchanged.'};
  if(context.sourceDuration!=null && context.duration>context.sourceDuration && !context.shortFootagePolicy)
    return {status:'needs_input',question:'The source is shorter than the requested video. Should I shorten the video or hold the final frame? I will not repeat it automatically.'};
  return null;
}
export function assertLockedSource(context,text) {
  for(const fragment of context.lockedSourceFragments??[]) {
    if(!fragment || text.split(fragment).length !== 2)throw Object.assign(Error('Keep each locked source region exactly once and unchanged. Read the current draft and edit only unlocked layout or copy.'),{code:'AUTHORING_REJECTED'});
  }
}
