import {readFile,writeFile,rename,open,unlink} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import {setTimeout as sleep} from 'node:timers/promises';
// Local filesystem coordination only. A stranded lock fails closed; never delete
// one until its owning test process is confirmed stopped. E2 needs DB accounting.
export class TestBudget {
 constructor(file,capUsd=5){this.file=file;this.capUsd=capUsd;}
 async update(change){
  let lock;
  for(let attempt=0;attempt<100;attempt++){
   try{lock=await open(this.file+'.lock','wx',0o600);break;}catch(e){if(e.code!=='EEXIST')throw e;await sleep(10);}
  }
  if(!lock)throw Error('Test budget locked; reconcile the owning process before retrying');
  try {
   let ledger;try{ledger=JSON.parse(await readFile(this.file,'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;ledger={capUsd:this.capUsd,calls:[]};}
   if(ledger.capUsd!==this.capUsd)throw Error('Budget cap changed');
   const result=change(ledger);
   await writeFile(this.file+'.tmp',JSON.stringify(ledger,null,2),{mode:0o600});await rename(this.file+'.tmp',this.file);return result;
  }finally{await lock.close();await unlink(this.file+'.lock');}
 }
 async reserve({prompt,system,maxTokens,image,model}){
  const inputRate=model.includes('opus')?5:3,outputRate=model.includes('opus')?25:15;
  const upper=((Buffer.byteLength(prompt)+Buffer.byteLength(system)+4096+(image?4096:0))*inputRate+maxTokens*outputRate)/1e6;
  const id=randomUUID();
  await this.update(ledger=>{
   if(ledger.calls.reduce((n,c)=>n+c.reservedUsd,0)+upper>this.capUsd)throw Object.assign(Error('Shared live-test budget exhausted'),{code:'BUDGET_EXHAUSTED'});
   ledger.calls.push({id,model,reservedUsd:upper,status:'reserved',createdAt:new Date().toISOString()});
  });
  return async response=>this.update(ledger=>{
   const row=ledger.calls.find(c=>c.id===id);if(!row)throw Error('Test reservation missing');
   if(row.status==='returned'){if(row.predictionId!==response.predictionId)throw Error('Reservation already settled for another prediction');return;}
   row.status='returned';row.predictionId=response.predictionId;row.metrics=response.metrics;
   const i=response.metrics?.token_input_count,o=response.metrics?.token_output_count;
   if(Number.isSafeInteger(i)&&i>=0&&Number.isSafeInteger(o)&&o>=0){
    row.upperBoundUsd=upper;row.tokenPriceEstimateUsd=(i*inputRate+o*outputRate)/1e6;
    row.reservedUsd=Math.min(upper,row.tokenPriceEstimateUsd*1.2+.005);
   }
  });
 }
}
