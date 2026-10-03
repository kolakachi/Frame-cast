import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
const root=new URL('../../',import.meta.url);
test('runtime preserves pinned Barty engine with only preview ownership changed',async()=>{
 const source=await readFile(new URL('agent/guidance/upstream/barty/motion-broll/engine/motion.js',root),'utf8');
 const runtime=await readFile(new URL('runtime/barty-motion.js',root),'utf8');
 assert.ok(runtime.includes('Source SHA256: '+createHash('sha256').update(source).digest('hex')));
 assert.equal(runtime.split('\n').slice(3).join('\n'),source.replace("const RENDER=location.search.includes('render');",'const RENDER=true;'));
 assert.equal(await readFile(new URL('runtime/barty-LICENSE',root),'utf8'),await readFile(new URL('agent/guidance/upstream/barty/LICENSE',root),'utf8'));
});

test('preflight recognizes the installed Barty timeline bridge without hiding missing runtime',async()=>{
 const {preflight}=await import('../preflight.mjs');
 const html=await readFile(new URL('fixtures/barty/index.html',root),'utf8');
 assert.ok(!preflight({path:'index.html',text:html,assets:[]}).warnings.some(w=>w.includes('registration')));
 assert.ok(preflight({path:'index.html',text:html.replace('src="barty-hyperframes.js"','src="missing.js"'),assets:[]}).warnings.some(w=>w.includes('registration')));
});
