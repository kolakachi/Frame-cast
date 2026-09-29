import {uploadProviderImage} from './provider-files.mjs';
import {readFile,writeFile} from 'node:fs/promises';
import {setTimeout as sleep} from 'node:timers/promises';
import {accountedCall} from './accounted-call.mjs';

// Uses the app's shared image adapter request and pricing. Only frozen source bytes
// become provider inputs; reference-only uploads cannot accidentally be copied.
export async function executeImage({directory,input,manifest,token,begin,settle,bindPrediction,signal,fetchImpl=fetch}) {
 const model=input.execution_policy?.media?.model,animation=model==='wan-video/wan-2.5-i2v';
 if(!['google/nano-banana','wan-video/wan-2.5-i2v'].includes(model)||!token)throw Error('Unsupported image execution');
 const request={...input.media_input};
 const sources=manifest.filter(f=>f.purpose==='source');
 if(sources.length>4||sources.some(f=>f.asset_type!=='image'||f.bytes>10*1024*1024))throw Error('Use up to four source images, each at most 10 MB for generation');
 if(animation && sources.length!==1)throw Error('One source image is required');
 const urls=await Promise.all(sources.map(async f=>uploadProviderImage({bytes:await readFile(directory+'/inputs/'+f.path),type:f.mime_type,token,signal,fetchImpl})));
 if(animation)request.image=urls[0];else if(urls.length)request.image_input=urls;
 const api=async(route,body)=>{
  const r=await fetchImpl('https://api.replicate.com/v1/'+route,{method:body?'POST':'GET',redirect:'error',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json','Cancel-After':animation?'300s':'120s'},body:body?JSON.stringify(body):undefined,signal});
  if(!r.ok)throw Error('Image provider HTTP '+r.status);return r.json();
 };
 const prediction=await accountedCall({key:'media-1',kind:'media',input:request,begin,settle,
  execute:async attempt=>{
   let p=await api('models/'+model+'/predictions',{input:request});
   if(!/^[a-zA-Z0-9_-]+$/.test(p.id??''))throw Error('Missing prediction ID');
   await writeFile(directory+'/media-prediction.json',JSON.stringify({id:p.id}),{flag:'wx',mode:0o600});
   await bindPrediction(attempt,p.id);
   while(['starting','processing'].includes(p.status)){await sleep(1500,undefined,{signal});p=await api('predictions/'+p.id);}
   if(p.status!=='succeeded')throw Error('Image provider did not succeed. Review the recorded prediction before retrying.');
   return p;
  },receipt:p=>({status:'succeeded',prediction_id:p.id})});
 const output=Array.isArray(prediction.output)?prediction.output[0]:prediction.output;
 const url=new URL(output);
 if(url.protocol!=='https:'||!(url.hostname==='replicate.delivery'||url.hostname.endsWith('.replicate.delivery'))||url.username||url.password)throw Error('Unapproved media delivery host');
 const response=await fetchImpl(url,{redirect:'error',signal});
 if(!response.ok||!response.body)throw Error('Output download failed');
 const parts=[];let size=0;
 for await(const chunk of response.body){size+=chunk.length;if(size>(animation?100:20)*1024*1024)throw Error('Image output exceeds size limit');parts.push(chunk);}
 const bytes=Buffer.concat(parts),type=response.headers.get('content-type')?.split(';')[0];
 if(!(animation?['video/mp4']:['image/png','image/jpeg','image/webp']).includes(type)||size===0)throw Error('Invalid image response');
 const artifact=directory+(animation?'/animation.mp4':'/image.'+({'image/png':'png','image/jpeg':'jpg','image/webp':'webp'}[type]));
 await writeFile(artifact,bytes,{flag:'wx',mode:0o600});
 return {artifact,type,bundle:{'index.html':'<!-- Image output; no executable composition. -->'},summary:animation?'Image animation ready. Check motion and source fidelity before using it.':'Image created from your brief. Check the result before using it.'};
}
