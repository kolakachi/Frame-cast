// Disposable API, no external network or paid calls.
import assert from 'node:assert/strict';
import {writeFile,mkdir} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const browser=await chromium.launch({headless:true,channel:'chrome'});
const user={id:1,workspace_id:1,role:'owner',name:'Local tester',email:'create-fixture@example.test',preferences:{onboarded:true},is_internal:true};
let page;const errors=[];let uploadAttempts=0;
try {
 const context=await browser.newContext({viewport:{width:1440,height:1080}});
 await context.addInitScript(u=>localStorage.setItem('framecast.auth',JSON.stringify({accessToken:'local-create-fixture',user:u})),user);
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(!['127.0.0.1','localhost'].includes(u.hostname)){await route.abort();return;}
  if(u.pathname.endsWith('/uploads') && uploadAttempts++ === 0){await route.fulfill({status:503,json:{message:'Temporary upload failure; retry this file.'}});return;}
  if(u.port==='8018'&&!u.pathname.startsWith('/api/v1/create/')&&!u.pathname.includes('/media/')&&!u.pathname.endsWith('/content')){
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
 // Fast automation can trigger the app shell's existing rage-click feedback card.
 await page.addLocatorHandler(page.locator('.rage-card'),async()=>page.locator('.rage-close').click());
 const button=name=>page.getByRole('button',{name,exact:true}), prompt=()=>page.getByLabel('Describe what you want to create or change');
 await page.goto('http://127.0.0.1:5188/create');
 await page.getByRole('heading',{name:'What are we making?'}).waitFor();
 if(await button('Got it').count())await button('Got it').click();
 assert.equal(await page.locator('dialog[open]').count(),0);
 const brief='E3 browser photo launch '+Date.now(); const title='E3 saved creation '+Date.now();
 await prompt().fill(brief);await page.reload();await prompt().waitFor();assert.equal(await prompt().inputValue(),brief);
 await page.locator('input[type=file]').setInputFiles({name:'product.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=','base64')});
 await button('Upload').click();await page.getByText('Temporary upload failure; retry this file.',{exact:true}).waitFor();
 await button('Retry upload').click();await page.locator('.chip, .upload, .asset').waitFor();
 assert.match(page.url(),/\/create\/[a-f0-9-]+/);assert.equal(await prompt().inputValue(),brief);
 await button('Send').click();await page.locator('.message').filter({hasText:brief}).waitFor();const conversationUrl=page.url();
 await prompt().fill('Keep this unsent text');
 await page.route('**/messages',async route=>route.fulfill({status:409,json:{message:'Conversation changed. Review the latest version.'}}),{times:1});
 await button('Send').click();await page.getByRole('alert').filter({hasText:'Conversation changed'}).waitFor();assert.equal(await prompt().inputValue(),'Keep this unsent text');
 await button('Details & versions').click();await page.getByRole('complementary',{name:'Details and versions'}).waitFor();
 await page.getByLabel('Conversation name').fill(title);await button('Save name').click();await page.getByRole('heading',{name:title,exact:true}).waitFor();
 await button('Archive conversation').click();await page.waitForURL('**/create');
 await button('Recent conversations').click();await page.getByLabel('Show archived conversations').check();await page.getByRole('link',{name:new RegExp(title)}).click();
 await button('Restore conversation').click();await prompt().waitFor();assert.equal(await prompt().inputValue(),'Keep this unsent text');
 await button('Recent conversations').click();await page.getByLabel('Show archived conversations').uncheck();await page.getByLabel('Search conversations').fill('product.png');await page.getByRole('link',{name:new RegExp(title)}).waitFor();
 await page.keyboard.press('Escape');await page.locator('dialog[open]').waitFor({state:'hidden'});assert.equal(await page.locator('dialog[open]').count(),0);
 await mkdir(root+'/artifacts/e3-ui',{recursive:true});await page.screenshot({path:root+'/artifacts/e3-ui/conversation-desktop.png',fullPage:true});
 await button('+ New creation').click();await page.getByRole('button',{name:/Product image from a photo/}).click();await button('Send').click();
 await page.getByText('Your image brief is saved. Image generation and editing are not enabled in this local preview yet.',{exact:true}).waitFor();assert.equal(await button('Review local sample plan').count(),0);
 await page.setViewportSize({width:390,height:844});await button('Details & versions').click();assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.screenshot({path:root+'/artifacts/e3-ui/mobile-details.png',fullPage:true});await page.keyboard.press('Escape');
 await button('+ New creation').click();await page.screenshot({path:root+'/artifacts/e3-ui/mobile-new.png',fullPage:true});
 await page.setViewportSize({width:1440,height:1080});await page.getByRole('heading',{name:'New creation',exact:true}).waitFor();await page.waitForTimeout(350);await page.screenshot({path:root+'/artifacts/e3-ui/desktop-new.png',fullPage:true});assert.deepEqual(errors,[]);
 const result={uploadBeforeBrief:true,uploadRetry:true,draftRecovery:true,conflictPreservesText:true,archiveRestore:true,filenameSearch:true,imageBriefGated:true,mobile:true,errors,conversationUrl,paidCalls:0};
 await writeFile(root+'/artifacts/e3-ui/browser.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
} catch(e) {if(page){await page.screenshot({path:root+'/artifacts/e3-browser-error.png',fullPage:true});console.error((await page.locator('body').innerText()).slice(-5000));}throw e;}
finally {await browser.close()}
