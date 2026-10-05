import {readFile,readdir,access} from 'node:fs/promises';
import {createHash} from 'node:crypto';

// What a build was approved to make: the plan with its selections, the output settings and the stage. A build is only
// continued by a run with the same fingerprint, so a changed selection starts fresh.
export function planHash(input){
 return createHash('sha256').update(JSON.stringify({plan:input?.plan??null,settings:input?.settings??null,stage:input?.build_stage??null})).digest('hex');
}

// Files a draft's code points at: audio, clips and images by name. The run's own inputs (asset-…) are staged again for
// the new run; anything the build made itself (derived files, rendered clips) has to come along with the code.
const MEDIA=/["'(=\s]([A-Za-z0-9_.-]+\.(?:wav|mp3|mp4|webm|mov|png|jpe?g|webp|svg|gif))(?=["')\s])/g;

// "Try again" after a build stopped continues from where it got to: the newest earlier run of the same approved plan,
// settings and stage, if it ended without a version (failed or cancelled), hands over its last checked draft (else its
// latest files) with every file the draft uses. A draft whose media is missing is not continued.
// current: this run's input file names (asset-<id>-<sha>.<ext>). An input re-prepared since the draft was written (a
// video made renderable) has a new name; the draft's references to the old one are pointed at it.
export function renameInputs(files,current=[]){
 const byId=new Map(current.map(n=>[n.match(/^asset-(\d+)-/)?.[1],n]).filter(([id])=>id));
 return Object.fromEntries(Object.entries(files).map(([k,t])=>[k,String(t).replace(/asset-(\d+)-[a-f0-9]{64}\.[a-z0-9]+/g,m=>{const id=m.match(/^asset-(\d+)-/)[1];return byId.get(id)??m;})]));
}

export async function findResume(run,live,current=[]){
 const conv=run.input.conversation_id,want=planHash(run.input);
 if(!run.input.plan?.plan_id||!conv)return null;
 const found=[];
 for(const name of await readdir(live).catch(()=>[])){
  if(!/^app-[a-f0-9-]{36}$/.test(name)||name==='app-'+run.id)continue;
  const j=await readFile(live+'/'+name+'/started.json','utf8').then(JSON.parse).catch(()=>null);
  if(j&&j.conversationId===conv&&j.planHash===want)found.push({name,at:String(j.startedAt)});
 }
 const last=found.sort((a,b)=>b.at.localeCompare(a.at))[0];if(!last)return null;
 const dir=live+'/'+last.name,st=await readFile(dir+'/agent-state.json','utf8').then(JSON.parse).catch(()=>null);
 // A builder that finished counts too when the run failed after it (saving its files, say): its draft is done.
 const runFailed=await access(dir+'/failure.json').then(()=>true,()=>false);
 if(!st||!(['failed','cancelled'].includes(st.status)||(runFailed&&st.status==='preview_ready')))return null;
 const ok=n=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(n);
 let files=st.lastGood?.files&&Object.keys(st.lastGood.files).some(ok)?Object.fromEntries(Object.entries(st.lastGood.files).filter(([n])=>ok(n))):null;const checked=!!files;
 if(!files){files={};for(const n of (await readdir(dir+'/project').catch(()=>[])).filter(ok))files[n]=await readFile(dir+'/project/'+n,'utf8');}
 if(!files['index.html'])return null;
 files=renameInputs(files,current);
 const media=[];
 for(const name of new Set(Object.values(files).flatMap(t=>[...String(t).matchAll(MEDIA)].map(m=>m[1])))){
  if(/^asset-\d+-/.test(name))continue;
  const path=dir+'/project/'+name;
  if(!await access(path).then(()=>true,()=>false))return null;
  media.push({name,path});
 }
 return {files,media,checked,from:last.name.slice(4)};
}
