(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
function setText(id,key){var el=document.getElementById(id);if(el&&typeof v[key]==='string'&&v[key].length){el.textContent=v[key];}}
setText('city','headline');setText('cond','line_1');setText('alertText','line_2');setText('brand','line_3');setText('cta','cta');

var tl=gsap.timeline({paused:true});
// The colour field drifts for the whole video; the world drifts up slowly (the camera never stops).
WM.mesh(tl,'#mesh',0,{colors:['#141A4A','#5B3FD9','#2E8BFF','#FF7AC6'],duration:15});
tl.fromTo('#world',{y:40,scale:1.02},{y:-40,scale:1,duration:15,ease:'sine.inOut',immediateRender:true},0);
// Glide in from depth.
function glide(sel,at){tl.fromTo(sel,{opacity:0,y:50,scale:.94},{opacity:1,y:0,scale:1,duration:.75,ease:'power3.out',immediateRender:true},at);}
glide('#main',0.1);
WM.count(tl,'#deg',0,21,0.4,1.1);
glide('#hours',3.0);
// Focus moves from the forecast to the hourly strip.
WM.rackFocus(tl,'#main','#hours',3.8,{blur:6,dim:.55});
// The alert slides down from the top.
tl.fromTo('#alert',{opacity:0,y:-140},{opacity:1,y:0,duration:.6,ease:'back.out(1.4)',immediateRender:true},7.2);
WM.rackFocus(tl,'#hours','#alert',7.3,{blur:6,dim:.55});
// Close: everything falls back, the brand pill comes forward.
tl.to(['#main','#hours','#alert'],{opacity:.25,filter:'blur(8px)',duration:.6,ease:'sine.inOut'},11.0);
glide('#close',11.3);
window.__wyvTl=tl;
})();
