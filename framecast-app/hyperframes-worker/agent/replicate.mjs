import {uploadProviderImage} from './provider-files.mjs';
import {setTimeout as sleep} from 'node:timers/promises';
const origin='https://api.replicate.com/v1';
export class ReplicateProvider {
  constructor({contract,token,enabled=false,maxCallUsd,fetchImpl=fetch,pollMs=1000}) {
    if(!enabled || !token)throw Error('Live provider requires explicit enablement and a test credential');
    if(!/^anthropic\/[a-z0-9.-]+$/.test(contract.model)||!Number.isFinite(maxCallUsd)||maxCallUsd<=0)throw Error('Invalid model or conservative per-call reservation');
    this.contract=contract;this.token=token;this.fetch=fetchImpl;this.maxCallUsd=maxCallUsd;this.pollMs=pollMs;this.id=contract.model+':'+contract.observedVersion;
  }
  async prepareImage(image,signal) {
    if(!image || !image.startsWith('data:'))return image;
    const match=image.match(/^data:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$/);
    if(!match)throw Error('Invalid image input');
    return uploadProviderImage({bytes:Buffer.from(match[2],'base64'),type:match[1],token:this.token,signal,fetchImpl:this.fetch});
  }
  async request(route,method,body,signal) {
    const response=await this.fetch(origin+route,{method,redirect:'error',headers:{Authorization:`Bearer ${this.token}`,'Content-Type':'application/json','Cancel-After':'120s'},body:body?JSON.stringify(body):undefined,signal});
    if(!response.ok)throw Error(`Replicate HTTP ${response.status}`); // Never log tokens or provider request bodies.
    return response.json();
  }
  async complete({prompt,system,maxTokens,image,signal,onPrediction=async()=>{}}) {
    const bounds=this.contract.input.properties.max_tokens;
    if(!Number.isInteger(maxTokens)||maxTokens<bounds.minimum||maxTokens>bounds.maximum)throw Error('Output token limit outside verified schema');
    const input={prompt,system_prompt:system,max_tokens:maxTokens};
    if(image){if(!/^https:\/\//.test(image) && !/^data:image\/(png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(image))throw Error('Only host-approved images allowed');if(image.length>1400000)throw Error('Inline image too large');input.image=image;input.max_image_resolution=.5;}
    let id;
    try {
      // Do not retry this POST: an interrupted response may already have incurred spend.
      let prediction=await this.request(`/models/${this.contract.model}/predictions`,'POST',{input},signal);
      if(!/^[a-zA-Z0-9_-]+$/.test(prediction.id??''))throw Error('Missing/invalid prediction id');
      id=prediction.id;await onPrediction(id);
      while(['starting','processing'].includes(prediction.status)) {
        await sleep(this.pollMs,undefined,{signal});
        prediction=await this.request(`/predictions/${id}`,'GET',undefined,signal);
      }
      if(prediction.status!=='succeeded')throw Error(`Prediction ended: ${prediction.status}`);
      if(prediction.version==='hidden'){const model=await this.request(`/models/${this.contract.model}`,'GET',undefined,signal);if(model.latest_version?.id!==this.contract.observedVersion)throw Error('Advertised model schema drift');}
      else if(prediction.version!==this.contract.observedVersion)throw Error('Model version drift; verify contract before another call');
      if(!Array.isArray(prediction.output)||prediction.output.some(v=>typeof v!=='string'))throw Error('Unexpected model output');
      return {text:prediction.output.join(''),predictionId:id,metrics:prediction.metrics??{},actualCostUsd:null};
    } catch(e) {
      if(id && signal?.aborted) {
        try {await this.request(`/predictions/${id}/cancel`,'POST',undefined,AbortSignal.timeout(5000));} catch { /* Unknown outcome stays reserved for reconciliation. */ }
      }
      throw e;
    }
  }
}
