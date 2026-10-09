// Pre-flight on every source write: the deterministic mistakes that used to
// cost a model call and a repair are fixed or named before the check runs.
// Fixes change only what has one right answer; everything else is a warning.
const titleCase=id=>String(id).replace(/[_-]+/g,' ').replace(/\b\w/g,c=>c.toUpperCase()).trim();
const TYPES=new Set(['string','number','color','boolean','enum','font','image']);

export function preflight({path,text,assets=[],audible=[]}){
 const fixed=[],warnings=[];let out=String(text);
 if(path==='index.html'){
  // Composition variables: each entry needs id, label, type (one of the runtime's) and default.
  out=out.replace(/data-composition-variables\s*=\s*'([\s\S]*?)'/,(all,json)=>{
   let list;try{list=JSON.parse(json);}catch{warnings.push('data-composition-variables is not valid JSON; the check will refuse it.');return all;}
   if(!Array.isArray(list))return all;
   let changed=false;
   const next=list.map(v=>{
    if(!v||typeof v!=='object')return v;
    const entry={...v};
    if(typeof entry.label!=='string'||!entry.label.trim()){entry.label=titleCase(entry.id||'value');changed=true;}
    if(!TYPES.has(entry.type)){entry.type=typeof entry.default==='number'?'number':/^#[0-9a-f]{3,8}$/i.test(String(entry.default??''))?'color':'string';changed=true;}
    if(!('default' in entry)){entry.default=entry.type==='number'?0:'';changed=true;}
    return entry;
   });
   if(changed){fixed.push('composition variables: label, type or default filled in');return "data-composition-variables='"+JSON.stringify(next).replace(/'/g,'&#39;')+"'";}
   return all;
  });
  // A timed <video> is muted unless it is a talking take or shot, whose own sound is the voice.
  out=out.replace(/<video\b([^>]*)>/gi,(all,attrs)=>{
   if(!/data-start=/.test(attrs)||/\bmuted\b|data-has-audio=/.test(attrs))return all;
   const src=(attrs.match(/\bsrc\s*=\s*["']([^"']+)["']/)||[])[1]||'';
   const speaks=audible.includes(src);
   fixed.push(speaks?'<video> with the talking take: data-has-audio="true"':'<video> muted (sound comes from audio clips)');
   return '<video'+attrs+(speaks?' data-has-audio="true"':' muted')+'>';
  });
  if(/<script[^>]+src\s*=\s*["']https?:\/\//i.test(out))warnings.push('A script is loaded from a URL; the sandbox has no network. Use the served files (gsap.min.js, wyv-motion.js, lottie_light.min.js).');
  if(/<link[^>]+href\s*=\s*["']https?:\/\//i.test(out)||/@import\s+url\(["']?https?:/i.test(out))warnings.push('A stylesheet or font is loaded from a URL; use the shipped fonts with @font-face.');
  if(!/window\.__timelines\s*(\[|\.)/.test(out)&&!(/<script\b[^>]*src=["'](?:\.\/)?barty-hyperframes\.js["'][^>]*>/.test(out)&&/WyvBroll\.scene\s*\(/.test(out)))warnings.push('index.html has no inline window.__timelines["<id>"] registration; the check reads only inline scripts for it.');
  for(const m of out.matchAll(/\b(?:src|href)\s*=\s*["']([a-zA-Z0-9_.-]+\.(?:png|jpg|jpeg|webp|svg|mp4|webm|mp3|wav|ttf))["']/g)){
   const f=m[1];
   if(!assets.includes(f)&&!/^(gsap\.min\.js|wyv-motion\.js|font\.ttf|inter\.ttf|anton\.ttf|bebas-neue\.ttf|playfair\.ttf|space-grotesk\.ttf|caveat\.ttf)$/.test(f))warnings.push(`"${f}" is referenced but is not a file in this project (call assets to list them).`);
  }
 }
 if(path.endsWith('.js')){
  if(/Math\.random\s*\(|Date\.now\s*\(|new Date\s*\(|performance\.now\s*\(/.test(out))warnings.push('Randomness or wall-clock time in a script makes frames non-deterministic; derive values from indexes and the timeline.');
  if(/\brepeat\s*:\s*-1\b/.test(out))warnings.push('repeat:-1 is an infinite loop; loop with a finite repeat count that covers the duration.');
  if(/setTimeout\s*\(|setInterval\s*\(|requestAnimationFrame\s*\(/.test(out))warnings.push('Timers do not seek; put every move on the paused timeline.');
 }
 if(path.endsWith('.css')){
  if(/\btransition\s*:/.test(out))warnings.push('CSS transitions do not seek; animate with the timeline instead (transition: none is fine).');
  if(/\banimation\s*:/.test(out)&&!/\banimation\s*:\s*none/.test(out))warnings.push('CSS animations do not seek unless HyperFrames drives them; prefer timeline tweens.');
 }
 return {text:out,fixed,warnings:[...new Set(warnings)].slice(0,6)};
}
