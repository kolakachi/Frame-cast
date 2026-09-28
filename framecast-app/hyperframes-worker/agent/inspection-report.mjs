// Retain actionable errors and hints; warnings must not bury a blocking defect.
export function inspectionReport(output) {
 let raw;try{raw=JSON.parse(output);}catch{return {ok:false,errors:[{message:String(output).slice(0,3000)}]};}
 const errors=Object.values(raw).flatMap(section=>section?.findings??[]).filter(f=>f.severity==='error').map(({code,message,selector,fixHint})=>({code,message,selector,fixHint})).slice(0,8);
 if(raw.ok!==true&&!errors.length)errors.push({message:'Inspection failed without structured findings. Inspect local command.log; do not assume success.'});
 return {ok:raw.ok===true,errors,note:raw.ok===true?'Validation passed; proceed to sampled visual review.':'Fix these blocking errors, preserving locked media and timings.'};
}
