// Disposable fixture account only. This test blocks external requests.
import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const evidence=JSON.parse(await readFile(root+'/artifacts/app-integration/evidence.json','utf8'));
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
const user={id:1,workspace_id:1,role:'owner',name:'Local tester',email:'create-fixture@example.test',preferences:{onboarded:true},is_internal:true};
const errors=[];let page;
try{
 const context=await browser.newContext({viewport:{width:1440,height:1050}});
 await context.addInitScript(u=>localStorage.setItem('framecast.auth',JSON.stringify({accessToken:'local-create-fixture',user:u})),user);
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(!['127.0.0.1','localhost'].includes(u.hostname)){await route.abort();return;}
  if(u.port==='8018'&&!u.pathname.startsWith('/api/v1/create/')){
   let data={};
   if(u.pathname.endsWith('/me'))data={user};
   else if(u.pathname.endsWith('/workspace-access'))data=[{id:1,name:'Create fixture',role:'owner'}];
   else if(u.pathname.includes('/workspaces/'))data={workspace:{id:1,name:'Create fixture',plan_tier:'creator'},usage:{},clients:[]};
   else if(u.pathname.endsWith('/notifications'))data={notifications:[],unread_count:0};
   else if(u.pathname.endsWith('/assets'))data={assets:[]};
   await route.fulfill({json:{data},headers:{'Access-Control-Allow-Origin':'http://127.0.0.1:5188','Access-Control-Allow-Credentials':'true'}});return;
  }
  await route.continue();
 });
 page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:5188/create/'+evidence.conversation);
 await page.getByText('LOCAL SAMPLE PREVIEW',{exact:true}).waitFor();
 if(await page.getByRole('button',{name:'Got it',exact:true}).count())await page.getByRole('button',{name:'Got it',exact:true}).click();
 await page.locator('video').waitFor();
 await page.getByRole('button',{name:'Save sample to videos',exact:true}).click();
 await page.getByRole('button',{name:'Saved to videos',exact:true}).waitFor();
 await page.locator('video').evaluate(async video=>{video.muted=true;await video.play()});
 await page.waitForTimeout(1500);
 const before=await page.locator('video').evaluate(v=>({time:v.currentTime,src:v.currentSrc}));
 // Trigger a real server refresh through an added message; player must keep its src and position.
 const followup='Keep this sample for comparison '+Date.now()+'.';
 await page.getByLabel('YOUR BRIEF OR NEXT CHANGE').fill(followup);
 await page.getByRole('button',{name:'Save brief ↑',exact:true}).click();
 await page.getByText(followup,{exact:true}).waitFor();
 await page.waitForTimeout(1500);
 const after=await page.locator('video').evaluate(v=>({time:v.currentTime,src:v.currentSrc}));
 assert.equal(after.src,before.src);assert.ok(after.time>before.time,'Playback reset during refresh');
 await page.getByRole('button',{name:'Details',exact:true}).click();
 await page.getByRole('button',{name:'Version 1',exact:true}).click();
 await page.getByRole('button',{name:'Restore as a new version'}).waitFor();
 await page.waitForFunction(()=>document.querySelector('video')?.readyState>=2);
 await page.screenshot({path:root+'/artifacts/app-integration/desktop.png',fullPage:true});
 await page.getByRole('button',{name:'Close details ×'}).click();
 await page.getByRole('button',{name:'＋ Add from library'}).click();
 await page.getByRole('dialog').waitFor();await page.keyboard.press('Escape');
 assert.equal(await page.getByRole('dialog').count(),0);
 await page.locator('video').evaluate(v=>v.pause());
 await page.setViewportSize({width:390,height:844});
 await page.evaluate(()=>window.scrollTo(0,0));
 await page.screenshot({path:root+'/artifacts/app-integration/mobile.png',fullPage:false,animations:'disabled'});
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile horizontal overflow');
 assert.deepEqual(errors,[]);
 const result={desktop:true,mobile:true,stablePlayback:true,outputRegistration:true,historySelection:true,dialogEscape:true,errors};
 await writeFile(root+'/artifacts/app-integration/browser.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}catch(e){ if(page){await page.screenshot({path:root+'/artifacts/app-integration/browser-error.png',fullPage:true});console.error((await page.locator('body').innerText()).slice(0,3000));console.error(errors);}throw e;}finally{await browser.close();}
