// Explicitly paid local benchmark. Shares the existing $5 ledger; never resets it.
import {spawn} from 'node:child_process';
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const worker=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const cases=[
 ['product-opus-e1final','opus','new','restrained','product'],
 ['product-sonnet-e1final','sonnet','edit','restrained','product'],
 ['product-opus-e1final','opus','edit','restrained','product'],
 ...['sonnet','opus'].flatMap(model=>['footage','typography','reference'].map(scenario=>['bench-'+model+'-'+scenario,model,'new',scenario==='footage'?'educational':'restrained',scenario])),
 ...['energetic','restrained'].map(style=>['bench-sonnet-footage-'+style,'sonnet','new',style,'footage']),
];
let report=[];try{report=JSON.parse(await readFile(worker+'/artifacts/benchmark/report.json','utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
const ledger=()=>readFile(worker+'/artifacts/live/budget.json','utf8').then(JSON.parse);
for(const args of cases){
 const before=await ledger();
 const stateFile=worker+'/artifacts/live/'+args[0]+'/'+args[2]+'-state.json';
 try{await readFile(stateFile);continue;}catch(e){if(e.code!=='ENOENT')throw e;}
 if(args[2]==='edit'){const prior=JSON.parse(await readFile(worker+'/artifacts/live/'+args[0]+'/new-state.json','utf8'));if(prior.status!=='preview_ready'){console.log(JSON.stringify({id:args[0],skipped:'Creation has not passed; no paid follow-up edit'}));continue;}}
 const child=spawn(process.execPath,[worker+'/agent/live-smoke.mjs',...args],{cwd:worker,stdio:['ignore','pipe','pipe']});let log='';for(const stream of [child.stdout,child.stderr])stream.on('data',b=>{log+=b;});
 const exitCode=await new Promise(resolve=>child.on('close',resolve));
 await mkdir(worker+'/artifacts/benchmark',{recursive:true});await writeFile(worker+'/artifacts/benchmark/'+args[0]+'-'+args[2]+'.log',log);
 let state;try{state=JSON.parse(await readFile(stateFile,'utf8'));}catch{state={status:'driver_failed'};}
 const after=await ledger(),added=after.calls.slice(before.calls.length);
 const row={id:args[0],model:args[1],mode:args[2],style:args[3],scenario:args[4],exitCode,status:state.status,reason:state.reason,calls:added.length,attemptSlots:state.calls,repairs:state.repairs,elapsedMs:state.elapsedMs,estimatedModelUsd:added.reduce((s,c)=>s+(c.tokenPriceEstimateUsd??0),0),reservedUsd:added.reduce((s,c)=>s+c.reservedUsd,0),humanReview:'pending',usage:state.usage};
 report.push(row);await writeFile(worker+'/artifacts/benchmark/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify({...row,usage:undefined}));
 if(state.status==='budget_exhausted')break;
}
