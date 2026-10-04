import test from 'node:test';import assert from 'node:assert/strict';
import {placeRigs} from '../rig-place.mjs';
const svg='<svg xmlns="http://www.w3.org/2000/svg" id="figma-root" viewBox="0 0 100 120"><g data-rig-part="head"><circle r="1"/></g></svg>';
test('a rig placeholder becomes the inline SVG with the placeholder id, class and style',()=>{
 const html='<div id="root"><div id="maya" class="clip rig" style="width:400px" data-start="0" data-duration="5" data-rig-src="asset-12-ab.svg"></div></div>';
 const out=placeRigs(html,f=>{assert.equal(f,'asset-12-ab.svg');return svg;});
 assert.match(out,/<svg id="maya" class="clip rig" style="width:400px" data-rig-file="asset-12-ab.svg"\s+xmlns="http:\/\/www.w3.org\/2000\/svg"\s+viewBox="0 0 100 120">/);
 assert.doesNotMatch(out,/figma-root/,'the file\'s own id gives way to the placeholder\'s');
 assert.match(out,/data-rig-part="head"/);assert.doesNotMatch(out,/data-rig-src/);
});
test('a missing or non-SVG file leaves the placeholder untouched',()=>{
 const html='<div id="m" data-rig-src="gone.svg"></div>';
 assert.equal(placeRigs(html,()=>{throw Error('no file');}),html);
 assert.equal(placeRigs(html,()=>'<html>not svg</html>'),html);
});
