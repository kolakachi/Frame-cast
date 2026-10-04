import {readFile,readdir} from 'node:fs/promises';

// "Try again" after a build stopped continues from where it got to: the newest earlier run of the same plan and
// stage, if it ended without a version (failed or cancelled), hands over its last checked draft, else its latest files.
export async function findResume(run,live){
 const planId=run.input.plan?.plan_id,conv=run.input.conversation_id,stage=run.input.build_stage??null;
 if(!planId||!conv)return null;
 const found=[];
 for(const name of await readdir(live).catch(()=>[])){
  if(!/^app-[a-f0-9-]{36}$/.test(name)||name==='app-'+run.id)continue;
  const j=await readFile(live+'/'+name+'/started.json','utf8').then(JSON.parse).catch(()=>null);
  if(j&&j.conversationId===conv&&j.planId===planId&&(j.stage??null)===stage)found.push({name,at:String(j.startedAt)});
 }
 const last=found.sort((a,b)=>b.at.localeCompare(a.at))[0];if(!last)return null;
 const st=await readFile(live+'/'+last.name+'/agent-state.json','utf8').then(JSON.parse).catch(()=>null);
 if(!st||!['failed','cancelled'].includes(st.status))return null;
 const ok=n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n);
 let files=st.lastGood?.files&&Object.keys(st.lastGood.files).some(ok)?Object.fromEntries(Object.entries(st.lastGood.files).filter(([n])=>ok(n))):null;const checked=!!files;
 if(!files){files={};for(const n of (await readdir(live+'/'+last.name+'/project').catch(()=>[])).filter(ok))files[n]=await readFile(live+'/'+last.name+'/project/'+n,'utf8');}
 return files['index.html']?{files,checked,from:last.name.slice(4)}:null;
}
