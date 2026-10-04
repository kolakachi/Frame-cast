// Copying a reference exactly: the reviewer sees every moment as a pair, the reference's frame on the left
// and ours on the right at the same time, labelled with the moment id. Ours is taken in the runtime the
// renderer uses; the reference frame straight from the reference file.
import {readFile,mkdir,readdir,unlink,writeFile} from 'node:fs/promises';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';
import {serve} from './reads-check.mjs';
const run=promisify(execFile);
export const LIMIT=24;

export async function compareSheet({root,width,height,reference,moments,out,browserPath=process.env.HYPERFRAMES_BROWSER_PATH}){
 const list=(moments||[]).filter(m=>m&&Number.isFinite(Number(m.at))&&Number(m.at)>=0&&Number(m.at)<=30).slice(0,LIMIT);
 if(!list.length)return {ok:false,error:'No moments to compare'};
 await mkdir(out,{recursive:true});for(const f of await readdir(out))if(/^(o|r|p)-\d+\.(png|jpg)$/.test(f))await unlink(out+'/'+f);
 const {default:puppeteer}=await import('puppeteer-core');
 const runtime=await readFile('/opt/worker/node_modules/hyperframes/dist/hyperframe.runtime.iife.js','utf8');
 const server=await serve(root,runtime);
 const browser=await puppeteer.launch({executablePath:browserPath,headless:true,args:['--no-sandbox','--disable-gpu','--font-render-hinting=none']});
 try{
  const page=await browser.newPage();await page.setViewport({width,height});
  await page.goto('http://127.0.0.1:'+server.address().port+'/index.html',{waitUntil:'load',timeout:30000});
  await page.waitForFunction(()=>window.__player&&typeof window.__player.renderSeek==='function',{timeout:15000,polling:200});
  await page.evaluate(()=>{window.__player.enableRenderMode?.();return document.fonts?.ready;});
  for(const [i,m] of list.entries()){
   await page.evaluate(async s=>{await window.__player.renderSeek(s);if(window.__hfWaitForSeekCompletion)await window.__hfWaitForSeekCompletion();},Number(m.at));
   await page.screenshot({path:`${out}/o-${i}.png`});
  }
 }finally{await browser.close();server.close();}
 // Each pair: reference | ours, 400 px wide each, with the moment id and time on a strip below.
 const font='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
 for(const [i,m] of list.entries()){
  await run('ffmpeg',['-v','error','-y','-ss',String(m.at),'-i',reference,'-frames:v','1','-vf','scale=400:-2',`${out}/r-${i}.png`],{timeout:30000});
  await writeFile(out+'/label.txt',`${m.id}  ${Number(m.at).toFixed(2)}s   reference | ours`);
  await run('ffmpeg',['-v','error','-y','-i',`${out}/r-${i}.png`,'-i',`${out}/o-${i}.png`,'-filter_complex',
   `[0]scale=400:225:force_original_aspect_ratio=decrease,pad=400:225:(ow-iw)/2:(oh-ih)/2:white[a];[1]scale=400:225:force_original_aspect_ratio=decrease,pad=400:225:(ow-iw)/2:(oh-ih)/2:white[b];[a][b]hstack,pad=iw+6:ih+28:3:0:black,drawtext=fontfile=${font}:textfile=${out}/label.txt:x=8:y=h-22:fontsize=16:fontcolor=white`,
   '-frames:v','1',`${out}/p-${i}.png`],{timeout:30000});
 }
 const cols=Math.min(2,list.length),rows=Math.ceil(list.length/cols);
 await run('ffmpeg',['-v','error','-y','-framerate','1','-i',`${out}/p-%d.png`,'-vf',`tile=${cols}x${rows}:padding=4:color=white`,'-frames:v','1','-q:v','3',`${out}/compare.jpg`],{timeout:60000});
 return {ok:true,cells:list.map(m=>({id:m.id,at:Number(m.at)}))};
}
