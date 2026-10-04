// Fixed local adapter grammar; never forwards arbitrary CLI flags/configuration.
export function remotionArgs(args) {
 if(!Array.isArray(args)||!['render','still'].includes(args[0])||!/^project\/[a-zA-Z0-9_-]+\.js$/.test(args[1]||''))throw Error('Remotion: render|still project/<source>.js <seconds> [frame]');
 if(args.length!==(args[0]==='still'?4:3)||!/^\d+(\.\d{1,3})?$/.test(args[2]||''))throw Error('Invalid Remotion arguments');
 const duration=Number(args[2]),frame=args[0]==='still'?Number(args[3]):null;
 if(duration<.5||duration>30||frame!==null&&(!/^\d+$/.test(args[3])||frame<0||frame>=Math.round(duration*24)))throw Error('Invalid Remotion duration/frame');
 return {operation:args[0],source:args[1].slice(8),duration,frame};
}
export function remotionSettings(settings,duration){
 const dims=({'16:9':[1920,1080],'9:16':[1080,1920],'1:1':[1080,1080],'4:5':[1080,1350]})[settings.aspect_ratio];
 if(!dims||!Number.isFinite(settings.duration_seconds)||settings.duration_seconds<.5||settings.duration_seconds>30||duration>settings.duration_seconds)throw Error('Remotion clip exceeds approved output settings');
 return {width:dims[0],height:dims[1],fps:24,durationInFrames:Math.round(duration*24)};
}
export function allowedRemotionImport(request){
 // three and @remotion/three render 3D characters (wyv-mascot3d.js); build tooling stays out of reach.
 return ['react','react/jsx-runtime','react/jsx-dev-runtime','remotion','three','@remotion/three'].includes(request)||/^\.\/[a-zA-Z0-9_-]+\.js$/.test(request);
}
