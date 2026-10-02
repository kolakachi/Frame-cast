import test from 'node:test';import assert from 'node:assert/strict';
import {inspectionReport} from '../inspection-report.mjs';
test('a check finding keeps its text, time, box and collision partner, and overlap gets the opacity note',()=>{
 const raw={ok:false,layout:{findings:[{code:'content_overlap',severity:'error',time:3.625,firstSeen:3.625,lastSeen:13.375,selector:'#line_1',text:'Voiced',rect:{left:878,top:298,width:212.8,height:82},containerSelector:'#scrC > div',message:'Two text blocks overlap',fixHint:'h'},{code:'x',severity:'warning',selector:'#w',message:'ignored'}]}};
 const r=inspectionReport(JSON.stringify(raw));
 assert.equal(r.ok,false);assert.equal(r.errors.length,1);
 assert.deepEqual(r.errors[0],{code:'content_overlap',message:'Two text blocks overlap',selector:'#line_1',text:'Voiced',seen:'3.625-13.375s',rect:[878,298,213,82],with:'#scrC > div',fixHint:'h'});
 assert.match(r.note,/opacity 0/);
 assert.ok(!/opacity 0/.test(inspectionReport(JSON.stringify({ok:false,lint:{findings:[{code:'page_error',severity:'error',message:'boom'}]}})).note));
});
