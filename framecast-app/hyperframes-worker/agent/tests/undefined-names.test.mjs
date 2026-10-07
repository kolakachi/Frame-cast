// Every name the worker's code uses is defined somewhere it can see. A name used outside the block that declares it
// only fails when that line runs: 0d813afc passed `resume` to the final checks from outside its block, and every
// build after it delivered with "The final checks could not run: resume is not defined" (GTM-1, 2026-10-08).
import test from 'node:test';import assert from 'node:assert/strict';
import {readFile,readdir} from 'node:fs/promises';import path from 'node:path';import {fileURLToPath} from 'node:url';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const acorn=require('acorn'),scope=require('eslint-scope');
const here=path.dirname(fileURLToPath(import.meta.url)),agent=path.dirname(here);
// Code that runs inside the page (functions handed to the browser) may use the page's globals.
const BROWSER=new Set(['window','document','navigator','getComputedStyle','requestAnimationFrame','cancelAnimationFrame','HTMLElement','HTMLMediaElement','HTMLVideoElement','HTMLAudioElement','HTMLImageElement','HTMLCanvasElement','Element','Node','NodeFilter','Image','location','gsap','MutationObserver','ResizeObserver','IntersectionObserver','getSelection','devicePixelRatio','innerWidth','innerHeight','DOMParser','XMLSerializer','CSS']);
export function undefinedNames(source){
 const ast=acorn.parse(source,{ecmaVersion:'latest',sourceType:'module',allowHashBang:true,ranges:true,locations:true});
 const manager=scope.analyze(ast,{ecmaVersion:2022,sourceType:'module'});
 const out=new Set();
 for(const ref of manager.globalScope.through){const n=ref.identifier.name;if(!(n in globalThis)&&!BROWSER.has(n))out.add(n+':'+ref.identifier.loc?.start.line);}
 return [...out];
}
test('the check finds a name used outside the block that declares it',()=>{
 assert.deepEqual(undefinedNames('function f(run){ if(run){ const resume=1; return g({resume}); } }\nfunction g(){}'),[]);
 assert.deepEqual(undefinedNames('export function f(run){\n if(run){ const resume=1; use(resume); }\n return {resume};\n}\nfunction use(){}').map(x=>x.split(':')[0]),['resume']);
});
test('every name in the worker code is defined',async()=>{
 const files=(await readdir(agent)).filter(f=>f.endsWith('.mjs'));
 const found={};
 for(const f of files){const names=undefinedNames(await readFile(path.join(agent,f),'utf8'));if(names.length)found[f]=names;}
 assert.deepEqual(found,{},'names used but never defined (file: name:line)');
});
