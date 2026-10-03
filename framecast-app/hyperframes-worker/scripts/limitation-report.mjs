// Owner-only local report. No network, provider calls, database writes or retries.
import {readdir,readFile,lstat} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {summarizeLimitations,observeStop} from '../agent/limitations.mjs';
const root=path.resolve(process.argv[2]||fileURLToPath(new URL('../artifacts/live/',import.meta.url)));
const runs=[],skipped=[];
for(const entry of (await readdir(root,{withFileTypes:true})).filter(e=>e.isDirectory()&&/^[a-zA-Z0-9_-]+$/.test(e.name)).sort((a,b)=>a.name.localeCompare(b.name))){
 const file=path.join(root,entry.name,'agent-state.json');
 try{
  const stat=await lstat(file);if(!stat.isFile()||stat.size>32*1024*1024){skipped.push({run:entry.name,reason:'not a regular bounded state file'});continue;}
  const state=JSON.parse(await readFile(file,'utf8'));
  // Older runs did not have the reporting tool. Recover only their recorded
  // terminal outcome, never invent an agent explanation or modify their journal.
  if(!state.limitations?.length&&(state.reason||state.recoveredDraft)){
   observeStop(state,state.reason||'Draft recovered before review completed');
   for(const record of state.limitations){record.code='legacy_'+record.code;record.evidence_status='legacy_recorded_outcome_only';}
  }
  runs.push({run:entry.name,state});
 }catch(e){if(e.code!=='ENOENT')skipped.push({run:entry.name,reason:'state unreadable'});}
}
console.log(JSON.stringify({...summarizeLimitations(runs),scope:'Local journals, including fixtures and experiments; not production failure rates. Legacy entries contain terminal outcomes only.',skipped},null,2));
