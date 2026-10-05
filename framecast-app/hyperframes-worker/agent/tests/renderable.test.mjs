import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {inspect,makeRenderable} from '../renderable.mjs';
const run=promisify(execFile);

test('clips with sparse keyframes or 60 fps are re-encoded in place; good clips are left alone',async t=>{
 const dir=await mkdtemp(path.join(os.tmpdir(),'renderable-'));t.after(()=>rm(dir,{recursive:true,force:true}));
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','testsrc=s=160x120:r=60:d=4','-c:v','libx264','-g','240','-pix_fmt','yuv420p',dir+'/cut.mp4']);
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','testsrc=s=160x120:r=30:d=2','-c:v','libx264','-g','30','-pix_fmt','yuv420p',dir+'/ok.mp4']);
 await run('cp',[dir+'/cut.mp4',dir+'/unused.mp4']);
 await (await import('node:fs/promises')).writeFile(dir+'/index.html','<video src="cut.mp4"></video><video src="ok.mp4"></video>');
 assert.equal((await inspect(dir+'/cut.mp4')).fix,true);
 const changed=await makeRenderable(dir);
 assert.deepEqual(changed.map(c=>c.name),['cut.mp4'],'a clip the page does not use is left alone');
 const after=await inspect(dir+'/cut.mp4');
 assert.equal(after.fix,false);assert.ok(after.gap<=1.1);assert.ok(Math.abs(after.fps-30)<0.6);
});
