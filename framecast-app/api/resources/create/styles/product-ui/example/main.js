(function(){
var V={};try{if(window.__hyperframes&&window.__hyperframes.getVariables)V=window.__hyperframes.getVariables()||{};}catch(e){}
var D={headline:'Paste a script, drop a link, or type an idea',line_1:'Voiced, captioned, ready-to-post video',line_2:'Four formats ready to post',line_3:'No camera or editing experience needed',cta:'Turn any idea into a video that sells'};
function setT(id,key){var el=document.getElementById(id);if(!el)return;var val=V[key];if(typeof val==='string'&&val.length&&val!==D[key]){el.textContent=val;}}
setT('t1','headline');setT('t2','line_1');setT('t3','line_2');setT('tag','cta');
var t4=document.getElementById('t4');if(t4&&typeof V.line_3==='string'&&V.line_3.length&&V.line_3!==D.line_3){t4.innerHTML='<span class="chk">\u2713</span>';t4.appendChild(document.createTextNode(V.line_3));}
var $=function(s){return document.querySelector(s)};
var tl=gsap.timeline({paused:true});
// Hook
tl.fromTo('#win',{opacity:0,y:90,scale:0.9},{opacity:1,y:0,scale:1,duration:0.7,ease:'power3.out'},0);
tl.fromTo('#t1',{opacity:0,y:40},{opacity:1,y:0,duration:0.6,ease:'power3.out'},0.1);
tl.fromTo('#caret',{opacity:1},{opacity:0.15,duration:0.28,ease:'power1.inOut',yoyo:true,repeat:11},0.3);
tl.fromTo('#cursor',{opacity:0,x:120,y:160},{opacity:1,x:0,y:0,duration:0.6,ease:'power3.out'},0.4);
tl.to('#cursor',{x:-160,y:-540,duration:0.8,ease:'power2.inOut'},1.0);
tl.to('#field',{borderColor:'#FF6B35',duration:0.3,ease:'power2.out'},1.7);
tl.to('#cursor',{scale:0.85,duration:0.1,ease:'power2.in',yoyo:true,repeat:1},1.8);
// Idea typed
var word='Your idea',typed=document.getElementById('typed'),p={n:0};
typed.textContent='';
tl.to(p,{n:word.length,duration:1.4,ease:'power1.inOut',onUpdate:function(){typed.textContent=word.slice(0,Math.round(p.n));}},2.0);
tl.fromTo('#stage',{scale:1},{scale:1.07,duration:2.6,ease:'power2.inOut',transformOrigin:'50% 62%'},2.4);
tl.to('#cursor',{x:-160,y:-265,duration:0.7,ease:'power2.inOut'},3.7);
tl.to('#gen',{backgroundColor:'#FF6B35',boxShadow:'0 0 60px rgba(255,107,53,.55)',duration:0.4,ease:'power2.out'},4.2);
tl.to('#gen',{scale:0.94,duration:0.12,ease:'power2.in'},4.7);
tl.to('#gen',{scale:1,duration:0.35,ease:'back.out(1.4)'},4.82);
tl.to('#cursor',{scale:0.85,duration:0.12,ease:'power2.in',yoyo:true,repeat:1},4.7);
tl.to('#t1',{opacity:0,y:-30,duration:0.35,ease:'power2.in'},4.95);
// Voiced and captioned
tl.to('#cursor',{opacity:0,y:-200,duration:0.4,ease:'power2.in'},5.1);
tl.to('#ui',{opacity:0,duration:0.3,ease:'power2.in'},5.2);
tl.to('#stage',{scale:1,duration:0.8,ease:'power3.inOut'},5.2);
tl.to('#win',{top:520,height:1080,duration:0.8,ease:'power3.inOut'},5.2);
tl.to('#pv',{opacity:1,duration:0.5,ease:'power2.out'},5.4);
tl.fromTo('#fig',{y:60,scale:0.96},{y:0,scale:1.04,duration:4,ease:'power1.out'},5.4);
tl.fromTo('#t2',{opacity:0,y:40},{opacity:1,y:0,duration:0.6,ease:'power3.out'},5.4);
tl.fromTo('#wave i',{scaleY:0.2},{scaleY:1,duration:0.22,ease:'power1.inOut',yoyo:true,repeat:13,stagger:{each:0.05,from:'center'}},5.8);
tl.fromTo('.cw',{opacity:0,y:20,scale:0.8},{opacity:1,y:0,scale:1,duration:0.35,ease:'back.out(1.4)',stagger:0.35},6.2);
tl.to('#t2',{opacity:0,y:-30,duration:0.35,ease:'power2.in'},9.15);
// Four formats
tl.to('#win',{opacity:0,scale:0.82,duration:0.45,ease:'power2.in'},9.2);
var fs=['#f1','#f2','#f3','#f4'],from=[[150,140],[-120,140],[-110,-120],[150,-140]];
fs.forEach(function(f,i){tl.fromTo(f,{opacity:0,scale:0.55,x:from[i][0],y:from[i][1]},{opacity:1,scale:1,x:0,y:0,duration:0.6,ease:'back.out(1.4)'},9.5+i*0.12);
tl.fromTo(f+' .ok',{scale:0},{scale:1,duration:0.35,ease:'back.out(1.4)'},10.3+i*0.15);});
tl.fromTo('#t3',{opacity:0,y:40},{opacity:1,y:0,duration:0.6,ease:'power3.out'},9.6);
tl.fromTo('#stage',{y:0},{y:-30,scale:1.03,duration:2.6,ease:'power1.inOut',transformOrigin:'50% 50%'},10.0);
tl.to('#t3',{opacity:0,y:-30,duration:0.35,ease:'power2.in'},12.35);
// Close
tl.to('#stage',{opacity:0,scale:0.9,duration:0.5,ease:'power2.in'},12.3);
tl.fromTo('#logo',{opacity:0,scale:0.5,rotation:-12},{opacity:1,scale:1,rotation:0,duration:0.6,ease:'back.out(1.4)'},12.75);
tl.fromTo('#name',{opacity:0,y:40},{opacity:1,y:0,duration:0.6,ease:'power3.out'},12.85);
tl.fromTo('#tag',{opacity:0,y:30},{opacity:1,y:0,duration:0.6,ease:'power3.out'},13.0);
tl.fromTo('#t4',{opacity:0,y:30},{opacity:1,y:0,duration:0.6,ease:'power3.out'},13.2);
tl.fromTo('#brand',{scale:1},{scale:1.04,duration:2.2,ease:'power1.out'},12.8);
window.__wyvTL=tl;
})();
