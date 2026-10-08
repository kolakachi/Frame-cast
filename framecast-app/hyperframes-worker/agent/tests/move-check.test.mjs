import test from 'node:test';import assert from 'node:assert/strict';
import {requiredMoves,moveFindings,RECIPES} from '../move-check.mjs';
const plan={reference_systems:[{system:'1:s1',name:'iris wipe',decision:'keep',move:'iris'},{system:'1:s2',name:'phone',decision:'adapt',move:'device'},
 {system:'1:s3',name:'sticker',decision:'drop',move:'pop'},{system:'1:s4',name:'caption',decision:'keep',move:'custom'}],
 reference_decisions:[{moment:'1:m9',decision:'replace',beat:'Payoff',move:'stamp'},{moment:'1:m2',decision:'keep',beat:'Hook',move:'iris'},{moment:'1:m3',decision:'drop',move:'toss'}]};
test('moves come from kept and adapted systems and kept or replaced moments; drops and custom ask for nothing',()=>{
 const m=requiredMoves(plan);
 assert.deepEqual([...m.keys()].sort(),['device','iris','stamp']);
 assert.deepEqual(m.get('iris'),['iris wipe','the Hook beat']);
});
test('a named move the composition never calls is sent back with its recipe',()=>{
 const f=moveFindings({plan,sources:'<script src="gsap.min.js"></script><script src="wyv-motion.js"></script><script>WM.iris(tl,"#b",2.2,{from:"#q"});</script>'});
 assert.deepEqual(f.map(x=>x.code),['reference_move_missing','reference_move_missing']);
 assert.match(f[0].message,/WM\.device/);assert.match(f[1].message,/WM\.stamp/);
 assert.ok(f.every(x=>x.severity==='error'&&!/Load wyv-motion/.test(x.fixHint)));
});
test('a call inside a comment does not count, and a missing kit is named in the hint',()=>{
 const f=moveFindings({plan:{reference_systems:[{name:'phone',decision:'keep',move:'device'}]},sources:'<script>// WM.device(tl,"#p",4)\n/* WM.device( */</script>'});
 assert.equal(f.length,1);assert.match(f[0].fixHint,/Load wyv-motion\.js/);
});
test('all moves used: nothing to report; no plan: nothing to report',()=>{
 assert.deepEqual(moveFindings({plan,sources:'WM.iris(tl);WM.device (tl);WM.stamp(tl)'}),[]);
 assert.deepEqual(moveFindings({plan:null,sources:''}),[]);
});
test('every recipe the check names exists in the kit',async()=>{
 const {readFile}=await import('node:fs/promises');const vm=await import('node:vm');
 const window={gsap:{}};vm.runInNewContext(await readFile(new URL('../../runtime/wyv-motion.js',import.meta.url),'utf8'),{window,document:{},Math,Object});
 for(const [move,fn] of Object.entries(RECIPES))assert.equal(typeof window.WM[fn],'function',move+' -> WM.'+fn);
});

test('a beat\'s planned transition move is required; a cut is not',async()=>{
 const {requiredMoves}=await import('../move-check.mjs');
 const m=requiredMoves({scenes:[{label:'Upload',transition_out:{from:'button',becomes:'ring',move:'morph'}},{label:'Result',transition_out:{move:'cut'}}]});
 assert.deepEqual([...m.keys()],['morph']);
 assert.deepEqual(m.get('morph'),['the transition out of Upload']);
});

test('review P2: a move whose code is present but never runs is a finding when the render says what ran',async()=>{
 const {moveFindings}=await import('../move-check.mjs');
 const plan={reference_systems:[{system:'1:s1',name:'iris wipe',decision:'keep',move:'iris'}]};
 const dead='function unused(){WM.iris(tl,"#b",3);}';
 assert.equal(moveFindings({plan,sources:dead}).length,0,'code alone (no runtime evidence) still passes the build-time check');
 const f=moveFindings({plan,sources:dead,ran:[{move:'pop',calls:2}]});
 assert.equal(f.length,1);assert.equal(f[0].code,'reference_move_not_run');
 assert.equal(moveFindings({plan,sources:dead,ran:[{move:'iris',calls:1}]}).length,0);
});
test('an edit is not asked to add a move the version it edits never had, but may not drop one it had',()=>{
 const edit={scenes:[{label:'Lockup',transition_out:{move:'wipe'}},{label:'Hook',transition_out:{move:'iris'}}]};
 const base='<script>WM.iris(tl,"#a",1);</script>';
 assert.deepEqual(moveFindings({plan:edit,sources:base,base}),[],'a voice-only change keeps the version as it was');
 const dropped=moveFindings({plan:edit,sources:'<script></script>',base});
 assert.equal(dropped.length,1);assert.match(dropped[0].message,/WM\.iris/,'removing a move the version had is still sent back');
 assert.match(moveFindings({plan:edit,sources:base}).map(f=>f.message).join(),/WM\.wipe/,'a new build still builds every planned move');
});
test('only an edit has a base: not a new build, a resumed build or the motion pass over an approved look',async()=>{
 const {editBaseSources}=await import('../composition-agent.mjs');
 const base_bundle={'index.html':'<b>WM.iris(tl)</b>','main.js':'WM.wipe(tl)','gsap.min.js':'x','wyv-motion.js':'WM.wipe=1','m.mp3':'bin'};
 assert.equal(editBaseSources({base_bundle}),'<b>WM.iris(tl)</b>\nWM.wipe(tl)');
 assert.equal(editBaseSources({}),null);
 assert.equal(editBaseSources({base_bundle,from_look:true}),null);
 assert.equal(editBaseSources({base_bundle,resume:{files:{}}}),null);
});
test('on an edit only the latest request decides whether the art library must be searched',async()=>{
 const {artExpected}=await import('../composition-agent.mjs');
 const art={items:[{id:1}]},plan={scenes:[{idea:'three icons pop in'}]};
 const messages=[{role:'user',content:'Promo with icons'},{role:'user',content:'Change version 2 of the video:\n- Re-voice it.'}];
 assert.equal(artExpected({plan,messages},art),true,'a new build that asks for icons searches');
 assert.equal(artExpected({plan,messages,base_bundle:{'index.html':'<b></b>'}},art),false,'a voice-only edit is not sent to search');
 assert.equal(artExpected({plan,messages:[...messages,{role:'user',content:'Add a 3D icon of a camera'}],base_bundle:{'index.html':'<b></b>'}},art),true,'an edit that asks for one does');
 assert.equal(artExpected({plan,messages},null),false,'no library, nothing to search');
});

test('$100 explainer: moves in the code on a page that never loads the kit are sent back before finishing, with the cause',()=>{
 const plan={scenes:[{label:'Hook',transition_out:{move:'morph'}},{label:'Mechanism',transition_out:{move:'push_in'}}]};
 const page='<script src="gsap.min.js"></script><script src="main.js"></script>';
 const js='function safe(fn,fb){try{fn();}catch(e){fb();}}safe(function(){WM.morph(tl,"#b",[]);},function(){});try{WM.pushIn(tl,"#c",3.4);}catch(e){}';
 const f=moveFindings({plan,sources:page+'\n'+js});
 assert.deepEqual(f.map(x=>x.code),['reference_move_kit_missing','reference_move_kit_missing']);
 assert.deepEqual(f.map(x=>x.move),['morph','push_in']);
 assert.match(f[0].message,/wyv-motion\.js is not loaded/);assert.match(f[0].fixHint,/<script src="wyv-motion\.js"><\/script>/);
 // After the render the cause is still named, not "never runs".
 assert.equal(moveFindings({plan,sources:page+'\n'+js,ran:[],requireRuntime:true})[0].code,'reference_move_kit_missing');
 // Loaded: the code check passes, and the render's evidence decides.
 const loaded='<script src="gsap.min.js"></script><script src="wyv-motion.js"></script><script src="main.js"></script>\n'+js;
 assert.deepEqual(moveFindings({plan,sources:loaded}),[]);
 assert.deepEqual(moveFindings({plan,sources:loaded,ran:[{move:'morph'},{move:'pushIn'}]}),[]);
});
