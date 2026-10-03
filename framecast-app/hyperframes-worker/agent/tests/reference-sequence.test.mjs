import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,mkdir,readFile,writeFile,readdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {createHash} from 'node:crypto';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {inspectReference,validateInspection} from '../reference-inspection.mjs';
import {actionFromToolUse} from '../protocol.mjs';
const exec=promisify(execFile),hash=b=>createHash('sha256').update(b).digest('hex');
async function fixture(t,filter="drawbox=color=lime:t=fill:enable='between(t,0.30,0.34)'",rate=24,offset=0){
 const dir=await mkdtemp(tmpdir()+'/reference-sequence-');t.after(()=>rm(dir,{recursive:true,force:true}));
 await mkdir(dir+'/inputs/reference',{recursive:true});await mkdir(dir+'/project');
 await exec('ffmpeg',['-y','-v','error','-f','lavfi','-i',`color=red:s=160x120:r=${rate}:d=4`,'-vf',filter,'-fps_mode','passthrough','-c:v','libx264','-pix_fmt','yuv420p','-output_ts_offset',String(offset),dir+'/inputs/reference/ref.mp4']);
 const bytes=await readFile(dir+'/inputs/reference/ref.mp4');
 await writeFile(dir+'/inputs/manifest.json',JSON.stringify([{name:'ref.mp4',path:'reference/ref.mp4',purpose:'reference',asset_type:'video',bytes:bytes.length,sha256:hash(bytes)}]));
 return {dir,inspect:params=>inspectReference({runDir:dir,request:{input:'ref.mp4',params}})};
}
test('consecutive inspection contract bounds pages, duration and crop without accepting arbitrary filters',()=>{
 const params={mode:'sequence',start:.15,end:.65,every_frame:true,page:1};
 assert.deepEqual(actionFromToolUse({name:'inspect_reference',input:{input:'ref.mp4',params}}),{type:'inspect_reference',input:'ref.mp4',params});
 for(const bad of [{count:8},{page:0},{page:16},{page:1.5},{every_frame:false},{every_frame:'true'},{end:3},{filter:'movie=/etc/passwd'},{crop:{x:0,y:0,width:2,height:1}}])assert.throws(()=>validateInspection('ref.mp4',{...params,...bad}));
 assert.throws(()=>validateInspection('ref.mp4',{mode:'sequence',start:0,end:1,count:8,page:1}));
});
test('consecutive pages catch a one-frame event, reuse extraction and bind cache to crop/source',async t=>{
 const {dir,inspect}=await fixture(t),params={mode:'sequence',start:.15,end:.65,every_frame:true};
 const first=await inspect(params);assert.equal(first.cache.hit,false);assert.equal(first.coverage.decoded_frames,12);assert.equal(first.coverage.pages,2);assert.equal(first.coverage.next_page,2);
 assert.equal(first.frames.length,8);assert.equal(first.renderable,false);assert.match(first.source_sha256,/^[a-f0-9]{64}$/);
 const second=await inspect({...params,page:2});assert.equal(second.cache.hit,true);assert.equal(second.cache.key,first.cache.key);assert.equal(second.frames.length,4);assert.equal(second.coverage.next_page,null);
 const times=[...first.frames,...second.frames].map(f=>f.source_seconds);
 for(let i=0;i<times.length;i++)assert.ok(Math.abs(times[i]-(4+i)/24)<.00001,'actual source timestamp '+i);
 const cached=dir+'/inspect_reference/cache/'+first.cache.key;
 const manifest=JSON.parse(await readFile(cached+'/manifest.json','utf8'));
 assert.ok(manifest.frames.some(f=>Math.abs(f.source_seconds-8/24)<.00001));
 const event=manifest.frames.find(f=>Math.abs(f.source_seconds-8/24)<.00001);
 const {stdout}=await exec('ffmpeg',['-v','error','-i',cached+'/'+event.name,'-vf','scale=1:1','-frames:v','1','-f','rawvideo','-pix_fmt','rgb24','-'],{encoding:'buffer'});
 assert.ok(stdout[1]>stdout[0]*2&&stdout[1]>stdout[2]*2,'green one-frame event survives actual extraction');
 const crop=await inspect({...params,crop:{x:.5,y:0,width:.5,height:1}});assert.notEqual(crop.cache.key,first.cache.key);assert.equal(crop.cache.hit,false);
 await assert.rejects(inspect({...params,page:3}),/page does not exist/);
 assert.deepEqual(await readdir(dir+'/project'),[]);
 assert.deepEqual((await readdir(dir+'/inspect_reference')).sort(),['cache','contact-sheet.jpg']);
 // Changed source bytes of identical length may not reuse trusted evidence.
 const source=dir+'/inputs/reference/ref.mp4',bytes=await readFile(source);bytes[bytes.length-1]^=1;await writeFile(source,bytes);
 await assert.rejects(inspect(params),/integrity/);
 // A corrupt cached frame is also refused rather than silently forwarded.
 bytes[bytes.length-1]^=1;await writeFile(source,bytes);await writeFile(cached+'/'+manifest.frames[0].name,Buffer.alloc(manifest.frames[0].bytes));
 await assert.rejects(inspect(params),/Cached reference frame integrity/);
});
test('late variable-rate interval uses decoded timestamps rather than frame-rate estimates',async t=>{
 const {dir,inspect}=await fixture(t,"select='eq(mod(n,5),0)+eq(mod(n,5),1)'");
 const result=await inspect({mode:'sequence',start:2.1,end:2.9,every_frame:true});
 const {stdout}=await exec('ffprobe',['-v','error','-select_streams','v:0','-show_entries','frame=best_effort_timestamp_time','-of','json',dir+'/inputs/reference/ref.mp4']);
 const expected=JSON.parse(stdout).frames.map(f=>Number(f.best_effort_timestamp_time)).filter(t=>t>=2.1&&t<2.9);
 assert.equal(result.coverage.decoded_frames,expected.length);
 for(const [i,f] of result.frames.entries())assert.ok(Math.abs(f.source_seconds-expected[i])<.00001);
 assert.ok(Math.abs((expected[1]-expected[0])-(expected[2]-expected[1]))>.05,'fixture genuinely has variable spacing');
});
test('oversized every-frame windows fail without leaving a partial cache',async t=>{
 const {dir,inspect}=await fixture(t,'null',90);
 await assert.rejects(inspect({mode:'sequence',start:0,end:2,every_frame:true}),/exceeds 120 frames/);
 assert.deepEqual(await readdir(dir+'/inspect_reference/cache'),[]);
});
test('media timestamp offset does not shift requested playback intervals',async t=>{
 const {inspect}=await fixture(t,'null',24,10);
 const r=await inspect({mode:'sequence',start:2.1,end:2.4,every_frame:true});
 assert.equal(r.timestamp_origin_seconds,10);assert.equal(r.coverage.decoded_frames,7);
 assert.equal(r.frames[0].source_seconds,2.125);
 const end=await inspect({mode:'sequence',start:3.9,end:4,every_frame:true});
 assert.equal(end.coverage.decoded_frames,2);assert.ok(end.frames.every(f=>f.source_seconds<4));
});
test('cache count and disk budgets reject additional extraction cleanly',async t=>{
 const {dir,inspect}=await fixture(t),base=dir+'/inspect_reference/cache';
 await mkdir(base,{recursive:true});
 for(let i=0;i<8;i++)await mkdir(base+'/'+String(i).padStart(64,'0'));
 await assert.rejects(inspect({mode:'sequence',start:0,end:.2,every_frame:true}),/cache limit reached/);
 for(const name of await readdir(base))await rm(base+'/'+name,{recursive:true});
 const occupied='a'.repeat(64);await mkdir(base+'/'+occupied);
 await writeFile(base+'/'+occupied+'/manifest.json',JSON.stringify({bytes:64*1024*1024}));
 await assert.rejects(inspect({mode:'sequence',start:0,end:.2,every_frame:true}),/exceeds 64 MiB/);
 assert.deepEqual(await readdir(base),[occupied],'temporary partial frames removed after rejection');
});
