import test from 'node:test';import assert from 'node:assert/strict';
import {numberFindings,visibleText} from '../grounding-check.mjs';
const html=`<html data-composition-variables='[{"id":"cta","type":"string","default":"Save 20% today"}]'><style>.a{width:1080px}</style>
<div class="label">00:00:04 · 9:16</div><div>Plans from $29/mo</div><div>Trusted by 10,000 teams</div><div>4 formats</div><div>© 2026</div>
<script>tl.to('#a',{x:1200})</script></html>`;
test('invented prices, percentages and big counts are flagged; timecodes, ratios, years and small counts are not',()=>{
 const e=numberFindings(html,'Make 4 formats in one workflow');
 assert.deepEqual(e.map(x=>x.message.match(/"([^"]+)"/)[1]).sort(),['$29','10,000','20%'].sort());
});
test('numbers the user approved pass',()=>{
 assert.deepEqual(numberFindings(html,'Approved: plans from $29 a month. 10,000 teams. Save 20%.'),[]);
});
test('script and style content is not visible text',()=>{
 assert.ok(!visibleText(html).includes('1080px'));assert.ok(!visibleText(html).includes('1200'));assert.ok(visibleText(html).includes('Save 20% today'));
});
