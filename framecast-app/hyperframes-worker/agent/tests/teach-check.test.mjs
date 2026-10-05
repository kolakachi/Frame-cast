import test from 'node:test';import assert from 'node:assert/strict';
import {repeatFindings,textBlocks} from '../teach-check.mjs';
const plan={creative_intent:{format:'educational'},narration:['Every video starts as a link you paste into WyvStudio.','Then it writes the script for you.']};
test('a teaching video flags on-screen text that repeats a spoken line; key words and other formats pass',()=>{
 const html='<div class="t">Every video starts as a link you paste in</div><div>Paste a link</div><script>const x="Every video starts as a link you paste into WyvStudio.";</script>';
 assert.deepEqual(textBlocks(html),['Every video starts as a link you paste in']);
 const f=repeatFindings({plan,html});
 assert.equal(f.length,1);assert.equal(f[0].code,'text_repeats_narration');
 assert.equal(repeatFindings({plan:{...plan,creative_intent:{format:'motion_graphics'}},html}).length,0,'ads and promos are not checked');
 assert.equal(repeatFindings({plan,html,settings:{captions:'provided'}}).length,0,'captions repeat speech by design');
});
