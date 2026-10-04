import test from 'node:test';import assert from 'node:assert/strict';
import {exactScaffold} from '../scaffold.mjs';
const plan={reference_match:'exact',mascot3d:{spec:{head:{shape:'sphere'}}},props3d:[{name:'camera',looks:'a camera',moments:['9:m2'],spin:true}],
 scenes:[{label:'Hook',start:0,end:2},{label:'Problem',start:2,end:4}],
 reference_layout:[
  {moment:'9:m1',beat:'Hook',at:.5,move:'pop',content:'bear pops in',elements:[{role:'mascot',box:[.6,.2,.4,.8],label:'bear'},{role:'headline',box:[.05,.3,.5,.3],label:'hook line'}]},
  {moment:'9:m2',beat:'Problem',at:2.6,move:'toss',content:'card tossed in',elements:[{role:'mascot',box:[0,.3,.35,.7],label:'bear'},{role:'card',box:[.4,.2,.2,.6],label:'card'}]},
  {moment:'9:m3',beat:'Problem',at:3.2,move:'pop',content:'bear winks',elements:[{role:'mascot',box:[0,.31,.35,.69],label:'bear winks'}]}]};
test('an exact copy starts from its reference layout: slots in their beats, merged across moments, with 3D stubs and placed audio',()=>{
 const r=exactScaffold({plan,settings:{aspect_ratio:'16:9',duration_seconds:15},words:[{text:'Want',start:.4,end:.6}],audio:{narration:'derived-1-speed.wav',narration_start:.3,music:'derived-3-fade.wav'}});
 assert.equal(r.beats,2);assert.equal(r.slots,4,'the bear in the Problem beat is one slot across two moments');
 const {['index.html']:html,['main.js']:js}=r.files;
 assert.match(html,/<canvas class="slot" id="s1" width="768" height="864"[^>]*data-ref="9:m1:0"/);
 assert.match(html,/data-ref="9:m2:0 9:m3:0"/);
 assert.match(html,/<audio id="vo" class="clip" src="derived-1-speed.wav" data-start="0.3"/);assert.match(html,/<audio id="bed"/);
 assert.match(html,/window.__timelines.main = window.wyvBuild\(\)/);
 assert.match(js,/WM.toss\(tl, '#s4', 2.60\)/,'the toss acts on the card the moment brings in');
 assert.match(js,/W3D.prop\('#s4p', \{active: \[2, 4\], spin: \{at: 2.60\}/);
 assert.match(js,/const WORDS = \[\{"text":"Want","start":0.4,"end":0.6\}\]/);
 assert.match(js,/W3D.clock\(tl, 15\);\nreturn tl;\n\};/);
 assert.equal(exactScaffold({plan:{...plan,reference_match:'inspired'},settings:{}}),null);
});
