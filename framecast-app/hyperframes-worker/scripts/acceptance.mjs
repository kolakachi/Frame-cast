import {spawnSync} from 'node:child_process';
import {mkdir,copyFile,readFile,writeFile,readdir,symlink} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import path from 'node:path';
import {scopedPath} from './lib/paths.mjs';
const root='/tmp/acceptance',out='/output/acceptance';
await mkdir(root,{recursive:true});await mkdir(out,{recursive:true});await mkdir(process.env.HOME,{recursive:true});
for(const f of ['index.html','product.svg'])await copyFile('/opt/worker/fixtures/'+f,root+'/'+f);
await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');
await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
const cli='/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs';
const report={commands:[],checks:{}};
async function run(name,args,expectedSuccess=true){const start=Date.now();const r=spawnSync(process.execPath,[cli,...args],{cwd:root,encoding:'utf8',timeout:120000,maxBuffer:16000000});await writeFile(out+'/'+name+'.log',(r.stdout||'')+(r.stderr||''));report.commands.push({name,args,status:r.status,ms:Date.now()-start});if(expectedSuccess)assert.equal(r.status,0,name);else assert.notEqual(r.status,0,name);return r;}
await run('timeline',['timeline','--json']);
await run('forward',['snapshot',root,'--at','1,6,12','--no-end','--describe','false','--output',out+'/forward']);
await run('reverse',['snapshot',root,'--at','12,6,1','--no-end','--describe','false','--output',out+'/reverse']);
const original=await readFile(root+'/index.html','utf8');
await writeFile(root+'/index.html',original.replace('./font.ttf','./missing-font.ttf'));
await run('missing-font',['check',root,'--json'],false);
await writeFile(root+'/index.html',original.replace('width:880px','width:1880px').replace('Your product.<br>Your story.','THIS IS A VERY LONG OVERFLOWING HEADLINE THAT MUST BE REJECTED'));
await run('overflow',['check',root,'--json'],false);
await writeFile(root+'/index.html',original);
await assert.rejects(scopedPath(root,'../etc/passwd'));await assert.rejects(scopedPath(root,'/etc/passwd'));
await symlink('/etc/passwd',root+'/escape');await assert.rejects(scopedPath(root,'escape'));
assert.equal(await scopedPath(root,'product.svg'),root+'/product.svg');report.checks.paths=true;
// The network namespace has no non-loopback route. Test public, metadata and private addresses.
const routes=await readFile('/proc/net/route','utf8');assert.equal(routes.trim().split('\n').length,1);report.checks.noNetworkRoute=true;
for(const target of ['http://1.1.1.1','http://169.254.169.254','http://10.0.0.1']) {
  await assert.rejects(fetch(target,{signal:AbortSignal.timeout(1000)}));
}
report.checks.egressDenied=true;
const hashes={};async function walk(dir){for(const entry of await readdir(dir,{withFileTypes:true})){const f=path.join(dir,entry.name);if(entry.isDirectory())await walk(f);else hashes[f.replace('/opt/worker/node_modules/hyperframes/dist/skills/','')]=createHash('sha256').update(await readFile(f)).digest('hex');}}
await walk('/opt/worker/node_modules/hyperframes/dist/skills');
await writeFile(out+'/skills-manifest.json',JSON.stringify(hashes,null,2));
report.skillFiles=Object.keys(hashes).length;report.license=JSON.parse(await readFile('/opt/worker/node_modules/hyperframes/package.json')).license;
report.memoryPeakBytes=Number(await readFile('/sys/fs/cgroup/memory.peak','utf8'));
report.memoryLimit=await readFile('/sys/fs/cgroup/memory.max','utf8');report.cpuLimit=await readFile('/sys/fs/cgroup/cpu.max','utf8');
await writeFile(out+'/report.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
