/** The version note for a free change: what was swapped in, without the builder (a free edit, or a PartSwap with photos). */
export function swapSummary(input={},checks=null){
 const fields=Object.keys(input.edit_values??{}).length,photos=(input.part_swap?.pictures??[]).length;
 const warn=input.part_swap?swapOverflow(input,checks):[];
 const note=warn.length?' One thing to check: new words run past the edge of the frame at '+[...new Set(warn.map(e=>Number(e.time).toFixed(1)+' s'))].slice(0,3).join(', ')+'. Ask to shorten them, or to fit them to the frame.':'';
 if(!photos&&!input.part_swap)return 'Updated '+fields+' field(s). Free: no model call, one render.';
 const parts=[fields?fields+(fields===1?' text or colour':' texts and colours'):null,photos?photos+(photos===1?' photo':' photos'):null].filter(Boolean).join(' and ');
 return 'Swapped in your '+parts+', with the rest of the video kept as it was. Free: no rebuild, one render.'+note;
}

/** Text the swap pushed off the frame: the render's edge findings the version did not already have (its own off-frame moves are on purpose). */
export function swapOverflow(input={},checks=null){
 const base=new Set((input.part_swap?.base_edges??[]).map(e=>e.selector+'|'+e.message));
 return (checks?.edges??[]).filter(e=>e&&!base.has((e.selector||'')+'|'+(e.message||'')));
}
