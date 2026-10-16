(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
function setText(id,key){var el=document.getElementById(id);if(el&&typeof v[key]==='string'&&v[key].length){el.textContent=v[key];}}
setText('cap1','line_1');setText('sfx','line_2');setText('say','line_3');setText('brand','headline');setText('cta','cta');

var tl=gsap.timeline({paused:true});
// The camera moves into one panel at a time: [left, top, width, height] on the page. Wide panels in a tall frame
// are zoomed past the frame's width (the sides crop), so the panel's middle fills the screen.
function look(r){var s=1.45;return {x:540-(r[0]+r[2]/2)*s,y:960-(r[1]+r[3]/2)*s,scale:s};}
var P1=[40,40,1000,580],P2=[40,650,1000,580],P3=[40,1260,1000,620],PAGE=[0,0,1040,1920];
gsap.set('#world',Object.assign({transformOrigin:'0 0'},look(P1)));
tl.fromTo('#world',look(P1),look(P1),0);
// Panel 1: the caption, then the pings pile up around the worried hero.
WM.stamp(tl,'#cap1',0.2,{});
gsap.utils.toArray('#pings .ping').forEach(function(p,i){WM.pop(tl,p,0.7+i*0.32,{});});
tl.to('#hero1',{x:-8,duration:.06,yoyo:true,repeat:7,ease:'none'},2.2);
// Panel 2: the finger presses FOCUS, CLICK! stamps on the hit.
tl.to('#world',Object.assign(look(P2),{duration:1.0,ease:'power2.inOut'}),3.6);
tl.fromTo('#finger',{y:-200},{y:14,duration:.5,ease:'power2.in',immediateRender:true},4.8).to('#finger',{y:-10,duration:.25,ease:'back.out(3)'},5.3);
WM.stamp(tl,'#click',5.3,{});
// Panel 3: calm, a wave, and the bubble says the feature name.
tl.to('#world',Object.assign(look(P3),{duration:1.0,ease:'power2.inOut'}),7.4);
tl.fromTo('#arm',{rotation:0},{rotation:-18,duration:.25,yoyo:true,repeat:3,ease:'sine.inOut',svgOrigin:'440 470',immediateRender:true},8.6);
WM.pop(tl,'#bubble',8.9,{});
// Pull back to the whole page, then the brand lands over it.
tl.to('#world',{x:540-520*0.62,y:960-960*0.62,scale:0.62,duration:1.2,ease:'power2.inOut'},11.0);
tl.to('#world',{opacity:.35,duration:.4},12.2);
WM.stamp(tl,'#close',12.45,{});
window.__wyvTl=tl;
})();
