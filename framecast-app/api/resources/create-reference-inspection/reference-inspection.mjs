// Read-only inspection of authenticated reference attachments. These images never
// enter project/, workspace.assets or the renderer's source manifest.
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {mkdir,mkdtemp,lstat,realpath,readFile,copyFile,rm} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
const exec=promisify(execFile);
const finite=n=>typeof n==='number'&&Number.isFinite(n);
export function validateInspection(input,params){
 if(typeof input!=='string'||!/^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.(png|jpg|webp|mp4)$/.test(input))throw Error('Inspection input must be a reference attachment filename');
 if(!params||typeof params!=='object'||Array.isArray(params))throw Error('Invalid inspection params');
 const keys={frames:['mode','times','crop'],sequence:['mode','start','end','count','crop','every_frame','page'],shots:['mode','start','end']}[params.mode];
 if(!keys||Object.keys(params).some(k=>!keys.includes(k)))throw Error('Inspection mode must be frames, sequence or shots with only its documented params');
 if(params.mode==='frames'&&(!Array.isArray(params.times)||params.times.length<1||params.times.length>8||params.times.some(t=>!finite(t)||t<0||t>86400)))throw Error('Inspection frames require 1 to 8 timestamps in seconds');
 if(params.mode!=='frames'&&(!finite(params.start)||!finite(params.end)||params.start<0||params.end<=params.start||params.end>86400||params.end-params.start>(params.mode==='shots'?30:2)))throw Error('Inspection window must be positive: at most 30 seconds for shots or 2 seconds for a sequence');
 if(params.mode==='sequence'){
  if(params.every_frame===true){
   if(params.count!==undefined||params.page!==undefined&&(!Number.isInteger(params.page)||params.page<1||params.page>15))throw Error('Consecutive inspection takes page 1 to 15, not a sample count');
  }else if(params.every_frame!==undefined||params.page!==undefined||!Number.isInteger(params.count)||params.count<2||params.count>8)throw Error('Inspection sequence count must be 2 to 8, or use every_frame:true with an optional page');
 }
 if(params.crop!==undefined){
  const c=params.crop;
  if(!c||Array.isArray(c)||Object.keys(c).sort().join(',')!=='height,width,x,y'||!Object.values(c).every(finite)||c.x<0||c.y<0||c.width<=0||c.height<=0||c.x+c.width>1||c.y+c.height>1)throw Error('Inspection crop requires normalized x, y, width, height inside the image');
 }
 return params;
}

export async function inspectReference({runDir,request,signal,timeoutMs=90000}){
 const {input,params}=request;validateInspection(input,params);
 const manifest=JSON.parse(await readFile(path.join(runDir,'inputs/manifest.json'),'utf8'));
 const item=manifest.find(f=>f.name===input&&f.purpose==='reference'&&['video','image'].includes(f.asset_type));
 if(!item||item.path!=='reference/'+input)throw Error('Inspection is limited to staged reference images and videos');
 const root=await realpath(path.join(runDir,'inputs/reference')),file=path.join(root,input);
 const stat=await lstat(file);
 if(!stat.isFile()||stat.size>100*1024*1024||stat.size!==item.bytes||path.dirname(await realpath(file))!==root)throw Error('Invalid reference file');
 if(createHash('sha256').update(await readFile(file)).digest('hex')!==item.sha256)throw Error('Reference integrity check failed');
 const deadline=Date.now()+Math.min(timeoutMs,90000);
 const command=async(bin,args)=>{
  signal?.throwIfAborted();if(Date.now()>=deadline)throw Error('Reference inspection timed out');
  return exec(bin,args,{signal,timeout:Math.max(1,deadline-Date.now()),maxBuffer:2*1024*1024,killSignal:'SIGKILL'});
 };
 const raw=JSON.parse((await command('ffprobe',['-v','error','-protocol_whitelist','file,pipe','-show_streams','-show_format','-of','json',file])).stdout);
 const stream=raw.streams?.find(s=>s.codec_type==='video');
 if(!stream||!Number.isInteger(stream.width)||!Number.isInteger(stream.height)||stream.width<2||stream.height<2||stream.width*stream.height>40000000)throw Error('Reference has no supported visual stream');
 const rotation=Number(stream.side_data_list?.find(s=>s.rotation!==undefined)?.rotation??stream.tags?.rotate??0);
 if(!finite(rotation)||rotation%90!==0)throw Error('Reference has an unsupported rotation');
 const rotated=Math.abs(rotation)%180===90;
 const width=rotated?stream.height:stream.width,height=rotated?stream.width:stream.height;
 const video=item.asset_type==='video',duration=video?Number(raw.format?.duration??stream.duration):null;
 const [num,den]=String(stream.avg_frame_rate||'0/1').split('/').map(Number),fps=den?num/den:0;
 if(video&&(!finite(duration)||duration<=0))throw Error('Reference duration is unavailable');
 if(!video&&(params.mode!=='frames'||params.times.some(t=>t!==0)))throw Error('Inspect an image with frames times [0]; sequence and shots require a video');
 if(video&&((params.mode==='frames'&&params.times.some(t=>t>=duration))||(params.mode!=='frames'&&(params.every_frame===true?params.end>duration:params.end>=duration))))throw Error('Inspection timestamps must be before the end of the reference');
 const out=path.join(runDir,'inspect_reference');await mkdir(out,{recursive:true});
 if(params.mode==='sequence'&&params.every_frame===true){
  const {inspectConsecutive}=await import('./reference-sequence.mjs');
  const origin=Number(raw.format?.start_time??0);
  if(!finite(origin))throw Error('Reference timestamp origin is unavailable');
  return inspectConsecutive({file,out,params,sourceHash:item.sha256,input,metadata:{width,height,duration_seconds:duration,fps:fps||null,timestamp_origin_seconds:origin},command});
 }
 const scratch=await mkdtemp(path.join(out,'frames-'));
 try{
  let times=params.times,cutCandidates=[];
  if(params.mode==='sequence')times=Array.from({length:params.count},(_,i)=>params.start+(params.end-params.start)*i/(params.count-1));
  if(params.mode==='shots'){
   // Cheap bounded cut candidates, not a semantic scene parser or an all-frame audit.
   const {stderr}=await command('ffmpeg',['-nostdin','-hide_banner','-threads','1','-filter_threads','1','-protocol_whitelist','file,pipe','-ss',String(params.start),'-t',String(params.end-params.start),'-i',file,'-an','-vf',"scale=320:-2,fps=12,select='gt(scene,0.25)',showinfo",'-frames:v','24','-f','null','-']);
   cutCandidates=[...stderr.matchAll(/pts_time:([0-9.]+)/g)].map(m=>+(params.start+Number(m[1])).toFixed(4)).filter(t=>t>params.start&&t<params.end);
   times=[params.start,...cutCandidates.slice(0,6),params.end];
  }
  times=times.map(t=>+t.toFixed(4));
  const crop=params.crop?{x:Math.floor(params.crop.x*width),y:Math.floor(params.crop.y*height),width:Math.floor(params.crop.width*width),height:Math.floor(params.crop.height*height)}:null;
  if(crop&&(crop.width<2||crop.height<2))throw Error('Inspection crop is smaller than two source pixels');
  for(const [i,time] of times.entries()){
   const size=times.length===1?1280:512;
   const filter=(crop?`crop=${crop.width}:${crop.height}:${crop.x}:${crop.y}:exact=1,`:'')+`scale=${size}:${size}:force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1`;
   await command('ffmpeg',['-y','-nostdin','-v','error','-threads','1','-filter_threads','1','-protocol_whitelist','file,pipe',...(video?['-ss',String(time)]:[]),'-i',file,'-an','-vf',filter,'-frames:v','1','-q:v','3',path.join(scratch,`frame-${i}.jpg`)]);
  }
  const cols=Math.min(4,times.length),rows=Math.ceil(times.length/cols);
  await command('ffmpeg',['-y','-nostdin','-v','error','-threads','1','-filter_threads','1','-framerate','1','-i',path.join(scratch,'frame-%d.jpg'),'-vf',`tile=${cols}x${rows}:nb_frames=${times.length}:padding=4:color=black`,'-frames:v','1','-q:v','3',path.join(scratch,'contact-sheet.jpg')]);
  await copyFile(path.join(scratch,'contact-sheet.jpg'),path.join(out,'contact-sheet.jpg'));
  return {ok:true,input,purpose:'reference',renderable:false,mode:params.mode,source_sha256:item.sha256,width,height,duration_seconds:duration,fps:fps||null,
   frames:times.map((time,i)=>({cell:i+1,requested_seconds:time})),columns:cols,rows,crop_pixels:crop,
   ...(params.mode==='shots'?{cut_candidates_seconds:cutCandidates,scan:{start:params.start,end:params.end,fps:12,threshold:0.25,max_candidates:24}}:{}),
   note:'Read cells left to right, then top to bottom. Frames are sampled near the requested timestamps, scaled to fit; crops use original source pixels. This is visual reference evidence, never footage. Cut candidates are heuristic; these samples do not establish every-frame, audio or pixel-perfect fidelity.'};
 }finally{await rm(scratch,{recursive:true,force:true});}
}
