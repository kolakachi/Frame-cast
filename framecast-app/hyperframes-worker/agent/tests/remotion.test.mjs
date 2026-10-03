import test from 'node:test';import assert from 'node:assert/strict';
import {remotionArgs,remotionSettings,allowedRemotionImport} from '../remotion-contract.mjs';
import {checkRunArgs,toolDefinitions} from '../protocol.mjs';
import {Workspace} from '../workspace.mjs';
import {runAgent} from '../runner.mjs';
import {mkdtemp,writeFile,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
test('Remotion accepts bounded render/still actions, rejects CLI flags and escapes',()=>{
 assert.equal(remotionArgs(['render','project/demo.js','6']).duration,6);
 checkRunArgs('remotion',['still','project/demo.js','6','72']);
 for(const args of [['render','work/demo.js','6'],['render','project/demo.js','31'],['still','project/demo.js','6','144'],['render','project/demo.js','6','--webpack'],['render','project/../demo.js','6'],['render','project/demo.js','NaN']])assert.throws(()=>checkRunArgs('remotion',args));
 assert.equal(remotionSettings({aspect_ratio:'16:9',duration_seconds:6},6).durationInFrames,144);
 assert.throws(()=>remotionSettings({aspect_ratio:'16:9',duration_seconds:5},6));
 assert.throws(()=>remotionSettings({aspect_ratio:'evil',duration_seconds:6},6));
});
test('Remotion browser source imports cannot select Node modules, loaders or external paths',()=>{
 for(const value of ['react','react/jsx-runtime','remotion','./other.js'])assert.equal(allowedRemotionImport(value),true);
 for(const value of ['./theme.css','node:fs','fs','child_process','@remotion/bundler','../x.js','/etc/passwd','raw-loader!./x.js','https://example.com/x.js','./nested/x.js'])assert.equal(allowedRemotionImport(value),false);
});
test('last-good recovery includes native React source after a failed edit',async()=>{
 const dir=await mkdtemp(tmpdir()+'/remotion-recovery-');
 await writeFile(dir+'/index.html','<html>valid</html>');await writeFile(dir+'/remotion-demo.js','export default ()=>null;');
 const actions=[{type:'check'},{type:'snapshot',times:[1]},{type:'patch',path:'remotion-demo.js',before:'null',after:'broken'}];let i=0;
 const state=await runAgent({stateFile:dir+'/state.json',workspace:new Workspace(dir),context:{brief:'Keep draft'},provider:{id:'offline',maxCallUsd:0,complete:async()=>({text:JSON.stringify(actions[i++])})},limits:{calls:3},tools:{check:async()=>({ok:true}),snapshot:async()=>({ok:true})}});
 assert.equal(state.status,'preview_ready');assert.equal(state.recoveredDraft,true);
 assert.equal(await readFile(dir+'/remotion-demo.js','utf8'),'export default ()=>null;');
});

test('source recovery removes new source files but keeps immutable media',async()=>{
 const dir=await mkdtemp(tmpdir()+'/remotion-restore-'),ws=new Workspace(dir);
 await writeFile(dir+'/old.mp4','media');await writeFile(dir+'/new.js','broken');
 await ws.restoreSources({'index.html':'good','remotion-demo.js':'good component'});
 assert.deepEqual(await ws.sourceFiles(),['index.html','remotion-demo.js']);
 assert.equal(await readFile(dir+'/old.mp4','utf8'),'media');
});
