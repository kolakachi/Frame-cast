// Sandbox media operations: fixed ffmpeg recipes over files already in the
// run's project folder. The model chooses an operation and bounded numbers;
// it never supplies a command, a path outside the project, or a filter string.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {readdir,lstat,readFile,unlink} from 'node:fs/promises';import {createHash} from 'node:crypto';
const run=promisify(execFile);
const FF={timeout:150000,maxBuffer:32*1024*1024};
export const OPS=['probe','silences','beats','duck','trim','cut','remove_silence','clean_audio','loudness','stabilize','speed','crop','frame','grade'];
const LOOKS={
 warm:'colorbalance=rs=.06:gs=.01:bs=-.06,eq=saturation=1.08',
 cool:'colorbalance=rs=-.05:gs=.0:bs=.07,eq=saturation=1.02',
 punchy:'eq=contrast=1.12:saturation=1.25:brightness=.01',
 muted:'eq=contrast=.95:saturation=.72',
 mono:'hue=s=0,eq=contrast=1.08',
 film:'curves=preset=vintage,eq=saturation=.9',
 // Print textures: film grain, and a mono halftone dot screen (for photos and footage;
 // for cut-out characters use a CSS dot-screen overlay so transparency is kept).
 grain:'noise=alls=16:allf=t+u,eq=contrast=1.04',
 halftone:"format=gray,geq=lum='255*gt(lum(X\\,Y)/255\\,(1+sin(X*0.9)*sin(Y*0.9))/2)'",
};
const RATIOS={'9:16':9/16,'1:1':1,'4:5':4/5,'16:9':16/9};
const num=(v,min,max,dflt)=>{const n=v===undefined?dflt:Number(v);if(!Number.isFinite(n)||n<min||n>max)throw Error(`Value out of range (${min}–${max})`);return n;};
const fx=n=>Number(n).toFixed(3);

export async function probe(file){
 const {stdout}=await run('ffprobe',['-v','error','-print_format','json','-show_format','-show_streams',file],FF);
 const j=JSON.parse(stdout),v=j.streams.find(s=>s.codec_type==='video'),a=j.streams.find(s=>s.codec_type==='audio');
 return {duration:Number(j.format.duration)||0,width:v?.width??null,height:v?.height??null,has_video:Boolean(v)&&!/png|mjpeg|webp/.test(v?.codec_name??''),has_audio:Boolean(a)};
}
async function silences(file,db,d){
 const {stderr}=await run('ffmpeg',['-hide_banner','-nostats','-i',file,'-af',`silencedetect=n=${db}dB:d=${d}`,'-f','null','-'],FF);
 const out=[];let start=null;
 for(const line of stderr.split('\n')){const s=line.match(/silence_start: ([\d.]+)/),e=line.match(/silence_end: ([\d.]+)/);if(s)start=Number(s[1]);if(e&&start!==null){out.push([start,Number(e[1])]);start=null;}}
 return out;
}
function keepFrom(silent,duration,pad){
 const keep=[];let t=0;
 for(const [s,e] of silent){const a=Math.max(0,s+pad),b=Math.min(duration,e-pad);if(a>t+.05)keep.push([t,a]);t=Math.max(t,b);}
 if(duration>t+.05)keep.push([t,duration]);return keep;
}
function sourceMap(keep){let o=0;return keep.map(([s,e])=>{const m={out_start:+o.toFixed(3),out_end:+(o+e-s).toFixed(3),src_start:s,src_end:e};o+=e-s;return m;});}
function concatArgs(input,keep,info){
 const parts=[],labels=[];
 keep.forEach(([s,e],i)=>{if(info.has_video)parts.push(`[0:v]trim=start=${fx(s)}:end=${fx(e)},setpts=PTS-STARTPTS[v${i}]`);if(info.has_audio)parts.push(`[0:a]atrim=start=${fx(s)}:end=${fx(e)},asetpts=PTS-STARTPTS[a${i}]`);labels.push((info.has_video?`[v${i}]`:'')+(info.has_audio?`[a${i}]`:''));});
 const graph=parts.join(';')+';'+labels.join('')+`concat=n=${keep.length}:v=${info.has_video?1:0}:a=${info.has_audio?1:0}`+(info.has_video?'[v]':'')+(info.has_audio?'[a]':'');
 return ['-i',input,'-filter_complex',graph,...(info.has_video?['-map','[v]']:[]),...(info.has_audio?['-map','[a]']:[])];
}
const videoOut=['-c:v','libx264','-preset','veryfast','-crf','20','-pix_fmt','yuv420p','-c:a','aac','-b:a','160k','-movflags','+faststart','-threads','2'];

// Beat grid of a music file: HyperFrames' own detector on a one-track scratch
// project, folded into tempo, beats, bars and the strongest hits.
async function musicBeats(file,duration){
 const {mkdtemp,writeFile:wf,readFile:rf,readdir:rd,rm,copyFile:cf}=await import('node:fs/promises');
 const {beatGrid}=await import('./beat-grid.mjs');
 const tmp=await mkdtemp('/tmp/beats-');
 try{
  const name='music'+file.slice(file.lastIndexOf('.'));
  await cf(file,tmp+'/'+name);
  await wf(tmp+'/index.html',`<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="root" data-composition-id="main" data-width="1080" data-height="1920" data-duration="${Math.ceil(duration)}"><audio id="music" class="clip" data-start="0" data-duration="${duration.toFixed(2)}" data-track-index="1" src="${name}"></audio></div></body></html>`);
  await promisify(execFile)(process.execPath,['/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs','beats',tmp,'--json'],{cwd:tmp,timeout:120000,maxBuffer:8000000});
  const out=(await rd(tmp+'/beats'))[0];
  return beatGrid(JSON.parse(await rf(tmp+'/beats/'+out,'utf8')).beats,duration);
 }finally{await rm(tmp,{recursive:true,force:true});}
}

export async function mediaOp({projectDir,request,nextName}){
 const {op,input,params={}}=request;
 if(!OPS.includes(op))throw Error('Unknown media operation');
 if(typeof input!=='string'||!/^[a-zA-Z0-9_.-]+\.(mp4|mp3|wav|png|jpg|webp)$/.test(input))throw Error('Input must be a media file in this project');
 if(!params||typeof params!=='object'||Array.isArray(params))throw Error('Invalid params');
 const file=projectDir+'/'+input;
 const st=await lstat(file).catch(()=>null);if(!st||!st.isFile()||st.isSymbolicLink())throw Error('Input file not found in this project');
 const info=await probe(file);
 if(op==='probe')return {ok:true,info};
 const isStill=/\.(png|jpg|webp)$/.test(input);
 if(isStill&&op!=='grade'&&op!=='crop')throw Error('That operation needs a video or audio file');
 if(!isStill&&info.duration>180)throw Error('Clips longer than 3 minutes are not supported yet');
 if(op==='beats'){if(!info.has_audio)throw Error('No audio to find beats in');return {ok:true,info,beats:await musicBeats(file,info.duration)};}
 if(op==='silences'){const s=await silences(file,num(params.noise_db,-60,-20,-35),num(params.min_silence,.2,3,.4));return {ok:true,info,silences:s};}
 const audioOnly=!info.has_video&&!isStill;
 const ext=isStill?(op==='frame'?'png':input.split('.').pop()==='jpg'?'jpg':'png'):op==='frame'?'png':audioOnly?'wav':'mp4';
 const output=nextName(op,ext),out=projectDir+'/'+output;
 let args,map=null;
 const enc=audioOnly?['-c:a','pcm_s16le']:videoOut;
 switch(op){
  case 'trim':{const s=num(params.start,0,info.duration,0),e=num(params.end,0,info.duration,info.duration);if(e-s<.2)throw Error('Trim must keep at least 0.2 seconds');args=concatArgs(file,[[s,e]],info).concat(enc);map=sourceMap([[s,e]]);break;}
  case 'cut':{const keep=params.keep;if(!Array.isArray(keep)||!keep.length||keep.length>20)throw Error('keep must list 1 to 20 [start,end] ranges');
   const k=keep.map(r=>{if(!Array.isArray(r)||r.length!==2)throw Error('Each range is [start,end]');const s=num(r[0],0,info.duration),e=num(r[1],0,info.duration);if(e-s<.1)throw Error('Ranges must be at least 0.1 seconds');return [s,e];});
   for(let i=1;i<k.length;i++)if(k[i][0]<k[i-1][1])throw Error('Ranges must be in order and not overlap');
   args=concatArgs(file,k,info).concat(enc);map=sourceMap(k);break;}
  case 'remove_silence':{if(!info.has_audio)throw Error('No audio to detect silences in');
   const s=await silences(file,num(params.noise_db,-60,-20,-35),num(params.min_silence,.2,3,.5));const k=keepFrom(s,info.duration,num(params.pad,0,.5,.08));
   if(!s.length)return {ok:true,info,output:null,note:'No silences long enough to remove.'};
   args=concatArgs(file,k,info).concat(enc);map=sourceMap(k);break;}
  case 'clean_audio':{if(!info.has_audio)throw Error('No audio to clean');args=['-i',file,'-af','highpass=f=80,lowpass=f=12000,afftdn=nf=-25',...(info.has_video?['-c:v','copy']:[]),...(audioOnly?['-c:a','pcm_s16le']:['-c:a','aac','-b:a','160k'])];break;}
  case 'duck':{
   // Music pulled down under the voice as it speaks (about 14 dB on speech peaks), back up in the gaps.
   if(!info.has_audio)throw Error('Needs music audio');
   const voice=params.voice;if(typeof voice!=='string'||!/^[a-zA-Z0-9_.-]+\.(mp4|mp3|wav)$/.test(voice)||voice===input)throw Error('voice must be a different audio file in this project');
   const vf=projectDir+'/'+voice,vs=await lstat(vf).catch(()=>null);if(!vs||!vs.isFile()||vs.isSymbolicLink())throw Error('Voice file not found in this project');
   const delta=num(params.voice_start,0,120,0)-num(params.music_start,0,120,0);
   const side=(delta>=0?'adelay='+Math.round(delta*1000)+'|'+Math.round(delta*1000):'atrim=start='+(-delta).toFixed(3)+',asetpts=PTS-STARTPTS')+',apad';
   args=['-i',file,'-i',vf,'-filter_complex','[1:a]'+side+'[sc];[0:a][sc]sidechaincompress=threshold=0.03:ratio=3:attack=40:release=500:makeup=1[out]','-map','[out]','-t',fx(info.duration),...enc];break;}
  case 'loudness':{if(!info.has_audio)throw Error('No audio to level');const t=num(params.target_lufs,-24,-9,-14);args=['-i',file,'-af',`loudnorm=I=${t}:TP=-1.5:LRA=11`,...(info.has_video?['-c:v','copy']:[]),...(audioOnly?['-c:a','pcm_s16le']:['-c:a','aac','-b:a','160k'])];break;}
  case 'stabilize':{if(!info.has_video)throw Error('Needs a video');const trf=projectDir+'/.'+output+'.trf';
   await run('ffmpeg',['-hide_banner','-y','-i',file,'-vf',`vidstabdetect=shakiness=6:accuracy=12:result=${trf}`,'-f','null','-'],FF);
   args=['-i',file,'-vf',`vidstabtransform=input=${trf}:smoothing=${Math.round(num(params.smoothing,4,40,12))}:zoom=2,unsharp=5:5:0.6`,...videoOut];break;}
  case 'speed':{const f=num(params.factor,.25,4,1);if(Math.abs(f-1)<.01)throw Error('Speed factor must differ from 1');
   const at=[];let r=f;while(r>2){at.push('atempo=2');r/=2;}while(r<.5){at.push('atempo=0.5');r/=.5;}at.push('atempo='+r.toFixed(4));
   args=['-i',file,...(info.has_video?['-filter:v',`setpts=PTS/${f}`]:[]),...(info.has_audio?['-filter:a',at.join(',')]:['-an']),...enc];break;}
  case 'crop':{const target=RATIOS[params.aspect];if(!target)throw Error('aspect must be 9:16, 1:1, 4:5 or 16:9');if(!info.width)throw Error('Needs a picture');
   const fxp=num(params.focus_x,0,1,.5),fyp=num(params.focus_y,0,1,.5),src=info.width/info.height;
   let w=info.width,h=info.height;if(src>target)w=Math.floor(h*target/2)*2;else h=Math.floor(w/target/2)*2;
   const x=Math.round((info.width-w)*fxp),y=Math.round((info.height-h)*fyp);
   args=['-i',file,'-vf',`crop=${w}:${h}:${x}:${y}`,...(isStill?['-frames:v','1']:videoOut)];break;}
  case 'frame':{if(!info.has_video)throw Error('Needs a video');args=['-ss',fx(num(params.at,0,info.duration,0)),'-i',file,'-frames:v','1'];break;}
  case 'grade':{const look=LOOKS[params.look];if(!look)throw Error('look must be one of '+Object.keys(LOOKS).join(', '));if(!info.width)throw Error('Needs a picture');args=['-i',file,'-vf',look,...(isStill?['-frames:v','1']:videoOut)];break;}
 }
 try{await run('ffmpeg',['-hide_banner','-y','-loglevel','error',...args,out],FF);}
 finally{if(op==='stabilize')await unlink(projectDir+'/.'+output+'.trf').catch(()=>{});}
 const made=await probe(out);
 const sha256=createHash('sha256').update(await readFile(out)).digest('hex');
 return {ok:true,op,input,output,sha256,info:made,...(map?{source_map:map}:{})};
}
export async function nextNameFactory(projectDir){
 const existing=(await readdir(projectDir)).filter(n=>/^derived-\d+-/.test(n)).length;let n=existing;
 return (op,ext)=>`derived-${++n}-${op.replace('_','-')}.${ext}`;
}
