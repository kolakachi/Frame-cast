import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';import vm from 'node:vm';
import {RECIPES} from '../move-check.mjs';
import {pinnedKits} from '../composition-agent.mjs';

// A recording timeline and stub elements, enough to check each move's tweens.
function kit(){
 const calls=[];const tl={set(el,v,at){calls.push(['set',el,v,at]);return tl;},to(el,v,at){calls.push(['to',el,v,at]);return tl;},fromTo(el,a,b,at){calls.push(['fromTo',el,a,b,at]);return tl;}};
 const mk=()=>({style:{},children:[],appendChild(c){this.children.push(c);},set textContent(v){this._t=v;this.children=[];},get textContent(){return this._t;}});
 const ctx={window:{},document:{querySelector:()=>null,querySelectorAll:()=>[],createElement:mk},gsap:{set:()=>{}},Math,Object,Array,String,Number,isFinite};
 ctx.window.gsap=ctx.gsap;vm.createContext(ctx);vm.runInContext(readFileSync(new URL('../../runtime/wyv-motion.js',import.meta.url),'utf8'),ctx);
 return {WM:ctx.window.WM,tl,calls,mk};
}
const NEW=['pushIn','pullBack','dutch','coldOpen','textMask','rampFreeze','hiddenCut','odometer','gauge','streak'];

test('the ten from-scratch moves exist, are named in the move check, and record that they ran', () => {
 const {WM,tl,mk}=kit();
 for(const n of NEW)assert.equal(typeof WM[n],'function',n);
 for(const id of ['push_in','pull_back','dutch','cold_open','text_mask','ramp_freeze','hidden_cut','odometer','gauge','streak'])assert.ok(NEW.includes(RECIPES[id]),id);
 WM.dutch(tl,mk(),1,{});assert.ok(WM.movesUsed().some(m=>m.move==='dutch'));
});

test('odometer rolls each digit column to its digit, keeping separators', () => {
 const {WM,tl,calls,mk}=kit();const el=mk();
 WM.odometer(tl,el,'1,250',2,{});
 assert.equal(el.children.length,5);
 const rolls=calls.filter(c=>c[0]==='fromTo').map(c=>c[3].yPercent);
 assert.deepEqual(rolls,[-10,-20,-50,-0]);
});

test('gauge sweeps a ring by stroke offset, or a bar by width, never past 100', () => {
 const {WM,tl,calls,mk}=kit();const ring={...mk(),getTotalLength:()=>400};
 WM.gauge(tl,ring,87,1,{});
 assert.equal(calls.find(c=>c[0]==='fromTo')[3].strokeDashoffset,400*(1-0.87));
 const k2=kit();k2.WM.gauge(k2.tl,k2.mk(),150,1,{});assert.equal(k2.calls.find(c=>c[0]==='fromTo')[3].scaleX,1);
});

test('a hidden cut swaps the scenes while the blocker covers the frame; cold open cuts each shot in turn', () => {
 const {WM,tl,calls,mk}=kit();const a=mk(),b=mk(),bl=mk();
 WM.hiddenCut(tl,bl,a,b,3,{duration:0.8});
 assert.ok(calls.some(c=>c[0]==='set'&&c[1]===a&&c[2].autoAlpha===0&&c[3]===3.4));
 assert.ok(calls.some(c=>c[0]==='set'&&c[1]===b&&c[2].autoAlpha===1&&c[3]===3.4));
 const k=kit();const shots=[k.mk(),k.mk(),k.mk()];k.WM.coldOpen(k.tl,shots,0,{each:0.25});
 assert.deepEqual(k.calls.filter(c=>c[2].autoAlpha===1).map(c=>c[3]),[0,0.25,0.5]);
});

test('a from-scratch plan pins the motion kit for the builder', async () => {
 const dir=new URL('../guidance/',import.meta.url).pathname.replace(/\/$/,'');
 assert.match(await pinnedKits(dir,{scratch_guide:'\n\n# Format playbook'}),/# kit\/motion-kit\.md \(pinned/);
});

test('smash, split, stack and parallax: the cut is instant, halves meet, the list steps up, nearer layers move further', () => {
 const k=kit();const a=k.mk(),b=k.mk();k.WM.smash(k.tl,a,b,4,{});
 assert.ok(k.calls.some(c=>c[1]===a&&c[2].autoAlpha===0&&c[3]===4)&&k.calls.some(c=>c[1]===b&&c[2].autoAlpha===1&&c[3]===4));
 const s=kit();s.WM.split(s.tl,s.mk(),s.mk(),1,{});const halves=s.calls.filter(c=>c[0]==='fromTo');
 assert.equal(halves[0][2].xPercent,-105);assert.equal(halves[1][2].xPercent,105);assert.equal(halves[0][3].xPercent,0);
 const l=kit();const items=[l.mk(),l.mk(),l.mk()];l.WM.stack(l.tl,items,[1,2,3],{gap:100});
 assert.ok(l.calls.some(c=>c[0]==='to'&&c[1]===items[0]&&c[2].y===-200&&c[3]===3),'the first item has stepped up two places when the third arrives');
 const p=kit();const layers=[p.mk(),p.mk()];p.WM.parallax(p.tl,layers,0,{distance:60});
 const moves=p.calls.filter(c=>c[0]==='fromTo').map(c=>c[3].x-c[2].x);
 assert.ok(Math.abs(moves[1])>Math.abs(moves[0]),'the front layer moves further');
 for(const id of ['smash','split','stack','parallax'])assert.equal(RECIPES[id],id);
});

test('specialty kits ride only on the videos that use them; the core kit lists them for reading on demand', async () => {
 const dir=new URL('../guidance/',import.meta.url).pathname.replace(/\/$/,'');
 const pins=async(...a)=>[...(await pinnedKits(dir,...a)).matchAll(/# (kit\/[a-z-]+\.md) \(pinned/g)].map(m=>m[1]);
 assert.deepEqual(await pins({}),[],'a plain plan carries no kit');
 assert.deepEqual(await pins({scratch_guide:'x'}),['kit/motion-kit.md'],'from scratch: the core kit only');
 assert.deepEqual(await pins({},[],{slug:'collage-zine'}),['kit/motion-kit.md','kit/collage.md']);
 assert.deepEqual(await pins({scenes:[{transition_out:{move:'tear'}}]}),['kit/motion-kit.md','kit/collage.md'],'a collage move brings its kit');
 assert.deepEqual(await pins({},[{kind:'document_page'}]),['kit/motion-kit.md','kit/documents.md']);
 const core=await pinnedKits(dir,{scratch_guide:'x'});
 assert.ok(!core.includes('WM.tear(')&&!core.includes('WM.book('),'the core kit no longer carries the specialty kits');
 assert.ok(core.includes('kit/collage.md')&&core.includes('kit/documents.md'),'but names them so the builder can read one');
});
