// Face patches from an expression sheet split into heads (split-sheet.mjs): each
// expression is aligned to the resting head, and only the eyes and mouth that
// changed are cut out with soft edges, so a talking, blinking face is the resting
// head plus patches switched on the timeline.
// Usage: node face-patches.mjs <dir with pose-1..9.png> <out dir> <names JSON>
// names: {"1":"rest","2":{"mouth":"smile"},"6":{"eyes":"closed"},"7":{"eyes":"wink","mouth":"wink"}, ...}
import {createRequire} from 'node:module';import {writeFile,mkdir,copyFile} from 'node:fs/promises';
const sharp=createRequire('/opt/worker/node_modules/hyperframes/package.json')('sharp');
const [,,dir,out,namesArg]=process.argv;const names=JSON.parse(namesArg);await mkdir(out,{recursive:true});

// Brightness with the halftone dots blurred away, and the alpha, at a given scale.
async function load(file,scale,sigma){
 const img=sharp(file),m=await img.metadata(),w=Math.round(m.width*scale),h=Math.round(m.height*scale);
 const rgba=await sharp(file).resize(w,h).raw().ensureAlpha().toBuffer();
 const lum=Buffer.alloc(w*h),alpha=Buffer.alloc(w*h);
 for(let i=0;i<w*h;i++){alpha[i]=rgba[i*4+3];lum[i]=alpha[i]<128?255:Math.round(.3*rgba[i*4]+.59*rgba[i*4+1]+.11*rgba[i*4+2]);}
 const blurred=sigma?await sharp(lum,{raw:{width:w,height:h,channels:1}}).blur(sigma).extractChannel(0).raw().toBuffer():lum;
 return {w,h,lum:blurred,alpha,fw:m.width,fh:m.height};
}
// Mean squared brightness difference over the face region of the rest head, for a shift and scale of b.
function cost(a,b,dx,dy,s,box){
 let sum=0,n=0;
 for(let y=box.y0;y<box.y1;y+=2)for(let x=box.x0;x<box.x1;x+=2){
  const bx=Math.round((x-dx)/s),by=Math.round((y-dy)/s);if(bx<0||by<0||bx>=b.w||by>=b.h)continue;
  const d=a.lum[y*a.w+x]-b.lum[by*b.w+bx];sum+=d*d;n++;
 }
 return n?sum/n:1e12;
}
function align(a,b,box,range,step,scales){
 let best={c:1e18};
 for(const s of scales)for(let dy=-range;dy<=range;dy+=step)for(let dx=-range;dx<=range;dx+=step){const c=cost(a,b,dx,dy,s,box);if(c<best.c)best={c,dx,dy,s};}
 return best;
}
const restFile=dir+'/pose-1.png',rest8=await load(restFile,1/8,1.5);
// The face region: where the eyes, brows and mouth sit on a head-and-shoulders portrait.
const faceBox=(img)=>({x0:Math.round(img.w*.28),x1:Math.round(img.w*.74),y0:Math.round(img.h*.18),y1:Math.round(img.h*.66)});
const manifest={base:'face-base.png',width:rest8.fw,height:rest8.fh,patches:[]};
await copyFile(restFile,out+'/face-base.png');
const restFull=await sharp(restFile).ensureAlpha().raw().toBuffer();
const rest2=await load(restFile,1/2,4);
const W=rest8.fw,H=rest8.fh;
const lumOf=async (buf,sigma)=>{const l=Buffer.alloc(W*H);for(let i=0;i<W*H;i++)l[i]=buf[i*4+3]<128?255:Math.round(.3*buf[i*4]+.59*buf[i*4+1]+.11*buf[i*4+2]);return sharp(l,{raw:{width:W,height:H,channels:1}}).blur(sigma).extractChannel(0).raw().toBuffer();};
const la=await lumOf(restFull,6);
// Solid black hair stays dark under a heavy blur; dotted shading on the face averages to mid-grey.
const heavy=await (async()=>{const l=Buffer.alloc(W*H);for(let i=0;i<W*H;i++)l[i]=restFull[i*4+3]<128?0:Math.round(.3*restFull[i*4]+.59*restFull[i*4+1]+.11*restFull[i*4+2]);return sharp(l,{raw:{width:W,height:H,channels:1}}).blur(14).extractChannel(0).raw().toBuffer();})();
const fb={x0:Math.round(W*.28),x1:Math.round(W*.74),y0:Math.round(H*.18),y1:Math.round(H*.66)},split=Math.round(H*.445);

// Pass 1: every expression aligned to the rest head (coarse at 1/8, fine at 1/2, scale 96-104%).
const cells=[];
for(const [cell,spec] of Object.entries(names)){
 if(cell==='1')continue;
 const file=dir+'/pose-'+cell+'.png';
 const b8=await load(file,1/8,1.5),c=align(rest8,b8,faceBox(rest8),10,1,[.96,.98,1,1.02,1.04]);
 const b2=await load(file,1/2,4),c2=align(rest2,b2,faceBox(rest2),5,1,[c.s-.01,c.s,c.s+.01]);
 const t={dx:(c.dx*4+c2.dx)*2,dy:(c.dy*4+c2.dy)*2,s:c2.s};
 const bm=await sharp(file).metadata(),sw=Math.round(bm.width*t.s),sh=Math.round(bm.height*t.s);
 const rs=await sharp(file).ensureAlpha().resize(sw,sh).raw().toBuffer(),warped=Buffer.alloc(W*H*4);
 for(let y=0;y<H;y++){const sy=y-t.dy;if(sy<0||sy>=sh)continue;for(let x=0;x<W;x++){const sx=x-t.dx;if(sx<0||sx>=sw)continue;rs.copy(warped,(y*W+x)*4,(sy*sw+sx)*4,(sy*sw+sx)*4+4);}}
 cells.push({cell,spec,t,warped,lb:await lumOf(warped,6)});
}

// Pass 2: where the features are, from how much each spot changes across all expressions together.
// The eyes and the mouth change in many expressions; a shifted outline only in one or two.
const G=8,gw=Math.ceil(W/G),gh=Math.ceil(H/G),mean=new Float32Array(gw*gh),cnt=new Uint16Array(gw*gh);
for(let y=fb.y0;y<fb.y1;y+=2)for(let x=fb.x0;x<fb.x1;x+=2){const i=y*W+x;let d=0;for(const c of cells)d+=Math.abs(la[i]-c.lb[i]);const g=((y/G)|0)*gw+((x/G)|0);mean[g]+=d/cells.length;cnt[g]++;}
for(let g=0;g<gw*gh;g++)if(cnt[g])mean[g]/=cnt[g];
const features=[];
let axis=null;
// Eyes: the pupils are round solid-dark blobs on the resting face, two at the same height (shading never hides them).
{
 const g=4,gw4=Math.ceil(W/g),dark=new Uint8Array(gw4*Math.ceil(H/g));
 for(let y=fb.y0;y<split;y+=g)for(let x=fb.x0;x<fb.x1;x+=g){const i=(y*W+x)*4;if(restFull[i+3]>128&&.3*restFull[i]+.59*restFull[i+1]+.11*restFull[i+2]<70)dark[((y/g)|0)*gw4+((x/g)|0)]=1;}
 const seen=new Uint8Array(dark.length),blobs=[];
 for(let k=0;k<dark.length;k++){if(!dark[k]||seen[k])continue;const q=[k];seen[k]=1;let a=1e9,b=1e9,c=0,d=0,n=0;
  while(q.length){const v=q.pop(),x=v%gw4,y=(v/gw4)|0;n++;a=Math.min(a,x);b=Math.min(b,y);c=Math.max(c,x);d=Math.max(d,y);
   for(const w2 of [v-1,v+1,v-gw4,v+gw4])if(w2>=0&&w2<dark.length&&dark[w2]&&!seen[w2]){seen[w2]=1;q.push(w2);}}
  const bw=(c-a+1)*g,bh=(d-b+1)*g;
  if(process.env.TRACE&&n>=8)console.error('blob',JSON.stringify({cx:(a+c+1)/2*g,cy:(b+d+1)/2*g,n,bw,bh}));
  if(n>=12&&n<=1200&&bw/bh<2.2&&bh/bw<2.2)blobs.push({cx:(a+c+1)/2*g,cy:(b+d+1)/2*g,n,bw,bh});}
 // One eye is always clear (the other may sit in shading): take the clearest round blob on the face at eye height,
 // find the head's centre line on that row, and mirror the eye across it.
 const eye=blobs.filter(b=>b.n>=60&&b.cy>H*.28&&b.cy<H*.42&&heavy[Math.round(b.cy)*W+Math.round(b.cx)]>40).sort((p1,p2)=>p2.n-p1.n)[0];
 if(eye){
  // The head's silhouette (hair included) is near-symmetric and its cut-out edges are exact, unlike shaded skin.
  const row=Math.round(eye.cy)*W;let l=0,r=W-1;while(l<W/2&&restFull[(row+l)*4+3]<128)l++;while(r>W/2&&restFull[(row+r)*4+3]<128)r--;
  axis=(l+r)/2;const fw=(r-l)*.6,other=2*axis-eye.cx;
  for(const cx of [eye.cx,other])features.push({band:'eyes',n:eye.n,cx,cy:eye.cy-H*.025,rx:fw*.21,ry:H*.075});
 }
}
for(const [band,[y0,y1],take] of [['mouth',[split,fb.y1],1]]){
 let peak=0;for(let gy=(y0/G)|0;gy<(y1/G)|0;gy++)for(let gx=(fb.x0/G)|0;gx<(fb.x1/G)|0;gx++)peak=Math.max(peak,mean[gy*gw+gx]);
 const on=g=>{const gy=(g/gw)|0,gx=g%gw;return gy>=((y0/G)|0)&&gy<((y1/G)|0)&&gx>=((fb.x0/G)|0)&&gx<((fb.x1/G)|0)&&mean[g]>peak*.4;};
 const seen=new Uint8Array(gw*gh),comps=[];
 for(let k=0;k<gw*gh;k++){if(!on(k)||seen[k])continue;const q=[k];seen[k]=1;let a=gw,b=gh,c=0,d=0,n=0;
  while(q.length){const v=q.pop(),x=v%gw,y=(v/gw)|0;n++;a=Math.min(a,x);b=Math.min(b,y);c=Math.max(c,x);d=Math.max(d,y);
   for(let dy=-1;dy<=1;dy++)for(let dx=-1;dx<=1;dx++){const w2=(y+dy)*gw+x+dx;if(x+dx<0||x+dx>=gw||y+dy<0||y+dy>=gh)continue;if(on(w2)&&!seen[w2]){seen[w2]=1;q.push(w2);}}}
  comps.push({band,n,cx:axis??(a+c+1)/2*G,cy:(b+d+1)/2*G,rx:W*.115,ry:H*.085});}
 features.push(...comps.sort((p1,p2)=>p2.n-p1.n).slice(0,take));
}
manifest.features=features.map(f=>({band:f.band,cx:Math.round(f.cx),cy:Math.round(f.cy),rx:Math.round(f.rx),ry:Math.round(f.ry)}));
console.log(JSON.stringify({features:manifest.features}));

// The resting face: its skin plus whatever the skin encloses, so hair and the outline always come from the base.
// Solid hair blurs to near black; even heavy dotted shading stays well above it.
const skin=new Uint8Array(W*H);for(let i=0;i<W*H;i++)skin[i]=heavy[i]>30?1:0;
const outside=new Uint8Array(W*H),q=[];const seed=i=>{if(!skin[i]&&!outside[i]){outside[i]=1;q.push(i);}};
for(let x=0;x<W;x++){seed(x);seed((H-1)*W+x);}for(let y=0;y<H;y++){seed(y*W);seed(y*W+W-1);}
while(q.length){const i=q.pop(),x=i%W,y=(i/W)|0;if(x>0)seed(i-1);if(x<W-1)seed(i+1);if(y>0)seed(i-W);if(y<H-1)seed(i+W);}
const inside=Buffer.alloc(W*H);for(let i=0;i<W*H;i++)inside[i]=outside[i]?0:255;
const face=await sharp(await sharp(inside,{raw:{width:W,height:H,channels:1}}).blur(10).extractChannel(0).raw().toBuffer(),{raw:{width:W,height:H,channels:1}}).linear(1.6,-0.6*255).blur(4).extractChannel(0).raw().toBuffer();

// Pass 3: each expression's patch is its own pixels inside the soft ovals of the features it changes.
const fo=30;
for(const {cell,spec,t,warped} of cells){
 const report={cell,t};
 for(const [part,label] of Object.entries(typeof spec==='string'?{}:spec)){
  const keep=features.filter(f=>f.band===part);if(!keep.length){report[part]='no feature found';continue;}
  const px0=Math.max(0,Math.floor(Math.min(...keep.map(e=>e.cx-e.rx))-fo)),py0=Math.max(0,Math.floor(Math.min(...keep.map(e=>e.cy-e.ry))-fo));
  const px1=Math.min(W,Math.ceil(Math.max(...keep.map(e=>e.cx+e.rx))+fo)),py1=Math.min(H,Math.ceil(Math.max(...keep.map(e=>e.cy+e.ry))+fo)),pw=px1-px0,ph=py1-py0;
  const oval=(x,y)=>{let best=0;for(const e of keep){const q2=Math.hypot((x-e.cx)/e.rx,(y-e.cy)/e.ry),edge=fo/Math.min(e.rx,e.ry),k=Math.max(0,Math.min(1,1-(q2-1)/edge));best=Math.max(best,k*k*(3-2*k));}return best;};
  const patch=Buffer.alloc(pw*ph*4);
  for(let y=0;y<ph;y++)for(let x=0;x<pw;x++){const i=(py0+y)*W+px0+x,s=i*4,d=(y*pw+x)*4;
   patch[d]=warped[s];patch[d+1]=warped[s+1];patch[d+2]=warped[s+2];patch[d+3]=Math.round(Math.min(warped[s+3],restFull[s+3])*oval(px0+x,py0+y)*face[i]/255);}
  const name=part+'-'+label+'.png';await sharp(patch,{raw:{width:pw,height:ph,channels:4}}).png().toFile(out+'/'+name);
  manifest.patches.push({name:part+'-'+label,file:name,part,x:px0,y:py0,w:pw,h:ph});report[part]={label,box:[px0,py0,pw,ph]};
 }
 console.log(JSON.stringify(report));
}
await writeFile(out+'/face.json',JSON.stringify(manifest,null,1));
