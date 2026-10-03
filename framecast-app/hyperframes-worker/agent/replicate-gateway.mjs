import {setTimeout as sleep} from 'node:timers/promises';

// No provider token, model URL or arbitrary HTTP proxy. The app owns the contract.
export class ReplicateGatewayProvider {
  constructor({call,upload,maxCallUsd}) { this.call=call;this.upload=upload;this.maxCallUsd=maxCallUsd;this.id='replicate:app-gateway'; }
  async prepareImage(image,signal) {
    signal?.throwIfAborted();
    return image ? (await this.upload(image)).url : image;
  }
  async complete({prompt,system,maxTokens,image,attemptId,onPrediction=async()=>{},signal}) {
    if(!attemptId)throw Error('An app attempt is required');
    const body={prompt,system,maxTokens,image:image??null};
    signal?.throwIfAborted();
    let p=await this.call(attemptId,{input:body});
    await onPrediction(p.id);
    while(['starting','processing'].includes(p.status)) {
      await sleep(1500,undefined,{signal});
      p=await this.call(attemptId,{input:body,poll:true});
    }
    if(p.status!=='succeeded'||!Array.isArray(p.output)||p.output.some(x=>typeof x!=='string'))throw Error('Provider result needs reconciliation');
    return {text:p.output.join(''),predictionId:p.id,metrics:p.metrics??{},actualCostUsd:null};
  }
}
