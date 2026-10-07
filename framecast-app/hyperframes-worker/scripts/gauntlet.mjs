// The media gauntlet (docs/product/archive/create/create-verify-and-teach-scope.md, 1a): real-world, messy footage through the same
// render path production uses (agent/live-tool.mjs render), with nothing paid. Every fault found on 2026-10-05 has a
// stand-in here: a 5-minute silent 60 fps screen recording with keyframes 4.3 s apart, a provider clip with one
// keyframe, the builder's own sparse cuts, odd containers and sizes, six clips playing at once, a zoomed clip, captions,
// music, the sound pass and a 60 fps render. Asserts are run, not claimed; the report says pass or fail for each.
// Run: docker compose -f compose.local.yml run --rm smoke node scripts/gauntlet.mjs
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {mkdir,writeFile,readFile,rm,readdir} from 'node:fs/promises';
const run=promisify(execFile);
const ff=(args,timeout=600000)=>run('ffmpeg',['-hide_banner','-loglevel','error','-y',...args],{timeout,maxBuffer:16e6});
const results=[],started=Date.now();
const check=(name,ok,detail='')=>{results.push({name,ok:!!ok,detail});console.log((ok?'PASS ':'FAIL ')+name+(detail?' · '+detail:''));};
const id=n=>'gauntlet-'+n,dir=n=>'/output/live/'+id(n);

// Fixtures (generated, never stored).
const fx='/tmp/gauntlet-fixtures';await mkdir(fx,{recursive:true});
const t0=Date.now();
await ff(['-f','lavfi','-i','testsrc2=s=1280x728:r=60:d=300','-c:v','libx264','-preset','ultrafast','-g','258','-keyint_min','258','-sc_threshold','0','-pix_fmt','yuv420p',fx+'/screen.mp4']);
await ff(['-f','lavfi','-i','testsrc=s=720x1280:r=24:d=4','-f','lavfi','-i','sine=f=330:d=4','-c:v','libx264','-g','96','-keyint_min','96','-sc_threshold','0','-pix_fmt','yuv420p','-c:a','aac','-shortest',fx+'/omni.mp4']);
for(const [n,at] of [[1,8],[2,60],[3,150],[4,198],[5,280]])
 await ff(['-ss',String(at),'-i',fx+'/screen.mp4','-t','3','-c:v','libx264','-preset','ultrafast','-g','70','-keyint_min','70','-sc_threshold','0','-r','30','-pix_fmt','yuv420p',fx+`/cut${n}.mp4`]);
await ff(['-f','lavfi','-i','testsrc=s=641x481:r=25:d=4','-c:v','mpeg4','-q:v','5',fx+'/odd.mov']);
await ff(['-f','lavfi','-i','testsrc=s=480x270:r=30:d=4','-c:v','libvpx-vp9','-b:v','300k','-deadline','realtime',fx+'/clip.webm']);
await ff(['-f','lavfi','-i','testsrc=s=540x960:r=30:d=6','-f','lavfi','-i','anullsrc=r=44100:cl=stereo:d=3','-f','lavfi','-i','sine=f=440:d=3',
 '-filter_complex','[1][2]concat=n=2:v=0:a=1[a]','-map','0:v','-map','[a]','-c:v','libx264','-g','180','-pix_fmt','yuv420p','-c:a','aac',fx+'/late.mp4']);
await ff(['-f','lavfi','-i','sine=f=220:d=15','-af','volume=0.2',fx+'/music.wav']);
// Other containers become MP4 at intake (MediaLinkService), odd sizes rounded to even: the same conversion here.
for(const [src,out] of [['odd.mov','odd.mp4'],['clip.webm','webm.mp4']]){
 await ff(['-i',fx+'/'+src,'-vf','scale=trunc(iw/2)*2:trunc(ih/2)*2','-c:v','libx264','-preset','veryfast','-crf','20','-pix_fmt','yuv420p','-c:a','aac','-movflags','+faststart',fx+'/'+out]);
 await rm(fx+'/'+src);
}
check('fixtures generated',true,`${Math.round((Date.now()-t0)/1000)} s`);

// One composition that uses every fixture: six clips on screen at once (4–8 s), a zoomed clip, deep seeks into the
// long source, captions, music and motion-kit moves (the sound pass places their effects).
const clip=(cid,src,start,dur,style,extra='')=>`<video id="${cid}" class="clip" src="${src}" ${/has-audio/.test(extra)?'':'muted '}playsinline data-start="${start}" data-duration="${dur}" ${extra} style="position:absolute;object-fit:cover;${style}"></video>`;
const html=`<!doctype html><html><head><meta charset="utf-8"><script src="gsap.min.js"></script><script src="wyv-motion.js"></script>
<style>@font-face{font-family:Inter;src:url(inter.ttf)}*{margin:0}#main{position:relative;width:1080px;height:1920px;overflow:hidden;background:#111;font-family:Inter,sans-serif}
.cap{position:absolute;left:60px;right:60px;top:220px;color:#ffe14d;font-size:72px;font-weight:800;text-align:center;-webkit-text-stroke:3px #000}#cap2,#cap3{visibility:hidden}</style></head><body>
<div id="main" data-composition-id="main" data-width="1080" data-height="1920" data-duration="15">
<audio id="music" class="clip" src="music.wav" data-start="0" data-duration="15" data-volume="0.4" data-track-index="1"></audio>
${clip('v0','omni.mp4',0,4,'left:0;top:0;width:1080px;height:1920px','data-has-audio="true" data-volume="0.3"')}
${clip('v1','screen.mp4',4,4,'left:0;top:0;width:540px;height:640px','data-media-start="200"')}
${clip('v2','screen.mp4',4,4,'left:540px;top:0;width:540px;height:640px','data-media-start="250"')}
${clip('v3','cut1.mp4',4,3,'left:0;top:640px;width:540px;height:640px')}
${clip('v4','cut2.mp4',4,3,'left:540px;top:640px;width:540px;height:640px')}
${clip('v5','odd.mp4',4,4,'left:0;top:1280px;width:540px;height:640px')}
${clip('v6','webm.mp4',4,4,'left:540px;top:1280px;width:540px;height:640px')}
${clip('v7','cut3.mp4',8,3,'left:-1250px;top:-80px;width:3456px;height:1962px')}
${clip('v8','cut4.mp4',11,2,'left:0;top:400px;width:1080px;height:1100px')}
${clip('v9','late.mp4',13,2,'left:0;top:0;width:1080px;height:1920px','data-has-audio="true"')}
<div id="cap1" class="cap">POV: six clips at once</div><div id="cap2" class="cap">Zoomed in 1.8x</div><div id="cap3" class="cap">Deep in the recording</div>
</div><script>
const tl=gsap.timeline({paused:true});
WM.pop(tl,'#cap1',0.3);tl.set('#cap1',{autoAlpha:0},7.9);
WM.pop(tl,'#cap2',8.2);tl.set('#cap2',{autoAlpha:0},10.9);
WM.stamp(tl,'#cap3',11.2);
tl.to({},{duration:15},0);
window.__timelines={main:tl};
</script></body></html>`;

async function stage(n,settings){
 await rm(dir(n),{recursive:true,force:true});await mkdir(dir(n)+'/project',{recursive:true});
 for(const f of await readdir(fx))await run('cp',[fx+'/'+f,dir(n)+'/project/'+f]);
 await writeFile(dir(n)+'/project/index.html',html);
 await writeFile(dir(n)+'/output-settings.json',JSON.stringify({aspect_ratio:'9:16',duration_seconds:15,...settings}));
}
// Peak processes and memory of the sandbox while a render runs (cgroup v2), sampled beside it.
// Memory that cannot be given back under pressure: programs (anon) and the in-memory /tmp (shmem); file cache is
// reclaimable and not counted.
const held=async()=>{const t=await readFile('/sys/fs/cgroup/memory.stat','utf8').catch(()=>'');const g=k=>Number((t.match(new RegExp('^'+k+' (\\d+)','m'))||[])[1]||0);return g('anon')+g('shmem');};
async function render(n){
 const peak={pids:0,mem:0};
 const read=async f=>Number((await readFile(f,'utf8').catch(()=>'0')).trim().split(/\s/)[0])||0;
 const timer=setInterval(async()=>{peak.pids=Math.max(peak.pids,await read('/sys/fs/cgroup/pids.current'));peak.mem=Math.max(peak.mem,await held());},250);
 const s=Date.now();
 try{await run(process.execPath,['agent/live-tool.mjs',id(n),'render'],{cwd:'/opt/worker',timeout:1200000,maxBuffer:32e6});}catch(e){/* the result file says what happened */}
 clearInterval(timer);
 const result=JSON.parse(await readFile(dir(n)+'/render/result.json','utf8').catch(()=>'{}'));
 return {result,seconds:Math.round((Date.now()-s)/1000),peak,file:result.directory&&result.artifact?result.directory+'/'+result.artifact:null};
}
const probe=async f=>JSON.parse((await run('ffprobe',['-v','error','-show_streams','-show_format','-of','json',f])).stdout);
const frameAt=async(f,t,out)=>{await ff(['-ss',String(t),'-i',f,'-frames:v','1',out]);return out;};
const cells=async f=>(await run('ffmpeg',['-hide_banner','-loglevel','error','-i',f,'-vf','scale=6:8:flags=area','-f','rawvideo','-pix_fmt','rgb24','-'],{encoding:'buffer',maxBuffer:1e6})).stdout;
const cellDiff=async(a,b)=>{const x=await cells(a),y=await cells(b);let m=0;for(let i=0;i<Math.min(x.length,y.length);i++)m=Math.max(m,Math.abs(x[i]-y[i]));return m;};
const ssim=async(a,b)=>{const {stderr}=await run('ffmpeg',['-hide_banner','-i',a,'-i',b,'-lavfi','[0][1]scale2ref[x][y];[x][y]ssim','-f','null','-']);return Number((stderr.match(/All:([\d.]+)/)||[])[1]||0);};

// 1. The render, twice, at 24 fps.
await stage(1,{});await stage(2,{});
const a=await render(1);
check('render ready',a.result.status==='ready',a.result.error||`${a.seconds} s`);
const limits={pids:await (async()=>{const v=(await readFile('/sys/fs/cgroup/pids.max','utf8').catch(()=>'max')).trim();return v==='max'?Infinity:Number(v);})(),
 mem:await (async()=>{const v=(await readFile('/sys/fs/cgroup/memory.max','utf8').catch(()=>'max')).trim();return v==='max'?Infinity:Number(v);})()};
check('process headroom (under 70%)',a.peak.pids<0.7*limits.pids,`peak ${a.peak.pids} of ${limits.pids}`);
check('memory headroom (under 70%)',a.peak.mem<0.7*limits.mem,`peak ${(a.peak.mem/2**30).toFixed(2)} of ${(limits.mem/2**30).toFixed(2)} GB`);
if(a.file){
 const p=await probe(a.file),v=p.streams.find(s=>s.codec_type==='video');
 check('size, length and frame rate',v.width===1080&&v.height===1920&&Math.abs(Number(p.format.duration)-15)<0.2&&v.r_frame_rate==='24/1',`${v.width}x${v.height} ${Number(p.format.duration).toFixed(2)} s ${v.r_frame_rate}`);
 check('has sound',p.streams.some(s=>s.codec_type==='audio'));
 const sounds=JSON.parse(await readFile(dir(1)+'/render/sounds.json','utf8').catch(()=>'{}'));
 check('sound pass placed effects',(sounds.placed||[]).length>=3,`${(sounds.placed||[]).length} placed`);
 const ran=JSON.parse(await readFile(dir(1)+'/render/moves.json','utf8').catch(()=>'[]'));
 check('moves that ran recorded',Array.isArray(ran)&&['pop','stamp'].every(m=>ran.some(r=>r.move===m)),JSON.stringify(ran));
 const prepped=JSON.parse(await readFile(dir(1)+'/render/renderable.json','utf8').catch(()=>'[]'));
 check('render prep fixed the sparse clips',['screen.mp4','omni.mp4','cut1.mp4'].every(n=>prepped.some?.(c=>c.name===n)),prepped.map?.(c=>c.name).join(', '));
 const b=await render(2);
 check('second render ready',b.result.status==='ready',b.result.error||`${b.seconds} s`);
 if(b.file)for(const t of [2,6,9.5]){
  const s=await ssim(await frameAt(a.file,t,`/tmp/g-a-${t}.png`),await frameAt(b.file,t,`/tmp/g-b-${t}.png`));
  check(`deterministic at ${t} s`,s>=0.995,`SSIM ${s.toFixed(4)}`);
 }
 // Preview equals export: the HyperFrames snapshot (the builder's preview) against the exported frame.
 try{await run(process.execPath,['agent/live-tool.mjs',id(1),'snapshot','2,6,9.5'],{cwd:'/opt/worker',timeout:300000,maxBuffer:32e6});}catch{/* checked below */}
 const snaps=(await readdir(dir(1)+'/snapshot').catch(()=>[])).filter(f=>/^frame-\d+-at-[\d.]+s\.png$/.test(f));
 check('preview snapshots made',snaps.length===3,snaps.join(', '));
 for(const f of snaps){
  // Region by region (a 6x8 grid of average colours): the preview scales clips in the browser and the export scales
  // extracted frames, so pixels differ on sharp test patterns; a missing or misplaced element moves a cell by 250.
  const t=Number(f.match(/at-([\d.]+)s/)[1]),e=await frameAt(a.file,t,`/tmp/g-e-${t}.png`),d=await cellDiff(dir(1)+'/snapshot/'+f,e);
  check(`preview matches export at ${t} s`,d<=60,`worst cell ${d} (SSIM ${(await ssim(dir(1)+'/snapshot/'+f,e)).toFixed(3)})`);
 }
}
// 2. The same at 60 fps.
await stage(3,{frame_rate:60});
const c=await render(3);
check('60 fps render ready',c.result.status==='ready',c.result.error||`${c.seconds} s`);
if(c.file){const v=(await probe(c.file)).streams.find(s=>s.codec_type==='video');check('60 fps output',v.r_frame_rate==='60/1',v.r_frame_rate);}

const failed=results.filter(r=>!r.ok);
const report={finished:new Date().toISOString(),seconds:Math.round((Date.now()-started)/1000),passed:results.length-failed.length,failed:failed.length,results};
await writeFile('/output/gauntlet-report.json',JSON.stringify(report,null,1));
console.log(`\n${report.passed} passed, ${report.failed} failed in ${report.seconds} s`);
process.exitCode=failed.length?1:0;
