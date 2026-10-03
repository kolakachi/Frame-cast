import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';import path from 'node:path';
import {cardRoute,cardsFor,pinCards,readCard,loadCards,availableCards,PINNED_BUDGET,ALWAYS} from '../cards.mjs';

async function guidance(cards){
 const dir=await mkdtemp(tmpdir()+'/cards-');await mkdir(dir+'/cards');
 for(const [name,text] of Object.entries(cards))await writeFile(path.join(dir,'cards',name+'.md'),text);
 return dir;
}
test('legacy packs remain usable; unclassified topic words get neutral guidance',()=>{
 assert.equal(cardRoute({stylePack:{slug:'mascot-explainer'}}),'mascot');
 assert.equal(cardRoute({stylePack:{slug:'launch-reel'},brief:'a ugc ad'}),'product','the pack wins over the brief');
 assert.equal(cardRoute({stylePack:{slug:'kinetic-type'}}),'motion');
 assert.equal(cardRoute({brief:'A 15 s UGC ad for our sale'}),'general');
 assert.equal(cardRoute({brief:'Show the dashboard of our SaaS app'}),'general');
 assert.equal(cardRoute({plan:{summary:'A presenter character explains the offer'}}),'general','topic words alone cannot classify the requested format');
 assert.equal(cardRoute({brief:'Our logo, animated'}),'general');
 for(const r of ['product','ad','mascot','motion'])assert.deepEqual(cardsFor(r).slice(0,2),ALWAYS);
 assert.deepEqual(cardsFor('product'),['seams','principles','launch','demo-loop']);
});
test('cards are hash-pinned: a changed card is refused, a missing card is skipped, the budget is enforced',async()=>{
 const dir=await guidance({seams:'# Seams\n- cut mid-motion',principles:'# Principles\n- ease out on enter',launch:'# Launch\n- hook in 2 s'});
 const files=await pinCards(dir);
 assert.deepEqual(Object.keys(files).sort(),['launch.md','principles.md','seams.md']);
 assert.deepEqual(await availableCards(dir),['launch','principles','seams']);
 const loaded=await loadCards(dir,'product');
 assert.deepEqual(loaded.names,['seams','principles','launch'],'demo-loop is not installed, so it is skipped');
 assert.match(loaded.text,/^CARD seams\n# Seams/);
 await writeFile(path.join(dir,'cards','seams.md'),'# Seams\n- edited after pinning');
 await assert.rejects(()=>readCard(dir,'seams'),/hash mismatch/);
 await assert.rejects(()=>readCard(dir,'../x'),/not allowed/);
 await assert.rejects(()=>readCard(dir,'ghost'),/not installed/);
 const big=await guidance({seams:'x'.repeat(PINNED_BUDGET),principles:'y'});await pinCards(big);
 await assert.rejects(()=>loadCards(big,'motion'),/exceed the budget/);
});
test('the shipped cards are pinned, each under 4,000 bytes, and every route fits the budget',async()=>{
 const dir=new URL('../guidance',import.meta.url).pathname;
 const names=await availableCards(dir);
 if(!names.length)return; // not yet written in this checkout
 for(const n of names){const text=await readCard(dir,n);assert.ok(Buffer.byteLength(text)<=4000,n+' is over 4,000 bytes');assert.match(text.split('\n')[0],/Distilled from/,n+' names its sources');}
 for(const r of ['product','ad','mascot','motion','editorial','footage','still','general']){const {text}=await loadCards(dir,r);assert.ok(Buffer.byteLength(text)<=PINNED_BUDGET);}
});

test('declared intent outranks a stale pack and routes only relevant doctrine',()=>{
 const pick=(format,motion='restrained')=>cardRoute({stylePack:{slug:'mascot-explainer'},brief:'UGC animation avatar software tutorial',plan:{creative_intent:{format,motion}}});
 assert.equal(pick('educational','kinetic'),'editorial');
 assert.equal(pick('talking_head','natural'),'footage');
 assert.equal(pick('footage_edit','natural'),'footage');
 assert.equal(pick('slideshow','none'),'still');
 assert.equal(pick('still_image','none'),'still');
 assert.equal(pick('character_animation','kinetic'),'mascot');
 assert.equal(pick('motion_graphics','kinetic'),'motion');
 for(const route of ['editorial','footage','still','general'])assert.deepEqual(cardsFor(route),['intent']);
});
