(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
function setText(id,key){var el=document.getElementById(id);if(el&&typeof v[key]==='string'&&v[key].length){el.textContent=v[key];}}
setText('hook','headline');setText('name','line_1');setText('now','line_2');setText('r1','line_3');setText('r2','line_4');setText('r3','line_5');setText('offer','line_6');setText('cta','cta');

var tl=gsap.timeline({paused:true});
// Hook: the block stamps in on frame 1, the sticker slaps on with a tilt.
WM.stamp(tl,'#hook',0.05,{});
WM.sticker(tl,'#ship',0.7,{rotate:-7,border:0});
tl.to('#hook',{rotation:-2,duration:0.12,ease:'power2.out'},1.6);
// The card is thrown in; the price slams into its shadow while the old price is struck.
WM.toss(tl,'#card',2.7,{});
tl.fromTo('#price',{x:-14,y:-14,boxShadow:'28px 28px 0 #111',opacity:0},{x:0,y:0,boxShadow:'14px 14px 0 #111',opacity:1,duration:0.18,ease:'power4.in',immediateRender:true},3.6)
  .fromTo('#price .was',{opacity:.3},{opacity:1,duration:.2},3.8);
// Three reasons pop in on an uneven rhythm.
WM.pop(tl,'#r1',6.3,{});WM.pop(tl,'#r2',6.9,{});WM.pop(tl,'#r3',7.3,{});
// The offer: the burst stamps over a marquee that runs at one constant speed.
tl.fromTo('#track',{x:0},{x:-900,duration:5.4,ease:'none',immediateRender:true},9.6);
WM.stamp(tl,'#burst',9.75,{});
tl.to('#burst',{rotation:6,duration:2.2,ease:'sine.inOut'},10.2);
// The button is pressed into its shadow; the brand block lands beside it.
WM.pop(tl,'#btn',11.4,{});WM.toss(tl,'#brand',11.8,{});
tl.to('#btn',{x:10,y:10,boxShadow:'4px 4px 0 #FF6FB5',duration:.08,ease:'power2.in'},13.0)
  .to('#btn',{x:0,y:0,boxShadow:'14px 14px 0 #FF6FB5',duration:.22,ease:'back.out(3)'},13.12);
window.__wyvTl=tl;
})();
