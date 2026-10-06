// Run in Linux/Docker with a disposable /output volume, never the app's live artifacts.
import {spawn} from 'node:child_process';
import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const entry=process.argv[2]??'/opt/worker/scripts/sandbox-entrypoint.sh';
function start(script,wait=10){
 const child=spawn('/bin/sh',[entry,process.execPath,'-e',script],{env:{...process.env,CREATE_SANDBOX_WAIT_SECONDS:String(wait)}});
 let stderr='',stdout='';const listeners=[];
 child.stderr.on('data',b=>{stderr+=b;for(const f of listeners)f();});child.stdout.on('data',b=>stdout+=b);
 const done=new Promise((resolve,reject)=>{child.once('error',reject);child.once('exit',(code,signal)=>resolve({code,signal,stderr,stdout}));});
 const marker=m=>new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Missing '+m+': '+stderr)),5000);const check=()=>{if(stderr.includes(m)){clearTimeout(timer);resolve();}};listeners.push(check);check();});
 return {child,done,marker};
}
const begin='const fs=require("fs");';
const hold=begin+'fs.appendFileSync("/output/events","A-start\\n");const timer=setInterval(()=>{if(fs.existsSync("/output/release")){clearInterval(timer);fs.appendFileSync("/output/events","A-end\\n");}},20);';
const first=start(hold);await first.marker('WYV_SANDBOX_ACQUIRED');
const cancelled=start(begin+'fs.appendFileSync("/output/events","CANCELLED-RAN\\n")');await cancelled.marker('WYV_SANDBOX_WAITING');cancelled.child.kill('SIGTERM');assert.equal((await cancelled.done).signal,'SIGTERM');
const timeout=start(begin+'fs.appendFileSync("/output/events","TIMED-OUT-RAN\\n")',1);await timeout.marker('WYV_SANDBOX_WAITING');assert.equal((await timeout.done).code,75);
const second=start(begin+'fs.appendFileSync("/output/events","B-start\\n")');await second.marker('WYV_SANDBOX_WAITING');
await writeFile('/output/release','yes');assert.equal((await first.done).code,0);assert.equal((await second.done).code,0);
assert.equal(await readFile('/output/events','utf8'),'A-start\nA-end\nB-start\n');
const failed=start('process.exit(17)');assert.equal((await failed.done).code,17);
const afterFailure=start('console.log("released")');assert.equal((await afterFailure.done).stdout.trim(),'released');
const killed=start('setInterval(()=>{},1000)');await killed.marker('WYV_SANDBOX_ACQUIRED');killed.child.kill('SIGKILL');await killed.done;
assert.equal((await start('console.log("released-after-kill")').done).code,0);
console.log('PASS: serial execution, cancelled waiter, bounded wait, failure release, killed holder release; no AI calls.');
