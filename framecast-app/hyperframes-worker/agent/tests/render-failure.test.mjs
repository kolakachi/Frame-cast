import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';import os from 'node:os';import path from 'node:path';
import {renderFailure} from '../render-failure.mjs';

test('a failed render reports what really failed, not always "render checks"',async t=>{
 const root=await mkdtemp(path.join(os.tmpdir(),'rf-'));t.after(()=>rm(root,{recursive:true,force:true}));
 assert.match(await renderFailure({error:'check failed; inspect check.log'},root),/did not pass render checks/);
 await mkdir(root+'/artifacts/live/x/render/r1',{recursive:true});
 await writeFile(root+'/artifacts/live/x/render/r1/render.log','[INFO] [Render] Failure summary {"error":"Video extraction failed for 2 source(s) [VIDEO_EXTRACTION_FAILED]","isTimeout":false}\n');
 assert.equal(await renderFailure({error:'render failed; inspect render.log',directory:'/output/live/x/render/r1'},root),'The render failed: Video extraction failed for 2 source(s) [VIDEO_EXTRACTION_FAILED]');
 assert.equal(await renderFailure({error:'Render deadline exceeded',directory:'/output/live/x/render/none'},root),'The render failed: Render deadline exceeded');
});
