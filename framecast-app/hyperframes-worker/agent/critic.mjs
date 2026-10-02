// The critic: a separate reviewer with no authoring context. It sees the brief,
// the beat sheet, our frames (and the reference row under them when there is
// one) and, for a motion build, a strip of frames across the whole video, and
// scores what the checks cannot: hook, hierarchy, density, energy, performance.
// It returns at most three directives the author must address before finishing.
export const CRITERIA=['hook','hierarchy','density','energy','performance'];
export const PASS_MIN=7,PASS_MEAN=8;

export const criticSystem=`You are the critic on a motion-graphics studio floor. You did not make this video and you owe its author nothing; you owe the viewer a video that stops a thumb and is remembered.
You get the brief, the beat sheet, a contact sheet of our frames at the beat moments (when it has two rows, the bottom row is the reference video the user pointed at, at the same moments: compare scale, framing, density and energy; never ask for its content), and for a motion build a strip of frames every half second across the whole video, left to right, top to bottom, so you can see pacing, how much is moving, and whether the seams carry momentum.
Score five things from 1 to 10:
- hook: do the first two seconds move and make a promise? Would a muted scroller stop?
- hierarchy: one hero per frame at hero scale, support clearly smaller, type with weight contrast; nothing centred-and-floating; nothing tiny or against an edge.
- density: real content at the density of a finished product (real UI, real copy, texture), not placeholders, lorem, empty cards, dark rectangles or a lone headline on a flat field.
- energy: something meaningful mid-flight at every moment; cuts land mid-motion in one direction; no slideshow of fades, no idle wobble, no dead holds except the one before the climax and the final lockup.
- performance: when there is a character or presenter, they act: speak on camera when a talking shot exists, change pose and side between beats, react after the cause; never a still sticker. Without a character, score the product as the performer: does it do something?
Be exact and unsentimental. A frame that is merely valid scores 5. Scores of 8 and above mean a professional would ship it.
Then give at most three directives, each one concrete change the author can make in one patch: name the beat or time, the element, and the change (for example "4.5 s: the giant WyvStudio word is at 40% opacity behind the window; bring it to full ink, three times the frame height, and let it wipe left across the cut"). Directives must not repeat the checks (contrast, safe area, reading time are measured elsewhere) and must not ask for new paid media.
Reply with one JSON object and nothing else: {"scores":{"hook":n,"hierarchy":n,"density":n,"energy":n,"performance":n},"verdict":"pass"|"revise","directives":[string],"note":string (under 30 words, what works)}. verdict is pass only when every score is at least ${PASS_MIN} and the mean is at least ${PASS_MEAN}.`;

const clip=(s,n)=>{s=String(s??'');return s.length>n?s.slice(0,n)+'…':s;};
const img=data=>{const m=/^data:(image\/(?:png|jpeg));base64,(.+)$/.exec(String(data||''));return m?{type:'image',source:{type:'base64',media_type:m[1],data:m[2]}}:null;};
// The one user turn the critic gets.
export function criticMessages({brief,plan,lookOnly=false,route,sheet,strip,authorScores,findings,fingerprint,round=1}){
 const beats=(plan?.scenes||[]).map(s=>`${s.start}-${s.end}s ${s.label}: ${s.idea||''}${s.uses?.length?' [uses '+s.uses.join(', ')+']':''}`).join('\n');
 const text=[
  `Brief: ${clip(brief,900)}`,
  plan?.summary?`Plan: ${clip(plan.summary,300)}`:'',
  beats?`Beats:\n${clip(beats,1200)}`:'',
  plan?.signature_move?`Signature move: ${clip(plan.signature_move,160)}`:'',
  fingerprint?`Reference fingerprint: ${clip(JSON.stringify(fingerprint),500)}`:'',
  route?`Kind of video: ${route}.`:'',
  lookOnly?'This is the LOOK stage: stills only, one per beat, no motion yet. Score hook on the first frame\'s promise and energy on the composition\'s implied motion; the strip is absent on purpose.':'',
  authorScores?.length?`The author's own scores: ${authorScores.map(x=>x.time+'s '+x.score).join(', ')}. Findings: ${clip(findings,400)}`:'',
  `Review round ${round}. Image 1: our contact sheet${sheet&&sheet.reference?' (bottom row: the reference at the same moments)':''}.${strip?' Image 2: the strip across the whole video.':''}`,
 ].filter(Boolean).join('\n\n');
 const content=[{type:'text',text}];
 const a=img(sheet?.image);if(a)content.push(a);
 const b=img(strip);if(b)content.push(b);
 return [{role:'user',content}];
}
// The verdict, validated; a malformed reply is a revise with its text as the only directive.
export function parseCriticVerdict(text){
 let raw=null;
 try{const s=String(text||'');const start=s.indexOf('{'),end=s.lastIndexOf('}');raw=JSON.parse(s.slice(start,end+1));}catch{/* handled below */}
 const scores={};
 for(const k of CRITERIA){const v=Number(raw?.scores?.[k]);scores[k]=Number.isInteger(v)&&v>=1&&v<=10?v:null;}
 const complete=CRITERIA.every(k=>scores[k]!==null);
 const directives=(Array.isArray(raw?.directives)?raw.directives:[]).filter(d=>typeof d==='string'&&d.trim()).map(d=>clip(d.trim(),300)).slice(0,3);
 if(!complete)return {ok:false,scores,verdict:'revise',directives:directives.length?directives:['The critic reply was unreadable; address the lowest-scoring frames of your own review.'],note:''};
 const values=CRITERIA.map(k=>scores[k]),mean=values.reduce((a,b)=>a+b,0)/values.length;
 const verdict=values.every(v=>v>=PASS_MIN)&&mean>=PASS_MEAN?'pass':'revise';
 return {ok:true,scores,mean:Math.round(mean*10)/10,verdict,directives,note:clip(raw?.note,200)};
}
export const criticLine=v=>'Critic: '+CRITERIA.map(k=>k+' '+(v.scores[k]??'-')).join(', ')+(v.mean?` (mean ${v.mean})`:'')+'.';
