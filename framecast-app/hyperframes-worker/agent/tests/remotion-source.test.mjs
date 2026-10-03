import test from 'node:test';import assert from 'node:assert/strict';
import {validateRemotionSource} from '../remotion-source.mjs';
test('source validation rejects unused, re-exported, dynamic and loader imports before bundling',()=>{
 for(const source of ["import fs from 'node:fs';export default ()=>null;","export {x} from '../secrets.js';","import('node:fs');","import(name);","require('raw-loader!./x.js');","export * from '/etc/passwd';"])assert.throws(()=>validateRemotionSource(source),/Unsupported Remotion import/);
 validateRemotionSource("import React from 'react';import {spring} from 'remotion';export default ()=> <div>Hello</div>;");
});
