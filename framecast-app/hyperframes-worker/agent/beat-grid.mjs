// A beat grid from detected music hits: a tempo in a musical range, beat times
// aligned to where the strong hits actually fall, bar starts, and the strongest
// hits as the best moments for cuts and pops.
const round=t=>Math.round(t*100)/100;

export function beatGrid(detected,duration){
 const onsets=(detected||[]).filter(h=>Number.isFinite(h?.time)&&h.time>=0&&h.time<=duration).map(h=>({time:h.time,strength:Number.isFinite(h.strength)?h.strength:0.5}));
 if(onsets.length<4)return null;
 // Tempo from the median gap between hits, folded into 70-180 BPM.
 const gaps=onsets.slice(1).map((h,i)=>h.time-onsets[i].time).filter(g=>g>0.15&&g<2).sort((a,b)=>a-b);
 let bpm=gaps.length?60/gaps[Math.floor(gaps.length/2)]:120;
 while(bpm>180)bpm/=2;while(bpm<70)bpm*=2;
 // Refine: the period within 4% of the guess that lines up the most hit strength.
 const score=(period,phase)=>onsets.reduce((s,h)=>{const d=Math.abs(((h.time-phase)/period)-Math.round((h.time-phase)/period))*period;return s+(d<0.06?h.strength:0);},0);
 let best={score:-1,period:60/bpm,phase:0};
 for(let k=-8;k<=8;k++){
  const period=(60/bpm)*(1+k*0.005);
  for(const h of onsets){const phase=h.time%period,s=score(period,phase);if(s>best.score)best={score:s,period,phase};}
 }
 const beats=[];for(let t=best.phase;t<=duration+1e-6;t+=best.period)beats.push(round(t));
 // Bars of four: the offset whose beats carry the most hit strength.
 const near=t=>onsets.reduce((s,h)=>s+(Math.abs(h.time-t)<0.06?h.strength:0),0);
 let barOffset=0,barBest=-1;
 for(let o=0;o<4;o++){const s=beats.filter((_,i)=>i%4===o).reduce((a,t)=>a+near(t),0);if(s>barBest){barBest=s;barOffset=o;}}
 const hits=[...onsets].sort((a,b)=>b.strength-a.strength).slice(0,10).map(h=>round(h.time)).sort((a,b)=>a-b);
 return {bpm:Math.round(60/best.period*10)/10,beat_seconds:round(best.period),beats:beats.slice(0,80),bars:beats.filter((_,i)=>i%4===barOffset).slice(0,24),strong_hits:hits};
}
