// Finishing checks read from the composition's own code (RD-07, from animate, claude-motion-design and the Opus Motion
// Design Harness, 2026-10-15). Warnings for the builder to fix before it finishes; they never block a delivery.
//  - the same technique repeated: a transition or showpiece move used more than twice reads as a template;
//  - constant-speed motion: a position or scale move with linear easing glides like a machine, not a hand.
const strip=s=>String(s||'').replace(/\/\*[\s\S]*?\*\//g,'').replace(/(^|[^:])\/\/[^\n]*/g,'$1');
// Moves the content drives (each number, line, list item or click is its own), never a repeated technique.
const CONTENT=new Set(['count','odometer','gauge','type','words','cursor','press','stack','pop','sticker','cards','edges','fly','writeOn','rise','blurIn','letters']);

export function techniqueFindings(sources,{max=2}={}){
 const code=strip(sources),counts={};
 for(const m of code.matchAll(/\bWM\.([a-zA-Z]+)\s*\(/g)){if(m[1]==='movesUsed'||m[1]==='cueTimes'||CONTENT.has(m[1]))continue;counts[m[1]]=(counts[m[1]]||0)+1;}
 return Object.entries(counts).filter(([,n])=>n>max).sort((a,b)=>b[1]-a[1]).slice(0,3).map(([move,n])=>({code:'repeated_move',severity:'warning',time:null,
  message:`WM.${move} is used ${n} times; the same technique over and over reads as a template.`,
  fixHint:`Keep it for the moments it suits best (at most ${max}) and give the others a different move from the purpose guide (a cut, a push, a hidden cut, a match on shape).`}));
}

export function constantSpeedFindings(sources,{minSeconds=0.6}={}){
 const code=strip(sources),out=[];
 for(const m of code.matchAll(/\{[^{}]*\bease\s*:\s*['"](none|linear|power0(?:\.\w+)?)['"][^{}]*\}/g)){
  const o=m[0],dur=Number((o.match(/\bduration\s*:\s*([\d.]+)/)||[])[1]);
  // Spins, drifts, scrolls, loops and counters run at constant speed on purpose.
  if(!/\b(x|y|xPercent|yPercent|scale|scaleX|scaleY|left|top)\s*:/.test(o)||/\b(rotation|rotate|repeat|backgroundPosition|innerText|snap|motionPath)\s*:/.test(o))continue;
  if(!(dur>=minSeconds))continue;
  out.push({code:'constant_speed',severity:'warning',time:null,message:`A ${dur} s move with ease "${m[1]}" glides at one constant speed: ${o.replace(/\s+/g,' ').slice(0,90)}`,
   fixHint:'Ease it by its material (a card or UI eases out and settles; a camera move eases in and out); keep linear only for spins, drifts and scrolls.'});
  if(out.length>=3)break;
 }
 return out;
}

export function finishFindings(sources){return [...techniqueFindings(sources),...constantSpeedFindings(sources)];}
