import test from 'node:test';import assert from 'node:assert/strict';
import {numberFindings,visibleText} from '../grounding-check.mjs';
const html=`<html data-composition-variables='[{"id":"cta","type":"string","default":"Save 20% today"}]'><style>.a{width:1080px}</style>
<div class="label">00:00:04 · 9:16</div><div>Plans from $29/mo</div><div>Trusted by 10,000 teams</div><div>4 formats</div><div>© 2026</div>
<script>tl.to('#a',{x:1200})</script></html>`;
test('invented prices, percentages, big counts and ratios are flagged; timecodes, years and small counts are not',()=>{
 const e=numberFindings(html,'Make 4 formats in one workflow');
 assert.deepEqual(e.map(x=>x.message.match(/"([^"]+)"/)[1]).sort(),['$29','10,000','20%','9:16'].sort());
});
test('numbers and ratios the user approved pass',()=>{
 assert.deepEqual(numberFindings(html,'Approved: plans from $29 a month. 10,000 teams. Save 20%. Export 9:16.'),[]);
});
test('script and style content is not visible text',()=>{
 assert.ok(!visibleText(html).includes('1080px'));assert.ok(!visibleText(html).includes('1200'));assert.ok(visibleText(html).includes('Save 20% today'));
});
test('colour variables are not on-screen numbers; an unapproved figure in a text variable still is',()=>{
 const vars=JSON.stringify([{id:'color_background',type:'color',default:'#121212'},{id:'color_text',default:'#111'},{id:'headline',type:'string',default:'Save 40%'},{id:'customers',type:'string',default:'50000'}]).replace(/"/g,'&quot;');
 const found=numberFindings(`<html data-composition-variables='${vars}'><body><h1>Hello</h1></body></html>`,'');
 assert.deepEqual(found.map(f=>f.message),['"40%" is on screen but not in the approved facts, copy or script.','"50000" is on screen but not in the approved facts, copy or script.'],'a plain number that could spell hex is still a number');
});
