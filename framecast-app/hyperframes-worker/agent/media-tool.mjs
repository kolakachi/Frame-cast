// Sandbox media operations: fixed ffmpeg recipes over files already in the
// run's project folder. The model chooses an operation and bounded numbers;
// it never supplies a command, a path outside the project, or a filter string.
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {readdir,lstat,readFile,unlink} from 'node:fs/promises';import {createHash} from 'node:crypto';
const run=promisify(execFile);
const FF={timeout:150000,maxBuffer:32*1024*1024};
export const OPS=['probe','silences','levels','beats','screen','duck','fade','space','trim','cut','remove_silence','clean_audio','loudness','stabilize','speed','crop','frame','grade'];
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
// Mean loudness (dBFS) over a short window starting at each time; quiet windows are where audio can start or stop cleanly.
export async function levels(file,times,duration,len=.06){
 const out=[];
 for(const t of times){
  const s=Math.max(0,Math.min(t,duration-len)),e=Math.min(duration,s+len);
  if(e-s<.01){out.push([t,null]);continue;}
  const {stderr}=await run('ffmpeg',['-hide_banner','-nostats','-ss',fx(s),'-t',fx(e-s),'-i',file,'-map','0:a:0?','-af','volumedetect','-f','null','-'],FF).catch(e=>({stderr:e.stderr||''}));
  const m=String(stderr).match(/mean_volume:\s*(-?[\d.]+|-inf)\s*dB/);
  out.push([t,!m||m[1]==='-inf'?-91:Number(m[1])]);
 }
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

/**
 * A device screen in a generated shot (todo E1): the largest bright, evenly lit region in a frame, as four corners
 * [top-left, top-right, bottom-right, bottom-left] in the clip's own pixels. Measured at three times; "stable" when the
 * corners stay within 1.5% of the frame, so a still overlay can be pinned to it. Confidence is how well the region
 * fills its quadrilateral (a screen is a solid four-sided shape).
 */
export async function screenQuad(file,info,at){
 const W=320,H=Math.max(2,Math.round(W*info.height/info.width/2)*2);
 const quadAt=async t=>{
  const {stdout}=await promisify(execFile)('ffmpeg',['-hide_banner','-loglevel','error','-ss',fx(t),'-i',file,'-frames:v','1','-vf',`scale=${W}:${H},format=gray`,'-f','rawvideo','-'],{encoding:'buffer',maxBuffer:W*H*2,timeout:30000});
  const px=stdout;let max=0;for(const v of px)if(v>max)max=v;
  const cut=Math.max(150,max-40),seen=new Uint8Array(W*H);let best=null;
  for(let i=0;i<W*H;i++){if(seen[i]||px[i]<cut)continue;
   const stack=[i],pts=[];seen[i]=1;
   while(stack.length){const j=stack.pop();pts.push(j);const x=j%W,y=(j-x)/W;
    for(const [dx,dy] of [[1,0],[-1,0],[0,1],[0,-1]]){const nx=x+dx,ny=y+dy;if(nx<0||ny<0||nx>=W||ny>=H)continue;const k=ny*W+nx;if(!seen[k]&&px[k]>=cut){seen[k]=1;stack.push(k);}}}
   if(!best||pts.length>best.length)best=pts;}
  if(!best||best.length<W*H*0.01)return null;
  // Corners: the points furthest along each diagonal.
  let tl,tr,br,bl;for(const j of best){const x=j%W,y=(j-x)/W;
   if(!tl||x+y<tl[0]+tl[1])tl=[x,y];if(!br||x+y>br[0]+br[1])br=[x,y];if(!tr||x-y>tr[0]-tr[1])tr=[x,y];if(!bl||y-x>bl[1]-bl[0])bl=[x,y];}
  const quad=[tl,tr,br,bl],area=Math.abs(quad.reduce((a,[x,y],k)=>{const [x2,y2]=quad[(k+1)%4];return a+x*y2-x2*y;},0))/2;
  const sx=info.width/W,sy=info.height/H;
  return {quad:quad.map(([x,y])=>[Math.round(x*sx),Math.round(y*sy)]),confidence:area?+Math.min(1,best.length/area).toFixed(2):0};
 };
 const times=(Array.isArray(at)&&at.length?at:[0.2,info.duration/2,Math.max(0.2,info.duration-0.3)]).slice(0,5).map(t=>Math.max(0,Math.min(info.duration-0.05,Number(t)||0)));
 const found=[];for(const t of times)found.push(await quadAt(t));
 if(found.some(f=>!f))return {found:false,times};
 const tol=0.015*Math.max(info.width,info.height);
 const moves=Math.max(...found.slice(1).flatMap(f=>f.quad.map((p,k)=>Math.hypot(p[0]-found[0].quad[k][0],p[1]-found[0].quad[k][1]))));
 return {found:true,times,quad:found[0].quad,quads:found.map(f=>f.quad),confidence:Math.min(...found.map(f=>f.confidence)),stable:moves<=tol,moves_px:Math.round(moves),width:info.width,height:info.height};
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
 // The same limit as footage the user can attach (5 minutes); trims and crops seek, so a long source stays quick.
 if(!isStill&&info.duration>300.5)throw Error('Clips longer than 5 minutes are not supported yet');
 if(op==='beats'){if(!info.has_audio)throw Error('No audio to find beats in');return {ok:true,info,beats:await musicBeats(file,info.duration)};}
 if(op==='screen'){if(!info.has_video||!info.width)throw Error('Needs a video');return {ok:true,info,screen:await screenQuad(file,info,params.at)};}
 if(op==='silences'){const s=await silences(file,num(params.noise_db,-60,-20,-35),num(params.min_silence,.05,3,.4));return {ok:true,info,silences:s};}
 if(op==='levels'){if(!info.has_audio)throw Error('No audio to measure');const at=params.at;if(!Array.isArray(at)||!at.length||at.length>60)throw Error('at must list 1 to 60 times');
  return {ok:true,info,levels:await levels(file,at.map(t=>num(t,0,info.duration+.5)),info.duration)};}
 const audioOnly=!info.has_video&&!isStill;
 const ext=isStill?(op==='frame'?'png':input.split('.').pop()==='jpg'?'jpg':'png'):op==='frame'?'png':audioOnly||op==='fade'?'wav':'mp4';
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
  case 'fade':{
   // A slice of the audio with its edges faded, so it starts and stops without a click or a cut-off note; fade_out about 1 s for music at the end.
   if(!info.has_audio)throw Error('No audio to fade');
   const s=num(params.start,0,info.duration,0),e=num(params.end,0,info.duration,info.duration);if(e-s<.2)throw Error('Fade must keep at least 0.2 seconds');
   const fi=num(params.fade_in,0,5,.02),fo=num(params.fade_out,0,10,.05);if(fi+fo>e-s)throw Error('Fades are longer than the slice');
   args=['-i',file,'-vn','-af',`atrim=start=${fx(s)}:end=${fx(e)},asetpts=PTS-STARTPTS,afade=t=in:st=0:d=${fx(Math.max(fi,.001))},afade=t=out:st=${fx(e-s-Math.max(fo,.001))}:d=${fx(Math.max(fo,.001))}`,'-c:a','pcm_s16le'];
   map=sourceMap([[s,e]]);break;}
  case 'space':{
   // Longer pauses in a voice, added only inside pauses it already has, so narration can spread over the beats
   // without a word being cut. Each requested point moves to the middle of the nearest pause within 0.4 s.
   if(!info.has_audio||info.has_video)throw Error('space works on an audio file');
   const ins=params.insert;if(!Array.isArray(ins)||!ins.length||ins.length>20)throw Error('insert must list 1 to 20 [at_seconds, pause_seconds] pairs');
   const pauses=await silences(file,-35,.08);
   const points=ins.map(r=>{if(!Array.isArray(r)||r.length!==2)throw Error('Each insert is [at_seconds, pause_seconds]');
    const at=num(r[0],0,info.duration),len=num(r[1],.05,5);
    const near=pauses.map(([a,b])=>({mid:(a+b)/2,d:at<a?a-at:at>b?at-b:0})).sort((x,y)=>x.d-y.d)[0];
    if(!near||near.d>.4)throw Error(`No pause within 0.4 s of ${fx(at)} s; the voice pauses at ${pauses.map(([a,b])=>fx(a)+'–'+fx(b)).join(', ')||'no point'} s`);
    return [near.mid,len];}).sort((a,b)=>a[0]-b[0]);
   for(let i=1;i<points.length;i++)if(points[i][0]-points[i-1][0]<.05)throw Error('Two inserts fall in the same pause; combine them');
   const cuts=[0,...points.map(p=>p[0]),info.duration],parts=[],labels=[];map=[];let shift=0;
   for(let i=0;i<cuts.length-1;i++){
    const gap=points[i]?.[1]??0;
    parts.push(`[0:a]atrim=start=${fx(cuts[i])}:end=${fx(cuts[i+1])},asetpts=PTS-STARTPTS${gap?`,apad=pad_dur=${fx(gap)}`:''}[s${i}]`);labels.push(`[s${i}]`);
    map.push({out_start:+(cuts[i]+shift).toFixed(3),out_end:+(cuts[i+1]+shift).toFixed(3),src_start:cuts[i],src_end:cuts[i+1]});shift+=gap;
   }
   args=['-i',file,'-filter_complex',parts.join(';')+';'+labels.join('')+`concat=n=${labels.length}:v=0:a=1[out]`,'-map','[out]','-c:a','pcm_s16le'];break;}
  case 'clean_audio':{if(!info.has_audio)throw Error('No audio to clean');args=['-i',file,'-af','highpass=f=80,lowpass=f=12000,afftdn=nf=-25',...(info.has_video?['-c:v','copy']:[]),...(audioOnly?['-c:a','pcm_s16le']:['-c:a','aac','-b:a','160k'])];break;}
  case 'duck':{
   // Music pulled down under the voice as it speaks (about 14 dB on speech peaks), back up in the gaps.
   if(!info.has_audio)throw Error('Needs music audio');
   const voice=params.voice;if(typeof voice!=='string'||!/^[a-zA-Z0-9_.-]+\.(mp4|mp3|wav)$/.test(voice)||voice===input)throw Error('voice must be a different audio file in this project');
   const vf=projectDir+'/'+voice,vs=await lstat(vf).catch(()=>null);if(!vs||!vs.isFile()||vs.isSymbolicLink())throw Error('Voice file not found in this project');
   const delta=num(params.voice_start,0,120,0)-num(params.music_start,0,120,0);
   const side=(delta>=0?'adelay='+Math.round(delta*1000)+'|'+Math.round(delta*1000):'atrim=start='+(-delta).toFixed(3)+',asetpts=PTS-STARTPTS')+',apad';
   args=['-i',file,'-i',vf,'-filter_complex','[1:a]'+side+'[sc];[0:a][sc]sidechaincompress=threshold=0.02:ratio=4:attack=30:release=650:makeup=1[out]','-map','[out]','-t',fx(info.duration),...enc];break;}
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
