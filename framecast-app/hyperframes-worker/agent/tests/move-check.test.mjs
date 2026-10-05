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
