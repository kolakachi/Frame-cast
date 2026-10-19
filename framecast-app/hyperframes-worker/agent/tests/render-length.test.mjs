import test from 'node:test';import assert from 'node:assert/strict';
import {declaredLength,renderLength} from '../render-length.mjs';
const page=d=>`<html><body><div id="root" data-composition-id="main" data-width="1080" data-height="1920" data-duration="${d}"><video data-duration="17"></video></div></body></html>`;
test('the root composition says how long the page is', () => {
 assert.equal(declaredLength(page(16.3)), 16.3);
 assert.equal(declaredLength('<div data-duration="9"></div>'), null, 'only the root composition counts');
});
test('a page a little longer than approved (a presenter take) renders at its own length; nothing else changes', () => {
 assert.equal(renderLength(page(16.3), 15), 16.3, "Brett's explainer: 16.3 s for an approved 15 s");
 assert.equal(renderLength(page(15), 15), 15);
 assert.equal(renderLength(page(15.1), 15), 15, 'within the check\'s own slack');
 assert.equal(renderLength(page(19.5), 15), 19.5, 'a lip-synced take 30% long');
 assert.equal(renderLength(page(20), 15), 15, 'more than 30% over: still the approved length, and the check refuses it');
 assert.equal(renderLength(page(12), 15), 15, 'never shorter');
 assert.equal(renderLength('', 15), 15);
});

test('LENGTH-TARGET: a take that speaks past the chosen length sets the video length (up to 30% over)', async () => {
 const {voiceTiming} = await import('../narration-timing.mjs');
 const words = [{text: 'Run', start: 0.1, end: 0.4}, {text: 'a', start: 0.4, end: 0.5}, {text: 'salon', start: 0.5, end: 1}, {text: 'Ask', start: 14.5, end: 14.8}, {text: 'today', start: 15.6, end: 16.2}];
 const scenes = [{label: 'Hook', starts_on: 'Run a salon', start: 0, end: 12.8}, {label: 'CTA', starts_on: 'Ask', start: 12.8, end: 15}];
 const t = voiceTiming({scenes, lines: ['Run a salon', 'Ask today'], words, clipStart: 0, videoSeconds: 15});
 assert.equal(t.chosen_seconds, 15);
 assert.equal(t.video_seconds, 16.7, 'the last word at 16.2 s, half a second after it');
 assert.equal(t.beats.at(-1).voiced[1], 16.7, 'the call to action runs to the new end');
 const short = voiceTiming({scenes, lines: ['Run a salon', 'Ask today'], words: words.slice(0, 4), clipStart: 0, videoSeconds: 15});
 assert.equal(short.video_seconds, 15); assert.equal(short.chosen_seconds, undefined, 'a voice that fits leaves the length alone');
 const long = voiceTiming({scenes, lines: ['x'], words: [{text: 'x', start: 0, end: 25}], clipStart: 0, videoSeconds: 15});
 assert.equal(long.video_seconds, 19.5, 'never more than 30% over');
});
