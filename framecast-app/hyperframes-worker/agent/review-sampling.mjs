// A bounded overview must span the requested duration rather than truncate it.
export function reviewSampling(duration) {
 if(!Number.isFinite(duration)||duration<5||duration>30)throw Error('Invalid review duration');
 const count=Math.min(30,Math.ceil(duration*2)),interval=duration/count;
 const times=Array.from({length:count},(_,i)=>Number(((i+.5)*interval).toFixed(3)));
 return {duration_seconds:duration,times,every_seconds:interval,method:'uniform_interval_midpoints',
  limitation:'Sampled overview only; brief gestures, transitions and audio require separate inspection.'};
}
