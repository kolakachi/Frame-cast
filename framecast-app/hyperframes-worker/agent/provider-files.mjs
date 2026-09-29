import {createHash} from 'node:crypto';

// Upload before fingerprinting the generation request. Replicate redacts inline
// data URIs in receipts; its stable file URL survives receipt verification.
export async function uploadProviderImage({bytes,type,token,signal,fetchImpl=fetch}) {
 if(!['image/png','image/jpeg','image/webp'].includes(type)||bytes.length>10*1024*1024)throw Error('Unsupported provider image');
 const hash=createHash('sha256').update(bytes).digest('hex');
 const form=new FormData();form.set('content',new Blob([bytes],{type}),hash+'.'+({'image/png':'png','image/jpeg':'jpg','image/webp':'webp'}[type]));
 form.set('metadata',new Blob([JSON.stringify({sha256:hash})],{type:'application/json'}));
 const r=await fetchImpl('https://api.replicate.com/v1/files',{method:'POST',redirect:'error',headers:{Authorization:'Bearer '+token},body:form,signal});
 if(!r.ok)throw Error('Provider input upload failed');
 const file=await r.json(),url=new URL(file.urls?.get);
 const providerFile=url.hostname==='api.replicate.com' && /^\/v1\/files\/[A-Za-z0-9_.-]+$/.test(url.pathname) && !url.search;
 if(url.protocol!=='https:'||!providerFile||url.port||url.username||url.password||url.hash)throw Error('Invalid provider file URL');
 return url.href;
}
