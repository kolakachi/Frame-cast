// Runs only inside the no-network render container. Browser source never becomes
// a Node script, webpack config, loader or executable chosen by the agent.
import {validateRemotionSource} from '../agent/remotion-source.mjs';
import {bundle} from '@remotion/bundler';
import {selectComposition,renderMedia,renderStill} from '@remotion/renderer';
import {mkdir,mkdtemp,readFile,writeFile,copyFile,readdir,lstat,rm,rename} from 'node:fs/promises';
import path from 'node:path';
import {randomUUID,createHash} from 'node:crypto';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {remotionArgs,remotionSettings,allowedRemotionImport} from '../agent/remotion-contract.mjs';
const [project,...args]=process.argv.slice(2),request=remotionArgs(args);
const settings=JSON.parse(await readFile(path.join(project,'../output-settings.json'),'utf8'));
const spec=remotionSettings(settings,request.duration);
const root=await mkdtemp('/tmp/wyv-remotion-'),src=path.join(root,'src'),publicDir=path.join(root,'public');
const hashes={};await mkdir(src);await mkdir(publicDir);
try{
 for(const name of await readdir(project)){
  const file=path.join(project,name),st=await lstat(file);
  if(!st.isFile())throw Error('Non-file in project');
  if(st.size>200*1024*1024)throw Error('Remotion input too large');
  if(/^[a-zA-Z0-9_-]+\.(js|css)$/.test(name)){
   if(st.size>128000)throw Error('Remotion source too large');
   await copyFile(file,path.join(src,name));hashes[name]=createHash('sha256').update(await readFile(file)).digest('hex');
  }else if(/^[a-zA-Z0-9_.-]+\.(png|jpg|webp|svg|mp4|mp3|wav|ttf)$/.test(name))await copyFile(file,path.join(publicDir,name));
 }
 // Validate only the native component dependency graph; unrelated Hyperframes
 // scripts in the same revision are not Remotion modules.
 const visited=new Set(),pendingSources=[request.source];
 while(pendingSources.length){const name=pendingSources.pop();if(visited.has(name))continue;visited.add(name);
  if(name.endsWith('.js'))pendingSources.push(...validateRemotionSource(await readFile(path.join(src,name),'utf8')));
 }
 // The host owns registration and output geometry, the source exports a component.
 const entry=path.join(root,'entry.jsx');
 await writeFile(entry,`import React from 'react';import {Composition,registerRoot} from 'remotion';import Clip from './src/${request.source}';registerRoot(()=>React.createElement(Composition,{id:'WyvClip',component:Clip,...${JSON.stringify(spec)}}));`);
 const serveUrl=await bundle({entryPoint:entry,publicDir,outDir:path.join(root,'bundle'),enableCaching:false,
  webpackOverride:config=>({...config,resolve:{...config.resolve,modules:['node_modules','/opt/worker/node_modules']},plugins:[...config.plugins,{
   apply(compiler){compiler.hooks.normalModuleFactory.tap('WyvImports',factory=>factory.hooks.beforeResolve.tap('WyvImports',data=>{
    if(data?.contextInfo?.issuer?.startsWith(src+path.sep)&&!allowedRemotionImport(data.request))throw Error('Unsupported Remotion import: '+data.request);
   }));}
  }]})});
 const common={serveUrl,browserExecutable:'/usr/bin/chromium',logLevel:'error',timeoutInMilliseconds:20000,chromiumOptions:{disableWebSecurity:false}};
 const composition=await selectComposition({...common,id:'WyvClip'});
 for(const k of ['width','height','fps','durationInFrames'])if(composition[k]!==spec[k])throw Error('Unexpected composition '+k);
 const ext=request.operation==='still'?'png':'mp4',pending=path.join(root,'pending.'+ext);
 if(request.operation==='still')await renderStill({...common,composition,frame:request.frame,output:pending,imageFormat:'png'});
 else{
  await renderMedia({...common,composition,codec:'h264',outputLocation:pending,concurrency:1,crf:20});
  const exec=promisify(execFile),{stdout}=await exec('ffprobe',['-v','error','-show_streams','-show_format','-of','json',pending]);
  const probe=JSON.parse(stdout),v=probe.streams.find(s=>s.codec_type==='video');
  if(!v||v.width!==spec.width||v.height!==spec.height||v.r_frame_rate!=='24/1'||Math.abs(Number(probe.format.duration)-spec.durationInFrames/24)>.15)throw Error('Remotion output verification failed');
  await exec('ffmpeg',['-v','error','-xerror','-i',pending,'-f','null','-'],{timeout:30000});
 }
 const name='remotion-'+randomUUID()+'.'+ext;
 // Copy then atomic rename: tmp and output may live on different filesystems.
 const temp=path.join(project,'.'+name+'.pending');await copyFile(pending,temp);await rename(temp,path.join(project,name));
 console.log(JSON.stringify({engine:'remotion',version:'4.0.532',operation:request.operation,source:request.source,sourceHashes:hashes,output:name,...spec}));
}finally{await rm(root,{recursive:true,force:true});}
