window.buildMain=function(){
var V={};try{if(window.__hyperframes&&window.__hyperframes.getVariables)V=window.__hyperframes.getVariables()||{};}catch(e){}
function txt(id,def){return (V[id]&&typeof V[id]==='string')?V[id]:def;}
var r=document.documentElement.style;['color_background','color_accent','color_text'].forEach(function(k){if(V[k])r.setProperty('--'+k,V[k]);});
function accent(id,def,word){var t=txt(id,def),el=document.getElementById(id);var i=t.indexOf(word);
if(i<0){el.textContent=t;return;}el.innerHTML='';el.appendChild(document.createTextNode(t.slice(0,i)));var em=document.createElement('em');em.textContent=word;el.appendChild(em);el.appendChild(document.createTextNode(t.slice(i+word.length)));}
accent('headline','Turn any idea into a video that sells','sells');
accent('line_1','No camera or editing experience needed','No camera');
accent('line_2','Voiced, captioned, ready-to-post videos','ready-to-post');
accent('line_3','One video, 4 formats','4 formats');
var cta=txt('cta','WyvStudio'),mk=document.getElementById('cta');
if(cta.indexOf('Wyv')===0){mk.innerHTML='<b>Wyv</b>';mk.appendChild(document.createTextNode(cta.slice(3)));}else mk.textContent=cta;
// editing timeline clutter
var tr=document.getElementById('tracks');var lay=[[0,180,'',200,150,'a',380,260,'',680,200,''],[40,240,'a',300,120,'',440,300,'a',760,130,''],[0,110,'',130,330,'',480,170,'a',670,220,''],[90,260,'',370,90,'a',480,240,'',740,150,'a'],[0,300,'a',320,200,'',540,330,'','',0,'']];
var blks=[];lay.forEach(function(row,ri){var t=document.createElement('div');t.className='trk';t.style.top=(ri*84)+'px';tr.appendChild(t);
for(var j=0;j<row.length;j+=3){if(row[j]==='')continue;var b=document.createElement('div');b.className='blk '+row[j+2];b.style.left=row[j]+'px';b.style.width=row[j+1]+'px';t.appendChild(b);blks.push(b);}});
var wv=document.getElementById('wave');var bars=[];for(var k=0;k<28;k++){var w=document.createElement('div');w.className='bar-w';wv.appendChild(w);bars.push(w);}
var tl=gsap.timeline({paused:true});
// frame
tl.fromTo('#prog',{scaleX:0},{scaleX:1,duration:15,ease:'none'},0);
var tc={t:0},tcel=document.getElementById('tc');
function pad(n){return (n<10?'0':'')+n;}
tl.to(tc,{t:15,duration:15,ease:'none',onUpdate:function(){var s=Math.floor(tc.t),f=Math.floor((tc.t-s)*30);tcel.textContent='00:'+pad(s)+':'+pad(f);}},0);
// s1 problem
tl.fromTo('#cam',{y:-60,opacity:0},{y:0,opacity:1,duration:.5,ease:'power3.out'},0);
tl.fromTo(blks,{scaleX:0,opacity:0},{scaleX:1,opacity:1,duration:.35,ease:'power3.out',stagger:.035,transformOrigin:'0 50%'},.1);
tl.fromTo('#playhead',{x:0},{x:880,duration:2.4,ease:'power1.inOut'},.2);
tl.to(blks,{x:function(i){return (i%3-1)*30;},duration:1.6,ease:'sine.inOut'},1);
tl.fromTo('#strike',{scaleX:0,rotation:-14},{scaleX:1,rotation:-14,duration:.45,ease:'expo.out'},1.4);
tl.to('#cam',{rotation:-8,duration:1.4,ease:'power2.in'},1.6);
// s2 what it does
tl.fromTo('#f2',{xPercent:-100},{xPercent:0,duration:.45,ease:'expo.out'},3);
tl.fromTo('#headline',{y:80,opacity:0},{y:0,opacity:1,duration:.6,ease:'power3.out'},3.25);
tl.fromTo('#browser',{y:220,opacity:0},{y:0,opacity:1,duration:.7,ease:'power3.out'},3.5);
tl.fromTo('#capbar',{scaleX:0},{scaleX:1,duration:.5,ease:'back.out(1.4)'},4.3);
tl.to('#browser',{y:-40,duration:2.2,ease:'sine.inOut'},4.2);
tl.to('#headline',{y:-30,duration:2.6,ease:'sine.inOut'},3.85);
// s3 easy
tl.fromTo('#f3',{yPercent:100},{yPercent:0,duration:.45,ease:'expo.out'},6.5);
tl.fromTo('#line_1',{y:70,opacity:0},{y:0,opacity:1,duration:.5,ease:'power3.out'},6.7);
tl.to('#line_1',{y:-70,opacity:0,duration:.3,ease:'power2.in'},8.3);
tl.fromTo('#line_2',{y:70,opacity:0},{y:0,opacity:1,duration:.5,ease:'power3.out'},8.45);
tl.fromTo(bars,{scaleY:.05},{scaleY:function(i){return .25+((i*37)%10)/12;},duration:.4,ease:'power3.out',stagger:.025},8.5);
tl.to(bars,{scaleY:function(i){return .2+((i*53+3)%10)/13;},duration:.5,ease:'sine.inOut',stagger:.02,repeat:1,yoyo:true},9);
// s4 formats
tl.fromTo('#f4',{xPercent:100},{xPercent:0,duration:.45,ease:'expo.out'},10);
tl.fromTo('#line_3',{y:70,opacity:0},{y:0,opacity:1,duration:.5,ease:'power3.out'},10.2);
var fm=gsap.utils.toArray('.fmt');
tl.fromTo(fm,{scale:.3,opacity:0},{scale:1,opacity:1,duration:.5,ease:'back.out(1.4)',stagger:.12},10.5);
tl.to(fm,{y:-24,duration:1.6,ease:'sine.inOut',stagger:.08},11.2);
// s5 close
tl.fromTo('#cta',{y:60,opacity:0},{y:0,opacity:1,duration:.6,ease:'power3.out'},13.05);
tl.fromTo('#under',{scaleX:0},{scaleX:1,duration:.6,ease:'expo.out'},13.35);
tl.to('#cta',{scale:1.04,duration:1.5,ease:'sine.inOut'},13.5);
return tl;};
