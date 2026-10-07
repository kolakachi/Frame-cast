import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {renderTransient} from '../render-failure.mjs';

const check=parts=>JSON.stringify(Object.fromEntries(Object.entries(parts).map(([k,v])=>[k,v?{ok:true,findings:[]}:null])));
test('a render whose only failure is the page timing out loading is worth one more render; a real layout failure is not', async () => {
 const root=await mkdtemp(tmpdir()+'/rt-');const dir=root+'/artifacts/live/app-x/render/r1';await mkdir(dir,{recursive:true});
 const report={error:'check failed; inspect check.log',directory:'/output/live/app-x/render/r1'};
 // Production 2026-10-07: runtime "Navigation timeout of 45000 ms exceeded", layout fine.
 await writeFile(dir+'/check.log','check output\n'+JSON.stringify({lint:{ok:true,findings:[]},runtime:{ok:false,findings:[{code:'check_runtime_failure',message:'Navigation timeout of 45000 ms exceeded'}]},layout:{ok:true,findings:[]}}));
 assert.equal(await renderTransient(report,root),true);
 await writeFile(dir+'/check.log',JSON.stringify({runtime:{ok:true,findings:[]},layout:{ok:false,findings:[{code:'overflow',message:'Text leaves the frame'}]}}));
 assert.equal(await renderTransient(report,root),false);
 await writeFile(dir+'/check.log',JSON.stringify({runtime:{ok:false,findings:[{code:'check_runtime_failure',message:'Navigation timeout of 45000 ms exceeded'}]},layout:{ok:false,findings:[{code:'overflow'}]}}));
 assert.equal(await renderTransient(report,root),false,'a timeout beside a real layout failure is not retried');
 assert.equal(await renderTransient({error:'check failed',directory:'/output/live/missing'},root),false);
});
