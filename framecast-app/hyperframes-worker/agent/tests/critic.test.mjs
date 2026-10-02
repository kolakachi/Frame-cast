import test from 'node:test';import assert from 'node:assert/strict';
import {parseCriticVerdict,criticMessages,criticLine,CRITERIA} from '../critic.mjs';
import {preflight} from '../preflight.mjs';

test('the verdict is validated: pass needs every score at 7 and a mean of 8; a malformed reply is a revise',()=>{
 const pass=parseCriticVerdict('Here you go {"scores":{"hook":8,"hierarchy":9,"density":8,"energy":8,"performance":7},"verdict":"pass","directives":["x"],"note":"Strong hook"}');
 assert.equal(pass.verdict,'pass');assert.equal(pass.mean,8);assert.deepEqual(pass.directives,['x']);
 const low=parseCriticVerdict('{"scores":{"hook":9,"hierarchy":9,"density":9,"energy":9,"performance":6},"verdict":"pass","directives":[]}');
 assert.equal(low.verdict,'revise','one score under 7 cannot pass whatever the model claims');
 const mean=parseCriticVerdict('{"scores":{"hook":7,"hierarchy":7,"density":7,"energy":7,"performance":7},"verdict":"pass","directives":["a","b","c","d"]}');
 assert.equal(mean.verdict,'revise');assert.equal(mean.directives.length,3,'at most three directives');
 const bad=parseCriticVerdict('I cannot see the image.');
 assert.equal(bad.ok,false);assert.equal(bad.verdict,'revise');assert.equal(bad.directives.length,1);
 assert.match(criticLine(pass),/^Critic: hook 8, hierarchy 9/);
 assert.deepEqual(CRITERIA,['hook','hierarchy','density','energy','performance']);
});
test('the critic gets one user turn: the brief, the beats, our sheet and the strip as images',()=>{
 const m=criticMessages({brief:'Sell the thing',plan:{summary:'A plan',scenes:[{start:0,end:3,label:'Hook',idea:'Word slams',uses:['cta-lockup']}],signature_move:'Giant wipe'},route:'product',
  sheet:{image:'data:image/jpeg;base64,AAAA',reference:true},strip:'data:image/png;base64,BBBB',authorScores:[{time:1,score:8}],findings:'Fine',round:2});
 assert.equal(m.length,1);assert.equal(m[0].role,'user');
 const text=m[0].content[0].text;
 assert.match(text,/Brief: Sell the thing/);assert.match(text,/0-3s Hook: Word slams \[uses cta-lockup\]/);assert.match(text,/bottom row: the reference/);assert.match(text,/Image 2: the strip/);assert.match(text,/Review round 2/);
 assert.deepEqual(m[0].content.slice(1).map(b=>[b.type,b.source.media_type]),[['image','image/jpeg'],['image','image/png']]);
 const look=criticMessages({brief:'x',lookOnly:true,sheet:{image:'nope'}});
 assert.equal(look[0].content.length,1,'an invalid image is left out');assert.match(look[0].content[0].text,/LOOK stage/);
});
test('pre-flight fills composition variables and names what would fail the check',()=>{
 const html=`<html data-composition-variables='[{"id":"headline","default":"Hi"},{"id":"accent","type":"color","label":"Accent","default":"#FF6B35"},{"id":"count","default":3}]'><head><script src="https://cdn.jsdelivr.net/npm/gsap"></script></head><body><img src="hero.png"><img src="missing.png"><script src="main.js"></script></body></html>`;
 const r=preflight({path:'index.html',text:html,assets:['hero.png']});
 const vars=JSON.parse(/data-composition-variables='([^']*)'/.exec(r.text)[1]);
 assert.deepEqual(vars[0],{id:'headline',default:'Hi',label:'Headline',type:'string'});
 assert.deepEqual(vars[1],{id:'accent',type:'color',label:'Accent',default:'#FF6B35'},'a complete entry is untouched');
 assert.equal(vars[2].type,'number');
 assert.deepEqual(r.fixed,['composition variables: label, type or default filled in']);
 assert.ok(r.warnings.some(w=>/loaded from a URL/.test(w)));assert.ok(r.warnings.some(w=>/__timelines/.test(w)));assert.ok(r.warnings.some(w=>/"missing.png" is referenced/.test(w)));
 assert.ok(!r.warnings.some(w=>/hero.png/.test(w)));
 const js=preflight({path:'main.js',text:'const r=Math.random();gsap.to(x,{repeat:-1});setTimeout(f,1)',assets:[]});
 assert.equal(js.warnings.length,3);assert.equal(js.text,'const r=Math.random();gsap.to(x,{repeat:-1});setTimeout(f,1)','scripts are never rewritten');
 const css=preflight({path:'style.css',text:'.a{transition:opacity .3s}',assets:[]});
 assert.equal(css.warnings.length,1);
 const clean=preflight({path:'index.html',text:`<html><body><script>window.__timelines["main"]=tl;</script></body></html>`,assets:[]});
 assert.deepEqual([clean.fixed,clean.warnings],[[],[]]);
});
test('pre-flight settles the sound of every timed video',()=>{
 const html='<html><body><video id="take" data-start="0" data-duration="13" src="take.mp4"></video><video data-start="1" src="broll.mp4"></video><video src="x.mp4"></video><video data-start="2" muted src="y.mp4"></video><script>window.__timelines["main"]=1;</script></body></html>';
 const r=preflight({path:'index.html',text:html,assets:['take.mp4','broll.mp4','x.mp4','y.mp4'],audible:['take.mp4']});
 assert.match(r.text,/<video id="take" data-start="0" data-duration="13" src="take.mp4" data-has-audio="true">/);
 assert.match(r.text,/<video data-start="1" src="broll.mp4" muted>/);
 assert.match(r.text,/<video src="x.mp4"><\/video>/,'an untimed video is left alone');
 assert.equal(r.fixed.length,2);
});
