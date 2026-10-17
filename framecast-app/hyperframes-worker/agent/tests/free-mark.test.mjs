import test from 'node:test';
import assert from 'node:assert/strict';
import {addMark,stripMark} from '../free-mark.mjs';

const page='<html><body><div id="root" data-composition-id="root" data-width="1080" data-height="1920"><section class="clip">Hi</section></div></body></html>';

test('the free first ad is marked inside the frame, sized to the video, and the saved code is clean again',()=>{
 const marked=addMark(page);
 assert.match(marked,/<div id="root"[^>]*><div id="wyv-free-mark" style="position:absolute;right:38px;bottom:38px;[^"]*font:600 28px[^"]*">Made with WyvStudio<\/div><section/);
 assert.equal(addMark(marked),marked,'marked once');
 assert.equal(stripMark(marked),page,'stripped before saving');
 assert.equal(addMark('<html><body>no root</body></html>'),'<html><body>no root</body></html>');
 assert.match(addMark(page.replace('data-width="1080" data-height="1920"','data-width="1920" data-height="1080"')),/font:600 28px/,'sized by the short side');
});
