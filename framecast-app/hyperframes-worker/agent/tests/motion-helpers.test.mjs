import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';import vm from 'node:vm';

// A stand-in timeline that records tweens, and elements with layout numbers, enough to check the helpers' maths.
function kit(){
 const calls=[];const sets=[];
 const tl={set(el,v,at){calls.push(['set',el,v,at]);return tl;},to(el,v,at){calls.push(['to',el,v,at]);if(v.onUpdate){Object.assign(el,Object.fromEntries(Object.entries(v).filter(([k])=>['l','r'].includes(k))));v.onUpdate();}return tl;},fromTo(el,a,b,at){calls.push(['fromTo',el,a,b,at]);return tl;}};
 const stage={offsetWidth:1080,offsetHeight:1920,offsetLeft:0,offsetTop:0,offsetParent:null,contains:()=>true};
 const el=(x,y,w,h)=>({offsetLeft:x,offsetTop:y,offsetWidth:w,offsetHeight:h,offsetParent:stage,style:{},parentNode:{style:{}}});
 const ctx={window:{},document:{querySelector:()=>stage,querySelectorAll:()=>[]},gsap:{set:(e,v)=>sets.push([e,v])},Math,Object,Array};
 ctx.window.gsap=ctx.gsap;vm.createContext(ctx);vm.runInContext(readFileSync(new URL('../../runtime/wyv-motion.js',import.meta.url),'utf8'),ctx);
 return {WM:ctx.window.WM,tl,calls,sets,el,stage};
}

test('camera zooms the content so the target fills about 60% of the frame, then returns', () => {
 const {WM,tl,calls,el}=kit();const content=el(0,0,1080,1920),target=el(100,800,300,120);
 WM.camera(tl,content,target,2,{});
 const zoom=calls.find(c=>c[0]==='to'&&c[2].scale>1),back=calls.find(c=>c[0]==='to'&&c[2].scale===1);
 assert.ok(zoom[2].scale>=1.15&&zoom[2].scale<=2.4);assert.ok(zoom[2].x<=0&&zoom[2].y<=0,'never shows past the frame edge');
 assert.equal(back[3],2+0.6+1.2);
});

test('flood grows from the button past the corners, holds, and shrinks into the next point', () => {
 const {WM,tl,calls,el}=kit();
 WM.flood(tl,el(0,0,1080,1920),4,{from:el(490,900,100,100),to:{x:540,y:1800},color:'#ff6a3d'});
 const grow=calls.find(c=>c[0]==='to'&&/circle\((\d+(\.\d+)?)px at 540px 950px\)/.test(c[2].clipPath));
 assert.ok(grow&&Number(grow[2].clipPath.match(/circle\(([\d.]+)px/)[1])>=Math.hypot(1080,1920)-1,'past the corners');
 assert.ok(calls.some(c=>c[0]==='to'&&c[2].clipPath==='circle(0px at 540px 1800px)'),'shrinks into the next scene');
});

test('edges: moving right, the right edge leads and the left edge trails', () => {
 const {WM,tl,calls,el}=kit();const pill=el(0,0,120,40);
 WM.edges(tl,pill,1,{x:0,w:120},{x:300,w:160},{});
 const tweens=calls.filter(c=>c[0]==='to');
 assert.equal(tweens[0][2].r,460);assert.equal(tweens[0][2].duration,0.32,'the leading edge is quicker');
 assert.equal(tweens[1][2].l,300);assert.equal(tweens[1][2].duration,0.48);
});
