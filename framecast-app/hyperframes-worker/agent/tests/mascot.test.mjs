import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../runtime/wyv-mascot.js',import.meta.url),'utf8');
const scope={};vm.runInNewContext(source,scope);
const {validate,attach}=scope.WyvMascot;
test('prepared mascot score rejects unbounded, overlapping and unsupported motion',()=>{
 for(const score of [
  {duration:Infinity},{duration:301},{duration:6,blinks:[{at:5.9,duration:.3}]},
  {duration:6,blinks:[{at:1,duration:.3},{at:1.1,duration:.2}]},
  {duration:6,head:[{at:1,duration:.3,x:0,y:0,tilt:90}]},
  {duration:6,mouths:[{at:1,shape:'smile'},{at:1,shape:'open'}]},
  {duration:6,mouths:[{at:1,shape:'phoneme-unknown'}]},
  {duration:6,walk:[{at:1}]}, {duration:6,gaze:[{at:1,duration:.3,x:NaN,y:0}]},
  {duration:6,blinks:Array.from({length:1001},()=>({at:0,duration:.1}))}
 ])assert.throws(()=>validate(score),/Mascot:/);
 const score={duration:6,blinks:[{at:1,duration:.2}],mouths:[{at:0,shape:'rest'},{at:2,shape:'smile'}]};
 const clean=validate(score);clean.blinks[0].at=4;assert.equal(score.blinks[0].at,1,'input not mutated');
});
test('no layers or flat image can silently become a rig',()=>{
 scope.gsap={};
 assert.throws(()=>attach({add(){}},{namespaceURI:'http://www.w3.org/1999/xhtml'},{duration:6}),/flat image/);
 assert.throws(()=>attach({add(){}},{namespaceURI:'http://www.w3.org/2000/svg',querySelectorAll:()=>[]},{duration:6}),/expected one layer/);
 assert.ok(!/<\/script/i.test(source),'safe for Hyperframes script inlining');
});

test('the talking face follows each syllable and closes between words',async()=>{
 const {readFile}=await import('node:fs/promises');const g={};
 new Function('globalThis',await readFile(new URL('../../runtime/wyv-mascot.js',import.meta.url),'utf8'))(g);
 const cues=g.WyvMascot.mouthCues([{text:'psst',start:.6,end:.9},{text:'Got',start:1.1,end:1.28},{text:'a',start:1.3,end:1.4},{text:'product',start:1.45,end:1.9}],2);
 assert.deepEqual(cues.map(c=>c.shape),['ee','rest','oh','open','oh','rest','oh','rest']);
 assert.equal(cues[0].t,2.6,'cues are placed at the face start plus the word time');
 assert.ok(cues.every((c,i)=>i===0||c.t>=cues[i-1].t),'in time order');
});
