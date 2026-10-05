import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';import vm from 'node:vm';
import {placeSounds} from '../sound-pass.mjs';

const library=JSON.parse(readFileSync(new URL('../../runtime/sounds/sounds.json',import.meta.url),'utf8'));
function kit(){
 const tl={set(){return tl;},to(){return tl;},fromTo(){return tl;},parent:null};
 const stage={offsetWidth:1080,offsetHeight:1920,offsetLeft:0,offsetTop:0,offsetParent:null,contains:()=>true};
 const ctx={window:{},document:{querySelector:()=>stage,querySelectorAll:()=>[]},gsap:{set(){}},Math,Object,Array,String,isFinite};
 ctx.window.gsap=ctx.gsap;vm.createContext(ctx);vm.runInContext(readFileSync(new URL('../../runtime/wyv-motion.js',import.meta.url),'utf8'),ctx);
 return {WM:ctx.window.WM,tl,root:ctx.gsap};
}
const page=extra=>`<html><body><div id="root" data-composition-id="main" data-duration="10">${extra||''}<div id="a"></div></div></body></html>`;

test('every library sound is on disk with its peak inside the file', () => {
 assert.equal(Object.keys(library).length,12);
 for(const [name,s] of Object.entries(library)){assert.ok(s.peak>=0&&s.peak<s.seconds,name);assert.ok(s.level>0&&s.level<=1,name);}
});

test('moves record their hit in composition time, nested timelines included; opts.sound picks or silences', () => {
 const {WM,tl}=kit();
 WM.pop(tl,{},1);WM.stamp(tl,{},2,{sound:false});WM.whip(tl,{},{},3);WM.press(tl,{},4,{sound:'thud'});
 WM.cursor(tl,{},[{x:0,y:0,at:5},{x:9,y:9,at:5.5,click:true}]);WM.type(tl,{},'hello',6,10);WM.sound(tl,'chime',7);
 const scene={...tl,parent:{parent:null,startTime:()=>0,timeScale:()=>1},startTime:()=>8,timeScale:()=>1};
 WM.pop(scene,{},0.5);
 assert.deepEqual(JSON.parse(JSON.stringify(WM.cueTimes())),[{sound:'pop',t:1.04},{sound:'whoosh-fast',t:3},{sound:'thud',t:4.09},{sound:'click',t:5.5},{sound:'keys',t:6,len:0.5},{sound:'chime',t:7},{sound:'pop',t:8.54}]);
});

test('sounds land on their peak, quieter under the voice, on their own tracks', () => {
 const html=page('<audio id="vo" class="clip" src="vo.wav" data-start="0.3" data-duration="5" data-track-index="1"></audio>');
 const {html:out,placed}=placeSounds({html,cues:[{sound:'whoosh',t:2},{sound:'pop',t:7}],library,duration:10});
 const w=placed.find(p=>p.sound==='whoosh'),p=placed.find(p=>p.sound==='pop');
 assert.equal(w.start,+(2-library.whoosh.peak).toFixed(3));
 assert.equal(w.volume,+(library.whoosh.level*0.6).toFixed(2),'under the voice');
 assert.equal(p.volume,library.pop.level,'clear of the voice');
 assert.match(out,/data-composition-id="main"[^>]*>\n<audio id="wm-sfx-1" class="clip" data-sfx="whoosh" src="sfx-whoosh.wav"[^>]*data-track-index="2"/);
});

test('crowded cues keep the hit, skip the builder\'s own effects and honour data-sounds="off"', () => {
 const html=page('<audio id="fx" class="clip" src="fx.wav" data-start="5" data-duration="0.5" data-track-index="3"></audio>');
 const {placed}=placeSounds({html,cues:[{sound:'slide',t:2},{sound:'thud',t:2.1},{sound:'pop',t:5.1},{sound:'tick',t:9.99}],library,duration:10});
 assert.deepEqual(placed.map(p=>p.sound),['thud']);
 assert.equal(placeSounds({html:html.replace('data-composition-id','data-sounds="off" data-composition-id'),cues:[{sound:'thud',t:2}],library,duration:10}).placed.length,0);
});

test('overlapping effects get separate tracks; a long sound is trimmed at the end of the video', () => {
 const {html,placed}=placeSounds({html:page(),cues:[{sound:'chime',t:9.5},{sound:'whoosh-big',t:9.2}],library,duration:10});
 const chime=placed.find(p=>p.sound==='chime');
 assert.ok(chime.start+chime.dur<=10+1e-9);
 const tracks=[...html.matchAll(/data-track-index="(\d+)"/g)].map(m=>m[1]);
 assert.equal(new Set(tracks).size,2);
});
