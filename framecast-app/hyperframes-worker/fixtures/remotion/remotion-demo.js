import React from 'react';
import {AbsoluteFill,Img,staticFile,useCurrentFrame,useVideoConfig,spring,interpolate} from 'remotion';
export default function Demo(){
 const frame=useCurrentFrame(),{fps}=useVideoConfig();
 const entrance=spring({frame,fps,config:{damping:16}});
 const focus=interpolate(frame,[36,78],[1,1.12],{extrapolateLeft:'clamp',extrapolateRight:'clamp'});
 return <AbsoluteFill style={{background:'#EDF5F2',color:'#173D45',fontFamily:'sans-serif',padding:100,justifyContent:'center'}}>
  <div style={{fontSize:28,letterSpacing:5,marginBottom:35}}>REMOTION · LOCAL INTEGRATION PROOF</div>
  <div style={{display:'flex',alignItems:'center',gap:110,transform:`translateY(${(1-entrance)*90}px)`,opacity:entrance}}>
   <div style={{fontSize:90,fontWeight:700,width:970}}>Your product.<br/>A new perspective.</div>
   <div style={{background:'#D4C7F3',borderRadius:48,width:450,height:550,display:'flex',alignItems:'center',justifyContent:'center',transform:`scale(${focus})`}}><Img src={staticFile('product.svg')} style={{width:300,height:400,objectFit:'contain'}}/></div>
  </div>
 </AbsoluteFill>;
}
