import test from 'node:test';import assert from 'node:assert/strict';
import {inspectionReport} from '../inspection-report.mjs';
test('a check finding keeps its text, time, box and collision partner, and overlap gets the opacity note',()=>{
 const raw={ok:false,layout:{findings:[{code:'content_overlap',severity:'error',time:3.625,firstSeen:3.625,lastSeen:13.375,selector:'#line_1',text:'Voiced',rect:{left:878,top:298,width:212.8,height:82},containerSelector:'#scrC > div',message:'Two text blocks overlap',fixHint:'h'},{code:'x',severity:'warning',selector:'#w',message:'ignored'}]}};
 const r=inspectionReport(JSON.stringify(raw),{advisory:[]});
 assert.equal(r.ok,false);assert.equal(r.errors.length,1);
 assert.deepEqual(r.errors[0],{code:'content_overlap',message:'Two text blocks overlap',selector:'#line_1',text:'Voiced',seen:'3.625-13.375s',rect:[878,298,213,82],with:'#scrC > div',fixHint:'h'});
 assert.match(r.note,/opacity 0/);
 assert.ok(!/opacity 0/.test(inspectionReport(JSON.stringify({ok:false,lint:{findings:[{code:'page_error',severity:'error',message:'boom'}]}})).note));
});
test('layout, contrast and motion findings are notes, not failures; a page error still fails',()=>{
 const raw={ok:false,layout:{errorCount:1,findings:[{code:'content_overlap',severity:'error',selector:'#a',message:'Two text blocks overlap'}]},contrast:{errorCount:1,findings:[{code:'contrast_aa_failure',severity:'error',selector:'#b',message:'Contrast is 2.8:1'}]},lint:{errorCount:0,findings:[]},runtime:{errorCount:0,findings:[]}};
 const r=inspectionReport(JSON.stringify(raw));
 assert.equal(r.ok,true);assert.equal(r.errors.length,0);assert.equal(r.notes.length,2);assert.equal(r.notes[0].section,'layout');
 const broken=inspectionReport(JSON.stringify({...raw,runtime:{errorCount:1,findings:[{code:'page_error',severity:'error',message:'boom'}]}}));
 assert.equal(broken.ok,false);assert.deepEqual(broken.errors.map(e=>e.code),['page_error']);
});
test('a check that failed, timed out or returned nothing is never a pass',()=>{
 for(const raw of [{ok:false,error:'Page navigation timed out'},{},{ok:false,layout:{errorCount:1,findings:[{code:'content_overlap',severity:'error',message:'x'}]}}]){
  const r=inspectionReport(JSON.stringify(raw));assert.equal(r.ok,false,JSON.stringify(raw));assert.ok(r.errors.length);
 }
 assert.match(inspectionReport(JSON.stringify({ok:false,error:'Page navigation timed out'})).errors[0].message,/navigation timed out/);
});
