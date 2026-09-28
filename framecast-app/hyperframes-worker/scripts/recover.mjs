// Offline recovery only: caller must stop the exclusively owned local worker first.
import {readdir,readFile,writeFile,rename,rm} from 'node:fs/promises';
import path from 'node:path';
const root=process.argv[2];
if (!root || process.argv[3] !== '--worker-stopped') throw Error('Stop the local worker first, then pass ROOT --worker-stopped');
let recovered=0;
for (const item of await readdir(root,{withFileTypes:true})) {
  if(!item.isDirectory() || !/^[a-f0-9-]{36}$/.test(item.name)) continue;
  const dir=path.join(root,item.name);
  let state; try {state=JSON.parse(await readFile(path.join(dir,'state.json'),'utf8'));} catch(e){if(e.code==='ENOENT')continue; throw e;}
  if(state.status!=='running')continue;
  for(const file of ['pending.mp4','video.mp4'])await rm(path.join(dir,file),{force:true});
  Object.assign(state,{status:'failed',artifact:null,error:'Worker interrupted; retry in a new run'});
  await writeFile(path.join(dir,'state.tmp'),JSON.stringify(state,null,2));
  await rename(path.join(dir,'state.tmp'),path.join(dir,'state.json'));recovered++;
}
console.log(JSON.stringify({recovered}));
