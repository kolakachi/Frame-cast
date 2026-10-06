import test from 'node:test';import assert from 'node:assert/strict';
import {apiOriginAllowed} from '../api-origin.mjs';
test('the worker token is sent over plain HTTP only to this machine or a private network; otherwise HTTPS',()=>{
 const ok=u=>apiOriginAllowed(new URL(u));
 for(const u of ['http://localhost:8000','http://127.0.0.1:8000','http://10.0.0.140','http://192.168.1.5','http://172.20.0.3','https://app.wyvstudio.com'])assert.ok(ok(u),u);
 for(const u of ['http://app.wyvstudio.com','http://132.145.195.157','http://172.32.0.1','https://user:pw@app.wyvstudio.com','http://10.0.0.140/api'])assert.ok(!ok(u),u);
});
