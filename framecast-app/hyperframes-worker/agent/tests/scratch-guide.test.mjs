import test from 'node:test';import assert from 'node:assert/strict';
import {pinnedKits} from '../composition-agent.mjs';

test('a from-scratch plan pins its playbook, concept and motion voice for the builder, capped in size', async () => {
 const guide='\n\n# Format playbook: Launch or motion promo\n- Hook · 15% · energy high · hold at least 1 s';
 assert.ok((await pinnedKits('/nonexistent',{scratch_guide:guide})).includes('# Format playbook: Launch or motion promo'));
 assert.equal(await pinnedKits('/nonexistent',{}),'');
 assert.equal((await pinnedKits('/nonexistent',{scratch_guide:'x'.repeat(9000)})).length,6000);
});
