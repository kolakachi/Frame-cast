import {createHash} from 'node:crypto';
import {mkdir,writeFile} from 'node:fs/promises';

const LABEL={stock_video:'stock footage',stock_image:'a stock photo',ai_image:'an AI image',animate_image:'an animation',voiceover:'narration',cloned_voiceover:'narration in your voice',library_music:'music',music:'music',sfx:'sound effects',character_poses:'the character preview',character_variants:'poses from your approved character',talking_shot:'the talking shot',reference_sheet:'the cast and world sheet',generated_shot:'a generated shot',ugc_take:'the UGC take',talking_take:'the talking take',brand_kit:'your brand kit'};

// Asks the app to buy each approved plan item in order, then stages the
// returned files as usable source footage. Uncertain paid outcomes stop at the
// app boundary. Non-paid unavailable stock can be reported to the author.
// Downloads one bought file into the run's inputs, verified by size and hash, and records it in the manifest.
export async function stageFile(f,{manifest,directory,download,signal,kind,description,taskId=null,requirementIds=[]}){
 if(!Number.isSafeInteger(f.asset_id)||!/^[a-f0-9]{64}$/.test(f.sha256)||!new RegExp('^asset-'+f.asset_id+'-'+f.sha256+'\\.(png|jpg|webp|mp4|mp3|wav)$').test(f.name))throw Error('Invalid plan media record');
 if(!manifest.some(m=>m.asset_id===f.asset_id)){
  const response=await download(f.asset_id,signal);
  if(!response.ok)throw Error('Plan media download failed');
  const data=Buffer.from(await response.arrayBuffer());
  if(data.length!==f.bytes||createHash('sha256').update(data).digest('hex')!==f.sha256)throw Error('Plan media hash or size mismatch');
  await mkdir(directory+'/source',{recursive:true});
  await writeFile(directory+'/source/'+f.name,data,{flag:'wx',mode:0o600});
  manifest.push({...f,path:'source/'+f.name,plan_media:{kind,description,task_id:taskId,requirement_ids:requirementIds}});
 }
 return f.name;
}

// Generated shots and takes run for minutes at the provider: each is started, then they are all checked together
// every pollMs until done (three ten-minute shots take about ten minutes, not thirty). maxWaitMs bounds the wait;
// past it the run stops with the shots still recorded as pending, and a later run collects them instead of buying again.
export async function buyPlanMedia({items,produce,download,directory,manifest,onStage=()=>{},signal,pollMs=15000,maxWaitMs=45*60000,sleep=ms=>new Promise((resolve,reject)=>{const t=setTimeout(resolve,ms);signal?.addEventListener('abort',()=>{clearTimeout(t);reject(signal.reason);},{once:true});})}){
 const results=new Array(items.length),waiting=new Map();
 const settle=async(i,r)=>{
  const out={task_id:items[i].id??null,requirement_ids:items[i].requirement_ids??[],kind:items[i].kind,description:items[i].description,status:r.status,speech_mode:r.speech_mode??'audio_driven',engine:r.engine??null,reused:!!r.reused,charged_credits:r.charged_credits??0,...(r.error?{error:r.error}:{}),...(r.brand?{brand:r.brand}:{}),...(typeof r.line==='string'?{line:r.line}:{}),...(Array.isArray(r.cues)?{cues:r.cues.slice(0,6)}:{}),...(r.character_contract?{character_contract:r.character_contract}:{}),...(r.master_sha256?{master_sha256:r.master_sha256}:{}),
   // Where a generated clip goes and what it is: the builder places it by these.
   ...Object.fromEntries(['beat','seconds','aspect','audio','engine_label','why','segments'].filter(k=>items[i][k]!=null).map(k=>[k,items[i][k]]))};
  const stage=f=>stageFile(f,{manifest,directory,download,signal,kind:items[i].kind,description:items[i].description,taskId:items[i].id??null,requirementIds:items[i].requirement_ids??[]});
  if(r.status==='succeeded'&&r.file){
   out.file=await stage(r.file);
   // A pose sheet brings several files; each is labelled with its pose.
   if(Array.isArray(r.more_files)&&r.more_files.length){
    const names=[out.file];for(const f of r.more_files.slice(0,5))names.push(await stage(f));
    out.files=names.map((name,k)=>({file:name,pose:(r.poses||[])[k]??null}));
   }
  }
  results[i]=out;
 };
 for(let i=0;i<items.length;i++){
  signal?.throwIfAborted();
  onStage((items[i].kind==='generated_shot'||items[i].kind==='ugc_take'?'Starting ':'Getting ')+(LABEL[items[i].kind]||'plan media')+' ('+(i+1)+' of '+items.length+')');
  const r=await produce(i);
  if(r.status==='pending')waiting.set(i,r);else await settle(i,r);
 }
 const began=Date.now();
 while(waiting.size){
  if(Date.now()-began>maxWaitMs)throw Error('Generated video is still rendering after '+Math.round(maxWaitMs/60000)+' minutes; it stays recorded and a retry collects it');
  const mins=Math.max(1,Math.round((Date.now()-began)/60000));
  onStage('The video model is making '+waiting.size+' generated '+(waiting.size===1?'clip':'clips')+' ('+mins+' min so far; usually 3 to 15)');
  await sleep(pollMs);
  for(const i of [...waiting.keys()]){
   signal?.throwIfAborted();
   const r=await produce(i);
   if(r.status!=='pending'){waiting.delete(i);await settle(i,r);}
  }
 }
 return results;
}
