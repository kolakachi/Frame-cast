import test from 'node:test';import assert from 'node:assert/strict';
import {declarations,withValues,values,leftovers,overflowing,bundleHash} from '../template-audit.mjs';
import {TEMPLATE_RULES} from '../composition-agent.mjs';

const page=`<html lang="en" data-composition-variables='[{"id":"headline","type":"string","label":"Hook","default":"Where&apos;s my order?"},{"id":"cta","type":"string","label":"Link","default":"parcelpath.app"},{"id":"color_accent","type":"color","label":"Accent","default":"#ff0000"}]'><body></body></html>`;

test('markers go into the text fields only, and the page keeps its other fields',()=>{
 const d=declarations(page);assert.deepEqual(d.map(x=>x.id),['headline','cta','color_accent']);
 const v=values(d);assert.deepEqual(v.short,{headline:'ZQ1',cta:'ZQ2'});assert.deepEqual(v.markers,{ZQ1:'headline',ZQ2:'cta'});
 assert.ok(v.long.headline.startsWith('ZQ1 ')&&v.long.headline.length===Math.round('Where\'s my order?'.length*1.5));
 const next=declarations(withValues(page,v.short));
 assert.deepEqual(next.map(x=>x.default),['ZQ1','ZQ2','#ff0000']);
});

test('a word or a price left on screen is written into the code; markers, counters and times are not (owner free ad, 2026-10-17)',()=>{
 assert.deepEqual(leftovers(['ZQ1','Z Q 2','01 / 04','00:12','ZQ3 ZQ4','wyvstudio.com','$39','25%','Northside Roasters']),['wyvstudio.com','$39','25%','Northside Roasters']);
});

test('long text counts as running out of its box only when it stays out and its short marker was inside',()=>{
 const shortOut=[{text:'ZQ1',at:0}];
 const longOut=[{text:'ZQ1 fresh daily',at:0},{text:'ZQ1 fresh daily',at:0.25},{text:'ZQ2 fresh',at:5},{text:'ZQ3 fresh',at:7},{text:'ZQ3 fresh',at:7.25}];
 assert.deepEqual(overflowing(shortOut,longOut,0.25),[{text:'ZQ3 fresh',at:7}]);
});

test('the code hash ignores asset ids, so an audit made locally holds on prod',()=>{
 const a=bundleHash({'index.html':'<img src="asset-12-'+'a'.repeat(64)+'.png">','style.css':'a{}'});
 assert.equal(a,bundleHash({'style.css':'a{}','index.html':'<img src="asset-990-'+'a'.repeat(64)+'.png">'}));
 assert.notEqual(a,bundleHash({'index.html':'<img src="asset-12-'+'b'.repeat(64)+'.png">','style.css':'a{}'}));
});

test('a template build tells the builder that every word on screen is a field',()=>{
 assert.ok(TEMPLATE_RULES.some(r=>/Every word on screen comes from a string composition variable/.test(r)));
 assert.ok(TEMPLATE_RULES.some(r=>/No generated video/.test(r)));
});
