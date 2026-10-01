window.buildMain=function(){
var v={};
try{v=(window.__hyperframes&&window.__hyperframes.getVariables&&window.__hyperframes.getVariables())||{}}catch(e){v={}}
var $=function(id){return document.getElementById(id)};
var root=$('root');
['color_background','color_accent','color_text'].forEach(function(k){if(v[k])root.style.setProperty('--'+k,v[k])});
var texts={label:['headline','Turn any idea into'],hero:['line_1','a video that sells'],f1:['line_2','Voiced, captioned, ready-to-post videos'],f2:['line_3','No camera or editing experience needed'],f3:['line_4','One video, 4 formats \u2014 reach more buyers'],url:['cta','wyvstudio.com']};
Object.keys(texts).forEach(function(id){var t=texts[id];var val=v[t[0]];if(typeof val==='string'&&val&&val!==t[1])$(id).textContent=val});
var tl=gsap.timeline({paused:true});
// background: always moving
tl.fromTo('#glow',{opacity:.35,scale:.9},{opacity:1,scale:1.12,duration:15,ease:'sine.inOut'},0);
tl.fromTo('#grid',{y:0,opacity:.5},{y:-180,opacity:1,duration:15,ease:'sine.inOut'},0);
tl.fromTo('#p1',{y:0,scale:1},{y:-420,scale:1.3,duration:15,ease:'sine.inOut'},0);
tl.fromTo('#p2',{y:0,x:0},{y:-520,x:-120,duration:15,ease:'sine.inOut'},0);
tl.fromTo('#p3',{scale:1,opacity:.9},{scale:1.9,opacity:.15,duration:5.5,ease:'power2.in'},0);
// tease: cursor
tl.fromTo('#cursor',{opacity:0,scaleY:.4},{opacity:1,scaleY:1,duration:.4,ease:'power3.out'},0.05);
tl.to('#cursor',{opacity:.1,duration:.3,repeat:5,yoyo:true,ease:'steps(1)'},0.6);
tl.to('#cursor',{scaleY:.1,opacity:0,duration:.3,ease:'power2.in'},3.15);
// glimpse
tl.fromTo('#card',{opacity:0,scale:.7,rotationX:24,transformPerspective:1400,filter:'blur(30px)'},{opacity:.4,scale:.76,rotationX:14,filter:'blur(14px)',duration:1.7,ease:'power2.out'},3.5);
tl.fromTo('#sweep',{x:-320},{x:960,duration:1.3,ease:'power2.inOut'},3.75);
tl.fromTo('#label',{opacity:0,y:30,letterSpacing:'0.5em'},{opacity:1,y:0,letterSpacing:'0.32em',duration:.9,ease:'power3.out'},3.6);
tl.to('#label',{y:-24,duration:4.4,ease:'sine.inOut'},4.5);
tl.to('#label',{opacity:0,y:-60,duration:.3,ease:'power2.in'},8.15);
// reveal burst
tl.fromTo('#burst',{opacity:0,scale:.2},{opacity:1,scale:1.3,duration:.2,ease:'expo.out'},5.35);
tl.to('#burst',{opacity:0,scale:1.8,duration:.9,ease:'power2.in'},5.6);
tl.to('#card',{opacity:1,scale:1,rotationX:0,filter:'blur(0px)',duration:.45,ease:'expo.out'},5.45);
tl.to('#card',{scale:1.04,duration:3,ease:'sine.inOut'},5.9);
tl.fromTo('#play',{scale:.6},{scale:1,duration:.5,ease:'back.out(1.4)'},5.55);
tl.fromTo('#capfill',{width:'8%'},{width:'100%',duration:5.4,ease:'power1.inOut'},5.6);
tl.fromTo('#hero',{opacity:0,y:80,scale:1.12},{opacity:1,y:0,scale:1,duration:.6,ease:'expo.out'},5.55);
tl.to('#hero',{y:-16,duration:2,ease:'sine.inOut'},6.15);
tl.to('#hero',{opacity:0,y:-50,duration:.25,ease:'power2.in'},8.2);
// flash 1: tilt + rim sweep
tl.to('#card',{rotationY:-12,y:60,scale:.94,duration:1.3,ease:'power2.inOut'},8.4);
tl.fromTo('#sweep',{x:-320},{x:960,duration:.9,ease:'power2.inOut'},8.6);
tl.fromTo('#f1',{opacity:0,y:40},{opacity:1,y:0,duration:.35,ease:'power3.out'},8.5);
tl.to('#f1',{opacity:0,y:-30,duration:.22,ease:'power2.in'},9.56);
// flash 2: parallax drift
tl.to('#card',{rotationY:10,x:-70,y:90,scale:.9,duration:1.2,ease:'power2.inOut'},9.75);
tl.to('#p1',{x:120,duration:1.3,ease:'power2.inOut'},9.8);
tl.fromTo('#f2',{opacity:0,y:40},{opacity:1,y:0,duration:.35,ease:'power3.out'},9.8);
tl.to('#f2',{opacity:0,y:-30,duration:.22,ease:'power2.in'},10.86);
tl.to('#card',{scale:.6,opacity:0,duration:.2,ease:'power2.in'},10.9);
// flash 3: four formats
tl.fromTo('#f3',{opacity:0,y:40},{opacity:1,y:0,duration:.35,ease:'power3.out'},11.1);
['#r1','#r2','#r3','#r4'].forEach(function(s,i){tl.fromTo(s,{opacity:0,scale:.3},{opacity:1,scale:1,duration:.45,ease:'back.out(1.4)'},11.12+i*.09);tl.to(s,{y:-20-i*8,duration:.7,ease:'sine.inOut'},11.6)});
tl.to(['#f3','#r1','#r2','#r3','#r4'],{opacity:0,duration:.22,ease:'power2.in',stagger:.03},12.12);
// lockup
tl.fromTo('#mark',{opacity:0,scale:.6},{opacity:1,scale:1,duration:.6,ease:'back.out(1.4)'},12.4);
tl.fromTo('#word',{opacity:0,y:40},{opacity:1,y:0,duration:.6,ease:'power3.out'},12.52);
tl.fromTo('#url',{opacity:0,y:30},{opacity:1,y:0,duration:.6,ease:'power3.out'},12.64);
tl.to('#mark',{scale:1.05,duration:1,repeat:1,yoyo:true,ease:'sine.inOut'},13);
tl.to('#lock',{y:-30,duration:2.6,ease:'sine.inOut'},12.4);
return tl;
};
