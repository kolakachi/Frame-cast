import test from 'node:test';import assert from 'node:assert/strict';
import {layoutFindings,uninspected,boxOff,TOLERANCE} from '../layout-check.mjs';
const layout=[{moment:'1471:m14',at:7.85,elements:[{role:'tile',label:'checkout tile',box:[0.05,0.09,0.29,0.84]},{role:'mascot',label:'on-air tile',box:[0.35,0.09,0.29,0.41]}]},
 {moment:'1471:m19',at:10.86,elements:[{role:'sticker',label:'stamp',box:[0.3,0.4,0.4,0.2]}]}];
test('a moment counts as looked at when an inspected time is within 0.6 s of it',()=>{
 assert.deepEqual(uninspected(layout,[7.5,11.2]),[]);
 assert.deepEqual(uninspected(layout,[7.5]),['1471:m19']);
});
test('box distance: centre offset and size ratio as fractions of the frame',()=>{
 assert.deepEqual(boxOff([0.1,0.1,0.2,0.2],[0.1,0.1,0.2,0.2]),{dist:0,size:0});
 assert.ok(boxOff([0.1,0.1,0.2,0.2],[0.5,0.1,0.2,0.2]).dist>TOLERANCE.centre);
 assert.ok(boxOff([0.1,0.1,0.2,0.2],[0.1,0.1,0.4,0.2]).size>TOLERANCE.size,'twice as wide is off');
});
test('an exact copy is sent back for unseen moments, unmarked, missing and misplaced elements',()=>{
 const measured={7.85:[{ref:'1471:m14:0',box:[0.06,0.1,0.28,0.82],visible:true},{ref:'1471:m14:1',box:[0.7,0.6,0.2,0.2],visible:true}],
  10.86:[{ref:'1471:m19:0',box:[0.3,0.4,0.4,0.2],visible:false}]};
 const f=layoutFindings({layout,measured,inspectedTimes:[7.85]});
 assert.deepEqual(f.map(x=>x.code),['reference_moment_not_inspected','reference_element_off_slot','reference_element_missing']);
 assert.match(f[1].fixHint,/x 0\.35, y 0\.09, width 0\.29, height 0\.41/);
 const none=layoutFindings({layout:[layout[1]],measured:{10.86:[]},inspectedTimes:[10.9]});
 assert.equal(none[0].code,'reference_element_unmarked');
});
test('every element in its slot and every moment looked at: nothing to report',()=>{
 const measured={7.85:[{ref:'1471:m14:0',box:[0.05,0.09,0.29,0.84],visible:true},{ref:'1471:m14:1',box:[0.36,0.1,0.28,0.4],visible:true}],
  10.86:[{ref:'1471:m19:0',box:[0.31,0.41,0.38,0.2],visible:true}]};
 assert.deepEqual(layoutFindings({layout,measured,inspectedTimes:[7.8,10.8]}),[]);
});
