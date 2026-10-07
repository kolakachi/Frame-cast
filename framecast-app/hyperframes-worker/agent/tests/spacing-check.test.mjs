import test from 'node:test';import assert from 'node:assert/strict';
import {spacing,RULES} from '../reads-check.mjs';

// Frames every 0.1 s; a thing is a block of words with its own box.
const words=(id,x,y,w,h,extra={})=>({id,text:true,label:'W'+id,words:{x,y,w,h},x,y,w,h,...extra});
const frames=(n,things,more={})=>Array.from({length:n},(_,i)=>({t:+(i*0.1).toFixed(1),W:1080,H:1920,things:things(i),...more}));

test('words held against the frame edge are found; inside the margins, moving past, or marked to bleed they are not', () => {
 // Production 2026-10-07: "WyvStudio" 0 px from the left edge, held.
 assert.equal(spacing(frames(10,()=>[words(1,0,900,1080,200)])).find(f=>f.code==='edge_margin')?.severity,'error');
 assert.equal(spacing(frames(10,()=>[words(1,100,900,880,200)])).length,0);
 assert.equal(spacing(frames(10,i=>[words(1,-200+i*40,900,600,200)])).filter(f=>f.code==='edge_margin').length,0,'an entrance passing the edge is motion');
 assert.equal(spacing(frames(10,()=>[words(1,-80,900,1240,200,{bleed:true})])).filter(f=>f.code==='edge_margin').length,0);
 assert.match(spacing(frames(10,()=>[words(1,200,20,600,100)]))[0].message,/77 px at the top/);
});

test('blocks of words held over each other are found; a brief crossing is not', () => {
 const over=[{pair:'1-2',share:0.6,labels:['SHOOT','WITHOUT A']}];
 assert.equal(spacing(frames(10,()=>[],{overlaps:over})).find(f=>f.code==='text_overlap')?.severity,'error');
 assert.equal(spacing(frames(3,()=>[],{overlaps:over})).length,0);
 assert.equal(spacing(frames(10,()=>[],{overlaps:[{...over[0],share:0.1}]})).length,0);
});

test('a group held in the top third with the rest empty is a warning; a centred group is fine', () => {
 assert.equal(spacing(frames(20,()=>[words(1,100,300,880,200)])).find(f=>f.code==='lopsided')?.severity,'warning');
 assert.equal(spacing(frames(20,()=>[words(1,100,800,880,200)])).length,0);
 assert.equal(RULES.side,0.06);
});
