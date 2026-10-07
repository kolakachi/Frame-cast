import test from 'node:test';import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

test('the drafting line is said once per version, not before every model call', async () => {
 const src=await readFile(new URL('../runner.mjs',import.meta.url),'utf8');
 assert.match(src,/if\(state\.announced!==state\.revision\)\{state\.announced=state\.revision;progress\(state\.revision\?'Thinking about the next change':'Designing the first draft'\);\}/);
 assert.equal((src.match(/'Designing the first draft'/g)||[]).length,1);
});
