import {createHash} from 'node:crypto';
import {lstat,readFile,readdir,writeFile} from 'node:fs/promises';
import path from 'node:path';

const roots=['agent','scripts','runtime','fixtures','package.json','package-lock.json','Dockerfile','.dockerignore','compose.local.yml','compose.release.yml'];
const hash=b=>createHash('sha256').update(b).digest('hex');
const hex=/^[a-f0-9]{64}$/;
export async function fingerprint(root,{art=false}={}){
 const entries=[];
 async function walk(relative){
  if(path.basename(relative)==='.DS_Store'||(!art&&relative==='runtime/art-packs'))return;
  const absolute=path.join(root,relative),stat=await lstat(absolute);
  if(stat.isSymbolicLink())throw Error('Release input must not be a symbolic link: '+relative);
  if(stat.isDirectory()){
   for(const name of (await readdir(absolute)).sort())await walk(relative+'/'+name);
  }else if(stat.isFile()){
   if(path.basename(relative).startsWith('.env'))throw Error('Environment file in release inputs');
   entries.push([relative,stat.size,hash(await readFile(absolute))]);
  }else throw Error('Unsupported release input: '+relative);
 }
 for(const relative of art?['runtime/art-packs']:roots)await walk(relative);
 if(!entries.length)throw Error('Empty release inputs');
 return {sha256:hash(JSON.stringify(entries)),files:entries.length};
}
export function validateManifest(m){
 if(m?.schema!==1||!/^([a-f0-9]{40})$/.test(m.revision)||m.protocol!=='create-worker-v1'
  ||!/^sha256:[a-f0-9]{64}$/.test(m.sandbox_image)||!['arm64','amd64'].includes(m.architecture)
  ||![m.source,m.art].every(x=>hex.test(x?.sha256)&&Number.isSafeInteger(x.files)&&x.files>0))throw Error('Invalid Create release manifest');
 return m;
}
export function verifyImage(manifest,image){
 validateManifest(manifest);
 const labels=image?.Config?.Labels??{};
 if(image?.Id!==manifest.sandbox_image||image?.Architecture!==manifest.architecture
   ||labels['com.wyv.create.revision']!==manifest.revision
   ||labels['com.wyv.create.source']!==manifest.source.sha256)throw Error('Sandbox image does not match the sealed worker release');
}
export async function verifyRelease(root,manifest,image){
 validateManifest(manifest);
 const source=await fingerprint(root),art=await fingerprint(root,{art:true});
 if(JSON.stringify(source)!==JSON.stringify(manifest.source)||JSON.stringify(art)!==JSON.stringify(manifest.art))throw Error('Worker source or art pack changed after sealing');
 verifyImage(manifest,image);
 return manifest;
}
export async function sealRelease(root,{revision,image}){
 const manifest=validateManifest({schema:1,revision,protocol:'create-worker-v1',sandbox_image:image.Id,
  architecture:image.Architecture,source:await fingerprint(root),art:await fingerprint(root,{art:true})});
 verifyImage(manifest,image);
 await writeFile(path.join(root,'RELEASE.json'),JSON.stringify(manifest,null,2)+'\n',{flag:'wx',mode:0o644});
 return manifest;
}
