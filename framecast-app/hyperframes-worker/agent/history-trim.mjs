// RD-31 (the arXiv harness study, 2026-10-15): tool output that a later step has made stale is the cheapest context to
// drop. A read of a file the agent later rewrote, and a check, timeline or preview result a newer one replaced, shrink
// to their opening lines. Rule-based, no model call; run only when the history is already being changed (old frames
// leaving in a batch), so it never makes the provider re-cache on its own.
const RERUN=new Set(['check','timeline','preview','snapshot','primitives']);
const KEEP=300,MIN=400;

export function trimStale(turns,{keepLast=2,from=1}={}){
 const uses=new Map(),writesAfter=new Map(),lastOf=new Map();
 turns.forEach((m,k)=>{if(m.role!=='assistant'||!Array.isArray(m.content))return;for(const b of m.content)if(b.type==='tool_use'){
  uses.set(b.id,{name:b.name,path:b.input?.path,k});lastOf.set(b.name,k);
  if((b.name==='write'||b.name==='patch')&&b.input?.path)writesAfter.set(b.input.path,k);
 }});
 const cut=s=>s.slice(0,KEEP)+' …[superseded later; shortened]';
 let saved=0;
 const userTurns=turns.map((m,k)=>m.role==='user'?k:-1).filter(k=>k>0);
 const protect=new Set(userTurns.slice(-keepLast));
 turns.forEach((m,k)=>{if(m.role!=='user'||k===0||k<from||protect.has(k)||!Array.isArray(m.content))return;
  for(const b of m.content){if(b.type!=='tool_result')continue;
   const u=uses.get(b.tool_use_id);if(!u)continue;
   const stale=u.name==='read'?(writesAfter.get(u.path)??-1)>u.k:RERUN.has(u.name)&&(lastOf.get(u.name)??-1)>u.k;
   if(!stale)continue;
   if(typeof b.content==='string'&&b.content.length>MIN&&!b.content.endsWith('shortened]')){saved+=b.content.length-KEEP;b.content=cut(b.content);}
   else if(Array.isArray(b.content))b.content=b.content.map(x=>x.type==='text'&&typeof x.text==='string'&&x.text.length>MIN&&!x.text.endsWith('shortened]')?(saved+=x.text.length-KEEP,{...x,text:cut(x.text)}):x);
  }});
 return saved;
}
