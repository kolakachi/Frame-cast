import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {LOOKS,looksIn,lookFonts,lookRecipes} from '../looks.mjs';
const card=new URL('../looks-kit.md',import.meta.url).pathname;

test('a brief that names looks is matched on whole words, and each look has a recipe', () => {
 assert.deepEqual(looksIn('A 10s promo, style: glassmorphism with a neon title'),['neon','glass']);
 assert.deepEqual(looksIn('an 80s TV commercial on VHS, then a split-flap stats board'),['splitflap','vhs']);
 assert.deepEqual(looksIn('neonatal care clinic, glasses shop'),[],'whole words only');
 const text=readFileSync(card,'utf8');
 for(const id of Object.keys(LOOKS))assert.match(text,new RegExp('\\n## '+id+'\\n'),id);
});

test('named looks bring at most three fonts and only their own recipes', async () => {
 assert.deepEqual(lookFonts(['deco','brutal']),['Poiret One','Limelight','Archivo Black']);
 const pinned=await lookRecipes(card,['comic']);
 assert.match(pinned,/kit\/looks\.md \(pinned/);assert.match(pinned,/## comic/);
 assert.doesNotMatch(pinned,/## neon/);
 assert.equal(await lookRecipes(card,[]),'');
});
