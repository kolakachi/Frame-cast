import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,cp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {skillCatalogue,readSkill} from '../skill-library.mjs';
const directory=new URL('../guidance/',import.meta.url).pathname;
test('upstream workflows are discoverable and paginated without loading all guidance',async()=>{
 const catalog=await skillCatalogue(directory);
 assert.equal(catalog.packages.find(p=>p.id==='hyperframes').commit,'385e9cf337c103d0918f9ea962c4e3859a2cc75f');
 assert.ok(catalog.packages.find(p=>p.id==='hyperframes').workflows.some(w=>w.name==='product-launch-video'));
 const first=await readSkill(directory,'skills/hyperframes/product-launch-video/SKILL.md');
 assert.match(first,/Next: .*#page=2/);assert.ok(first.length<13000);
 assert.notEqual(first,await readSkill(directory,'skills/hyperframes/product-launch-video/SKILL.md#page=2'));
 assert.match(await readSkill(directory,'skills/hyperframes/index'),/skills\/hyperframes\//);
});
test('unlisted paths and traversal are rejected; file integrity is checked',async t=>{
 for(const name of ['skills/../LICENSE','skills/hyperframes/../../secret','skills/no-package/index','skills/hyperframes/unknown.md'])await assert.rejects(readSkill(directory,name));
 const dir=await mkdtemp(tmpdir()+'/skill-test-');t.after(()=>rm(dir,{recursive:true,force:true}));
 await cp(directory+'upstream',dir+'/upstream',{recursive:true});
 await writeFile(dir+'/upstream/hyperframes/product-launch-video/SKILL.md','changed');
 await assert.rejects(readSkill(dir,'skills/hyperframes/product-launch-video/SKILL.md'),/hash mismatch/);
});

test('specialist workflows are recommended by task and declare unavailable runtimes',async()=>{
 const product=await skillCatalogue(directory,{route:'product'});
 assert.ok(product.recommendedWorkflows.includes('skills/iart-product/product-demo-video/SKILL.md'));
 assert.ok(!product.recommendedWorkflows.some(p=>p.includes('barty')));
 const speech=await skillCatalogue(directory,{route:'ad',speech:true});
 assert.ok(speech.recommendedWorkflows.includes('skills/barty/motion-broll/SKILL.md'));
 for(const route of ['editorial','footage','still','general']){
  assert.deepEqual((await skillCatalogue(directory,{route,speech:true})).recommendedWorkflows,[],route+': narration does not require motion graphics');
 }
 assert.equal(speech.packages.find(p=>p.id==='iart-ads').runtime,'remotion');
 for(const name of speech.recommendedWorkflows){
  const text=await readSkill(directory,name);assert.match(text,/NOT installed/);assert.match(text,/Approved colour treatment overrides example palettes/);
 }
 assert.match(await readSkill(directory,'skills/barty/motion-broll/engine/motion.js'),/motion-kit engine/);
 assert.match(await readSkill(directory,'skills/barty/LICENSE'),/MIT License/);
});
test('every vendored reference matches its recorded hash',async()=>{
 const {readFile}=await import('node:fs/promises');const {digest}=await import('../workspace.mjs');
 const m=JSON.parse(await readFile(directory+'upstream/manifest.json','utf8'));
 assert.equal(m.packages.length,6);
 for(const [name,record] of Object.entries(m.files))assert.equal(digest(await readFile(directory+'upstream/'+name)),record.sha256,name);
});
