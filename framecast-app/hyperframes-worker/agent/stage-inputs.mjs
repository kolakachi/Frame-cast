import {mkdir,writeFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';

// Manifest comes from the authenticated coordinator, not the model. Still validate
// filenames and sizes before touching the host filesystem. References stay separate.
export async function stageInputs({directory,files=[],baseBundle=null,download,signal,maxBytes=200*1024*1024}) {
 if(!Array.isArray(files)||files.length>20)throw Error('Invalid input manifest');
 const seen=new Set();let total=0;
 for(const file of files){
  if(!Number.isSafeInteger(file.asset_id)||file.asset_id<1||seen.has(file.asset_id)
    ||!['source','reference'].includes(file.purpose)||!['image','video','audio'].includes(file.asset_type)
    ||!Number.isSafeInteger(file.bytes)||file.bytes<1||file.bytes>100*1024*1024
    ||!/^[a-f0-9]{64}$/.test(file.sha256)
    ||!new RegExp('^asset-'+file.asset_id+'-'+file.sha256+'\\.(png|jpg|webp|svg|mp4|mp3|wav)$').test(file.name))throw Error('Invalid input manifest');
  seen.add(file.asset_id);total+=file.bytes;
 }
 if(total>maxBytes)throw Error('Input manifest exceeds byte limit');
 if(baseBundle && (typeof baseBundle!=='object'||Array.isArray(baseBundle)||Object.keys(baseBundle).length>30
  ||Object.entries(baseBundle).some(([name,text])=>!/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(name)||typeof text!=='string'||Buffer.byteLength(text)>128000)))throw Error('Invalid base revision');
 await mkdir(directory); // Exclusive run directory: never overwrite an earlier attempt.
 const manifest=[];
 for(const file of files){
  signal?.throwIfAborted();
  const response=await download(file.asset_id,signal);
  if(!response.ok||!response.body)throw Error('Input download failed');
  const reader=response.body.getReader(),parts=[];let size=0;
  try{
   for(;;){signal?.throwIfAborted();const {done,value}=await reader.read();if(done)break;size+=value.byteLength;if(size>file.bytes)throw Error('Input size mismatch');parts.push(value);}
  }catch(e){await reader.cancel().catch(()=>{});throw e;}
  const data=Buffer.concat(parts);
  if(size!==file.bytes||createHash('sha256').update(data).digest('hex')!==file.sha256)throw Error('Input hash or size mismatch');
  await mkdir(directory+'/'+file.purpose,{recursive:true});
  await writeFile(directory+'/'+file.purpose+'/'+file.name,data,{flag:'wx',mode:0o600});
  manifest.push({...file,path:file.purpose+'/'+file.name});
 }
 if(baseBundle){await mkdir(directory+'/base');for(const [name,text] of Object.entries(baseBundle))await writeFile(directory+'/base/'+name,text,{flag:'wx',mode:0o600});}
 await writeFile(directory+'/manifest.json',JSON.stringify(manifest,null,2),{flag:'wx',mode:0o600});
 return manifest;
}
