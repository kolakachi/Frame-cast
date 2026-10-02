// Retain actionable errors and hints; warnings must not bury a blocking defect.
export function inspectionReport(output) {
 let raw;try{raw=JSON.parse(output);}catch{return {ok:false,errors:[{message:String(output).slice(0,3000)}]};}
 // Each finding keeps what a repair needs: the text, when it is seen, where it sits, and what it collides with.
 const r=n=>Number.isFinite(n)?Math.round(n):undefined;
 const errors=Object.values(raw).flatMap(section=>section?.findings??[]).filter(f=>f.severity==='error').map(({code,message,selector,fixHint,text,time,firstSeen,lastSeen,rect,containerSelector})=>({code,message,selector,
  ...(typeof text==='string'&&text.trim()?{text:text.trim().slice(0,60)}:{}),
  ...(Number.isFinite(firstSeen)?{seen:(Number.isFinite(lastSeen)&&lastSeen!==firstSeen?firstSeen+'-'+lastSeen:firstSeen)+'s'}:Number.isFinite(time)?{seen:time+'s'}:{}),
  ...(rect&&Number.isFinite(rect.left)?{rect:[r(rect.left),r(rect.top),r(rect.width),r(rect.height)]}:{}),
  ...(containerSelector?{with:containerSelector}:{}),fixHint})).slice(0,8);
 if(raw.ok!==true&&!errors.length)errors.push({message:'Inspection failed without structured findings. Inspect local command.log; do not assume success.'});
 const layered=errors.some(e=>/overlap|occlu/.test(e.code||''));
 return {ok:raw.ok===true,errors,note:raw.ok===true?'Validation passed; proceed to sampled visual review.':'Fix these blocking errors, preserving locked media and timings.'+(layered?' Overlap and occlusion count elements at opacity 0: a scene or screen that is not on yet must be visibility:hidden (GSAP autoAlpha:0, or tl.set display:none), and "with" names the element it collides with. data-layout-allow-overlap or data-layout-allow-occlusion goes on the flagged element itself, only when the layering is intended.':'')};
}
