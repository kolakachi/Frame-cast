import test from 'node:test';import assert from 'node:assert/strict';
import {trimStale} from '../history-trim.mjs';

const big=s=>s+'x'.repeat(900);
const use=(id,name,input={})=>({role:'assistant',content:[{type:'tool_use',id,name,input}]});
const res=(id,content)=>({role:'user',content:[{type:'tool_result',tool_use_id:id,content}]});

test('a read of a file rewritten later and a superseded check shrink; current ones and the newest turns stay', () => {
 const t=[{role:'user',content:[{type:'text',text:'context'}]},
  use('r1','read',{path:'main.js'}),res('r1',big('main ')),
  use('r2','read',{path:'style.css'}),res('r2',big('style ')),
  use('c1','check'),res('c1',big('check one ')),
  use('w1','write',{path:'main.js',content:'x'}),res('w1','ok'),
  use('c2','check'),res('c2',big('check two ')),
  use('p1','preview'),res('p1',[{type:'text',text:big('frames ')}])];
 const saved=trimStale(t);
 assert.match(t[2].content[0].content,/superseded later; shortened]$/,'stale read');
 assert.equal(t[4].content[0].content.length,906,'style.css never rewritten');
 assert.match(t[6].content[0].content,/shortened]$/,'superseded check');
 assert.equal(t[10].content[0].content.length,910,'newest turns kept');
 assert.ok(saved>1000);
 assert.equal(trimStale(t),0,'idempotent');
});

test('turns before the frame batch are left alone', () => {
 const t=[{role:'user',content:[]},use('c1','check'),res('c1',big('a ')),use('c2','check'),res('c2',big('b ')),use('x','read',{path:'a'}),res('x','s'),use('y','read',{path:'b'}),res('y','s')];
 assert.equal(trimStale(t,{from:3}),0);
});
