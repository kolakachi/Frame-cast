import {spawnSync} from 'node:child_process';
import {mkdirSync,copyFileSync,readFileSync,writeFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
const root='/tmp/real-proof', out='/output/real-media', cli='/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs';
mkdirSync(root,{recursive:true});mkdirSync(out,{recursive:true});mkdirSync(process.env.HOME,{recursive:true});
const report={started:new Date().toISOString(),runs:[],limitations:['No transcription or claim verification','Presenter bottle differs from product photo','10s demo: original clip followed by photo, no footage loop','No paid AI calls']};
const hash=f=>createHash('sha256').update(readFileSync(f)).digest('hex');
function run(cmd,args,label){const start=Date.now();const r=spawnSync(cmd,args,{cwd:root,encoding:'utf8',timeout:600000,maxBuffer:16*1024*1024});writeFileSync(`${out}/${label}.log`,(r.stdout||'')+(r.stderr||''));report.runs.push({label,seconds:(Date.now()-start)/1000,status:r.status});writeFileSync(`${out}/report.json`,JSON.stringify(report,null,2));if(r.status!==0)throw Error(`${label}: ${(r.stderr||r.stdout||r.error).toString().slice(-2000)}`);return r.stdout;}
function hf(args,label){return run(process.execPath,[cli,...args],label);}
for(const f of ['presenter.mp4','product.png'])copyFileSync('/output/real-inputs/'+f,root+'/'+f);
copyFileSync('/opt/worker/fixtures/real-media.html',root+'/index.html');copyFileSync('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');copyFileSync('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
run('ffmpeg',['-v','error','-y','-i',root+'/presenter.mp4','-vn','-ar','48000','-ac','1',root+'/source.wav'],'decode-original-audio');
report.sourceHashes=Object.fromEntries(['presenter.mp4','product.png','source.wav'].map(f=>[f,hash(root+'/'+f)]));
copyFileSync(root+'/source.wav',out+'/original-audio.wav');
hf(['check',root,'--json'],'validation');
for(const name of ['original','cta-edit']){
 if(name==='cta-edit')writeFileSync(root+'/index.html',readFileSync(root+'/index.html','utf8').replace('See the details','Explore the colours'));
 hf(['render',root,'--output',`${out}/${name}.mp4`,'--fps','24','--workers','1','--quality','looks','--strict','--no-best-effort'],name);
 const probe=JSON.parse(run('ffprobe',['-v','error','-show_streams','-show_format','-of','json',`${out}/${name}.mp4`],name+'-probe'));
 const v=probe.streams.find(s=>s.codec_type==='video');if(v.width!==1080||v.height!==1920||v.r_frame_rate!=='24/1'||Math.abs(Number(probe.format.duration)-10)>.15||!probe.streams.some(s=>s.codec_type==='audio'))throw Error('Output contract failed');
 report[name]={sha256:hash(`${out}/${name}.mp4`),duration:probe.format.duration};
 for(const t of [1,4,6,9])run('ffmpeg',['-v','error','-y','-ss',String(t),'-i',`${out}/${name}.mp4`,'-frames:v','1',`${out}/${name}-${t}.png`],name+'-frame-'+t);
 run('ffmpeg',['-v','error','-y','-i',`${out}/${name}.mp4`,'-vn','-ar','48000','-ac','1','-f','s16le',`${out}/${name}-audio.pcm`],name+'-audio');
}
run('ffmpeg',['-v','error','-y','-i',root+'/source.wav','-f','s16le',out+'/source-audio.pcm'],'source-pcm');
report.sourceUnchanged=Object.entries(report.sourceHashes).every(([f,h])=>hash(root+'/'+f)===h);if(!report.sourceUnchanged)throw Error('Source changed');report.completed=true;writeFileSync(`${out}/report.json`,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
