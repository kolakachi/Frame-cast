import test from 'node:test';import assert from 'node:assert/strict';
import {techniqueFindings,constantSpeedFindings} from '../finish-lint.mjs';

test('a transition used more than twice is flagged; content-driven moves never are', () => {
 const code='WM.whip(tl,a,b,1);WM.whip(tl,b,c,3);WM.whip(tl,c,d,5);WM.count(tl,n,0,9,1,1);WM.count(tl,n,0,9,2,1);WM.count(tl,n,0,9,3,1);WM.ease.snappy;// WM.iris(tl,x,1)';
 const f=techniqueFindings(code);
 assert.deepEqual(f.map(x=>x.code+':'+x.message.slice(0,10)),['repeated_move:WM.whip is']);
 assert.equal(techniqueFindings('WM.whip(tl,a,b,1);WM.whip(tl,b,c,3);').length,0,'twice is fine');
});

test('a long linear position or scale move is flagged; spins, drifts and short moves are not', () => {
 const f=constantSpeedFindings('tl.to("#card",{x:400,duration:1.2,ease:"none"},2);tl.to("#globe",{rotation:360,duration:8,ease:"none"},0);tl.to("#bg",{backgroundPosition:"100% 0",duration:6,ease:"linear"},0);tl.to("#t",{y:-20,duration:0.3,ease:"none"},1);tl.to("#c",{scale:1.1,duration:2,ease:"power2.out"},3)');
 assert.equal(f.length,1);
 assert.match(f[0].message,/1.2 s move with ease "none"/);
});
