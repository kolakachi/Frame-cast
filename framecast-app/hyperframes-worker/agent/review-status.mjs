// Creative review belongs to a specific authored revision, not just a completed render.
export function reviewStatus(state) {
 const reviewed=state.reviewedRevision===state.revision&&state.criticRevision===state.revision&&state.critic?.verdict==='pass'&&!state.recoveredDraft;
 const findings=[...(state.critic?.verdict==='revise'?state.critic.directives??[]:[]),...(state.layoutAdvisories??[]).map(x=>x.message||x.code)];
 if(state.recoveredDraft)findings.unshift('A previous draft was recovered; the final review was not completed for this delivery.');
 if(!reviewed&&!findings.length)findings.push('Independent creative review has not passed for this version.');
 const requirements=state.criticRevision===state.revision&&!state.recoveredDraft&&Array.isArray(state.critic?.requirement_checks)?state.critic.requirement_checks.slice(0,24):[];
 const checks=state.criticRevision===state.revision&&!state.recoveredDraft&&Array.isArray(state.critic?.performance_checks)?state.critic.performance_checks.slice(0,24):[];
 return {status:reviewed&&!findings.length?'passed':'incomplete',revision:state.revision,findings:findings.filter(x=>typeof x==='string').slice(0,8).map(x=>x.slice(0,300)),...(requirements.length?{requirement_checks:requirements}:{}),...(checks.length?{performance_checks:checks}:{})};
}
