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
 assert.equal(renderLength(page(20), 15), 15, 'more than a quarter over: still the approved length, and the check refuses it');
 assert.equal(renderLength(page(12), 15), 15, 'never shorter');
 assert.equal(renderLength('', 15), 15);
});
