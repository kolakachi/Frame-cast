import test from 'node:test';import assert from 'node:assert/strict';
import {verifiedSummary,verifiedParts} from '../claim-note.mjs';
const blocked={status:'blocked',checks:[
 {id:'words',label:'Every approved word is spoken',status:'pass',message:''},
 {id:'reading',label:'Every word on screen can be read',status:'fail',message:'"ZERO SUGAR" is fully on screen for 1.3 s; it needs 1.5 s to be read.'},
 {id:'required',label:'Must appear: Ends on the wordmark',status:'unverified',message:'Could not be confirmed by sight.'}]};
test('a note that says the checks pass is rewritten from the stored result (CLAIM-CHECK)',()=>{
 const said='I fixed the three reading-time problems by changing only the timing. The words and design are unchanged. The check now reports no reading-time problems.\n\nI fixed both problems, and everything passes now.';
 const out=verifiedSummary(said,blocked);
 assert.match(out,/I fixed the three reading-time problems by changing only the timing\. The words and design are unchanged\./,'what was changed stays');
 assert.doesNotMatch(out,/check now reports no/);
 assert.doesNotMatch(out,/everything passes/);
 assert.match(out,/Final checks: 1 passed; still open: Every word on screen can be read \("ZERO SUGAR" is fully on screen for 1\.3 s; it needs 1\.5 s to be read\); Must appear: Ends on the wordmark \(Could not be confirmed by sight\), not confirmed\.$/);
});
test('a passed video keeps its note and says so; no checks, no line',()=>{
 const passed={status:'passed',checks:[{label:'Every approved word is spoken',status:'pass'},{label:'No blank frames',status:'pass'}]};
 assert.equal(verifiedSummary('The check now passes.',passed),'The check now passes.\n\nFinal checks: all 2 passed.');
 assert.deepEqual(verifiedParts('Done.',null),{text:'Done.',line:''});
 assert.equal(verifiedSummary('When I previewed it, the check still flagged two moves.',blocked).startsWith('When I previewed it, the check still flagged two moves.'),true,'an honest note is not cut');
});
