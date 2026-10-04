import {requirementChecks,HEARD} from './requirement-review.mjs';
// The critic: a separate reviewer with no authoring context. It sees the brief,
// the beat sheet, our frames (and the reference row under them when there is
// one) and, for a motion build, a strip of frames across the whole video, and
// scores what the checks cannot: hook, hierarchy, density, energy, performance.
// It returns at most three directives the author must address before finishing.
export const CRITERIA=['hook','hierarchy','density','energy','performance'];
export const PASS_MIN=7,PASS_MEAN=8;

export const criticSystem=`You review a video or image against the user's intended outcome, not a universal motion-graphics template. Infer format from the complete brief and approved creative_intent. Educational text/UI, natural talking heads, footage edits, slideshows, still images, animation and mixed work are valid. The subject of a video is not its format: a tutorial about UGC does not require a presenter or generated footage.
You receive sampled frames at listed times. A strip is a bounded timeline overview, not every frame or proof of audio quality; use its actual coverage metadata, never assume a fixed interval. Do not declare an unobserved ending missing. Request closer inspection where evidence is insufficient.
Score five things from 1 to 10, interpreting each for the requested format:
- hook: does the opening clearly establish the intended idea, question, speaker or offer? A calm opening can be excellent; it need not move in two seconds.
- hierarchy: is the intended content readable, composed clearly and framed appropriately? Centered text or a stable speaker can be correct.
- density: is the requested information present without clutter or unintended empty placeholders? Minimal slides, negative space and held screenshots are valid, not defects by themselves.
- energy: does pacing serve the brief and timing driver? Educational reads must follow the explanation; interviews may hold, slideshows may use simple fades, animated spots may cut quickly. Do not demand continuous movement or penalize intentional stillness.
- performance: does the content do what was requested? Character articulation or speech is required only when promised. For text/UI or footage work judge communication, sequence and source fidelity; do not invent an avatar, gesture, product action or AI-video requirement.
Character performance requirements are listed separately with IDs and times. Report performance_checks for EVERY active ID: status pass, fail, unverified, or deferred, with timestamped evidence. Defer movement/speech only in LOOK stage. Position/scale/rotation of a flat image does not demonstrate blinking, mouth movement or body articulation. A bought clip is not proof it appears in the final composition. Do not infer lip-sync or heard speech from silent frame samples; mark unverified if evidence is insufficient.
Check every frozen requirement individually: return requirement_checks with its exact id and version, status fulfilled/unmet/unverified/deferred, concise evidence, and observed start/end seconds for actions or ordering. Only production-stage requirements may be deferred during LOOK. A missing or ambiguous result is unverified. Follow after_ids in addition to checking each action. The critic receives no audio here: requirements about speech, narration, sync, music or sound are checked separately by listening to the finished video, so mark them unverified and never list them in unmet_requirements. Requirements superseded or removed in requirement_history are not active; follow the approved active list. Inferred direction_notes are suggestions, not mandatory requirements. Check every explicit requirement against the visible result. A photoreal character with an overlay does not satisfy halftone illustration or pixel art. Return unmet_requirements as concrete strings; an unmet or unverified requirement prevents pass regardless of scores. In LOOK stage assess appearance now and defer only speech and motion. If a source asset is wrong, report it as needing asset replacement; never hide the mismatch to avoid a purchase. Be exact and unsentimental. A frame that is merely valid scores 5. Scores of 8 and above mean a professional would ship it.
Then give at most three directives, each one concrete change the author can make in one patch: name the beat or time, the element, and the change (for example "4.5 s: the giant WyvStudio word is at 40% opacity behind the window; bring it to full ink, three times the frame height, and let it wipe left across the cut"). Directives must not repeat the checks (contrast, safe area, reading time are measured elsewhere) and must not ask for new paid media.
Reply with one JSON object and nothing else: {"scores":{"hook":n,"hierarchy":n,"density":n,"energy":n,"performance":n},"verdict":"pass"|"revise","unmet_requirements":[string],"requirement_checks":[{"id":string,"version":number,"status":"fulfilled"|"unmet"|"unverified"|"deferred","evidence":string,"start":number|null,"end":number|null}],"performance_checks":[{"id":string,"status":"pass"|"fail"|"unverified"|"deferred","evidence":string}],"directives":[string],"note":string (under 30 words, what works)}. verdict is pass only when every score is at least ${PASS_MIN} and the mean is at least ${PASS_MEAN}.`;

const clip=(s,n)=>{s=String(s??'');return s.length>n?s.slice(0,n)+'…':s;};
const img=data=>{const m=/^data:(image\/(?:png|jpeg));base64,(.+)$/.exec(String(data||''));return m?{type:'image',source:{type:'base64',media_type:m[1],data:m[2]}}:null;};
// The one user turn the critic gets.
export function criticMessages({brief,plan,lookOnly=false,route,sheet,strip,stripEvidence,authorScores,findings,fingerprint,round=1}){
 const beats=(plan?.scenes||[]).map(s=>`${s.start}-${s.end}s ${s.label}: ${s.idea||''}${s.uses?.length?' [uses '+s.uses.join(', ')+']':''}`).join('\n');
 const text=[
  `Brief: ${clip(brief,900)}`,
  plan?.creative_intent?`Approved creative intent (planner interpretation of the user brief): ${JSON.stringify(plan.creative_intent)}`:'',
  strip?`Strip coverage: ${stripEvidence?JSON.stringify(stripEvidence):'Unknown timestamps/coverage. Do not assume it spans the full video.'}`:'',
  plan?.reference_observations?.length?`Approved reference interpretation (preserve/replace/uncertain; not verified truth): ${JSON.stringify(plan.reference_observations)}`:'',
  plan?.reference_evidence?.length?`Reference inspection receipts (coverage only, not output proof or heard audio): ${JSON.stringify(plan.reference_evidence)}`:'',
  plan?.requirements?.length?`Explicit requirements: ${JSON.stringify(plan.requirements)}`:'',
  plan?.character_performance?.length?`Character performance requirements: ${JSON.stringify(plan.character_performance)}`:'',
  plan?.omitted_character_performance?.length?`User explicitly left these actions out; this overrides earlier brief/requirement wording for these actions only. Do not penalize their absence: ${JSON.stringify(plan.omitted_character_performance)}`:'',
  plan?.character_style?`Character treatment: ${plan.character_style}`:'',
  plan?.summary?`Plan: ${clip(plan.summary,300)}`:'',
  beats?`Beats:\n${clip(beats,1200)}`:'',
  plan?.signature_move?`Signature move: ${clip(plan.signature_move,160)}`:'',
  (plan?.asks||[]).some(a=>a.file)?`The user uploaded these for specific beats; check each appears where planned: ${JSON.stringify(plan.asks.filter(a=>a.file).map(a=>({what:a.what,beat:a.beat})))}`:'',
  fingerprint?`Reference fingerprint: ${clip(JSON.stringify(fingerprint),500)}`:'',
  route?`Kind of video: ${route}.`:'',
  lookOnly?'This is the LOOK stage: stills only, one per beat. Assess design/readability against intent; do not require motion, implied motion or audio. The strip is absent on purpose.':'',
  authorScores?.length?`The author's own scores: ${authorScores.map(x=>x.time+'s '+x.score).join(', ')}. Findings: ${clip(findings,400)}`:'',
  `Review round ${round}. Image 1: our contact sheet${sheet&&sheet.reference?' (bottom row: the reference at the same moments)':''}.${strip?' Image 2: sampled strip; use the coverage above.':''}`,
 ].filter(Boolean).join('\n\n');
 const content=[{type:'text',text}];
 const a=img(sheet?.image);if(a)content.push(a);
 const b=img(strip);if(b)content.push(b);
 return [{role:'user',content}];
}
// The verdict, validated; a malformed reply is a revise with its text as the only directive.
export function parseCriticVerdict(text,{performance=[],requirements=[],lookOnly=false}={}){
 let raw=null;
 try{const s=String(text||'');const start=s.indexOf('{'),end=s.lastIndexOf('}');raw=JSON.parse(s.slice(start,end+1));}catch{/* handled below */}
 const scores={};
 for(const k of CRITERIA){const v=Number(raw?.scores?.[k]);scores[k]=Number.isInteger(v)&&v>=1&&v<=10?v:null;}
 const complete=CRITERIA.every(k=>scores[k]!==null);
 const directives=(Array.isArray(raw?.directives)?raw.directives:[]).filter(d=>typeof d==='string'&&d.trim()).map(d=>clip(d.trim(),300)).slice(0,3);
 if(!complete)return {ok:false,requirement_checks:requirementChecks([],requirements,{lookOnly}),scores,verdict:'revise',directives:directives.length?directives:['The critic reply was unreadable; address the lowest-scoring frames of your own review.'],note:''};
 // On a storyboard only explicit misses block: the critic's free-text list mixes in sound and export, which stills cannot show.
 // Free-text misses about sound the frames cannot show are left to the listening check.
 const unheardable=x=>HEARD.test(x)&&/unverif|cannot|can't|can not|not (be )?(confirm|verif|heard|audible)|silent|no audio|without audio/i.test(x);
 const unmet=lookOnly?[]:(Array.isArray(raw?.unmet_requirements)?raw.unmet_requirements:[]).filter(x=>typeof x==='string'&&x.trim()&&!unheardable(x)).map(x=>clip(x,300)).slice(0,8);
 const performanceChecks=[];
 for(const requirement of performance){
  const matches=(Array.isArray(raw?.performance_checks)?raw.performance_checks:[]).filter(x=>x&&x.id===requirement.id);
  const item=matches.length===1?matches[0]:null;
  const valid=item&&['pass','fail','unverified','deferred'].includes(item.status)&&typeof item.evidence==='string'&&item.evidence.trim();
  const status=valid?item.status:'unverified';
  performanceChecks.push({id:requirement.id,status,evidence:valid?clip(item.evidence,400):'No unique evidence returned.'});
  if(!(status==='pass'||(lookOnly&&status==='deferred')))unmet.push(clip(`Character action "${requirement.action}": ${status}. ${performanceChecks.at(-1).evidence}`,300));
 }
 const reqChecks=requirementChecks(raw?.requirement_checks,requirements,{lookOnly});
 for(const c of reqChecks)if(lookOnly?c.status==='unmet':!['fulfilled','deferred','by_ear'].includes(c.status))unmet.push(clip(`Requirement "${c.text}": ${c.status}. ${c.evidence}`,300));
 const values=CRITERIA.map(k=>scores[k]),mean=values.reduce((a,b)=>a+b,0)/values.length;
 const verdict=raw?.verdict==='pass' && !unmet.length && values.every(v=>v>=PASS_MIN)&&mean>=PASS_MEAN?'pass':'revise';
 return {ok:true,requirement_checks:reqChecks,performance_checks:performanceChecks,unmet_requirements:unmet,scores,mean:Math.round(mean*10)/10,verdict,directives:[...unmet.map(x=>'Unmet requirement: '+x),...directives].slice(0,8),note:clip(raw?.note,200)};
}
export const criticLine=v=>'Critic: '+CRITERIA.map(k=>k+' '+(v.scores[k]??'-')).join(', ')+(v.mean?` (mean ${v.mean})`:'')+'.';
