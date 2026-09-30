// Claude API calls made by the app on the worker's behalf. The worker sends the
// exact prompt it recorded for the attempt; the app holds the key, makes the
// call, reads the usage and settles the charge. Nothing here is retried: a lost
// response is reconciled, never re-sent.
export class AnthropicGatewayProvider {
  constructor({model,call,maxCallUsd}) {
    if(!/^claude-[a-z0-9-]+$/.test(model??''))throw Error('Invalid Claude model');
    if(typeof call!=='function'||!Number.isFinite(maxCallUsd)||maxCallUsd<=0)throw Error('Gateway call and per-call ceiling are required');
    this.call=call;this.maxCallUsd=maxCallUsd;this.id='anthropic:'+model;
  }
  async complete({prompt,system,maxTokens,image,attemptId,recordPrediction=async()=>{},signal}) {
    if(!attemptId)throw Error('Gateway calls need a recorded attempt');
    if(!Number.isInteger(maxTokens)||maxTokens<256||maxTokens>8192)throw Error('Output token limit outside the gateway bounds');
    if(image&&(!/^data:image\/(png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(image)||image.length>1400000))throw Error('Only inline PNG or JPEG review images are sent');
    signal?.throwIfAborted();
    const out=await this.call(attemptId,{prompt,system,max_tokens:maxTokens,image:image??null});
    if(out?.status!=='succeeded'||!/^[a-zA-Z0-9_-]+$/.test(out.message_id??'')||typeof out.text!=='string')throw Error('Gateway returned no usable answer');
    await recordPrediction(out.message_id);
    return {text:out.text,predictionId:out.message_id,metrics:out.usage??{},actualCostUsd:out.cost_microusd/1e6,stopReason:out.stop_reason??null};
  }
}
