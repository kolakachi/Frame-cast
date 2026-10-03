// Consecutive frames from a bounded source interval. No inference/provider calls.
// Source identity/ownership/integrity and time/crop bounds are checked by inspectReference.
import {mkdir,mkdtemp,readFile,writeFile,readdir,lstat,copyFile,rename,rm} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
export const SEQUENCE_VERSION=1,MAX_SEQUENCE_FRAMES=120,PAGE_FRAMES=8,MAX_CACHE_BYTES=64*1024*1024;
const digest=b=>createHash('sha256').update(b).digest('hex');
export async function inspectConsecutive({file,out,params,sourceHash,input,metadata,command}){
 const crop=params.crop?{x:Math.floor(params.crop.x*metadata.width),y:Math.floor(params.crop.y*metadata.height),width:Math.floor(params.crop.width*metadata.width),height:Math.floor(params.crop.height*metadata.height)}:null;
 if(crop&&(crop.width<2||crop.height<2))throw Error('Inspection crop is smaller than two source pixels');
 const settings={version:SEQUENCE_VERSION,start:params.start,end:params.end,crop,max_edge:512};
 const key=digest(JSON.stringify({source:sourceHash,...settings})),base=path.join(out,'cache'),dir=path.join(base,key);
 await mkdir(base,{recursive:true});
 if((await lstat(base)).isSymbolicLink())throw Error('Invalid inspection cache');
 let record,hit=false;
 try{
  if(!(await lstat(dir)).isDirectory()||(await lstat(dir)).isSymbolicLink())throw Error('Invalid inspection cache');
  record=JSON.parse(await readFile(path.join(dir,'manifest.json'),'utf8'));hit=true;
 }catch(e){if(e.code!=='ENOENT')throw e;}
 if(!record){
  const entries=await readdir(base);if(entries.length>=8)throw Error('Reference inspection cache limit reached; use an existing interval');
  const scratch=await mkdtemp(path.join(base,'pending-'));
  try{
   // Preserve PTS after coarse seek, normalized to the media's playback origin.
   // trim is half-open; no fps filter: VFR frames retain their individual spacing.
   const filter=`trim=start=${params.start}:end=${params.end},`+(crop?`crop=${crop.width}:${crop.height}:${crop.x}:${crop.y}:exact=1,`:'')+'scale=512:512:force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1,showinfo';
   const {stderr}=await command('ffmpeg',['-y','-nostdin','-hide_banner','-loglevel','info','-threads','1','-filter_threads','1','-copyts','-start_at_zero','-ss',String(Math.max(0,params.start-1)),'-protocol_whitelist','file,pipe','-i',file,'-an','-vf',filter,'-fps_mode','passthrough','-frames:v',String(MAX_SEQUENCE_FRAMES+1),'-q:v','3',path.join(scratch,'frame-%03d.jpg')]);
   const times=[...stderr.matchAll(/\bn:\s*\d+\s+pts:.*?pts_time:([0-9.e+-]+)/g)].map(m=>Number(m[1]));
   const files=(await readdir(scratch)).filter(n=>/^frame-\d{3}\.jpg$/.test(n)).sort();
   if(!files.length)throw Error('No source frames in this interval');
   if(files.length>MAX_SEQUENCE_FRAMES||times.length>MAX_SEQUENCE_FRAMES)throw Error('Consecutive inspection exceeds 120 frames; choose a shorter interval');
   if(files.length!==times.length||times.some((t,i)=>!Number.isFinite(t)||t<params.start-.0001||t>=params.end+.0001||i>0&&t<=times[i-1]))throw Error('Cannot establish source timestamps for consecutive frames');
   const frames=[];let bytes=0;
   for(const [i,name] of files.entries()){
    const data=await readFile(path.join(scratch,name));bytes+=data.length;
    frames.push({name,source_seconds:times[i],sha256:digest(data),bytes:data.length});
   }
   let used=0;
   for(const name of entries){
    if(!/^[a-f0-9]{64}$/.test(name))throw Error('Unexpected inspection cache entry');
    const info=JSON.parse(await readFile(path.join(base,name,'manifest.json'),'utf8'));used+=info.bytes;
   }
   if(!Number.isFinite(used)||used+bytes>MAX_CACHE_BYTES)throw Error('Reference inspection cache exceeds 64 MiB; use a smaller crop or interval');
   record={source_sha256:sourceHash,settings,frames,bytes};
   await writeFile(path.join(scratch,'manifest.json'),JSON.stringify(record));
   await rename(scratch,dir);
  }finally{await rm(scratch,{recursive:true,force:true});}
 }
 if(!Array.isArray(record.frames)||!record.frames.length||record.frames.length>MAX_SEQUENCE_FRAMES||!Number.isFinite(record.bytes)||record.bytes<=0||record.bytes>MAX_CACHE_BYTES)throw Error('Invalid inspection cache manifest');
 const pages=Math.ceil(record.frames.length/PAGE_FRAMES),page=params.page??1;
 if(page>pages)throw Error('Consecutive inspection page does not exist; available pages: '+pages);
 if(record.source_sha256!==sourceHash||JSON.stringify(record.settings)!==JSON.stringify(settings))throw Error('Reference inspection cache identity mismatch');
 const frames=record.frames.slice((page-1)*PAGE_FRAMES,page*PAGE_FRAMES);
 const scratch=await mkdtemp(path.join(out,'page-'));
 try{
  for(const [i,f] of frames.entries()){
   if(!/^frame-\d{3}\.jpg$/.test(f.name))throw Error('Invalid cached frame name');
   const source=path.join(dir,f.name),stat=await lstat(source);
   if(!stat.isFile()||stat.isSymbolicLink()||stat.size!==f.bytes)throw Error('Invalid cached reference frame');
   const data=await readFile(source);if(digest(data)!==f.sha256)throw Error('Cached reference frame integrity check failed');
   await writeFile(path.join(scratch,`cell-${i}.jpg`),data);
  }
  const columns=Math.min(4,frames.length),rows=Math.ceil(frames.length/columns);
  await command('ffmpeg',['-y','-nostdin','-v','error','-threads','1','-filter_threads','1','-framerate','1','-i',path.join(scratch,'cell-%d.jpg'),'-vf',`tile=${columns}x${rows}:nb_frames=${frames.length}:padding=4:color=black`,'-frames:v','1','-q:v','3',path.join(scratch,'contact-sheet.jpg')]);
  await copyFile(path.join(scratch,'contact-sheet.jpg'),path.join(out,'contact-sheet.jpg'));
  return {ok:true,input,purpose:'reference',renderable:false,mode:'sequence',every_frame:true,...metadata,source_sha256:sourceHash,
   cache:{key,version:SEQUENCE_VERSION,hit,bytes:record.bytes},crop_pixels:crop,columns,rows,
   frames:frames.map((f,i)=>({cell:i+1,source_frame_in_interval:(page-1)*PAGE_FRAMES+i+1,source_seconds:f.source_seconds})),
   coverage:{start_seconds:params.start,end_seconds:params.end,end_exclusive:true,decoded_frames:record.frames.length,page,pages,shown_frames:frames.length,first_source_seconds:frames[0].source_seconds,last_source_seconds:frames.at(-1).source_seconds,next_page:page<pages?page+1:null},
   note:'Every decoded frame in the requested interval is cached; only this page is shown. Read cells left to right, then down. Times are decoded PTS relative to the media playback origin, not fps estimates. Images are scaled to at most 512 pixels per edge; cropped detail excludes the rest of the frame. Unseen pages, other intervals and audio remain unverified.'};
 }finally{await rm(scratch,{recursive:true,force:true});}
}
