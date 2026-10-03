import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,readFile,writeFile,copyFile,readdir,rm,symlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {createHash} from 'node:crypto';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {inspectReference,validateInspection} from '../reference-inspection.mjs';
import {actionFromToolUse,parseAction} from '../protocol.mjs';
const exec=promisify(execFile);
test('inspection protocol refuses paths, unknown fields, unbounded scans and invalid crops',()=>{
 for(const input of ['../r.mp4','/r.mp4','https://example.com/r.mp4','r.mp4\n'])assert.throws(()=>validateInspection(input,{mode:'frames',times:[0]}));
 for(const params of [{mode:'frames',times:[NaN]},{mode:'frames',times:Array(9).fill(0)},{mode:'shots',start:0,end:31},{mode:'sequence',start:0,end:3,count:8},{mode:'sequence',start:0,end:1,count:99},{mode:'frames',times:[0],crop:{x:.9,y:0,width:.5,height:1}},{mode:'frames',times:[0],filter:'movie=/etc/passwd'}])assert.throws(()=>validateInspection('r.mp4',params));
 const action={type:'inspect_reference',input:'r.mp4',params:{mode:'sequence',start:1,end:1.2,count:6}};
 assert.deepEqual(parseAction(JSON.stringify(action)),action);
 assert.deepEqual(actionFromToolUse({name:'inspect_reference',input:{input:action.input,params:action.params}}),action);
});

test('real reference frames, cut candidates and close-ups stay outside renderable sources',async t=>{
 const dir=await mkdtemp(tmpdir()+'/reference-inspection-');t.after(()=>rm(dir,{recursive:true,force:true}));
 await mkdir(dir+'/inputs/reference',{recursive:true});await mkdir(dir+'/project');
 // Two contrasting shots, a known cut at 1 s, and a separate still with two colours.
 await exec('ffmpeg',['-y','-v','error','-f','lavfi','-i','color=red:s=160x120:r=24:d=1','-f','lavfi','-i','color=blue:s=160x120:r=24:d=1','-filter_complex','[0:v][1:v]concat=n=2:v=1:a=0[out]','-map','[out]','-c:v','libx264','-pix_fmt','yuv420p',dir+'/inputs/reference/ref.mp4']);
 await exec('ffmpeg',['-y','-v','error','-f','lavfi','-i','color=red:s=160x120,drawbox=x=80:y=0:w=80:h=120:color=blue:t=fill','-frames:v','1',dir+'/inputs/reference/still.png']);
 const manifest=await Promise.all([['ref.mp4','video'],['still.png','image']].map(async([name,asset_type])=>{const bytes=await readFile(dir+'/inputs/reference/'+name);return {name,path:'reference/'+name,purpose:'reference',asset_type,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')};}));
 await writeFile(dir+'/inputs/manifest.json',JSON.stringify(manifest));
 const inspect=(input,params)=>inspectReference({runDir:dir,request:{input,params}});
 let r=await inspect('ref.mp4',{mode:'frames',times:[.2,1.2]});
 assert.equal(r.renderable,false);assert.equal(r.fps,24);assert.equal(r.duration_seconds,2);
 assert.deepEqual(r.frames.map(f=>f.requested_seconds),[.2,1.2]);
 // Decode the actual returned image: the first frame is red, second is blue.
 const pixels=async file=>(await exec('ffmpeg',['-v','error','-i',file,'-vf','format=rgb24,scale=2:1','-frames:v','1','-f','rawvideo','-pix_fmt','rgb24','-'],{encoding:'buffer'})).stdout;
 const p=await pixels(dir+'/inspect_reference/contact-sheet.jpg');
 assert.ok(p[0]>p[2]*2&&p[5]>p[3]*2,'contact sheet contains both shots in timestamp order: '+[...p]);
 r=await inspect('ref.mp4',{mode:'shots',start:0,end:1.9});
 assert.ok(r.cut_candidates_seconds.some(s=>Math.abs(s-1)<.1));assert.equal(r.scan.fps,12);
 r=await inspect('ref.mp4',{mode:'sequence',start:.9,end:1.15,count:7});
 assert.equal(r.frames.length,7);assert.equal(r.columns,4);assert.equal(r.rows,2);
 r=await inspect('still.png',{mode:'frames',times:[0],crop:{x:.5,y:0,width:.5,height:1}});
 assert.deepEqual(r.crop_pixels,{x:80,y:0,width:80,height:120});
 const c=await pixels(dir+'/inspect_reference/contact-sheet.jpg');assert.ok(c[2]>c[0]*2,'crop really isolates the blue half');
 assert.deepEqual(await readdir(dir+'/project'),[],'reference frames never enter project');
 assert.deepEqual(await readdir(dir+'/inspect_reference'),['contact-sheet.jpg'],'temporary frames are cleaned');
 await assert.rejects(inspect('ref.mp4',{mode:'frames',times:[2]}),/before the end/);
 await assert.rejects(inspect('still.png',{mode:'sequence',start:0,end:1,count:2}),/image with frames/);
 await copyFile(dir+'/inputs/reference/still.png',dir+'/inputs/reference/not-listed.png');
 await assert.rejects(inspect('not-listed.png',{mode:'frames',times:[0]}),/staged reference/);
 manifest[1].purpose='source';await writeFile(dir+'/inputs/manifest.json',JSON.stringify(manifest));
 await assert.rejects(inspect('still.png',{mode:'frames',times:[0]}),/staged reference/);
 manifest[1].purpose='reference';await writeFile(dir+'/inputs/manifest.json',JSON.stringify(manifest));
 await rm(dir+'/inputs/reference/still.png');await symlink(dir+'/inputs/reference/not-listed.png',dir+'/inputs/reference/still.png');
 await assert.rejects(inspect('still.png',{mode:'frames',times:[0]}),/Invalid reference/);
 await rm(dir+'/inputs/reference/still.png');await writeFile(dir+'/inputs/reference/still.png',Buffer.alloc(manifest[1].bytes));
 await assert.rejects(inspect('still.png',{mode:'frames',times:[0]}),/integrity/);
});
