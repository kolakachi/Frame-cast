// The toolbar has to say which of two states you are in. "Export ready" sitting
// next to "Update video" with nothing to separate them is what got one project
// rendered twice, a second apart — two charges of compute, two identical files.
const assert = require('node:assert');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');

// Load the ESM helper without a build step.
const src = fs.readFileSync(path.join(__dirname, '../src/composables/exportToolbarState.js'), 'utf8');
const exportToolbarState = new Function(src.replace('export function', 'return function'))();

const done = { status: 'completed' };

test('a current export does not ask to be updated', () => {
  const s = exportToolbarState(done, false);
  assert.equal(s.status, 'Export ready');
  assert.equal(s.action, 'Re-export');
  assert.equal(s.primary, false, 'nothing to do must not look like the next step');
  assert.match(s.hint, /already matches/);
});

test('edits since the export are stated, not left to be guessed', () => {
  const s = exportToolbarState(done, true);
  assert.equal(s.status, 'Changes not exported');
  assert.equal(s.action, 'Update video');
  assert.equal(s.primary, true);
  assert.equal(s.stale, true);
});

test('a project never exported still reads as the first action', () => {
  const s = exportToolbarState(null, false);
  assert.equal(s.action, 'Finish video');
  assert.equal(s.primary, true);
  assert.equal(s.status, '');
});

test('an unfinished or failed export is never dressed up as stale', () => {
  for (const job of [{ status: 'failed' }, { status: 'queued' }, { status: 'processing', progress_percent: 40 }]) {
    const s = exportToolbarState(job, true);
    assert.equal(s.stale, false, `${job.status} is not a stale export`);
    assert.notEqual(s.status, 'Changes not exported');
  }
  assert.equal(exportToolbarState({ status: 'processing', progress_percent: 40 }, false).status, 'Exporting 40%');
});
