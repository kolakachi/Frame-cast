import {createHash} from 'node:crypto';
import {mkdir,writeFile} from 'node:fs/promises';

const LABEL={stock_video:'stock footage',stock_image:'a stock photo',ai_image:'an AI image',animate_image:'an animation',voiceover:'narration',cloned_voiceover:'narration in your voice',library_music:'music',music:'music',sfx:'sound effects',character_poses:'the character preview',character_variants:'poses from your approved character',talking_shot:'the talking shot',talking_take:'the talking take',brand_kit:'your brand kit'};

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

export async function buyPlanMedia({items,produce,download,directory,manifest,onStage=()=>{},signal}){
 const results=[];
 for(let i=0;i<items.length;i++){
  signal?.throwIfAborted();
  onStage('Getting '+(LABEL[items[i].kind]||'plan media')+' ('+(i+1)+' of '+items.length+')');
  const r=await produce(i);
  const out={task_id:items[i].id??null,requirement_ids:items[i].requirement_ids??[],kind:items[i].kind,description:items[i].description,status:r.status,speech_mode:r.speech_mode??'audio_driven',engine:r.engine??null,reused:!!r.reused,charged_credits:r.charged_credits??0,...(r.error?{error:r.error}:{}),...(r.brand?{brand:r.brand}:{}),...(typeof r.line==='string'?{line:r.line}:{}),...(Array.isArray(r.cues)?{cues:r.cues.slice(0,6)}:{}),...(r.character_contract?{character_contract:r.character_contract}:{}),...(r.master_sha256?{master_sha256:r.master_sha256}:{})};
  const stage=f=>stageFile(f,{manifest,directory,download,signal,kind:items[i].kind,description:items[i].description,taskId:items[i].id??null,requirementIds:items[i].requirement_ids??[]});
  if(r.status==='succeeded'&&r.file){
   out.file=await stage(r.file);
   // A pose sheet brings several files; each is labelled with its pose.
   if(Array.isArray(r.more_files)&&r.more_files.length){
    const names=[out.file];for(const f of r.more_files.slice(0,5))names.push(await stage(f));
    out.files=names.map((name,k)=>({file:name,pose:(r.poses||[])[k]??null}));
   }
  }
  results.push(out);
 }
 return results;
}
