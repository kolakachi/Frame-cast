import test from 'node:test';import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';import {promisify} from 'node:util';import {mkdtemp} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {finalVerdict,unintendedBlanks,blankSpans,repairable,repairBrief} from '../final-checks.mjs';
const run=promisify(execFile);
const plan={agreement:{required:['Maya lights the candle','Start with the $9 Test Pass']}};
const look={status:'checked',has_cast:true,required:[{item:'Maya lights the candle',status:'present',time:3.2},{item:'Start with the $9 Test Pass',status:'unclear',time:null,note:'spoken only'}],
 identity:{status:'consistent',times:[]},lettering:{status:'clean',times:[]},actions:[{shot:1,status:'present',time:3}]};

test('a clean video passes only when every blocking check could actually run', () => {
 const v=finalVerdict({plan,look,audio:{script_coverage:0.97,missing:[]},expectsSpeech:true,generatedPeople:true});
 assert.equal(v.status,'unverified','a spoken-only required item seen as unclear is not a pass');
 const seen=finalVerdict({plan:{agreement:{required:['Maya lights the candle']}},look,audio:{script_coverage:0.97,missing:[]},expectsSpeech:true,generatedPeople:true});
 assert.equal(seen.status,'passed');
});

test('lost words, a missing required item, identity drift, blank frames and a missing planned move block delivery', () => {
 const words=finalVerdict({plan:{},look:null,audio:{script_coverage:0.7,missing:['nine dollar test pass']},expectsSpeech:true});
 assert.equal(words.status,'blocked');assert.match(words.findings[0],/nine dollar test pass/);
 const missing=finalVerdict({plan,look:{...look,required:[{item:'Maya lights the candle',status:'missing',time:null}]},audio:{script_coverage:1,missing:[]},expectsSpeech:true,generatedPeople:true});
 assert.equal(missing.status,'blocked');
 const drift=finalVerdict({plan:{},look:{...look,identity:{status:'drift',times:[8.5],note:'different hair'}},generatedPeople:true});
 assert.equal(drift.status,'blocked');assert.match(drift.findings[0],/^At 8\.5 s: /);
 assert.equal(finalVerdict({plan:{},blanks:[{start:6,end:6.8}]}).status,'blocked');
 assert.equal(finalVerdict({plan:{},moves:[{code:'reference_move_missing',message:'never calls WM.iris'}]}).status,'blocked');
});

test('garbled lettering and an unclear directed action are advisory, never blocking', () => {
 const v=finalVerdict({plan:{},look:{...look,required:[],lettering:{status:'garbled',times:[4]},actions:[{shot:2,status:'missing',time:5}]},generatedPeople:true});
 assert.equal(v.status,'issues');assert.equal(v.checks.filter(c=>c.status==='fail').every(c=>!c.blocking),true);
});

test('a look that could not run leaves required items and identity unverified, not passed', () => {
 const v=finalVerdict({plan,look:{status:'unverified'},audio:{script_coverage:1,missing:[]},expectsSpeech:true,generatedPeople:true});
 assert.equal(v.status,'unverified');
 assert.deepEqual(v.checks.filter(c=>c.status==='unverified').map(c=>c.id).sort(),['identity','required']);
});

test('only blank stretches nobody asked for count: not a short fade in or out', async () => {
 assert.deepEqual(unintendedBlanks([{start:0,end:0.4},{start:6,end:7},{start:14.6,end:15}],15),[{start:6,end:7}]);
 const dir=await mkdtemp(tmpdir()+'/blank-'),file=dir+'/v.mp4';
 await run('ffmpeg',['-loglevel','error','-y','-f','lavfi','-i','color=c=white:s=64x64:d=2','-f','lavfi','-i','color=c=black:s=64x64:d=1','-f','lavfi','-i','color=c=white:s=64x64:d=2','-filter_complex','[0][1][2]concat=n=3:v=1','-pix_fmt','yuv420p',file]);
 const spans=await blankSpans(file);
 assert.equal(spans.length,1);assert.ok(Math.abs(spans[0].start-2)<0.2&&Math.abs(spans[0].end-3)<0.2,JSON.stringify(spans));
});

test('only what the build can fix goes to a repair round; a changed person or a take\'s words go to the user', () => {
 const v=finalVerdict({plan:{agreement:{required:['The candle']}},look:{...look,required:[{item:'The candle',status:'missing'}],identity:{status:'drift',times:[9]}},blanks:[{start:6,end:7}],audio:{script_coverage:0.6,missing:['test pass']},expectsSpeech:true,generatedPeople:true});
 assert.deepEqual(repairable(v).map(c=>c.id).sort(),['blank','required','words']);
 assert.deepEqual(repairable(v,{takeUsed:true}).map(c=>c.id).sort(),['blank','required'],'a take speaks its own words: not a build fix');
 assert.match(repairBrief(repairable(v)),/at 6 s, No blank frames/);
});

test('each generated shot gets three frames inside its own window, numbered as the look check numbers shots',async()=>{
 const {shotWindows,shotFrames}=await import('../final-checks.mjs');
 const planMedia=[{kind:'voiceover',status:'succeeded',file:'vo.wav'},{kind:'generated_shot',status:'failed'},{kind:'generated_shot',status:'succeeded',file:'shot-b.mp4'}];
 const html='<video id="x" class="clip" src="shot-b.mp4" data-start="4" data-duration="5" muted></video>';
 const w=shotWindows(html,planMedia);
 assert.deepEqual(w,[{shot:2,start:4,end:9}]);
 assert.deepEqual(shotFrames(w,15),[{time:5,label:'shot 2'},{time:6.5,label:'shot 2'},{time:8.25,label:'shot 2'}]);
});
