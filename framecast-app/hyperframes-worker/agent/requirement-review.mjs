// A review checks the frozen contract, not whichever requirements the critic remembers.
export function requirementChecks(raw,requirements,{lookOnly=false}={}){
 const active=(Array.isArray(requirements)?requirements:[]).filter(r=>/^req-[a-f0-9]{20}$/.test(r?.id));
 const checks=active.map(r=>{
  const matches=(Array.isArray(raw)?raw:[]).filter(c=>c?.id===r.id);
  const c=matches.length===1?matches[0]:null;
  let status=c&&['fulfilled','unmet','unverified','deferred'].includes(c.status)&&typeof c.evidence==='string'&&c.evidence.trim()?c.status:'unverified';
  const timed=c&&Number.isFinite(c.start)&&Number.isFinite(c.end)&&c.start>=0&&c.end>=c.start&&c.end<=86400;
  let evidence=c&&typeof c.evidence==='string'?c.evidence.trim().slice(0,400):'No unique evidence returned for this requirement.';
  if(status==='deferred'&&!(lookOnly&&r.review_stage==='production')){status='unverified';evidence='This requirement cannot be deferred at this stage.';}
  // Stills cannot show sound, timing or motion: on a storyboard those are checked on the full video.
  if(lookOnly&&r.review_stage==='production'&&status!=='unmet'&&status!=='fulfilled'){status='deferred';evidence='Checked on the full video.';}
  if((c?.version??1)!==(r.version??1)){status='unverified';evidence='The review belongs to an older requirement version.';}
  if(r.order_unresolved||r.evidence_status==='unverified'){status='unverified';evidence='Requirement order or source evidence needs clarification.';}
  // The current critic receives images only. It cannot certify speech/sound from them.
  if(r.category==='audio'&&status==='fulfilled'){status='unverified';evidence='Audio requires a separate audio check; frames do not verify it.';}
  if(status==='fulfilled'&&(r.category==='action'||r.after_ids?.length)&&!timed){status='unverified';evidence='The claimed action/order has no observed time range.';}
  return {id:r.id,text:r.text,version:r.version??1,status,evidence,...(timed?{start:c.start,end:c.end}:{})};
 });
 for(let pass=0;pass<active.length;pass++)for(const r of active){
  const c=checks.find(x=>x.id===r.id);
  if(c.status!=='fulfilled')continue;
  for(const id of r.after_ids??[]){
   const prior=checks.find(x=>x.id===id);
   if(!prior||prior.status!=='fulfilled'||!Number.isFinite(prior.end)||!Number.isFinite(c.start)){
    c.status='unverified';c.evidence='The preceding action has not been verified.';break;
   }
   if(prior.end>c.start){c.status='unmet';c.evidence='The observed action order does not match the approved sequence.';break;}
  }
 }
 return checks;
}
