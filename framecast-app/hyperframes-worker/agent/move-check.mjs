// The moves a plan takes from the reference (plan.reference_systems[].move and
// reference_decisions[].move) must be built with their motion-kit recipe, so the
// reference's signature motion is not quietly replaced by fades. Move ids match
// api/app/Services/Create/MotionMoves.php; "custom" asks for no recipe.
export const RECIPES={words:'words',write_on:'writeOn',iris:'iris',toss:'toss',pop:'pop',device:'device',through:'through',fly:'fly',
 stamp:'stamp',type:'type',count:'count',cursor:'cursor',press:'press',morph:'morph',whip:'whip',wipe:'wipe',push:'push',giant_wipe:'giantWipe',field:'field'};

/** Each required move with what it reproduces: Map(move -> [labels]). */
export function requiredMoves(plan){
 const out=new Map(),add=(m,label)=>{if(!RECIPES[m])return;if(!out.has(m))out.set(m,[]);if(label&&!out.get(m).includes(label))out.get(m).push(label);};
 for(const s of plan?.reference_systems||[])if(s&&s.decision!=='drop')add(s.move,s.name||s.system);
 for(const d of plan?.reference_decisions||[])if(d&&d.decision!=='drop')add(d.move,d.beat?'the '+d.beat+' beat':d.moment);
 return out;
}

/** Findings for required moves the composition never calls. sources: every composition file except the kit's own. */
export function moveFindings({plan,sources}){
 const code=String(sources||'').replace(/\/\*[\s\S]*?\*\//g,'').replace(/(^|[^:])\/\/[^\n]*/g,'$1');
 const out=[];
 for(const [move,labels] of requiredMoves(plan)){
  const fn=RECIPES[move];
  if(new RegExp('\\bWM\\.'+fn+'\\s*\\(').test(code))continue;
  const what=labels.slice(0,3).join(', ')||'a reference element';
  out.push({code:'reference_move_missing',severity:'error',time:null,
   message:`The plan rebuilds ${what} with the reference's ${move} move, but the composition never calls WM.${fn}.`,
   fixHint:`${/wyv-motion\.js/.test(code)?'':'Load wyv-motion.js after gsap.min.js, then '}build it with WM.${fn}(…) as in kit/motion-kit.md and the worked example kit/reference-moves.html; or finish with a summary saying why this move does not fit.`});
 }
 return out;
}
