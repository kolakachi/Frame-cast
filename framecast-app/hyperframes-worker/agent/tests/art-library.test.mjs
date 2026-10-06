import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,readFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {loadArt,searchArt,useArt} from '../art-library.mjs';

async function fixture(){
  const dir=await mkdtemp(path.join(os.tmpdir(),'art-'));
  await mkdir(path.join(dir,'lucide'));await mkdir(path.join(dir,'fluent3d'));
  await writeFile(path.join(dir,'lucide','coins.svg'),'<!-- @license --><svg stroke="currentColor"><circle r="4"/></svg>');
  await writeFile(path.join(dir,'fluent3d','coin.png'),Buffer.from([137,80,78,71]));
  await writeFile(path.join(dir,'index.json'),JSON.stringify({packs:{lucide:{license:'ISC'},fluent3d:{license:'MIT'}},items:[
    {id:'lucide:coins',pack:'lucide',style:'line',kind:'svg',file:'lucide/coins.svg',words:['coins','money','cash']},
    {id:'lucide:brain',pack:'lucide',style:'line',kind:'svg',file:'lucide/brain.svg',words:['brain','mind']},
    {id:'fluent3d:coin',pack:'fluent3d',style:'3d',kind:'png',file:'fluent3d/coin.png',glyph:'🪙',words:['coin','money','gold']}]}));
  return dir;
}

test('art is found by its words, and a style narrows it',async()=>{
  const art=await loadArt(await fixture());
  assert.deepEqual(searchArt(art,{query:'money'}).map(r=>r.id).sort(),['fluent3d:coin','lucide:coins']);
  assert.deepEqual(searchArt(art,{query:'coin 3d'}).map(r=>r.id),['fluent3d:coin']);
  assert.equal(searchArt(art,{query:'brain'})[0].id,'lucide:brain');
  assert.deepEqual(searchArt(art,{query:'spaceship'}),[]);
});

test('an icon comes back as inline svg; a 3d image is copied into the project with its hash',async()=>{
  const dir=await fixture(),art=await loadArt(dir),project=await mkdtemp(path.join(os.tmpdir(),'proj-'));
  const icon=await useArt(art,'lucide:coins',project);
  assert.equal(icon.kind,'svg');assert.match(icon.svg,/^<svg stroke="currentColor">/);assert.equal(icon.license,'ISC');
  const png=await useArt(art,'fluent3d:coin',project);
  assert.equal(png.file.path,'art-fluent3d-coin.png');
  assert.deepEqual([...await readFile(path.join(project,'art-fluent3d-coin.png'))],[137,80,78,71]);
  await assert.rejects(useArt(art,'lucide:nope',project),/search first/);
});
