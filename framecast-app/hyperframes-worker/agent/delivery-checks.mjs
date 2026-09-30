import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {rename,unlink} from 'node:fs/promises';
const run=promisify(execFile);
// Social target -14 LUFS; level anything more than 1 LU away (parity standard).
export const TARGET=-14,LOW=-15,HIGH=-13;

// Integrated loudness and true peak of the encoded file (EBU R128).
export function parseLoudness(stderr){
 const i=[...String(stderr).matchAll(/\bI:\s+(-?[\d.]+|-inf)\s+LUFS/g)].at(-1),p=[...String(stderr).matchAll(/Peak:\s+(-?[\d.]+|-inf)\s+dBFS/g)].at(-1);
 const num=v=>v===undefined||v==='-inf'?null:Number(v);
 return {lufs:num(i?.[1]),peak:num(p?.[1])};
}
export async function measure(file,ffmpeg='ffmpeg'){
 const {stderr}=await run(ffmpeg,['-hide_banner','-nostats','-i',file,'-map','0:a:0?','-af','ebur128=peak=true','-f','null','-'],{timeout:120000,maxBuffer:32000000}).catch(e=>({stderr:e.stderr||''}));
 return parseLoudness(stderr);
}
// Social platforms play around -14 LUFS; level only when clearly outside, video copied untouched.
export async function levelIfNeeded(file,{silent=false,ffmpeg='ffmpeg'}={}){
 if(silent)return {status:'silent'};
 const before=await measure(file,ffmpeg);
 if(before.lufs===null||before.lufs<-60)return {status:'no_audio'};
 if(before.lufs>=LOW&&before.lufs<=HIGH&&(before.peak===null||before.peak<=-1))return {status:'ok',lufs:before.lufs,peak:before.peak};
 const tmp=file+'.level.mp4';
 try{
  await run(ffmpeg,['-hide_banner','-loglevel','error','-y','-i',file,'-map','0','-c:v','copy','-af',`loudnorm=I=${TARGET}:TP=-1.5:LRA=11`,'-c:a','aac','-b:a','192k','-movflags','+faststart',tmp],{timeout:180000,maxBuffer:4000000});
  let after=await measure(tmp,ffmpeg);
  // One loudnorm pass often lands short and hot; correct the gain once more under a limiter.
  // One loudnorm pass often lands short and hot, and AAC encoding overshoots the
  // limiter; correct gain under a -3 dB sample limiter, at most twice, until the
  // true peak is at or below -1 dB.
  for(let pass=0;pass<2&&after.lufs!==null&&(Math.abs(after.lufs-TARGET)>0.5||(after.peak??-99)>-1);pass++){
   const fix=file+'.fix.mp4',gain=(TARGET-after.lufs-(pass&&after.peak>-1?after.peak+1.5:0)).toFixed(2);
   await run(ffmpeg,['-hide_banner','-loglevel','error','-y','-i',tmp,'-map','0','-c:v','copy','-af',`volume=${gain}dB,alimiter=limit=0.708:level=false`,'-c:a','aac','-b:a','192k','-movflags','+faststart',fix],{timeout:180000,maxBuffer:4000000});
   await rename(fix,tmp);after=await measure(tmp,ffmpeg);
  }
  await rename(tmp,file);
  return {status:'levelled',from:before.lufs,lufs:after.lufs,peak:after.peak};
 }catch(e){await unlink(tmp).catch(()=>{});return {status:'check_failed',lufs:before.lufs,peak:before.peak};}
}
export function summary(sandbox,loudness){
 const text=f=>({selector:f.selector,time:f.time??null,message:String(f.message||'').slice(0,200)});
 return {safe_area:(sandbox?.safe_area||[]).map(text),edges:(sandbox?.edges||[]).map(text),contrast:(sandbox?.contrast||[]).map(text),loudness,
  ok:!(sandbox?.safe_area?.length||sandbox?.edges?.length||sandbox?.contrast?.length)&&!['check_failed'].includes(loudness?.status)};
}
