// Split a character sheet (figures on a flat neutral background) into transparent poses.
import {createRequire} from 'node:module';import {writeFile} from 'node:fs/promises';
const sharp=createRequire('/opt/worker/node_modules/hyperframes/package.json')('sharp');
const [,,input,outDir,expectArg]=process.argv;const expect=Number(expectArg)||9;
const {data,info}=await sharp(input).removeAlpha().raw().toBuffer({resolveWithObject:true});
const W=info.width,H=info.height,px=i=>[data[i*3],data[i*3+1],data[i*3+2]];
// Background: the median of the border pixels.
const border=[];for(let x=0;x<W;x+=7){border.push(px(x),px((H-1)*W+x));}for(let y=0;y<H;y+=7){border.push(px(y*W),px(y*W+W-1));}
const med=k=>border.map(p=>p[k]).sort((a,b)=>a-b)[border.length>>1];const bg=[med(0),med(1),med(2)];
const isBg=i=>{const [r,g,b]=px(i);return Math.max(r,g,b)-Math.min(r,g,b)<=8&&Math.abs((r+g+b)/3-(bg[0]+bg[1]+bg[2])/3)<=16;};
// Figures: connected non-background areas on a 1/8 grid.
const G=8,gw=Math.ceil(W/G),gh=Math.ceil(H/G),grid=new Uint8Array(gw*gh);
for(let y=0;y<H;y+=2)for(let x=0;x<W;x+=2)if(!isBg(y*W+x))grid[((y/G)|0)*gw+((x/G)|0)]=1;
const seen=new Int32Array(gw*gh).fill(-1),comps=[];
for(let s=0;s<gw*gh;s++){if(!grid[s]||seen[s]>=0)continue;const id=comps.length,q=[s];seen[s]=id;let x0=gw,y0=gh,x1=0,y1=0,n=0;
 while(q.length){const c=q.pop(),cx=c%gw,cy=(c/gw)|0;n++;x0=Math.min(x0,cx);y0=Math.min(y0,cy);x1=Math.max(x1,cx);y1=Math.max(y1,cy);
  for(let dy=-1;dy<=1;dy++)for(let dx=-1;dx<=1;dx++){const nx=cx+dx,ny=cy+dy;if(nx<0||ny<0||nx>=gw||ny>=gh)continue;const k=ny*gw+nx;if(grid[k]&&seen[k]<0){seen[k]=id;q.push(k);}}}
 comps.push({x0:x0*G,y0:y0*G,x1:(x1+1)*G,y1:(y1+1)*G,n});}
const figs=comps.filter(c=>c.n>=(gw*gh)*0.004).sort((a,b)=>b.n-a.n).slice(0,expect);
// Reading order: rows by centre, then left to right.
figs.sort((a,b)=>{const ay=(a.y0+a.y1)/2,by=(b.y0+b.y1)/2;return Math.abs(ay-by)>H/(2*Math.sqrt(expect))?ay-by:(a.x0-b.x0);});
const report={bg,figures:comps.filter(c=>c.n>=(gw*gh)*0.004).length,small:comps.length,poses:[]};
for(const [k,f] of figs.entries()){
 const pad=24,x0=Math.max(0,f.x0-pad),y0=Math.max(0,f.y0-pad),x1=Math.min(W,f.x1+pad),y1=Math.min(H,f.y1+pad),w=x1-x0,h=y1-y0;
 // Transparent where the background is reachable from the crop's edge.
 const alpha=new Uint8Array(w*h).fill(255),q=[];
 const tryPush=(x,y)=>{const a=y*w+x;if(alpha[a]===0)return;if(!isBg((y0+y)*W+(x0+x)))return;alpha[a]=0;q.push(a);};
 for(let x=0;x<w;x++){tryPush(x,0);tryPush(x,h-1);}for(let y=0;y<h;y++){tryPush(0,y);tryPush(w-1,y);}
 while(q.length){const a=q.pop(),x=a%w,y=(a/w)|0;if(x>0)tryPush(x-1,y);if(x<w-1)tryPush(x+1,y);if(y>0)tryPush(x,y-1);if(y<h-1)tryPush(x,y+1);}
 const rgba=Buffer.alloc(w*h*4);for(let y=0;y<h;y++)for(let x=0;x<w;x++){const s=((y0+y)*W+x0+x)*3,d=(y*w+x)*4;rgba[d]=data[s];rgba[d+1]=data[s+1];rgba[d+2]=data[s+2];rgba[d+3]=alpha[y*w+x];}
 const softA=await sharp(Buffer.from(alpha),{raw:{width:w,height:h,channels:1}}).blur(0.7).extractChannel(0).raw().toBuffer({resolveWithObject:false});
 if(softA.length!==w*h)throw Error('soft alpha has '+softA.length/(w*h)+' channels');
 for(let i=0;i<w*h;i++)rgba[i*4+3]=Math.min(rgba[i*4+3],softA[i]);
 const name=outDir+'/pose-'+(k+1)+'.png';await sharp(rgba,{raw:{width:w,height:h,channels:4}}).png().toFile(name);
 // Edge figures touching the crop edge may be cut by the sheet's cell boundary.
 report.poses.push({name:'pose-'+(k+1)+'.png',x:x0,y:y0,w,h,touchesEdge:f.x0<=0||f.y0<=0||f.x1>=W||f.y1>=H});
}
await writeFile(outDir+'/split.json',JSON.stringify(report,null,1));console.log(JSON.stringify(report));
