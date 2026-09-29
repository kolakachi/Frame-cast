// Carry word times from an original clip to a file derived from it in this run.
// Trims, cuts and silence removal keep ranges (a source map); speed rescales
// time; the other edits leave timing alone. A word whose middle was cut out is
// dropped, and one that straddles a cut is clipped to what remains.
const SAME_TIMING=new Set(['stabilize','clean_audio','loudness','crop','grade']);

export function chainFor(path,assets){
 const steps=[];let current=assets.find(a=>a.path===path);
 if(!current)throw Error('Input is not a file in this project. Call assets to list them.');
 for(let guard=0;current.derivedFrom;guard++){
  if(guard>20)throw Error('Derived file chain is too long');
  steps.unshift({operation:current.operation,params:current.params??{},sourceMap:current.sourceMap??null});
  current=assets.find(a=>a.path===current.derivedFrom);
  if(!current)throw Error('Derived file has lost its source');
 }
 return {root:current.path,steps};
}

function mapStep(items,step){
 if(SAME_TIMING.has(step.operation))return items;
 if(step.operation==='speed'){const f=Number(step.params.factor);if(!(f>=.25&&f<=4))throw Error('Speed step has no usable factor');return items.map(w=>({...w,start:w.start/f,end:w.end/f}));}
 if(['trim','cut','remove_silence'].includes(step.operation)){
  if(!Array.isArray(step.sourceMap))throw Error('Cut step has no source map; transcribe the edited file directly');
  const out=[];
  for(const w of items){
   const mid=(w.start+w.end)/2,seg=step.sourceMap.find(m=>mid>=m.src_start&&mid<m.src_end);
   if(!seg)continue;
   const s=Math.max(w.start,seg.src_start),e=Math.min(w.end,seg.src_end);
   out.push({...w,start:seg.out_start+(s-seg.src_start),end:seg.out_start+(e-seg.src_start)});
  }
  return out;
 }
 throw Error('Timing cannot be carried through '+step.operation);
}

export function mapThrough(items,steps){
 let out=items.map(w=>({text:w.text,start:+w.start,end:+w.end}));
 for(const step of steps)out=mapStep(out,step);
 return out.map(w=>({text:w.text,start:+w.start.toFixed(2),end:+w.end.toFixed(2)}));
}

// Compact enough for the model's context: [text,start,end] triples.
export function compact({words,segments},maxWords=1500){
 return {words:words.slice(0,maxWords).map(w=>[w.text,w.start,w.end]),segments:segments.slice(0,300).map(s=>[s.text,s.start,s.end]),truncated:words.length>maxWords};
}
