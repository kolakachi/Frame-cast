// The version note is written from the stored final checks, not from what the builder believes (CLAIM-CHECK: run
// 5768a13a said "the check now passes" and "I fixed both problems" while its stored checks were blocked, 2026-10-14;
// verify-don't-claim, 2026-10-05). The builder's account of what it changed stays; a sentence that says the checks pass
// is dropped when they do not, and one line says what passed and what is still open.

/** A sentence that reports a check result as good ("the check now passes", "reports no problems", "everything passes"). */
const CLAIM=/\b(checks?|review|verdict|everything|all)\b[^.!?]{0,60}\b(now\s+)?(pass(es|ed)?|clean|reports?\s+no|shows?\s+no|finds?\s+no|is\s+green|all\s+clear)\b|\bno\s+(remaining\s+)?(problems?|issues?|findings?)\s+(left|remain\w*)\b/i;

/** The builder's account with claims the stored checks contradict removed, and the checks' own line: {text, line}. */
export function verifiedParts(summary,finalReview){
 const text=String(summary||'').trim();
 if(!finalReview||!Array.isArray(finalReview.checks)||!finalReview.checks.length)return {text,line:''};
 const checks=finalReview.checks,passed=checks.filter(c=>c.status==='pass').length;
 const open=checks.filter(c=>c.status!=='pass');
 if(!open.length)return {text,line:`Final checks: all ${passed} passed.`};
 // Sentences, kept with their paragraph breaks; only claims about checks passing are dropped.
 const kept=text.split(/\n{2,}/).map(p=>p.split(/(?<=[.!?])\s+/).filter(s=>!CLAIM.test(s)).join(' ')).filter(p=>p.trim()).join('\n\n');
 const items=open.slice(0,3).map(c=>c.label+(c.message?' ('+String(c.message).replace(/\.$/,'')+')':'')+(c.status==='unverified'?', not confirmed':''));
 return {text:kept,line:`Final checks: ${passed} passed; still open: ${items.join('; ')}${open.length>3?`; and ${open.length-3} more`:''}.`};
}

/** The two joined: the note as the user reads it. */
export function verifiedSummary(summary,finalReview){
 const {text,line}=verifiedParts(summary,finalReview);
 return (text+(text&&line?'\n\n':'')+line).trim();
}
