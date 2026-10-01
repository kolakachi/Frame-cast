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
  async complete({prompt,system,maxTokens,image,messages,tools,attemptId,recordPrediction=async()=>{},signal}) {
    if(!attemptId)throw Error('Gateway calls need a recorded attempt');
    if(!Number.isInteger(maxTokens)||maxTokens<256||maxTokens>16384)throw Error('Output token limit outside the gateway bounds');
    if(image&&(!/^data:image\/(png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(image)||image.length>1400000))throw Error('Only inline PNG or JPEG review images are sent');
    signal?.throwIfAborted();
    let out;
    // The app says so when Anthropic was never reached: nothing to reconcile.
    // Tool mode sends the history and tool list as the exact JSON strings that were hashed.
    const extra=messages?{messages_json:JSON.stringify(messages),tools_json:JSON.stringify(tools??[])}:{};
    if(extra.messages_json&&Buffer.byteLength(extra.messages_json)>5500000)throw Error('Tool conversation too large');
    try{out=await this.call(attemptId,{prompt,system,max_tokens:maxTokens,image:image??null,...extra});}
    catch(e){if(/nothing was sent|nothing was charged/i.test(String(e.message)))e.code='NOT_SENT';throw e;}
    if(out?.status!=='succeeded'||!/^[a-zA-Z0-9_-]+$/.test(out.message_id??'')||typeof out.text!=='string')throw Error('Gateway returned no usable answer');
    await recordPrediction(out.message_id);
    return {text:out.text,content:Array.isArray(out.content)?out.content:[{type:'text',text:out.text}],predictionId:out.message_id,metrics:out.usage??{},actualCostUsd:out.cost_microusd/1e6,stopReason:out.stop_reason??null};
  }
}
