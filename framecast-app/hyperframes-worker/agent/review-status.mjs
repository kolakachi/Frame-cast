// What the user is told about a delivered version. The user is the reviewer now: a version that passed its technical
// checks is "ready" for their review; specific, timestamped findings (reference slots missed, planned moves unused,
// pacing, a recovered draft) make it "issues"; a version whose checks did not pass is "incomplete". A critic pass,
// when a build still runs one, is "passed".
const at=f=>(Number.isFinite(Number(f?.time))?'At '+Number(f.time).toFixed(1)+' s: ':'')+String(f?.message||f?.code||'').trim();
export function reviewStatus(state) {
 const critiqued=state.criticRevision===state.revision&&!!state.critic;
 const reviewed=state.reviewedRevision===state.revision&&critiqued&&state.critic?.verdict==='pass'&&!state.recoveredDraft;
 const checked=state.checkedRevision===state.revision&&state.snapshotRevision===state.revision;
 const notes=[...(state.layoutNotes??[]),...(state.pacing&&state.pacing.revision===state.revision?(state.pacing.findings||[]).filter(f=>f.severity!=='warning'):[])].map(at).filter(Boolean);
 const findings=[...(critiqued&&state.critic?.verdict==='revise'?state.critic.directives??[]:[]),...(state.layoutAdvisories??[]).map(x=>x.message||x.code),...notes];
 if(state.recoveredDraft)findings.unshift('This is the last version that passed its checks; the build stopped before a later change was finished.');
 const status=reviewed&&!findings.length?'passed':!checked&&!state.recoveredDraft?'incomplete':findings.length?'issues':critiqued&&!reviewed?'issues':'ready';
 const requirements=critiqued&&!state.recoveredDraft&&Array.isArray(state.critic?.requirement_checks)?state.critic.requirement_checks.slice(0,24):[];
 const checks=critiqued&&!state.recoveredDraft&&Array.isArray(state.critic?.performance_checks)?state.critic.performance_checks.slice(0,24):[];
 return {status,revision:state.revision,findings:findings.filter(x=>typeof x==='string').slice(0,8).map(x=>x.slice(0,300)),...(requirements.length?{requirement_checks:requirements}:{}),...(checks.length?{performance_checks:checks}:{})};
}
