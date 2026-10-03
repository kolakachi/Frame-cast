// Run in the existing network-disabled proof image with agent/ mounted from source.
// Calls the real Hyperframes snapshot/strip path; no provider, account or customer data.
import {mkdir,writeFile,readFile,readdir} from 'node:fs/promises';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {createRequire} from 'node:module';
import assert from 'node:assert/strict';
const exec=promisify(execFile),id='review-sampling-proof',dir='/output/live/'+id;
await mkdir(dir+'/project',{recursive:true});await mkdir(dir+'/strip',{recursive:true});
await writeFile(dir+'/project/index.html',`<!doctype html><html><head><script src="gsap.min.js"></script><style>body{margin:0}#main{width:1920px;height:1080px;background:#f02030;position:relative}h1{position:absolute;left:160px;top:350px;font:100px Arial;color:white}#ending{opacity:0}</style></head><body><main id="main" data-composition-id="main" data-width="1920" data-height="1080" data-duration="30"><h1 id="opening">Opening held for reading</h1><h1 id="ending">Closing CTA after 20 seconds</h1></main><script>const tl=gsap.timeline({paused:true});tl.set('#opening',{opacity:0},20).set('#ending',{opacity:1},20).set('#main',{backgroundColor:'#2060f0'},20).to({}, {duration:10},20);window.__timelines={main:tl};</script></body></html>`);
await writeFile(dir+'/output-settings.json',JSON.stringify({aspect_ratio:'16:9',duration_seconds:30}));
// Prove a second review cannot retain a first-review timestamp.
await writeFile(dir+'/strip/frame-0001-at-0.25s.png','stale marker');
await exec(process.execPath,['/opt/worker/agent/live-tool.mjs',id,'strip'],{timeout:180000,maxBuffer:2000000});
const result=JSON.parse(await readFile(dir+'/strip/result.json','utf8'));
assert.equal(result.ok,true);assert.equal(result.frames,30);assert.equal(result.coverage.times[0],.5);assert.equal(result.coverage.times.at(-1),29.5);
const shots=(await readdir(dir+'/strip')).filter(n=>/^frame-\d+-at-[0-9.]+s\.png$/.test(n)).sort();
assert.equal(shots.length,30);assert.ok(!shots.some(n=>n.includes('0.25s')));
const sharp=createRequire('/opt/worker/node_modules/hyperframes/package.json')('sharp');
const pixel=async n=>[...await sharp(dir+'/strip/'+n).extract({left:10,top:10,width:1,height:1}).removeAlpha().raw().toBuffer()];
const first=await pixel(shots[0]),last=await pixel(shots.at(-1));
assert.deepEqual(first,[240,32,48]);assert.deepEqual(last,[32,96,240]);
await writeFile('/output/verification.json',JSON.stringify({passed:true,coverage:result.coverage,first_pixel:first,last_pixel:last,stale_frames_removed:true,provider_calls:0},null,2));
console.log(JSON.stringify({passed:true,frames:result.frames,first:result.coverage.times[0],last:result.coverage.times.at(-1),provider_calls:0}));
