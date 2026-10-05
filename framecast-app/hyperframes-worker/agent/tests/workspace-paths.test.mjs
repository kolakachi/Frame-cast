import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {Workspace} from '../workspace.mjs';

test('a wrong file type in the scratch folder is the builder\'s to correct; reaching outside still stops the run',async t=>{
 const root=await mkdtemp(path.join(os.tmpdir(),'ws-'));t.after(()=>rm(root,{recursive:true,force:true}));
 const w=new Workspace(root,[],path.join(root,'scratch'));
 await assert.rejects(()=>w.resolve('work/save.html',true),e=>e.code==='AUTHORING_REJECTED'&&/scratch folder/.test(e.message));
 assert.ok((await w.resolve('work/grab.mjs',true)).endsWith('/grab.mjs'));
 await assert.rejects(()=>w.resolve('../index.html',true),e=>e.message==='Source path is not allowed'&&!e.code);
 await assert.rejects(()=>w.resolve('work/../../x.mjs',true),e=>e.message==='Source path is not allowed');
});
