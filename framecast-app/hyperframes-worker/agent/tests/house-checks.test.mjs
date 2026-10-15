import test from 'node:test';import assert from 'node:assert/strict';
import {criticMessages} from '../critic.mjs';

const text=m=>JSON.stringify(m);
test('approved house checks reach the visual reviewer; none, no section', () => {
 const withChecks=criticMessages({brief:'b',plan:{},houseChecks:['Every line of on-screen text is fully inside the frame.']});
 assert.match(text(withChecks),/House checks \(faults users caught/);
 assert.match(text(withChecks),/fully inside the frame/);
 assert.doesNotMatch(text(criticMessages({brief:'b',plan:{}})),/House checks/);
});

test('the taste checklist reaches the reviewer only when the taste rules are on', () => {
 assert.match(text(criticMessages({brief:'b',plan:{},taste:true})),/Generic AI looks are findings/);
 assert.doesNotMatch(text(criticMessages({brief:'b',plan:{},taste:false})),/Generic AI looks/);
});
test('the taste card is installed and readable', async () => {
 const {readCard}=await import('../cards.mjs');
 const dir=new URL('../guidance/',import.meta.url).pathname.replace(/\/$/,'');
 assert.match(await readCard(dir,'taste'),/only this product/);
});
