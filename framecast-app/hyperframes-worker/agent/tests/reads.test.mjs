import test from 'node:test';import assert from 'node:assert/strict';
import {findings,visualFindings,RULES} from '../reads-check.mjs';
const frames=(n,fn)=>Array.from({length:n},(_,i)=>({t:Math.round(i*RULES.step*100)/100,...fn(i*RULES.step)}));
test('a headline that leaves before it can be read is flagged; one held long enough is not',()=>{
 const f=frames(151,t=>({texts:[...(t<1?[{id:1,x:0,y:0,text:'No camera or editing experience needed'}]:[]),...(t>=2&&t<6?[{id:2,x:0,y:0,text:'One video, four formats'}]:[])],things:[{id:9,x:0,y:0}]}));
 const codes=findings(f,15).filter(x=>x.code==='reading_time');
 assert.equal(codes.length,1);assert.match(codes[0].message,/No camera/);
});
test('the closing line only needs to be on screen at the end',()=>{
 const f=frames(151,t=>({texts:t>=14.5?[{id:3,x:0,y:0,text:'WyvStudio, turn any idea into a video'}]:[],things:[{id:9,x:0,y:0}]}));
 assert.equal(findings(f,15).filter(x=>x.code==='reading_time').length,0);
});
test('an empty stretch mid-video is a blank-frame finding; a filled one is not',()=>{
 const f=frames(151,t=>({texts:[],things:t>5&&t<5.6?[]:[{id:9,x:0,y:0}]}));
 const b=findings(f,15).filter(x=>x.code==='blank_frames');
 assert.equal(b.length,1);assert.ok(b[0].time>5&&b[0].time<5.2);
});
test('slow drift is one advisory finding listing its moments',()=>{
 const f=frames(151,t=>({texts:[],things:[{id:1,x:t*10,y:0},{id:2,x:t*10,y:50}]}));
 const d=findings(f,15).filter(x=>x.code==='slow_drift');
 assert.equal(d.length,1);assert.equal(d[0].severity,'warning');
});

// Portrait frame; a thing is {id,x,y,w,h,sig}. sig changes when the element moves, fades or changes words.
const W=1080,H=1920,box=(id,x,y,w,h,extra={})=>({id,x,y,w,h,sig:[x,y,w,h].map(Math.round).join(','),...extra});
const bg={id:100,x:0,y:0,w:W,h:H,sig:'bg',bg:true};
test('a picture that does not change for over 1.5 s is a still stretch; moving beats, playing video, a marked hold and the end card are not',()=>{
 const f=frames(151,t=>({texts:[],small:[],W,H,held:t>=8&&t<10.5,things:[bg,
  t<3?box(1,100+t*200,400,900,300):t<6?box(2,90,400,900,300):t<8?box(3,90,400,900,300,{live:true}):t<10.5?box(4,90,400,900,300):t<12.6?box(5,90,400,900,400*(t-10)):box(6,90,400,900,300)]}));
 const s=visualFindings(f,15).filter(x=>x.code==='still_stretch');
 assert.deepEqual(s.map(x=>x.time),[3],'only the 3 s held card mid-video: '+JSON.stringify(s.map(x=>x.message)));
 assert.match(s[0].message,/from 3\.0 s to 6\.0 s \(3\.0 s\)/);
 const long=frames(151,t=>({texts:[],small:[],W,H,held:true,things:[bg,box(1,90,400,900,t<4?300:301)]}));
 assert.equal(visualFindings(long.slice(40),15).filter(x=>x.code==='still_stretch').length,1,'a marked hold still may not exceed 3 s');
});
test('sentence text too small for a phone is one finding listing the lines; a short label is not',()=>{
 const f=frames(31,t=>({texts:[],W,H,held:false,things:[bg,box(1,0,0,900,900,{sig:String(t)})],small:t>=1&&t<2?[{id:7,text:'Generate your video then check the result',size:22}]:[]}));
 const s=visualFindings(f,3).filter(x=>x.code==='small_text');
 assert.equal(s.length,1);assert.match(s[0].message,/under 32 px\): "Generate your video then check the result" 22 px at 1\.0 s/);
 const brief=frames(31,t=>({texts:[],W,H,held:false,things:[bg],small:t>=1&&t<1.3?[{id:7,text:'A passing line of small words',size:20}]:[]}));
 assert.equal(visualFindings(brief,3).filter(x=>x.code==='small_text').length,0,'a line on screen under half a second is not counted');
});
test('a small card alone in a big frame is mostly empty; a big headline or a filled frame is not',()=>{
 const f=frames(61,t=>({texts:[],small:[],W,H,held:false,things:[bg,t<3?box(1,400,800,280,200,{sig:String(t)}):box(2,60,600,960,300,{sig:String(t)})]}));
 const e=visualFindings(f,6).filter(x=>x.code==='mostly_empty');
 assert.equal(e.length,1);assert.equal(e[0].time,0);assert.match(e[0].message,/for 3\.0 s/);
});
test('a video that opens on an empty or nearly empty frame is a weak opening; one that opens on its hook is not',()=>{
 const empty=frames(31,t=>({texts:[],small:[],W,H,held:false,things:t<0.5?[bg]:[bg,box(2,60,600,960,300,{sig:String(t)})]}));
 const weak=visualFindings(empty,3).filter(x=>x.code==='weak_opening');
 assert.equal(weak.length,1);assert.match(weak[0].message,/opens on an empty frame/);
 const label=frames(31,t=>({texts:[],small:[],W,H,held:false,things:[bg,t<1?box(1,400,100,280,60,{sig:'l'}):box(2,60,600,960,300,{sig:String(t)})]}));
 assert.match(visualFindings(label,3).find(x=>x.code==='weak_opening')?.message||'',/nearly empty frame/);
 const hook=frames(31,t=>({texts:[],small:[],W,H,held:false,things:[bg,box(2,60,600,960,300,{sig:String(t)})]}));
 assert.equal(visualFindings(hook,3).filter(x=>x.code==='weak_opening').length,0);
});
test('text cards that barely change for most of the video are a slideshow; kinetic type that keeps moving and pictured beats are not',()=>{
 // Four cards of 3.75 s each, text on a plain background, each held still.
 const cards=frames(151,t=>({texts:[],small:[],W,H,held:false,things:[bg,box(10+Math.floor(t/3.75),90,700,900,300,{text:true})]}));
 const s=visualFindings(cards,15).filter(x=>x.code==='slideshow');
 assert.equal(s.length,1);assert.match(s[0].message,/slideshow of cards/);
 // The same cards with words building on the voice every step: kinetic, not a slideshow.
 const kinetic=frames(151,t=>({texts:[],small:[],W,H,held:false,things:[bg,box(10+Math.floor(t/3.75),90,700,900,300+Math.round(t*10)%7,{text:true})]}));
 assert.equal(visualFindings(kinetic,15).filter(x=>x.code==='slideshow').length,0);
 // Cards beside a product picture for most of it: not a slideshow.
 const pictured=frames(151,t=>({texts:[],small:[],W,H,held:false,things:[bg,box(10+Math.floor(t/3.75),90,700,900,300,{text:true}),...(t<10?[box(50,200,1200,600,600,{media:true})]:[])]}));
 assert.equal(visualFindings(pictured,15).filter(x=>x.code==='slideshow').length,0);
});
