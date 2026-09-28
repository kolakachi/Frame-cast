import {readFile,writeFile,rename} from 'node:fs/promises';
// Single local coordinator; no parallel writers. Unknown requests retain their full bound.
export class TestBudget {
 constructor(file,capUsd=5){this.file=file;this.capUsd=capUsd;}
 async reserve({prompt,system,maxTokens,image,model}){
  const inputRate=model.includes('opus')?5:3,outputRate=model.includes('opus')?25:15;
  // UTF-8 bytes conservatively bound text tokens; additional framing/image headroom.
  const upper=((Buffer.byteLength(prompt)+Buffer.byteLength(system)+4096+(image?4096:0))*inputRate+maxTokens*outputRate)/1e6;
  let ledger;try{ledger=JSON.parse(await readFile(this.file,'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;ledger={capUsd:this.capUsd,calls:[]};}
  if(ledger.capUsd!==this.capUsd)throw Error('Budget cap changed');
  if(ledger.calls.reduce((n,c)=>n+c.reservedUsd,0)+upper>this.capUsd)throw Object.assign(Error('Shared live-test budget exhausted'),{code:'BUDGET_EXHAUSTED'});
  const row={model,reservedUsd:upper,status:'reserved',createdAt:new Date().toISOString()};ledger.calls.push(row);
  await writeFile(this.file+'.tmp',JSON.stringify(ledger,null,2),{mode:0o600});await rename(this.file+'.tmp',this.file);
  return async response=>{row.status='returned';row.predictionId=response.predictionId;row.metrics=response.metrics;
   const i=response.metrics?.token_input_count,o=response.metrics?.token_output_count;
   if(Number.isSafeInteger(i)&&i>=0&&Number.isSafeInteger(o)&&o>=0){
     row.upperBoundUsd=upper;row.tokenPriceEstimateUsd=(i*inputRate+o*outputRate)/1e6;
     // Verified Replicate metric names and model page rates; retain 20% plus half-cent headroom.
     row.reservedUsd=Math.min(upper,row.tokenPriceEstimateUsd*1.2+.005);
   }
   await writeFile(this.file+'.tmp',JSON.stringify(ledger,null,2),{mode:0o600});await rename(this.file+'.tmp',this.file);
  };
 }
}
