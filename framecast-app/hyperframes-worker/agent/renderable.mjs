// Every video the render will seek in must have a keyframe at least every second and run at no more than 30 fps:
// clips cut by the builder, generated clips (one keyframe in four seconds is common) and anything that slipped past
// intake. Done on the render's own copy of the project, just before it renders; files already fine are untouched.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {readdir,readFile,rename,rm} from 'node:fs/promises';
const run=promisify(execFile);
export const LIMITS={gap:1.1,fps:30.5};

export async function inspect(file){
 const {stdout:k}=await run('ffprobe',['-v','error','-select_streams','v:0','-skip_frame','nokey','-show_entries','frame=pts_time','-of','csv=p=0',file],{timeout:60000,maxBuffer:8e6});
 const times=k.split(/\s+/).filter(Boolean).map(x=>parseFloat(x)).filter(t=>Number.isFinite(t)&&t>=0);
 const {stdout:i}=await run('ffprobe',['-v','error','-show_entries','format=duration:stream=avg_frame_rate,codec_type','-of','json',file],{timeout:30000});
 const info=JSON.parse(i),dur=Number(info.format?.duration)||0,v=(info.streams||[]).find(s=>s.codec_type==='video');
 const [n,d]=String(v?.avg_frame_rate||'0/1').split('/').map(Number),fps=d?n/d:0;
 let gap=times.length?Math.max(times[0],dur-times.at(-1)):dur;
 for(let x=1;x<times.length;x++)gap=Math.max(gap,times[x]-times[x-1]);
 return {gap:+gap.toFixed(2),fps:+fps.toFixed(2),video:!!v,fix:!!v&&(gap>LIMITS.gap||fps>LIMITS.fps)};
}

/** Re-encodes, in place, the videos in dir that would make the render's seeks fail. Returns what it changed. */
export async function makeRenderable(dir){
 const changed=[];
 // Only the clips the composition uses (a long source it cut from stays as it is).
 const code=(await Promise.all((await readdir(dir)).filter(n=>/\.(html|css|js)$/.test(n)).map(n=>readFile(dir+'/'+n,'utf8').catch(()=>'')))).join('\n');
 for(const name of (await readdir(dir)).filter(n=>/\.(mp4|mov|webm)$/i.test(n)&&code.includes(n))){
  const file=dir+'/'+name;
  let info;try{info=await inspect(file);}catch{continue;}
  if(!info.fix)continue;
  const tmp=dir+'/.renderable-'+name.replace(/\.\w+$/,'.mp4');
  try{
   await run('ffmpeg',['-hide_banner','-loglevel','error','-y','-i',file,'-map','0:v:0','-map','0:a:0?','-vf','scale=trunc(iw/2)*2:trunc(ih/2)*2',
    '-r',info.fps>LIMITS.fps?'30':String(Math.max(1,Math.round(info.fps))),'-c:v','libx264','-preset','veryfast','-crf','18','-pix_fmt','yuv420p',
    '-g','30','-keyint_min','30','-sc_threshold','0','-c:a','aac','-b:a','160k','-movflags','+faststart',tmp],{timeout:240000});
   await rename(tmp,file);changed.push({name,...info});
  }catch{await rm(tmp,{force:true});}
 }
 return changed;
}
