(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
function setText(id,key){var el=document.getElementById(id);if(el&&typeof v[key]==='string'&&v[key].length){el.textContent=v[key];}}
setText('question','headline');setText('cause1','line_1');setText('cause2','line_2');setText('cause3','line_3');
setText('turnLine','line_4');setText('fixLine','line_5');setText('brand','line_6');setText('url','cta');

var tl=gsap.timeline({paused:true});
// Handwriting writes on left to right; its length sets its time.
function write(sel,at,d){tl.fromTo(sel,{clipPath:'inset(-20% 100% -20% -4%)'},{clipPath:'inset(-20% -4% -20% -4%)',duration:d,ease:'power1.inOut'},at);}
// Strokes draw along their path, one after another.
function draw(sel,at,each){gsap.utils.toArray(sel).forEach(function(p,i){tl.fromTo(p,{strokeDashoffset:1},{strokeDashoffset:0,duration:each,ease:'power1.inOut'},at+i*each*0.85);});}
// The camera glides to the next stop; eased in and out like a hand moving the board.
function glide(y,at){tl.to('#board',{y:-y,duration:1,ease:'sine.inOut'},at);}

tl.set('#board',{y:0},0);
// Stop 1: the question and the wilting plant.
write('#question',0.2,1.3);
draw('#plant path',0.6,0.35);
// Stop 2: three causes, each drawn as it is said.
glide(1920,2.4);
draw('#c1 path',3.4,0.5);write('#cause1',3.7,0.5);
draw('#c2 path',4.3,0.4);write('#cause2',4.6,0.5);
draw('#c3 path',5.2,0.35);write('#cause3',5.5,0.5);
// The turn: the usual cause circled in red.
draw('#circle1 path',6.3,0.7);write('#turnLine',6.9,0.7);
// Stop 3: the arrow to the fix, then the fix drawn.
tl.to('#board',{y:-2700,duration:0.9,ease:'sine.inOut'},8.0);
draw('#arrow path',8.5,0.4);
glide(3840,9.0);
write('#fixLine',9.6,1.2);
draw('#finger path',10.4,0.4);
// Stop 4: the brand written on and underlined; held to the end.
glide(5760,12.0);
write('#brand',12.8,0.8);
draw('#under path',13.5,0.4);
write('#url',13.8,0.5);
tl.to('#board',{y:-5790,duration:1.2,ease:'sine.inOut'},13.8);

window.__wyvTl=tl;
window.__timelines=window.__timelines||{};
})();
