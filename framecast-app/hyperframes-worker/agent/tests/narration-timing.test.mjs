import test from 'node:test';import assert from 'node:assert/strict';
import {findPhrase,voiceTiming,beatFindings} from '../narration-timing.mjs';
// The real narration of a build: six lines, 19 s of voice, in a 30 s video.
const words=[['Want',0.28,0.5],['to',0.5,0.6],['create',0.6,0.9],['your',0.9,1.0],['first',1.0,1.3],['UGC',1.3,1.8],['ad?',1.8,2.1],['Start',2.4,2.7],['here.',2.7,3.0],
 ['First,',3.5,3.9],['give',3.9,4.1],['Wiv',4.1,4.3],['Studio',4.3,4.7],['your',4.7,4.8],['product',4.8,5.2],['and',5.2,5.3],['a',5.3,5.35],['clear',5.35,5.6],['idea.',5.6,6.0],
 ['Next,',6.6,6.9],['review',6.9,7.2],['your',7.2,7.3],['script',7.3,7.7],['and',7.7,7.8],['choose',7.8,8.1],['your',8.1,8.2],['presenter.',8.2,8.8],
 ['Generate',9.4,9.9],['your',9.9,10.0],['video,',10.0,10.4],['then',10.6,10.8],['check',10.8,11.1],['the',11.1,11.2],['result.',11.2,11.7],
 ['Happy',12.3,12.6],['with',12.6,12.8],['it?',12.8,13.0],['Download',13.3,13.8],['your',13.8,13.9],['ad',13.9,14.1],['and',14.1,14.2],['get',14.2,14.4],['ready',14.4,14.7],['to',14.7,14.8],['share.',14.8,15.3],
 ['Start',15.9,16.2],['creating',16.2,16.6],['with',16.6,16.8],['Wiv',16.8,17.0],['Studio.',17.0,17.5]];
const lines=['Want to create your first UGC ad? Start here.','First, give WyvStudio your product and a clear idea.','Next, review your script and choose your presenter.','Generate your video, then check the result.','Happy with it? Download your ad and get ready to share.','Start creating with WyvStudio.'];
const scenes=[{label:'Hook',start:0,end:4.5,starts_on:'Want to create'},{label:'Step 1 card',start:4.5,end:5.5,starts_on:'First, give'},{label:'Step 1 UI',start:5.5,end:10,starts_on:'your product'},
 {label:'Step 2 card',start:10,end:11,starts_on:'Next, review'},{label:'Step 2 UI',start:11,end:16},{label:'Step 3',start:16,end:22,starts_on:'Generate your video'},{label:'Close',start:22,end:30,starts_on:'Start creating'}];
test('a phrase is found in order, and a recogniser slip still counts',()=>{
 assert.equal(findPhrase(words,'Start here').start,2.4);
 assert.equal(findPhrase(words,'Start creating').start,15.9,'the second "Start" is the one that starts "Start creating"');
 assert.equal(findPhrase(words,'your product').start,4.7);
 assert.equal(findPhrase(words,'Generat your video').start,9.4,'one wrong letter is still heard');
 assert.equal(findPhrase(words,'not in the script'),null);
});
test('beats start when their words are said; a beat without words sits proportionally between its neighbours',()=>{
 const t=voiceTiming({scenes,lines,words,clipStart:0.3,videoSeconds:30});
 const at=l=>t.beats.find(b=>b.label===l).voiced;
 assert.deepEqual(at('Hook'),[0,3.65]);
 assert.equal(at('Step 1 card')[0],3.65,'First is said at 3.5 s, plus the clip start, less a short lead');
 assert.equal(at('Step 1 UI')[0],4.85);assert.equal(at('Step 2 card')[0],6.75);
 const s2=at('Step 2 UI');assert.ok(s2[0]>6.75&&s2[0]<9.55,'between its neighbours: '+s2);
 assert.equal(at('Close')[0],16.05);assert.equal(at('Close')[1],30);
});
test('a voice that ends well before the video gets its own pauses lengthened, one per later line',()=>{
 const t=voiceTiming({scenes,lines,words,clipStart:0.3,videoSeconds:30});
 assert.equal(t.voice_ends,17.8);assert.equal(t.spare_seconds,10.7);
 assert.equal(t.suggestion.op,'space');assert.equal(t.suggestion.insert.length,5);
 assert.deepEqual(t.suggestion.insert[0],[3.25,1.5],'between "here." and "First,", capped at 1.5 s');
 assert.equal(voiceTiming({scenes,lines,words,clipStart:0.3,videoSeconds:19}).suggestion,null,'a voice that fills the video needs nothing');
});
test('the check measures marked beats against the narration as placed, through its offset',()=>{
 const html='<audio id="vo" src="vo.wav" data-start="0.3"></audio><section id="b1" data-beat="Step 1 card" data-start="4.5"></section><section id="b2" data-beat="Close" data-start="16"></section>';
 const rows=[{id:'vo',kind:'audio',src:'vo.wav',start:0.3,end:20}];
 const f=beatFindings({rows,html,scenes,voiceFiles:new Set(['vo.wav']),transcripts:{'vo.wav':words},videoSeconds:30});
 assert.deepEqual(f.map(x=>[x.code,x.selector]),[['beat_off_voice','#b1']]);
 assert.match(f[0].message,/"First, give" is said at 3\.80 s/);assert.match(f[0].fixHint,/data-start to 3\.65/);
 assert.equal(beatFindings({rows,html:'<audio id="vo" src="vo.wav" data-start="0.3"></audio>',scenes,voiceFiles:new Set(['vo.wav']),transcripts:{'vo.wav':words},videoSeconds:30})[0].code,'beats_unmarked');
 assert.deepEqual(beatFindings({rows,html,scenes,voiceFiles:new Set(['vo.wav']),transcripts:{},videoSeconds:30}),[],'no transcript yet, nothing to measure');
});
