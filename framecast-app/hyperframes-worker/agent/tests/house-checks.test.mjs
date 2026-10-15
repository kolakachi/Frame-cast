import test from 'node:test';import assert from 'node:assert/strict';
import {criticMessages} from '../critic.mjs';

const text=m=>JSON.stringify(m);
test('approved house checks reach the visual reviewer; none, no section', () => {
 const withChecks=criticMessages({brief:'b',plan:{},houseChecks:['Every line of on-screen text is fully inside the frame.']});
 assert.match(text(withChecks),/House checks \(faults users caught/);
 assert.match(text(withChecks),/fully inside the frame/);
 assert.doesNotMatch(text(criticMessages({brief:'b',plan:{}})),/House checks/);
});
