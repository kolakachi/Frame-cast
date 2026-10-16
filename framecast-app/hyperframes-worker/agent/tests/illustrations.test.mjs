import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const src=readFileSync(new URL('../../runtime/wyv-illos.js',import.meta.url),'utf8');
const NAMES=['bag','phone','rocket','cart','parcel','gift','tag','chart','chat','mail','megaphone','rating','thumbs','bulb','trophy','plant','checklist','calendar','coffee','clock','pin','lock','search','sparkle','money'];

test('the 25 illustrations are defined, seeded (no randomness) and named in the builder guide', () => {
 for(const n of NAMES)assert.match(src,new RegExp('\\n    '+n+': function \\(tl, el, at, opts\\)'),n);
 assert.doesNotMatch(src,/Math\.random|Date\.now|requestAnimationFrame|setTimeout/);
 const kit=readFileSync(new URL('../motion-kit.md',import.meta.url),'utf8');
 for(const n of NAMES)assert.match(kit,new RegExp('`'+n+'`'),n);
});
