import test from 'node:test';import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';import vm from 'node:vm';
const src=await readFile(new URL('../../runtime/wyv-motion.js',import.meta.url),'utf8');
const window={gsap:{}};vm.runInNewContext(src,{window,document:{},Math,Object});
const {ease}=window.WM;
const curve=e=>Array.from({length:101},(_,i)=>e(i/100));
test('spring eases start at 0 and end exactly at 1',()=>{
 for(const k of ['snappy','default','heavy','playful']){assert.equal(ease[k](0),0,k);assert.equal(ease[k](1),1,k);}
});
test('playful and snappy overshoot; heavy never does',()=>{
 assert.ok(Math.max(...curve(ease.playful))>1.15);
 assert.ok(Math.max(...curve(ease.snappy))>1.01);
 assert.ok(Math.max(...curve(ease.heavy))<=1.0001);
});
test('springs are deterministic',()=>{assert.deepEqual(curve(ease.default),curve(ease.default));});
test('the kit never contains a closing script tag (HyperFrames inlines scripts into the page)',()=>{
 assert.ok(!/<\/script/i.test(src),'a literal closing script tag ends the inlined script early and breaks every build that loads the kit');
});
test('the signature-move recipes exist',()=>{for(const k of ['giantWipe','stamp','field'])assert.equal(typeof window.WM[k],'function',k);});
test('a morph marks the narrowest box it becomes, so the text fit uses it (SWAP-SAFE canary: a web address overflowed its pill)',()=>{
 const attrs={},el={setAttribute:(k,v)=>{attrs[k]=String(v);}},tl={set(){return tl;},to(){return tl;}};
 window.WM.morph(tl,el,[{at:1,width:440,height:460,borderRadius:28},{at:2,width:400,height:116,borderRadius:58}]);
 assert.deepEqual(attrs,{'data-wyv-fit-w':'400','data-wyv-fit-h':'116','data-wyv-fit-r':'58'});
 const none={};window.WM.morph(tl,{setAttribute:(k,v)=>{none[k]=v;}},[{at:1,opacity:1}]);
 assert.deepEqual(none,{},'a morph that keeps its size marks nothing');
});
