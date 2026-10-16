import test from 'node:test';import assert from 'node:assert/strict';
import {missingIds,missingIdWarning} from '../missing-ids.mjs';

test('a script that fills an element the page does not have is named before a preview', () => {
 const files={'index.html':'<div id="card"></div><div id="marquee"></div>','main.js':"document.getElementById('price').textContent='$24';WM.stamp(tl,'#card',1);tl.to('#offer .burst',{x:1});document.querySelector('#marquee').appendChild(x);"};
 assert.deepEqual(missingIds(files).map(m=>m.id),['price','offer']);
 assert.match(missingIdWarning(files),/main\.js uses #price, #offer, but no element has those ids/);
});

test('colours, ids made in code and markup after a "//" are not flagged', () => {
 const files={'index.html':'<span>// 01</span><canvas id="p1"></canvas>','main.js':"gsap.set('#p1',{color:'#FF6B35',background:'#fff'});const d=document.createElement('div');d.id='made';tl.to('#made',{x:1});CUR('cur11');tl.to('#cur11',{x:1});items.forEach(i=>{el.innerHTML+=`<b id=\"row-${i}\"></b>`});tl.to('#row-2',{x:1});"};
 assert.deepEqual(missingIds(files),[]);
 assert.equal(missingIdWarning(files),null);
});
