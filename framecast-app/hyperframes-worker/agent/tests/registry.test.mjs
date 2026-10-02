import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,readFile,readdir} from 'node:fs/promises';import {tmpdir} from 'node:os';import path from 'node:path';
import {rewriteHtml,headerDoc,mountKind,itemDeps,vendorRegistry} from '../../scripts/vendor-registry.mjs';
import {loadCatalog,searchCatalog,catalogItem,referencedItems,stageRegistryFiles} from '../registry.mjs';

const BLOCK=`<!-- hyperframes-registry-item: walker -->
<!--
  walker: a mascot walks in and points.
  Variables: title (string).
-->
<!doctype html><html data-composition-id="walker" data-composition-variables='[{"id":"title","type":"string","label":"Title","default":"Hi"}]'><head>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Outfit:wght@900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/MotionPathPlugin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lottie-web@5.12.2/build/player/lottie_light.min.js"></script>
<style>@import url("https://fonts.googleapis.com/css2?family=Bebas+Neue&display=block");</style></head>
<body><div id="root" data-composition-id="walker" data-width="1920" data-height="1080" data-duration="7.5"></div></body></html>`;
const SNIPPET=`<!--\n  grain: paste the overlay div.\n-->\n<div class="grain"></div><style>.grain{}</style>`;
const THREE=`<html><head><script src="https://cdn.jsdelivr.net/npm/three@0.147.0/build/three.min.js"></script></head></html>`;

async function fixture(){
 const src=await mkdtemp(tmpdir()+'/reg-');
 const item=async(kind,name,files,meta)=>{const d=path.join(src,kind,name);await mkdir(d+'/assets',{recursive:true});for(const [p,t] of Object.entries(files))await writeFile(path.join(d,p),t);await writeFile(d+'/registry-item.json',JSON.stringify({name,type:'hyperframes:'+kind.slice(0,-1),title:meta.title,description:meta.description,tags:meta.tags,dimensions:{width:1920,height:1080},duration:meta.duration??5,variables:meta.variables||[],files:Object.keys(files).map(p=>({path:p,target:(kind==='blocks'?'compositions/'+name+'/':'compositions/components/')+p,type:p.endsWith('.html')?'hyperframes:composition':'hyperframes:asset'}))}));};
 await item('blocks','walker',{'walker.html':BLOCK,'assets/mascot.json':'{"v":"5"}'},{title:'Lottie Character Walk',description:'A mascot walks in and points at a card',tags:['character','mascot','explainer'],duration:7.5,variables:[{id:'title',type:'string',label:'Title',default:'Hi',maxLength:28}]});
 await item('components','grain-overlay',{'grain-overlay.html':SNIPPET},{title:'Grain Overlay',description:'Film grain texture overlay',tags:['texture','overlay']});
 await item('blocks','globe',{'globe.html':THREE},{title:'Globe',description:'A 3D globe',tags:['3d']});
 return src;
}
test('rewriteHtml points CDN scripts at local files and turns Google Fonts into shipped @font-face rules',()=>{
 const r=rewriteHtml(BLOCK);
 assert.ok(!/cdn\.jsdelivr|fonts\.googleapis|fonts\.gstatic/.test(r.html),'no network references remain');
 assert.match(r.html,/src="\/gsap\.min\.js"/);assert.match(r.html,/src="\/gsap\/MotionPathPlugin\.min\.js"/);assert.match(r.html,/src="\/lottie_light\.min\.js"/);
 assert.match(r.html,/@font-face\{font-family:"Inter";src:url\(\/inter\.ttf\);font-weight:100 900\}/);
 assert.match(r.html,/font-family:"Bebas Neue"/,'an @import font is covered too');
 assert.deepEqual(r.notes,['fonts not shipped (fallback): Outfit']);
 assert.deepEqual(itemDeps(BLOCK),['gsap','lottie-web']);
 assert.match(headerDoc(BLOCK),/^walker: a mascot walks in/);assert.ok(!/registry-item/.test(headerDoc(BLOCK)));
 assert.equal(mountKind('block',BLOCK),'sub-composition');assert.equal(mountKind('component',SNIPPET),'snippet');assert.equal(mountKind('component','<template><div data-composition-id="x"></div></template>'),'sub-composition');
});
test('vendorRegistry copies supported items with assets, excludes items needing libraries we do not ship, and writes the catalogue',async()=>{
 const src=await fixture(),out=path.join(src,'out'),cat=path.join(src,'catalog.json');
 const r=await vendorRegistry({source:src,out,catalog:cat});
 assert.deepEqual(r.items.map(i=>i.name),['walker','grain-overlay']);
 assert.deepEqual(r.excluded,[{name:'globe',reason:'needs three'}]);
 assert.equal(await readFile(path.join(out,'compositions/walker/assets/mascot.json'),'utf8'),'{"v":"5"}');
 assert.match(await readFile(path.join(out,'compositions/walker/walker.html'),'utf8'),/\/gsap\.min\.js/);
 const items=await loadCatalog(cat);
 const w=items.find(i=>i.name==='walker');
 assert.equal(w.entry,'compositions/walker/walker.html');assert.equal(w.mount,'sub-composition');assert.deepEqual(w.variables,[{id:'title',type:'string',label:'Title',default:'Hi',maxLength:28}]);
 assert.deepEqual(w.deps,['gsap','lottie-web']);assert.match(w.doc,/mascot walks in/);
 const manifest=JSON.parse(await readFile(path.join(out,'manifest.json'),'utf8'));
 assert.equal(manifest.items,2);assert.ok(manifest.files['compositions/walker/walker.html']);
 // search and staging
 assert.deepEqual(searchCatalog(items,{query:'mascot pointing'}).map(i=>i.name),['walker']);
 assert.deepEqual(searchCatalog(items,{tag:'overlay'}).map(i=>i.name),['grain-overlay']);
 assert.deepEqual(searchCatalog(items,{query:'grain',type:'block'}),[]);
 assert.equal(catalogItem(items,'nope'),null);
 const html='<div data-composition-src="compositions/walker/walker.html"></div><div data-composition-src="compositions/ghost.html"></div>';
 assert.deepEqual(referencedItems(html,items).map(r=>r.ref),['walker','compositions/ghost.html']);
 const root=path.join(src,'staged');await mkdir(root);await writeFile(path.join(src,'lottie.js'),'lottie');
 const s=await stageRegistryFiles({root,registryRoot:out,html,items,libs:{'lottie_light.min.js':path.join(src,'lottie.js'),'gsap/x.js':path.join(src,'missing.js')}});
 assert.deepEqual(s.staged,['compositions/walker/walker.html','compositions/walker/assets/mascot.json']);
 assert.deepEqual(s.missing,['compositions/ghost.html']);
 assert.deepEqual((await readdir(path.join(root,'compositions/walker'))).sort(),['assets','walker.html']);
 assert.equal(await readFile(path.join(root,'lottie_light.min.js'),'utf8'),'lottie');
});
