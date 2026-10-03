// Network-disabled contract proof, not a model creativity evaluation.
// Requires /output/{one,two,three}.aiff: locally synthesized fixture phrases.
import {mkdir,copyFile,readFile,writeFile,readdir,rm} from 'node:fs/promises';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const puppeteer=createRequire('/opt/worker/node_modules/hyperframes/package.json')('puppeteer-core');
import {executeCompositionAgent} from '../agent/composition-agent.mjs';
import {digest} from '../agent/workspace.mjs';
const exec=promisify(execFile), id='barty-agent', directory='/output/live/'+id;
await rm(directory,{recursive:true,force:true});
await mkdir(directory+'/project',{recursive:true});await mkdir(directory+'/inputs/source',{recursive:true});
const command=async(cmd,args)=>exec(cmd,args,{timeout:180000,maxBuffer:16000000});
const phrases=['One idea.','A new perspective.','Ready to create.'];
for(const [i,name] of ['one','two','three'].entries())await command('ffmpeg',['-v','error','-y','-i','/output/'+name+'.aiff','-af','apad','-t','2','-ar','48000','-ac','1',directory+'/part'+i+'.wav']);
await command('ffmpeg',['-v','error','-y',...['0','1','2'].flatMap(n=>['-i',directory+'/part'+n+'.wav']),'-filter_complex','[0:a][1:a][2:a]concat=n=3:v=0:a=1[a]','-map','[a]',directory+'/inputs/source/narration.wav']);
await copyFile('/opt/worker/fixtures/product.svg',directory+'/inputs/source/product.svg');
const files=await Promise.all(['narration.wav','product.svg'].map(async name=>({purpose:'source',asset_type:name.endsWith('wav')?'audio':'image',name,path:'source/'+name,sha256:digest(await readFile(directory+'/inputs/source/'+name))})));
const transcript={segments:phrases.map((text,i)=>({text,start:i*2,end:i*2+1.8})),words:phrases.flatMap((text,i)=>text.split(' ').map((text,j,a)=>({text,start:i*2+j*1.5/a.length,end:i*2+(j+1)*1.5/a.length})))};
// Known fixture timings; no ASR call is claimed. Live runs use their real transcript tool.
let html=await readFile('/opt/worker/fixtures/barty/index.html','utf8');
html=html.replace('LOCAL ADAPTER PROOF · NO AI GENERATION','OFFLINE AGENT CONTRACT · ORIGINAL');
html=html.replace('<div id="world">','<img id="product" src="product.svg" style="position:absolute;right:80px;top:50px;width:130px;height:130px"><audio id="narration" src="narration.wav" data-start="0" data-duration="6" data-volume="1"></audio><div id="world">');
for(const [i,id] of ['one','two','three'].entries())html=html.replace(`id="${id}" class="line"`,`id="${id}" class="line" data-start="${i*2}" data-duration="2" data-spoken="${phrases[i]}"`);
await writeFile(directory+'/output-settings.json',JSON.stringify({aspect_ratio:'16:9',duration_seconds:6}));
const trace=[],receipts=[];
const invoke=async(operation,args={})=>{
 assert.ok(['check','snapshot','timeline','render','media'].includes(operation),'Unexpected offline tool');
 if(operation==='media')await writeFile(directory+'/media-request.json',JSON.stringify({op:args.op,input:args.input,params:args.params}));
 await command(process.execPath,['/opt/worker/agent/live-tool.mjs',id,operation,(args.times??[1,3,5]).join(',')]);
 const result=JSON.parse(await readFile(directory+'/'+operation+'/result.json','utf8'));
 trace.push({operation,ok:result.ok??result.status==='ready',diagnostics:result.diagnostics});
 assert.ok(result.ok===true||result.status==='ready',JSON.stringify(result));
 if(operation==='snapshot')result.providerImage='data:image/jpeg;base64,'+(await readFile(directory+'/snapshot/contact-sheet.jpg')).toString('base64');
 return result;
};
let calls=0,transcripts=0;
const run=async(base)=>{
 await rm(directory+'/agent-state.json',{force:true});
 const actions=[{type:'read',path:'kit/barty.md'},{type:'transcript',input:'narration.wav'},...(!base?[{type:'read',path:'skills/barty/motion-broll/reference/engine-api.md'},{type:'write',path:'index.html',content:html}]:[{type:'patch',path:'index.html',before:'OFFLINE AGENT CONTRACT · ORIGINAL',after:'OFFLINE AGENT CONTRACT · EDITED'}]),{type:'check'},{type:'snapshot',times:[1,3,5]},{type:'finish',summary:'Offline contract fixture; no model creative review.'}];
 let cursor=0;
 return executeCompositionAgent({directory,input:{messages:[{role:'user',content:'Use Barty for these three narrated phrases; preserve the supplied product and mint background.'}],settings:{aspect_ratio:'16:9',duration_seconds:6},plan:{colour_treatment:{roles:{background:{hex:'#EAF4F2',locked:true}}}},base_bundle:base,base_revision_id:base?'fixture-first':null,execution_policy:{agent:{max_calls:10}}},manifest:files,
 provider:{id:'offline-barty-contract',maxCallUsd:0,complete:async args=>{
  const ctx=JSON.parse(args.prompt).context;assert.ok(ctx.runtimeFiles.some(f=>f.path==='barty-hyperframes.js'));
  assert.ok(actions[cursor],'Unexpected extra provider call');
  if(cursor===1)assert.match(args.prompt,/Barty motion-broll inside Hyperframes/);
  assert.equal(ctx.colourTreatment.roles.background.hex,'#EAF4F2');calls++;
  return {text:JSON.stringify(actions[cursor++])};}},
 begin:async()=>({id:'offline-'+calls,may_execute:true}),settle:async(id,r)=>receipts.push({id,...r}),receipt:()=>({status:'succeeded',cost_microusd:0}),invoke,
 transcribe:async({input})=>{assert.equal(input,'narration.wav');transcripts++;return transcript;},guidanceDirectory:'/opt/worker/agent/guidance'});
};
const visualChecks=[];
const inspect=async label=>{
 const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
  const page=await browser.newPage();await page.setViewport({width:1920,height:1080});
  await page.goto('file:///tmp/live-project/index.html');
  for(const [i,id] of ['one','two','three'].entries()){
   await page.evaluate(t=>{window.__timelines.main.seek(t,true);},i*2+1);
   assert.ok(await page.$eval('#'+id,n=>getComputedStyle(n).display!=='none'&&Number(getComputedStyle(n).opacity)>.95),'Phrase not visible: '+id);
   for(const other of ['one','two','three'].filter(n=>n!==id))assert.equal(await page.$eval('#'+other,n=>getComputedStyle(n).display),'none');
   await page.screenshot({path:'/output/'+label+'-'+id+'.png'});
  }
  assert.equal(await page.$eval('#stage',n=>getComputedStyle(n).backgroundColor),'rgb(234, 244, 242)');
  assert.ok(await page.$eval('#product',n=>n.complete&&n.naturalWidth>0));
  visualChecks.push({label,phraseSlots:true,approvedBackground:true,productLoaded:true});
 }finally{await browser.close();}
};
const first=await run();assert.equal(first.state.status,'preview_ready',JSON.stringify(first.state));
assert.equal(transcripts,1);assert.match(first.bundle['index.html'],/WyvBroll.scene/);
await inspect('original');
const render1=await invoke('render');
const second=await run(first.bundle);assert.equal(second.state.status,'preview_ready',JSON.stringify(second.state));
assert.equal(second.bundle['index.html'],first.bundle['index.html'].replace('OFFLINE AGENT CONTRACT · ORIGINAL','OFFLINE AGENT CONTRACT · EDITED'));
for(const f of files)assert.equal(digest(await readFile(directory+'/project/'+f.name)),f.sha256);
assert.equal(transcripts,2);
await inspect('edited');
const render2=await invoke('render');
const audio=async file=>{
 const {stdout}=await exec('ffmpeg',['-v','error','-i',file,'-vn','-ar','48000','-ac','1','-f','f32le','pipe:1'],{encoding:'buffer',maxBuffer:4000000});return new Float32Array(stdout.buffer,stdout.byteOffset,stdout.length/4);
};
const source=await audio(directory+'/project/narration.wav');
const renders=[];
for(const render of [render1,render2]){
 const file=render.directory+'/'+render.artifact,decoded=await audio(file);assert.ok(Math.abs(decoded.length-source.length)<4800);
 let ab=0,aa=0,bb=0;for(let i=0;i<Math.min(source.length,decoded.length);i++){ab+=source[i]*decoded[i];aa+=source[i]**2;bb+=decoded[i]**2;}
 const correlation=ab/Math.sqrt(aa*bb);assert.ok(correlation>.95,'Narration shifted or changed: '+correlation);
 const artifact='/output/barty-agent-'+(renders.length?'edited':'original')+'.mp4';
 await copyFile(file,artifact);
 renders.push({file,artifact,audioCorrelation:correlation});
}
assert.ok(receipts.every(r=>r.cost_microusd===0));
await writeFile('/output/barty-agent-report.json',JSON.stringify({passed:true,kind:'offline-scripted-provider',calls,transcripts,trace,visualChecks,renders,sourceHashes:files,paidUsd:0,limits:['No live model selection or creative assessment','Transcript timings are fixture metadata, not ASR output']},null,2));
console.log(JSON.stringify({passed:true,calls,transcripts,renders,paidUsd:0}));
