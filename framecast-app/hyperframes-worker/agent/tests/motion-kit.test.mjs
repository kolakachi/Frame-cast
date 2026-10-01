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
