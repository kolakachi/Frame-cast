// Run from a prepared, clean checkout. Does not build images, install dependencies or contact providers.
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {fingerprint,sealRelease,verifyRelease} from '../agent/release-manifest.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const command=process.argv[2];
const docker=process.env.DOCKER_BIN??'docker';
const inspect=id=>JSON.parse(execFileSync(docker,['image','inspect',id],{encoding:'utf8',timeout:30000}))[0];
try{
 if(command==='fingerprint')console.log((await fingerprint(root)).sha256);
 else if(command==='seal'){
  const revision=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  const dirty=execFileSync('git',['status','--porcelain','--untracked-files=all','--','.'],{cwd:root,encoding:'utf8'}).trim();
  if(dirty)throw Error('Commit worker changes and remove untracked release inputs before sealing');
  if(!process.argv[3])throw Error('Pass the locally built sandbox image ID or tag');
  console.log(JSON.stringify(await sealRelease(root,{revision,image:inspect(process.argv[3])})));
 }else if(command==='verify'){
  const manifest=JSON.parse(await readFile(root+'/RELEASE.json','utf8'));
  await verifyRelease(root,manifest,inspect(manifest.sandbox_image));
  console.log(JSON.stringify({verified:true,revision:manifest.revision,sandbox_image:manifest.sandbox_image,art:manifest.art}));
 }else throw Error('Use fingerprint, seal IMAGE, or verify');
}catch(error){console.error(error.message);process.exitCode=1;}
