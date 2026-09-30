import {createHash} from 'node:crypto';
import {mkdir,writeFile} from 'node:fs/promises';

const LABEL={stock_video:'stock footage',stock_image:'a stock photo',ai_image:'an AI image',animate_image:'an animation',voiceover:'narration',cloned_voiceover:'narration in your voice',library_music:'music',brand_kit:'your brand kit'};

// Asks the app to buy each approved plan item in order, then stages the
// returned files as usable source footage. A failed item never stops the
// build: the agent is told what is missing and works around it.
export async function buyPlanMedia({items,produce,download,directory,manifest,onStage=()=>{},signal}){
 const results=[];
 for(let i=0;i<items.length;i++){
  signal?.throwIfAborted();
  onStage('Getting '+(LABEL[items[i].kind]||'plan media')+' ('+(i+1)+' of '+items.length+')');
  const r=await produce(i);
  const out={kind:items[i].kind,description:items[i].description,status:r.status,reused:!!r.reused,charged_credits:r.charged_credits??0,...(r.error?{error:r.error}:{}),...(r.brand?{brand:r.brand}:{}),...(Array.isArray(r.cues)?{cues:r.cues.slice(0,6)}:{})};
  const f=r.file;
  if(r.status==='succeeded'&&f){
   if(!Number.isSafeInteger(f.asset_id)||!/^[a-f0-9]{64}$/.test(f.sha256)||!new RegExp('^asset-'+f.asset_id+'-'+f.sha256+'\\.(png|jpg|webp|mp4|mp3|wav)$').test(f.name))throw Error('Invalid plan media record');
   if(!manifest.some(m=>m.asset_id===f.asset_id)){
    const response=await download(f.asset_id,signal);
    if(!response.ok)throw Error('Plan media download failed');
    const data=Buffer.from(await response.arrayBuffer());
    if(data.length!==f.bytes||createHash('sha256').update(data).digest('hex')!==f.sha256)throw Error('Plan media hash or size mismatch');
    await mkdir(directory+'/source',{recursive:true});
    await writeFile(directory+'/source/'+f.name,data,{flag:'wx',mode:0o600});
    manifest.push({...f,path:'source/'+f.name,plan_media:{kind:items[i].kind,description:items[i].description}});
   }
   out.file=f.name;
  }
  results.push(out);
 }
 return results;
}
