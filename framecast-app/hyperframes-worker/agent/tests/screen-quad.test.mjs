import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {mediaOp} from '../media-tool.mjs';
const run=promisify(execFile);

test('a blank glowing screen is found as four corners, and a moving one is not stable', async () => {
 const dir=await mkdtemp(tmpdir()+'/screen-');
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','color=c=0x141420:s=640x360:d=2','-vf','drawbox=x=200:y=90:w=240:h=150:color=0xf0f0ff:t=fill','-pix_fmt','yuv420p',dir+'/still.mp4']);
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','color=c=0x141420:s=640x360:d=2','-f','lavfi','-i','color=c=0xf0f0ff:s=240x150:d=2','-filter_complex',"[0][1]overlay=x='100+t*120':y=90",'-pix_fmt','yuv420p',dir+'/moving.mp4']);
 const still=(await mediaOp({projectDir:dir,request:{op:'screen',input:'still.mp4',params:{}},nextName:()=>'x'})).screen;
 assert.equal(still.found,true);assert.equal(still.stable,true);
 const [[x0,y0],,[x2,y2]]=still.quad;
 assert.ok(Math.abs(x0-200)<8&&Math.abs(y0-90)<8&&Math.abs(x2-440)<8&&Math.abs(y2-240)<8,JSON.stringify(still.quad));
 assert.ok(still.confidence>0.9);
 const moving=(await mediaOp({projectDir:dir,request:{op:'screen',input:'moving.mp4',params:{}},nextName:()=>'x'})).screen;
 assert.equal(moving.stable,false,'a camera move means no still pin');
});
