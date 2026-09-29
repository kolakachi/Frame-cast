import test from 'node:test';import assert from 'node:assert/strict';
import {AnthropicGatewayProvider} from '../anthropic-gateway.mjs';
const ok={status:'succeeded',message_id:'msg_01abc',text:'{"type":"finish"}',cost_microusd:100000,usage:{input_tokens:1}};
test('sends the recorded call to the app and never holds a key',async()=>{
 const sent=[];const journal=[];
 const p=new AnthropicGatewayProvider({model:'claude-opus-5-5',maxCallUsd:.3,call:async(id,body)=>{sent.push([id,body]);return ok;}});
 const out=await p.complete({prompt:'p',system:'s',maxTokens:1024,attemptId:'a1',recordPrediction:async id=>journal.push(id)});
 assert.deepEqual(sent,[['a1',{prompt:'p',system:'s',max_tokens:1024,image:null}]]);
 assert.deepEqual([out.text,out.predictionId,out.actualCostUsd],['{"type":"finish"}','msg_01abc',.1]);
 assert.deepEqual(journal,['msg_01abc']);assert.equal(p.id,'anthropic:claude-opus-5-5');
});
test('refuses calls without an attempt, outside token bounds, or with remote images',async()=>{
 let calls=0;const p=new AnthropicGatewayProvider({model:'claude-opus-5-5',maxCallUsd:.3,call:async()=>{calls++;return ok;}});
 await assert.rejects(p.complete({prompt:'p',system:'s',maxTokens:1024}),/recorded attempt/);
 await assert.rejects(p.complete({prompt:'p',system:'s',maxTokens:99999,attemptId:'a'}),/token limit/);
 await assert.rejects(p.complete({prompt:'p',system:'s',maxTokens:1024,attemptId:'a',image:'https://x/y.png'}),/inline/);
 assert.equal(calls,0);
});
test('an unusable gateway answer fails the call without retrying',async()=>{
 let calls=0;const p=new AnthropicGatewayProvider({model:'claude-opus-5-5',maxCallUsd:.3,call:async()=>{calls++;return {status:'failed'};}});
 await assert.rejects(p.complete({prompt:'p',system:'s',maxTokens:1024,attemptId:'a'}),/no usable answer/);assert.equal(calls,1);
});
