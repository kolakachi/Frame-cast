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
 if(['trim','cut','remove_silence','fade'].includes(step.operation)){
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

// Cut suggestions from word timings: filler words, a word or phrase said twice
// in a row (a false start), and long pauses. Each keeps the word indices it
// covers so a later cut can tell a planned removal from lost content.
const FILLERS=new Set(['um','uh','erm','er','ah','hmm','mm','uhm','umm']);
const plain=s=>String(s).toLowerCase().replace(/[^\p{L}\p{N}']/gu,'');
export function suggestCuts(words,{pause=1.0}={}){
 const w=words.map(x=>Array.isArray(x)?{text:x[0],start:x[1],end:x[2]}:x);
 const cuts=[];
 w.forEach((x,i)=>{if(FILLERS.has(plain(x.text)))cuts.push({start:x.start,end:x.end,reason:'filler',text:x.text,indices:[i]});});
 for(let i=0;i<w.length;i++)for(let n=4;n>=1;n--){
  if(i+2*n>w.length)continue;
  const a=w.slice(i,i+n).map(x=>plain(x.text)),b=w.slice(i+n,i+2*n).map(x=>plain(x.text));
  if(a.every(Boolean)&&a.join(' ')===b.join(' ')&&!(n===1&&FILLERS.has(a[0]))){cuts.push({start:w[i].start,end:w[i+n].start,reason:'repeat',text:w.slice(i,i+n).map(x=>x.text).join(' '),indices:Array.from({length:n},(_,k)=>i+k)});i+=n-1;break;}
 }
 for(let i=1;i<w.length;i++)if(w[i].start-w[i-1].end>=pause)cuts.push({start:+(w[i-1].end+0.1).toFixed(2),end:+(w[i].start-0.1).toFixed(2),reason:'pause',text:'',indices:[]});
 return cuts.sort((x,y)=>x.start-y.start).slice(0,40);
}

// Which words a cut removed, and which of those were not planned removals.
export function removedWords(words,step){
 const w=words.map(x=>Array.isArray(x)?{text:x[0],start:x[1],end:x[2]}:x);
 const kept=new Set();
 for(const [i,x] of w.entries()){const mid=(x.start+x.end)/2;if(step.sourceMap.some(m=>mid>=m.src_start&&mid<m.src_end))kept.add(i);}
 const planned=new Set(suggestCuts(w).flatMap(c=>c.indices));
 const removed=w.map((x,i)=>({...x,i})).filter(x=>!kept.has(x.i));
 return {removed:removed.map(x=>x.text),content:removed.filter(x=>!planned.has(x.i)).map(x=>x.text)};
}

// Keep ranges that remove the suggested filler and false starts (and, if
// asked, long pauses) from a clip of the given duration.
export function tightenRanges(words,duration,{pauses=false,pad=0.04}={}){
 const cuts=suggestCuts(words).filter(c=>c.reason!=='pause'||pauses).map(c=>[Math.max(0,c.start-pad),Math.min(duration,c.reason==='repeat'?c.end:c.end+pad)]).filter(([s,e])=>e-s>0.05).sort((a,b)=>a[0]-b[0]);
 const merged=[];for(const c of cuts){const last=merged.at(-1);if(last&&c[0]<=last[1])last[1]=Math.max(last[1],c[1]);else merged.push([...c]);}
 const keep=[];let at=0;
 for(const [s,e] of merged){if(s-at>=0.1)keep.push([+at.toFixed(3),+s.toFixed(3)]);at=e;}
 if(duration-at>=0.1)keep.push([+at.toFixed(3),+duration.toFixed(3)]);
 return {keep:keep.slice(0,20),removed:merged.length};
}
