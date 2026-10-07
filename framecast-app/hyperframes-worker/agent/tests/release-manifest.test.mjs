import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,symlink,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fingerprint,sealRelease,verifyRelease,verifyImage} from '../release-manifest.mjs';

async function fixture(t){
 const root=await mkdtemp(path.join(tmpdir(),'create-release-'));
 t.after(()=>rm(root,{recursive:true,force:true}));
 for(const d of ['agent','scripts','runtime','fixtures','runtime/art-packs'])await mkdir(root+'/'+d,{recursive:true});
 for(const f of ['agent/worker.mjs','runtime/font.ttf','runtime/art-packs/index.json','runtime/art-packs/icon.svg',
  'package.json','package-lock.json','Dockerfile','.dockerignore','compose.local.yml','compose.release.yml'])await writeFile(root+'/'+f,'fixture');
 const revision='a'.repeat(40),source=await fingerprint(root);
 const image={Id:'sha256:'+'b'.repeat(64),Architecture:'arm64',Config:{Labels:{'com.wyv.create.revision':revision,'com.wyv.create.source':source.sha256}}};
 return {root,revision,image};
}
test('seal verifies source, exact image, architecture and full art contents',async t=>{
 const {root,revision,image}=await fixture(t),manifest=await sealRelease(root,{revision,image});
 assert.equal((await verifyRelease(root,manifest,image)).revision,revision);
 await writeFile(root+'/runtime/art-packs/icon.svg','changed content, same catalog');
 await assert.rejects(verifyRelease(root,manifest,image),/changed after sealing/);
});
test('source mutation and added files invalidate release; run journals do not',async t=>{
 const {root,revision,image}=await fixture(t),manifest=await sealRelease(root,{revision,image});
 await mkdir(root+'/artifacts');await writeFile(root+'/artifacts/run.json','saved customer work');
 await verifyRelease(root,manifest,image);
 await writeFile(root+'/agent/extra.mjs','unreviewed');
 await assert.rejects(verifyRelease(root,manifest,image),/changed after sealing/);
});
test('an image tag swap or mismatched build provenance cannot start the release',async t=>{
 const {root,revision,image}=await fixture(t),manifest=await sealRelease(root,{revision,image});
 for(const wrong of [{...image,Id:'sha256:'+'c'.repeat(64)},{...image,Architecture:'amd64'},
  {...image,Config:{Labels:{}}}])assert.throws(()=>verifyImage(manifest,wrong),/does not match/);
 await assert.rejects(sealRelease(root,{revision,image}),/EEXIST/);
});
test('symlinked source and embedded credentials are rejected',async t=>{
 const {root}=await fixture(t);
 await symlink('/tmp',root+'/agent/escape');
 await assert.rejects(fingerprint(root),/symbolic link/);
 await rm(root+'/agent/escape');await writeFile(root+'/agent/.env','not a real secret');
 await assert.rejects(fingerprint(root),/Environment file/);
});
