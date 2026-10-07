import test from 'node:test';import assert from 'node:assert/strict';
import {withOwnAudio} from '../composition-agent.mjs';

const file=(kind,name)=>({purpose:'source',asset_type:'audio',kind,name});
test("the user's own voice is the narration and their track the bed, unless the plan bought its own", () => {
 const out=withOwnAudio([],[file('voice','asset-1-me.mp3'),file('music','asset-2-song.mp3'),file('sound','asset-3-ding.wav')]);
 assert.deepEqual(out.map(m=>[m.kind,m.file,m.own]),[['voiceover','asset-1-me.mp3',true],['music','asset-2-song.mp3',true]]);
 const bought=[{kind:'voiceover',status:'succeeded',file:'vo.wav'},{kind:'music',status:'succeeded',file:'bed.mp3'}];
 assert.deepEqual(withOwnAudio(bought,[file('voice','me.mp3'),file('music','song.mp3')]),bought);
 assert.equal(withOwnAudio([],[{...file('voice','ref.mp3'),purpose:'reference'}]).length,0,'a reference is never used as the narration');
 assert.equal(withOwnAudio([{kind:'ugc_take',status:'succeeded',file:'take.mp4'}],[file('voice','me.mp3')]).length,1,'a take already speaks');
});
