import test from 'node:test';import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';import {mkdtemp,writeFile,copyFile,rm} from 'node:fs/promises';
// Runs in the sandbox image, where the browser and the HyperFrames runtime live.
const inImage=existsSync('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js');
test('close inspection crops the biggest text and pictures and frames an action',{skip:!inImage&&'needs the sandbox image'},async()=>{
 const {detailSheet}=await import('../detail-review.mjs');
 const root=await mkdtemp('/tmp/detail-root-'),out=await mkdtemp('/tmp/detail-out-');
 try{
  await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
  await writeFile(root+'/index.html',`<!doctype html><html><head><style>body{margin:0;background:#fff}h1{font:700 120px sans-serif;position:absolute;left:100px;top:200px;margin:0}#box{position:absolute;left:900px;top:500px;width:300px;height:300px;background:#f60}</style></head><body>
<div id="root" data-composition-id="main" data-width="1920" data-height="1080" data-duration="4"><h1 id="h" class="clip" data-start="0" data-duration="4">Step 1</h1><div id="box" class="clip" data-start="0" data-duration="4"></div></div>
<script src="gsap.min.js"></script><script>const tl=gsap.timeline({paused:true});tl.fromTo('#box',{x:0},{x:400,duration:2},1);window.__timelines={main:tl};</script></body></html>`);
  const r=await detailSheet({root,width:1920,height:1080,times:[0.5],sequences:[[1,2,'box slides']],out});
  assert.equal(r.ok,true,JSON.stringify(r));
  assert.match(r.cells[0].what,/text "Step 1"/);
  assert.ok(r.cells.filter(c=>/box slides/.test(c.what)).length>=2,'frames across the action');
  assert.ok(existsSync(out+'/detail.jpg'));
 }finally{await rm(root,{recursive:true,force:true});await rm(out,{recursive:true,force:true});}
});
