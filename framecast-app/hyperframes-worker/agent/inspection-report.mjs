// Retain actionable errors and hints; warnings must not bury a blocking defect.
// Sections whose findings are notes for the user, not failures: the user reviews the result, so only what breaks the
// video (code that renders differently each time, a page that throws) stops a build.
export const ADVISORY_SECTIONS=['layout','contrast','motion'];
export function inspectionReport(output, {advisory=ADVISORY_SECTIONS}={}) {
 let raw;try{raw=JSON.parse(output);}catch{return {ok:false,errors:[{message:String(output).slice(0,3000)}]};}
 // Notes: the advisory sections' errors, kept short; blocking: everything else.
 const notes=advisory.flatMap(k=>(raw[k]?.findings??[]).filter(f=>f.severity==='error').map(f=>({section:k,code:f.code,message:String(f.message||'').slice(0,160),selector:f.selector}))).slice(0,6);
 if(advisory.length&&raw.ok!==true){
  const blocking=Object.entries(raw).filter(([k,v])=>v&&typeof v==='object'&&!advisory.includes(k)&&(Number(v.errorCount)>0||(v.findings??[]).some(f=>f.severity==='error')));
  if(!blocking.length)raw={...raw,ok:true,...Object.fromEntries(advisory.map(k=>[k,raw[k]?{...raw[k],findings:[]}:raw[k]]))};
  else raw={...raw,...Object.fromEntries(advisory.map(k=>[k,raw[k]?{...raw[k],findings:[]}:raw[k]]))};
 }
 // Each finding keeps what a repair needs: the text, when it is seen, where it sits, and what it collides with.
 const r=n=>Number.isFinite(n)?Math.round(n):undefined;
 const errors=Object.values(raw).flatMap(section=>section?.findings??[]).filter(f=>f.severity==='error').map(({code,message,selector,fixHint,text,time,firstSeen,lastSeen,rect,containerSelector})=>({code,message,selector,
  ...(typeof text==='string'&&text.trim()?{text:text.trim().slice(0,60)}:{}),
  ...(Number.isFinite(firstSeen)?{seen:(Number.isFinite(lastSeen)&&lastSeen!==firstSeen?firstSeen+'-'+lastSeen:firstSeen)+'s'}:Number.isFinite(time)?{seen:time+'s'}:{}),
  ...(rect&&Number.isFinite(rect.left)?{rect:[r(rect.left),r(rect.top),r(rect.width),r(rect.height)]}:{}),
  ...(containerSelector?{with:containerSelector}:{}),fixHint})).slice(0,8);
 if(raw.ok!==true&&!errors.length)errors.push({message:'Inspection failed without structured findings. Inspect local command.log; do not assume success.'});
 const layered=errors.some(e=>/overlap|occlu/.test(e.code||''));
 return {ok:raw.ok===true,errors,...(notes.length?{notes}:{}),note:raw.ok===true?'Validation passed; proceed to sampled visual review.':'Fix these blocking errors, preserving locked media and timings.'+(layered?' Overlap and occlusion count elements at opacity 0: a scene or screen that is not on yet must be visibility:hidden (GSAP autoAlpha:0, or tl.set display:none), and "with" names the element it collides with. data-layout-allow-overlap or data-layout-allow-occlusion goes on the flagged element itself, only when the layering is intended.':'')};
}
