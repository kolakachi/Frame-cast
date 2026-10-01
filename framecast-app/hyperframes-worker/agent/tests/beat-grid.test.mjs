import test from 'node:test';import assert from 'node:assert/strict';
import {beatGrid} from '../beat-grid.mjs';
test('a 120 BPM track with off-beat hits gives a 0.5 s grid aligned to the strong beats',()=>{
 const hits=[];for(let t=0.25;t<15;t+=0.5){hits.push({time:t,strength:(Math.round((t-0.25)/0.5)%4===0)?1:0.8});hits.push({time:t+0.25,strength:0.3});}
 const g=beatGrid(hits,15);
 assert.ok(Math.abs(g.bpm-120)<2,String(g.bpm));
 assert.ok(g.beats.some(t=>Math.abs(t-0.25)<0.03));
 assert.ok(Math.abs(g.bars[1]-g.bars[0]-2)<0.05,'bars are four beats apart');
 assert.ok(g.strong_hits.every(t=>g.beats.some(b=>Math.abs(b-t)<0.06)),'strong hits sit on the grid');
});
test('too few hits gives no grid',()=>{assert.equal(beatGrid([{time:1,strength:1}],15),null);});
