import {spawnSync} from 'node:child_process';
import {mkdirSync,copyFileSync,readFileSync,writeFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
const cli='/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs';
const root='/tmp/proof';mkdirSync(root,{recursive:true});mkdirSync(process.env.HOME,{recursive:true});
const report={started:new Date().toISOString(),runs:[],limitations:['Synthetic fixtures, not real talking-head footage','No AI calls','Not a production sandbox certification']};
function run(command,args,cwd=root,label=command){const start=Date.now();const r=spawnSync(command,args,{cwd,encoding:'utf8',timeout:600000,maxBuffer:16*1024*1024});writeFileSync(`/output/${label}.log`,(r.stdout||'')+(r.stderr||''));report.runs.push({label,seconds:(Date.now()-start)/1000,status:r.status,error:r.error?.message});writeFileSync('/output/report.json',JSON.stringify(report,null,2));if(r.status!==0)throw Error(`${label} failed: ${(r.stderr||r.stdout||r.error).toString().slice(-3000)}`);return r.stdout;}
function hf(args,label){return run(process.execPath,[cli,...args],root,label);}
for(const f of ['index.html','product.svg'])copyFileSync('/opt/worker/fixtures/'+f,root+'/'+f);
copyFileSync('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');copyFileSync('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
const hash=f=>createHash('sha256').update(readFileSync(f)).digest('hex');
const original=readFileSync(root+'/index.html','utf8');
report.runtime={node:process.version,hyperframes:JSON.parse(readFileSync('/opt/worker/node_modules/hyperframes/package.json')).version,browser:run('/usr/bin/chromium',['--version'],root,'browser-version').trim(),ffmpeg:run('ffmpeg',['-version'],root,'ffmpeg-version').split('\n')[0],lock:hash('/opt/worker/package-lock.json'),font:hash(root+'/font.ttf')};
hf(['render','--help'],'render-help');hf(['check','--help'],'check-help');
hf(['check',root],'product-check');
function render(name){hf(['render',root,'--output',`/output/${name}.mp4`,'--fps','24','--workers','1','--quality','draft','--no-best-effort','--strict'],name);const probe=JSON.parse(run('ffprobe',['-v','error','-show_streams','-show_format','-of','json',`/output/${name}.mp4`],root,name+'-probe'));const video=probe.streams.find(s=>s.codec_type==='video');if(video.width!==1080||video.height!==1920||Math.abs(Number(probe.format.duration)-15)>.15)throw Error('Unexpected output dimensions/duration');report[name]={sha256:hash(`/output/${name}.mp4`),duration:probe.format.duration,audio:probe.streams.some(s=>s.codec_type==='audio')};run('ffmpeg',['-y','-ss','12','-i',`/output/${name}.mp4`,'-frames:v','1',`/output/${name}.png`],root,name+'-poster');}
render('product');
const mediaBefore=hash(root+'/product.svg');writeFileSync(root+'/index.html',original.replace('Explore the collection','Meet your new favourite'));render('cta-edit');if(hash(root+'/product.svg')!==mediaBefore)throw Error('Source modified');
run('ffmpeg',['-y','-f','lavfi','-i','testsrc2=size=540x960:rate=24','-f','lavfi','-i','sine=frequency=440:sample_rate=48000','-t','15','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac',root+'/source.mp4'],root,'make-source');
writeFileSync(root+'/index.html',original.replace('<section class="clip"','<video id="source" data-has-audio="true" src="./source.mp4" data-start="0" data-duration="15"></video><div id="shade"></div><section class="clip"').replace('Your product.<br>Your story.','Original footage.<br>New overlays.'));
hf(['check',root],'footage-check');render('footage');if(!report.footage.audio)throw Error('Original audio missing');report.sourceSha256=hash(root+'/source.mp4');writeFileSync('/output/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
