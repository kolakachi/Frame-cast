// Builds the built-in motion-graphics sound library (runtime/sounds): twelve short effects synthesized here from
// noise, filters and sines, so they are ours outright (no licence, no provider) and rebuild byte for byte.
// Each file is peak-normalised; sounds.json records its length, its measured peak (where the hit is, so the host
// lines that point up with the move) and its mix level. Run: node scripts/make-sounds.mjs
import {writeFile,mkdir} from 'node:fs/promises';
const SR=44100,OUT=new URL('../runtime/sounds/',import.meta.url);

function rng(seed){return()=>{seed|=0;seed=seed+0x6D2B79F5|0;let t=Math.imul(seed^seed>>>15,1|seed);t=t+Math.imul(t^t>>>7,61|t)^t;return((t^t>>>14)>>>0)/4294967296*2-1;};}
// A biquad whose cutoff can move every sample (RBJ cookbook).
function biquad(type){
 let x1=0,x2=0,y1=0,y2=0;
 return(x,f,q=0.9)=>{
  const w=2*Math.PI*Math.min(f,SR*0.45)/SR,c=Math.cos(w),a=Math.sin(w)/(2*q);
  const b=type==='band'?[a,0,-a]:type==='low'?[(1-c)/2,1-c,(1-c)/2]:[(1+c)/2,-(1+c),(1+c)/2];
  const a0=1+a,y=(b[0]*x+b[1]*x1+b[2]*x2+2*c*y1-(1-a)*y2)/a0;
  x2=x1;x1=x;y2=y1;y1=y;return y;
 };
}
const lerp=(a,b,p)=>a+(b-a)*Math.max(0,Math.min(1,p));
const sweep=(pts,t)=>{for(let i=1;i<pts.length;i++)if(t<=pts[i][0])return lerp(pts[i-1][1],pts[i][1],(t-pts[i-1][0])/(pts[i][0]-pts[i-1][0]));return pts.at(-1)[1];};
// Rises as a curve to the peak, then falls away exponentially.
const swell=(t,peak,rise=2,decay=8)=>t<peak?Math.pow(t/peak,rise):Math.exp(-(t-peak)*decay);
function make(seconds,fn){const n=Math.round(seconds*SR),out=new Float64Array(n);for(let i=0;i<n;i++)out[i]=fn(i/SR,i);return out;}

function whoosh({len,peak,from,mid,to,q=1.1,rise=2,decay=9,seed}){
 const r=rng(seed),bp=biquad('band'),bp2=biquad('band');
 return make(len,t=>{const f=sweep([[0,from],[peak,mid],[len,to]],t),n=r();return (bp(n,f,q)+0.5*bp2(n,f*1.9,q*1.4))*swell(t,peak,rise,decay);});
}
function keystroke(r,lp){return t=>t<0||t>0.03?0:lp(r(),lerp(3200,1400,t/0.03),0.8)*Math.exp(-t*220);}

const SOUNDS={
 whoosh:{level:0.55,make:()=>whoosh({len:0.65,peak:0.32,from:350,mid:2400,to:700,q:1.6,seed:1})},
 'whoosh-fast':{level:0.5,make:()=>whoosh({len:0.38,peak:0.17,from:700,mid:4200,to:1200,q:1.8,rise:3,decay:16,seed:2})},
 'whoosh-big':{level:0.6,make:()=>{
  const air=whoosh({len:0.95,peak:0.5,from:150,mid:1300,to:300,q:0.8,rise:2.4,decay:6,seed:3});
  // A low drop under the air, landing on the peak.
  let ph=0;return make(0.95,(t,i)=>{ph+=2*Math.PI*sweep([[0,95],[0.5,62],[0.95,40]],t)/SR;return air[i]+0.9*Math.sin(ph)*(t<0.5?Math.pow(t/0.5,3):Math.exp(-(t-0.5)*7));});
 }},
 swish:{level:0.4,make:()=>{const r=rng(4),hp=biquad('high'),bp=biquad('band');return make(0.42,t=>{const n=r();return (0.6*hp(n,sweep([[0,2500],[0.15,6000],[0.42,3500]],t),0.7)+bp(n,sweep([[0,1500],[0.15,5000],[0.42,2500]],t),1.2))*swell(t,0.15,2.5,14);});}},
 slide:{level:0.35,make:()=>whoosh({len:0.55,peak:0.26,from:260,mid:1200,to:500,q:1.4,rise:1.6,decay:10,seed:5})},
 pop:{level:0.6,make:()=>{let ph=0;const r=rng(6);return make(0.16,t=>{ph+=2*Math.PI*sweep([[0,950],[0.06,320],[0.16,260]],t)/SR;return Math.sin(ph)*Math.exp(-t*38)*Math.min(1,t/0.002)+0.25*r()*Math.exp(-t*900);});}},
 click:{level:0.5,make:()=>{const r=rng(7),hp=biquad('high');return make(0.08,t=>0.7*hp(r(),2000,0.7)*Math.exp(-t*600)+0.6*Math.sin(2*Math.PI*2600*t)*Math.exp(-t*180)*Math.min(1,t/0.0008));}},
 tick:{level:0.35,make:()=>make(0.06,t=>Math.sin(2*Math.PI*4100*t)*Math.exp(-t*260)*Math.min(1,t/0.0005)+0.3*Math.sin(2*Math.PI*8200*t)*Math.exp(-t*500))},
 thud:{level:0.7,make:()=>{let ph=0;const r=rng(8),lp=biquad('low');return make(0.5,t=>{ph+=2*Math.PI*sweep([[0,130],[0.12,58],[0.5,45]],t)/SR;return Math.sin(ph)*Math.exp(-t*9)*Math.min(1,t/0.003)+0.7*lp(r(),900,0.7)*Math.exp(-t*60);});}},
 blip:{level:0.45,make:()=>{let ph=0;return make(0.18,t=>{ph+=2*Math.PI*(t<0.05?1180:1560)/SR;return (Math.sin(ph)+0.18*Math.sin(3*ph))*Math.exp(-(t<0.05?t:t-0.05)*(t<0.05?30:26))*Math.min(1,t/0.002);});}},
 chime:{level:0.45,make:()=>{
  // Two bell notes (C6 then G6) with inharmonic partials, each partial decaying at its own rate.
  const bell=(f,t)=>t<0?0:[[1,1,3.2],[2.76,0.35,6],[5.4,0.12,11],[2,0.2,4.5]].reduce((s,[k,a,d])=>s+a*Math.sin(2*Math.PI*f*k*t)*Math.exp(-t*d),0)*Math.min(1,t/0.003);
  return make(1.5,t=>0.6*bell(1046.5,t)+0.55*bell(1568,t-0.09));
 }},
 page:{level:0.45,make:()=>{
  // A page turning: paper air sweeping up as it lifts and over, a dry crackle in the paper, a soft slap as it lands.
  const r=rng(11),c=rng(12),bp=biquad('band'),hp=biquad('high'),lp=biquad('low');
  return make(0.6,t=>{
   const air=bp(r(),sweep([[0,600],[0.28,2800],[0.6,1200]],t),0.8)*swell(t,0.28,1.5,9);
   const k=c(),crackle=Math.abs(k)>0.93?k:0,tex=hp(crackle,3000,0.7)*swell(t,0.24,1.2,7)*0.9;
   const slap=t<0.42?0:lp(r(),700,0.7)*Math.exp(-(t-0.42)*45)*1.4;
   return air+tex+slap;
  });
 }},
 keys:{level:0.35,align:'start',make:()=>{
  // A short typing run: irregular keystrokes, the last one heavier (a space or return).
  const r=rng(9),lp=biquad('band'),hits=[];let at=0.005;while(at<1.1){hits.push([at,0.55+0.35*Math.abs(r())]);at+=0.065+0.06*Math.abs(r());}
  hits.push([at,1]);const k=keystroke(rng(10),lp);
  return make(at+0.06,t=>hits.reduce((s,[h,a])=>s+a*k(t-h),0));
 }},
};

function wav(samples){
 const b=Buffer.alloc(44+samples.length*2);
 b.write('RIFF',0);b.writeUInt32LE(36+samples.length*2,4);b.write('WAVEfmt ',8);b.writeUInt32LE(16,16);b.writeUInt16LE(1,20);b.writeUInt16LE(1,22);
 b.writeUInt32LE(SR,24);b.writeUInt32LE(SR*2,28);b.writeUInt16LE(2,32);b.writeUInt16LE(16,34);b.write('data',36);b.writeUInt32LE(samples.length*2,40);
 samples.forEach((s,i)=>b.writeInt16LE(Math.round(Math.max(-1,Math.min(1,s))*32767),44+i*2));
 return b;
}
/** Where the sound hits: the centre of its loudest 10 ms window, in seconds. */
export function peakOf(samples,sr=SR){
 const w=Math.round(sr*0.01);let best=0,at=0;
 for(let i=0;i+w<=samples.length;i+=Math.round(w/2)){let e=0;for(let j=i;j<i+w;j++)e+=samples[j]*samples[j];if(e>best){best=e;at=i+w/2;}}
 return +(at/sr).toFixed(3);
}

if(import.meta.url===`file://${process.argv[1]}`){
 await mkdir(OUT,{recursive:true});
 const library={};
 for(const [name,s] of Object.entries(SOUNDS)){
  const raw=s.make(),max=raw.reduce((m,v)=>Math.max(m,Math.abs(v)),0)||1;
  // Peak at -3 dBFS, with 3 ms fades so no file starts or ends on a click.
  const fade=Math.round(SR*0.003),out=raw.map((v,i)=>v/max*0.708*Math.min(1,(i+1)/fade,(raw.length-i)/fade));
  await writeFile(new URL('sfx-'+name+'.wav',OUT),wav(out));
  library[name]={file:'sfx-'+name+'.wav',seconds:+(out.length/SR).toFixed(3),peak:peakOf(out),level:s.level,...(s.align?{align:s.align}:{})};
 }
 await writeFile(new URL('sounds.json',OUT),JSON.stringify(library,null,1)+'\n');
 console.log(library);
}
