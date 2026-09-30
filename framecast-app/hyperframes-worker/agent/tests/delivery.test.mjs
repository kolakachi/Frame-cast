import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {parseLoudness,measure,levelIfNeeded,summary} from '../delivery-checks.mjs';
const run=promisify(execFile);
const clip=async(volume)=>{const d=await mkdtemp(tmpdir()+'/lvl-'),f=d+'/v.mp4';
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','color=c=black:s=160x284:d=4','-f','lavfi','-i','sine=frequency=440:d=4','-shortest','-af','volume='+volume,'-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',f]);return f;};
test('parses the final EBU R128 summary',()=>{
 assert.deepEqual(parseLoudness('... I: -30.1 LUFS ...\n  Summary:\n    I:         -23.4 LUFS\n  True peak:\n    Peak:       -8.2 dBFS'),{lufs:-23.4,peak:-8.2});
 assert.deepEqual(parseLoudness('I: -inf LUFS'),{lufs:null,peak:null});
});
test('a quiet mix is levelled to social loudness and the picture is copied',async()=>{
 const f=await clip('0.02');const before=await measure(f);assert.ok(before.lufs<-25,'test clip is quiet: '+before.lufs);
 const r=await levelIfNeeded(f);assert.equal(r.status,'levelled');assert.ok(Math.abs(r.lufs+14)<=1.5,'after '+r.lufs);
});
test('silent-by-choice and correct levels are left alone',async()=>{
 assert.equal((await levelIfNeeded('/nonexistent',{silent:true})).status,'silent');
 const f=await clip('1');const m=await measure(f);
 const r=await levelIfNeeded(f);assert.ok(['ok','levelled'].includes(r.status),'status '+r.status+' at '+m.lufs);
});
test('summary is ok only when nothing needs attention',()=>{
 assert.equal(summary({safe_area:[],edges:[],contrast:[]},{status:'ok'}).ok,true);
 const s=summary({safe_area:[{selector:'#cta',time:13,message:'Collides with caption band'}],edges:[],contrast:[]},{status:'levelled',from:-24,lufs:-14});
 assert.equal(s.ok,false);assert.deepEqual(s.safe_area[0],{selector:'#cta',time:13,message:'Collides with caption band'});
});
