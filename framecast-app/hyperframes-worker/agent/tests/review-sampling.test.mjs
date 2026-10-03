import test from 'node:test';
import assert from 'node:assert/strict';
import {reviewSampling} from '../review-sampling.mjs';

test('bounded review sampling spans all supported durations including the final beat',()=>{
 for(const duration of [5,10,15,26,30]){
  const r=reviewSampling(duration);
  assert.ok(r.times.length<=30);
  assert.ok(r.times[0]>=0&&r.times.at(-1)<duration);
  assert.ok(r.times.at(-1)>=duration-0.5);
  assert.equal(new Set(r.times).size,r.times.length);
 }
 assert.equal(reviewSampling(15).every_seconds,.5);
 assert.equal(reviewSampling(30).every_seconds,1);
 assert.equal(reviewSampling(30).times.at(-1),29.5);
 for(const bad of [0,31,NaN,Infinity])assert.throws(()=>reviewSampling(bad));
});
