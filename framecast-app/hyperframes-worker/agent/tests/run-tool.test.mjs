import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile,mkdir,readdir} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {runOp} from '../run-tool.mjs';import {checkRunArgs,validateAction} from '../protocol.mjs';
async function runDir(){
 const dir=await mkdtemp(tmpdir()+'/run-');await mkdir(dir+'/project');await mkdir(dir+'/work');
 await writeFile(dir+'/project/index.html','<html>v1</html>');await writeFile(dir+'/project/clip.wav','RIFF');
 return dir;
}
test('argv rules: no shell, no absolute paths, no .., no URLs, no node flags, hyperframes allowlist',()=>{
 checkRunArgs('ffmpeg',['-y','-i','project/clip.mp4','-vf','scale=1080:-2','-ss','00:00:01','-filter_complex','[0:v]hue=s=0[o]','out.png']);
 for(const [c,a] of [['ffmpeg',['-i','/etc/passwd']],['ffmpeg',['-i','../other/x']],['ffmpeg',['-i','file:project/x']],['ffmpeg',['-i','http://x']],['node',['--allow-child-process','a.mjs']],['node',['-e','1']],['hyperframes',['publish']],['sh',['-c','ls']],['ffprobe',['a\nb']]])
  assert.throws(()=>checkRunArgs(c,a),c+' '+a.join(' '));
 assert.equal(validateAction({type:'run',cmd:'fc-list',args:[]}).cmd,'fc-list');
 assert.throws(()=>validateAction({type:'run',cmd:'ffmpeg',args:'-i x'}));
});
test('a node script runs under the permission model: it can write project media, cannot change protected files, cannot spawn',async()=>{
 const dir=await runDir();
 await writeFile(dir+'/work/gen.mjs',`import {writeFileSync} from 'node:fs';
writeFileSync('project/sprite.png',Buffer.from('89504e470d0a1a0a','hex'));
writeFileSync('project/index.html','<html>hacked</html>');
writeFileSync('project/notes.txt','x');
writeFileSync('scratch.json','{}');
let spawned='no';try{const {execSync}=await import('node:child_process');execSync('id');spawned='yes';}catch(e){spawned=e.code||'blocked';}
let outside='no';try{const {writeFileSync:w}=await import('node:fs');w('${dir}/escaped.txt','x');outside='yes';}catch(e){outside=e.code||'blocked';}
console.log(JSON.stringify({spawned,outside}));`);
 const r=await runOp({runDir:dir,request:{cmd:'node',args:['gen.mjs']}});
 const out=JSON.parse(r.stdout.trim().split('\n').at(-1));
 assert.equal(out.spawned,'ERR_ACCESS_DENIED','child processes are denied');
 assert.equal(out.outside,'ERR_ACCESS_DENIED','writes outside the run are denied');
 assert.deepEqual(r.outputs.map(o=>o.path),['sprite.png']);
 assert.deepEqual(r.restored,['index.html']);assert.equal(r.ok,false);assert.match(r.error,/never changed/);
 assert.equal(await readFile(dir+'/project/index.html','utf8'),'<html>v1</html>');
 assert.deepEqual(r.removed,['notes.txt']);
 assert.ok(r.scratch.includes('scratch.json')&&!r.scratch.includes('project'));
 assert.deepEqual((await readdir(dir+'/project')).sort(),['clip.wav','index.html','sprite.png']);
});
test('a deleted protected file comes back; ffmpeg makes a project file from nothing',async()=>{
 const dir=await runDir();
 await writeFile(dir+'/work/rm.mjs',`import {unlinkSync} from 'node:fs';unlinkSync('project/clip.wav');`);
 const r=await runOp({runDir:dir,request:{cmd:'node',args:['rm.mjs']}});
 assert.deepEqual(r.restored,['clip.wav']);assert.equal(await readFile(dir+'/project/clip.wav','utf8'),'RIFF');
 const f=await runOp({runDir:dir,request:{cmd:'ffmpeg',args:['-y','-f','lavfi','-i','color=c=red:s=64x64','-frames:v','1','project/field.png']}});
 assert.equal(f.ok,true,f.stderr);assert.equal(f.outputs[0].path,'field.png');assert.match(f.outputs[0].sha256,/^[a-f0-9]{64}$/);
 const bad=await runOp({runDir:dir,request:{cmd:'ffprobe',args:['project/missing.mp4']}});
 assert.equal(bad.ok,false);assert.notEqual(bad.exit,0);
});
test('an ffmpeg command that stops making progress is stopped early, not at the time limit',async()=>{
 const dir=await runDir();
 const t=Date.now();
 // Endless silent input, no logging, output thrown away: nothing prints and no file grows.
 const r=await runOp({runDir:dir,request:{cmd:'ffmpeg',args:['-loglevel','quiet','-f','lavfi','-i','anullsrc=r=8000','-f','null','-']},timeoutMs:60000,stallMs:2000});
 assert.equal(r.exit,124);assert.match(r.stderr,/no progress for 2 s; the command had stalled/);
 assert.ok(Date.now()-t<20000,'stopped well before the 60 s limit');
});
test('a quiet ffmpeg command that keeps writing its output is not stopped',async()=>{
 const dir=await runDir();
 const r=await runOp({runDir:dir,request:{cmd:'ffmpeg',args:['-y','-loglevel','quiet','-re','-f','lavfi','-i','sine=frequency=440:duration=4','project/tone.wav']},timeoutMs:60000,stallMs:2500});
 assert.equal(r.exit,0,r.stderr);
});
