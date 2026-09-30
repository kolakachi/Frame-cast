import {TestBudget} from './budget.mjs';
import {randomUUID} from 'node:crypto';

// A second, conservative host limit survives disposal of the test API/database.
// Unknowns retain their entire reservation. Completed calls may settle only from
// the app-verified provider receipt, retaining 20% plus $0.005 metering headroom.
// Start a new campaign only with a new explicit user allowance.
export class PilotBudget {
 // capUsd null = no ledger cap (an explicit owner decision); per-run limits still apply.
 constructor(file,capUsd=5){if(!(capUsd===null||(capUsd>0&&capUsd<=10)))throw Error('Invalid pilot allowance');this.cap=capUsd;this.ledger=new TestBudget(file,capUsd);}
 async settle(id,{status,prediction_id,cost_microusd}){
  if(status!=='succeeded'||!prediction_id||!Number.isSafeInteger(cost_microusd)||cost_microusd<0)throw Error('Verified successful metering required');
  return this.ledger.update(ledger=>{
   const row=ledger.calls.find(c=>c.id===id);if(!row)throw Error('Reservation missing');
   if(row.predictionId){if(row.predictionId!==prediction_id||row.costMicrousd!==cost_microusd)throw Error('Receipt changed');return;}
   if(ledger.calls.some(c=>c.predictionId===prediction_id))throw Error('Receipt already used');
   if(cost_microusd/1e6>row.reservedUsd)throw Error('Cost exceeds reservation');
   row.upperBoundUsd=row.reservedUsd;row.predictionId=prediction_id;row.costMicrousd=cost_microusd;row.status='metered';row.reservedUsd=Math.min(row.upperBoundUsd,cost_microusd/1e6*1.2+.005);
  });
 }
 async reserve(model,usd){
  if(!((model==='anthropic/claude-4.5-sonnet'&&usd===.3)||(model==='claude-opus-5-5'&&usd===.3)||(model==='google/nano-banana'&&usd===.1)||(model==='wan-video/wan-2.5-i2v'&&usd===.6)))throw Error('Unpriced pilot call');
  return this.ledger.update(ledger=>{
   const sum=ledger.calls.reduce((total,c)=>total+c.reservedUsd,0);
   if(this.cap!==null&&sum+usd>this.cap+1e-9)throw Object.assign(Error('Additional $'+this.cap+' pilot allowance exhausted'),{code:'BUDGET_EXHAUSTED'});
   const id=randomUUID();ledger.calls.push({id,model,reservedUsd:usd,status:'reserved',createdAt:new Date().toISOString()});return id;
  });
 }
}
