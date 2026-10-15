import test from 'node:test';import assert from 'node:assert/strict';
import {splitNotes,listItems,unansweredNotes} from '../change-notes.mjs';

test('a change message splits into its notes: bullets, lines, or sentences; one note is not a list', () => {
 assert.deepEqual(splitNotes('- make the logo bigger\n- slower ending\n2) blue background'),['make the logo bigger','slower ending','blue background']);
 assert.deepEqual(splitNotes('Make the logo bigger. Slow the ending down a bit. Use the blue background.'),['Make the logo bigger.','Slow the ending down a bit.','Use the blue background.']);
 assert.deepEqual(splitNotes('Aumente o logo. Deixe o final mais lento.'),['Aumente o logo.','Deixe o final mais lento.']);
 assert.deepEqual(splitNotes('make the logo bigger'),[]);
});

test('the summary must list one item per note, in any language', () => {
 const notes=['bigger logo','slower end','blue'];
 assert.equal(listItems('Done.\nYour changes:\n- Logo is 30% bigger\n- Ending holds 1 s longer\n- Background is blue'),3);
 assert.equal(listItems('Pronto. Suas mudanças: - Logo maior - Final mais lento - Fundo azul'),3);
 assert.deepEqual(unansweredNotes('Made the changes.',notes),notes);
 assert.deepEqual(unansweredNotes('Done: - a - b - c',notes),[]);
 assert.deepEqual(unansweredNotes('Made it.',['one']),[]);
});
