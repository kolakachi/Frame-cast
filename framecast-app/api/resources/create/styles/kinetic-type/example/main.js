(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
function setText(id,key){var el=document.getElementById(id);if(el&&typeof v[key]==='string'&&v[key].length){el.textContent=v[key];}}
setText('hookWord','headline');
setText('url','cta');

var tl=gsap.timeline({paused:true});

// background glow carried by opacity and scale
tl.fromTo('#bgGlow',{opacity:0.6,scale:1},{opacity:1,scale:1.15,duration:7.5,ease:'sine.inOut'},0);
tl.to('#bgGlow',{opacity:0.5,scale:1,duration:7.5,ease:'sine.inOut'},7.5);

// S1 hook: VIDEO? slams in frame 1
tl.fromTo('#hookWord',{scale:1.7,opacity:0.2},{scale:1,opacity:1,duration:0.22,ease:'expo.out'},0);
tl.to('#hookWord',{scale:1.08,duration:1.1,ease:'sine.inOut'},0.22);
tl.to('#hookWord',{scale:1.5,opacity:0,duration:0.18,ease:'power3.in'},1.32);

// S2 SLOW. letters stretch in sluggishly then settle
var slow=gsap.utils.toArray('#slowWord span');
tl.fromTo(slow,{x:function(i){return (i-2)*160;},scaleX:1.8,opacity:0},{x:0,scaleX:1,opacity:1,duration:0.9,ease:'power3.out',stagger:0.06},1.5);
tl.to('#slowWord',{scale:1.06,duration:0.9,ease:'sine.inOut'},2.4);
tl.to('#slowWord',{y:-260,opacity:0,duration:0.2,ease:'power3.in'},3.3);

// S3 orange field flips in, HARD. drops heavy and cracks
tl.fromTo('#hardField',{scaleY:0,transformOrigin:'50% 0%'},{scaleY:1,duration:0.22,ease:'power3.out'},3.5);
tl.fromTo('#crack',{y:-900},{y:0,duration:0.32,ease:'expo.out'},3.62);
tl.fromTo('#crack',{scale:1.08},{scale:1,duration:0.3,ease:'power2.out'},3.9);
tl.fromTo('#hardTop',{y:0,rotation:0},{y:-70,x:-20,rotation:-5,duration:0.35,ease:'power3.out'},4.55);
tl.fromTo('#hardBot',{y:0,rotation:0},{y:80,x:24,rotation:4,duration:0.35,ease:'power3.out'},4.58);
tl.to('#hardTop',{y:-140,duration:0.6,ease:'sine.inOut'},4.9);
tl.to('#hardBot',{y:150,duration:0.6,ease:'sine.inOut'},4.9);
tl.to('#crack',{opacity:0,scale:0.9,duration:0.18,ease:'power3.in'},5.32);

// S4 relief stack restacks in
var rl=gsap.utils.toArray('#relief .ln');
tl.fromTo(rl,{y:160,opacity:0},{y:0,opacity:1,duration:0.4,ease:'power3.out',stagger:0.08},5.5);
tl.fromTo('#relief .outline',{scale:0.8},{scale:1,duration:0.45,ease:'back.out(1.4)'},5.78);
tl.to('#relief',{scale:1.05,duration:1.4,ease:'sine.inOut'},5.9);
tl.to(rl,{y:-120,opacity:0,duration:0.2,ease:'power3.in',stagger:0.03},7.22);

// S5 turn: stillness, WyvStudio alone with breathing glow
tl.fromTo('#turnGlow',{opacity:0.3,scale:0.85},{opacity:1,scale:1.12,duration:1.5,ease:'sine.inOut'},7.5);
tl.fromTo('#brandWord',{opacity:0,scale:0.94},{opacity:1,scale:1,duration:0.5,ease:'power3.out'},7.55);
tl.to('#brandWord',{scale:1.04,duration:0.8,ease:'sine.inOut'},8.05);
tl.to('#brandWord',{opacity:0,y:-80,duration:0.18,ease:'power3.in'},8.82);

// S6 promise stacks in, sells pops
var pl=gsap.utils.toArray('#promise .ln');
tl.fromTo(pl,{x:function(i){return i%2?260:-260;},opacity:0},{x:0,opacity:1,duration:0.38,ease:'power3.out',stagger:0.1},9);
tl.fromTo('#promise .grad',{scale:1.4,display:'inline-block'},{scale:1,duration:0.4,ease:'back.out(1.4)'},9.35);
tl.to('#promise',{scale:1.05,duration:1.7,ease:'sine.inOut'},9.5);
tl.to(pl,{y:-140,opacity:0,duration:0.2,ease:'power3.in',stagger:0.03},11.25);

// S7 formats split
tl.fromTo('#fmtTitle',{y:80,opacity:0},{y:0,opacity:1,duration:0.35,ease:'power3.out'},11.5);
var tiles=['#t916','#t11','#t45','#t169'];
tiles.forEach(function(t,i){
 tl.fromTo(t,{scaleY:0,opacity:0,transformOrigin:'50% 100%'},{scaleY:1,opacity:1,duration:0.35,ease:'back.out(1.4)'},11.62+i*0.09);
});
tl.to('.tiles',{y:-20,duration:0.8,ease:'sine.inOut'},11.95);
tl.to('#fmtTitle',{opacity:0,y:-60,duration:0.18,ease:'power3.in'},12.82);
tl.to(tiles,{x:function(i){return [360,190,-40,-300][i];},scale:0.4,opacity:0,duration:0.2,ease:'power3.in',stagger:0.02},12.8);

// S8 close held
tl.fromTo('#closeGlow',{opacity:0.4,scale:0.9},{opacity:1,scale:1.1,duration:2,ease:'sine.inOut'},13);
tl.fromTo('#closeWord',{scale:1.25,opacity:0},{scale:1,opacity:1,duration:0.35,ease:'expo.out'},13);
tl.to('#closeWord',{scale:1.05,duration:1.6,ease:'sine.inOut'},13.35);
tl.fromTo('#url',{y:50,opacity:0},{y:0,opacity:1,duration:0.4,ease:'power3.out'},13.2);

window.__wyvTl=tl;
})();
