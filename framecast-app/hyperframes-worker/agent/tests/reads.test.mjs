import test from 'node:test';import assert from 'node:assert/strict';
import {findings,RULES} from '../reads-check.mjs';
const frames=(n,fn)=>Array.from({length:n},(_,i)=>({t:Math.round(i*RULES.step*100)/100,...fn(i*RULES.step)}));
test('a headline that leaves before it can be read is flagged; one held long enough is not',()=>{
 const f=frames(151,t=>({texts:[...(t<1?[{id:1,x:0,y:0,text:'No camera or editing experience needed'}]:[]),...(t>=2&&t<6?[{id:2,x:0,y:0,text:'One video, four formats'}]:[])],things:[{id:9,x:0,y:0}]}));
 const codes=findings(f,15).filter(x=>x.code==='reading_time');
 assert.equal(codes.length,1);assert.match(codes[0].message,/No camera/);
});
test('the closing line only needs to be on screen at the end',()=>{
 const f=frames(151,t=>({texts:t>=14.5?[{id:3,x:0,y:0,text:'WyvStudio, turn any idea into a video'}]:[],things:[{id:9,x:0,y:0}]}));
 assert.equal(findings(f,15).filter(x=>x.code==='reading_time').length,0);
});
test('an empty stretch mid-video is a blank-frame finding; a filled one is not',()=>{
 const f=frames(151,t=>({texts:[],things:t>5&&t<5.6?[]:[{id:9,x:0,y:0}]}));
 const b=findings(f,15).filter(x=>x.code==='blank_frames');
 assert.equal(b.length,1);assert.ok(b[0].time>5&&b[0].time<5.2);
});
test('slow drift is one advisory finding listing its moments',()=>{
 const f=frames(151,t=>({texts:[],things:[{id:1,x:t*10,y:0},{id:2,x:t*10,y:50}]}));
 const d=findings(f,15).filter(x=>x.code==='slow_drift');
 assert.equal(d.length,1);assert.equal(d[0].severity,'warning');
});
