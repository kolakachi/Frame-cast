(function(){
var v={};
try{if(window.__hyperframes&&window.__hyperframes.getVariables){v=window.__hyperframes.getVariables()||{};}}catch(e){v={};}
var T=function(k,d){return typeof v[k]==='string'&&v[k].length?v[k]:d;};
document.getElementById('brand').textContent=T('headline','Parcelo');document.getElementById('cta').textContent=T('cta','Track every step');
var ACC='#FF7A45',INK='#26324B',NS='http://www.w3.org/2000/svg',world=document.getElementById('world');

// Isometric projection: ground x runs down-right, y down-left, z up. Screen origin set by the world group.
var C=Math.cos(Math.PI/6),S=0.5;
function P(x,y,z){return [(x-y)*C,(x+y)*S-z];}
function pts(a){return a.map(function(p){var q=P(p[0],p[1],p[2]);return q[0].toFixed(1)+','+q[1].toFixed(1);}).join(' ');}
function el(tag,attrs,parent){var e=document.createElementNS(NS,tag);for(var k in attrs)e.setAttribute(k,attrs[k]);(parent||world).appendChild(e);return e;}
// A block: three faces of one colour family (top light, left mid, right dark).
function block(g,x,y,z,w,d,h,top,left,right){
  el('polygon',{points:pts([[x,y+d,z],[x+w,y+d,z],[x+w,y+d,z+h],[x,y+d,z+h]]),fill:left},g);
  el('polygon',{points:pts([[x+w,y,z],[x+w,y+d,z],[x+w,y+d,z+h],[x+w,y,z+h]]),fill:right},g);
  el('polygon',{points:pts([[x,y,z+h],[x+w,y,z+h],[x+w,y+d,z+h],[x,y+d,z+h]]),fill:top},g);
}
function group(id){var g=el('g',{id:id});return g;}

// Ground: a long strip with a faint tile grid.
var ground=group('ground');
el('polygon',{points:pts([[-260,-260,0],[1900,-260,0],[1900,260,0],[-260,260,0]]),fill:'#EDE3D3'},ground);
for(var gx=-260;gx<=1900;gx+=120)el('polyline',{points:pts([[gx,-260,0],[gx,260,0]]),stroke:'#E2D5C0','stroke-width':2,fill:'none'},ground);
el('polyline',{points:pts([[-100,0,1],[1750,0,1]]),stroke:'#D9C9AE','stroke-width':10,'stroke-dasharray':'20 18',fill:'none'},ground);

// The stops: a phone, a warehouse, a van, a house (x along the path).
var stops=[0,520,1040,1560],objs=[];
var g1=group('o1');block(g1,-60,-140,0,120,40,240,'#9FB7FF','#7F9BF2','#6A86DE');var scr=el('polygon',{points:pts([[-40,-100,30],[40,-100,30],[40,-100,220],[-40,-100,220]]),fill:'#26324B'},g1);el('polygon',{points:pts([[-30,-101,60],[30,-101,60],[30,-101,190],[-30,-101,190]]),fill:'#FFFFFF',id:'screen',opacity:0},g1);objs.push(g1);
var g2=group('o2');block(g2,420,-200,0,220,170,150,'#FFD7A8','#F5BF85','#E6A866');block(g2,420,-200,150,220,170,40,'#F28C6B','#E07558','#C9604A');block(g2,480,-31,0,90,2,90,'#8E6E55','#8E6E55','#7A5C45');objs.push(g2);
var g3=group('o3');block(g3,960,-120,20,190,110,110,'#FFFFFF','#E9EEF7','#D5DDEA');block(g3,1150,-120,20,70,110,80,'#9FD8C9','#7CC6B4','#64B09F');
// Wheels only on the face we see (the left face, y = -10).
[1000,1180].forEach(function(wx){var c=P(wx,-9,22);el('ellipse',{cx:c[0],cy:c[1],rx:24,ry:24,fill:INK},g3);el('ellipse',{cx:c[0],cy:c[1],rx:9,ry:9,fill:'#D5DDEA'},g3);});objs.push(g3);
var g4=group('o4');block(g4,1480,-190,0,180,160,140,'#FFF3D6','#F2DFB3','#E3CC97');
el('polygon',{points:pts([[1480,-190,140],[1660,-190,140],[1660,-110,220],[1480,-110,220]]),fill:'#7FA7E8'},g4);el('polygon',{points:pts([[1480,-30,140],[1660,-30,140],[1660,-110,220],[1480,-110,220]]),fill:'#5E88CF'},g4);
var door=el('polygon',{points:pts([[1540,-29,0],[1600,-29,0],[1600,-29,90],[1540,-29,90]]),fill:'#8E6E55',id:'door'},g4);objs.push(g4);

// The parcel: a small glowing block that travels along the path.
var parcel=group('parcel');
el('ellipse',{cx:0,cy:8,rx:60,ry:30,fill:ACC,opacity:.22},parcel);
block(parcel,-30,-30,0,60,60,55,'#FFC2A3',ACC,'#E2602F');

// Labels: pills floating above each stop.
var names=[T('line_1','Order placed'),T('line_2','Packed'),T('line_3','On its way'),T('line_4','Delivered')],labels=[];
stops.forEach(function(sx,i){
  var p=P(sx,-60,320),g=el('g',{transform:'translate('+p[0].toFixed(1)+' '+p[1].toFixed(1)+')'}),inner=el('g',{},g);
  var w=names[i].length*24+90;el('rect',{x:-w/2,y:-42,width:w,height:84,rx:42,fill:'#FFFFFF',stroke:'#E4D8C6','stroke-width':3},inner);
  el('circle',{cx:-w/2+40,cy:0,r:12,fill:ACC},inner);var t=el('text',{x:-w/2+64,y:14,'font-family':'Inter, sans-serif','font-weight':700,'font-size':40,fill:INK},inner);t.textContent=names[i];
  labels.push(inner);gsap.set(inner,{svgOrigin:'0 40'});
});

var tl=gsap.timeline({paused:true});
// The camera keeps the active stop near the middle of the frame.
// Scale from the SVG's own origin, so the camera maths is exact.
function cam(x,y,s){var p=P(x,y,0);return {x:540-p[0]*s,y:1120-p[1]*s,scale:s};}
gsap.set(world,Object.assign({svgOrigin:'0 0'},cam(0,0,1.5)));
tl.fromTo(world,cam(0,0,1.5),cam(0,0,1.5),0);
// Stop 1: the phone's screen lights, the parcel pops out of it.
tl.fromTo('#screen',{opacity:0},{opacity:1,duration:.3,immediateRender:true},0.3);
WM.pop(tl,labels[0],0.6,{});
var p0=P(stops[0],0,0);
tl.fromTo(parcel,{x:p0[0],y:p0[1]-160,scale:0},{x:p0[0],y:p0[1],scale:1,duration:.5,ease:'back.out(2)',immediateRender:true,svgOrigin:'0 0'},1.0);
// Each run: the camera and the parcel glide together; a hop and a label at each stop.
[1,2,3].forEach(function(i){
  var t0=1.9+(i-1)*3.1,a=P(stops[i],0,0);
  tl.to(world,Object.assign(cam(stops[i],0,1.5),{duration:1.4,ease:'sine.inOut'}),t0);
  tl.to(parcel,{x:a[0],y:a[1],duration:1.4,ease:'power2.inOut'},t0);
  tl.to(parcel,{y:a[1]-50,duration:.18,ease:'power2.out',yoyo:true,repeat:1},t0+1.4);
  WM.pop(tl,labels[i],t0+1.55,{});
});
// Arrival: the door opens; then pull back over the whole path for the close.
tl.to('#door',{fill:'#FFD66B',duration:.3},10.3);
tl.to(world,Object.assign(cam(840,0,0.52),{duration:1.6,ease:'sine.inOut'}),11.0);
tl.fromTo('#close',{opacity:0,y:30},{opacity:1,y:0,duration:.6,ease:'power3.out',immediateRender:true},12.3);
window.__wyvTl=tl;
})();
