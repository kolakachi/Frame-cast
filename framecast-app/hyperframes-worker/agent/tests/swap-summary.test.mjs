import test from 'node:test';import assert from 'node:assert/strict';
import {swapSummary,swapOverflow} from '../swap-summary.mjs';
test('a free change says what was swapped in',()=>{
 assert.equal(swapSummary({edit_values:{cta:'Shop'}}),'Updated 1 field(s). Free: no model call, one render.');
 assert.equal(swapSummary({edit_values:{a:1,b:2},part_swap:{pictures:[{},{}]}}),'Swapped in your 2 texts and colours and 2 photos, with the rest of the video kept as it was. Free: no rebuild, one render.');
 assert.equal(swapSummary({part_swap:{pictures:[{}]}}),'Swapped in your 1 photo, with the rest of the video kept as it was. Free: no rebuild, one render.');
});
test('a swap names only the overflow its new words caused',()=>{
 const input={edit_values:{headline:'money, sorted?'},part_swap:{pictures:[],base_edges:[{selector:'#wipe',message:'Text extends outside the composition canvas.'}]}};
 const checks={edges:[{selector:'#wipe',time:14.2,message:'Text extends outside the composition canvas.'},{selector:'#l1b',time:0.83,message:'Text extends outside the composition canvas.'}]};
 assert.deepEqual(swapOverflow(input,checks).map(e=>e.selector),['#l1b']);
 assert.match(swapSummary(input,checks),/run past the edge of the frame at 0\.8 s/);
 assert.doesNotMatch(swapSummary(input,{edges:[checks.edges[0]]}),/edge of the frame/,'the template\'s own off-frame moves are not news');
 assert.match(swapSummary(input,null),/^Swapped in your 1 text or colour,/);
});
