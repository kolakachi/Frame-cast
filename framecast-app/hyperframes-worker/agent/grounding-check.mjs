// Numbers on screen must come from the user's approved words. Prices,
// percentages, big counts and "10k"-style figures are claims; a design must not
// invent them. Timecodes, ratios, years and small counts are allowed.
const NUMBER=/[$€£]\s?\d[\d,.]*\s?[kKmMbB]?\b|\b\d[\d,.]*\s?%|\b\d{1,3}(?:,\d{3})+(?:\.\d+)?\b|\b\d+(?:\.\d+)?\s?(?:k|K|M|B|x|X)\b|\b\d{3,}\b/g;
const digits=s=>String(s).replace(/[^\d.]/g,'').replace(/\.$/,'');

// Visible words of a composition: text between tags plus declared variable defaults.
export function visibleText(html){
 const h=String(html);
 const vars=[...h.matchAll(/data-composition-variables='([^']*)'/g)].flatMap(m=>{try{return JSON.parse(m[1].replace(/&quot;/g,'"')).map(v=>String(v.default??''))}catch{return []}});
 const body=h.replace(/<script[\s\S]*?<\/script>/gi,' ').replace(/<style[\s\S]*?<\/style>/gi,' ').replace(/<[^>]+>/g,' ').replace(/&[a-z#0-9]+;/gi,' ');
 return [body,...vars].join(' ');
}

export function numberFindings(html,allowed){
 const text=visibleText(html).replace(/\b\d{1,2}:\d{2}(?::\d{2})?\b/g,' ').replace(/\b\d{1,2}:\d{1,2}\b/g,' ');
 const ok=String(allowed||'');const okDigits=new Set((ok.match(NUMBER)||[]).map(digits).concat((ok.match(/\d[\d,.]*/g)||[]).map(digits)));
 const seen=new Set(),errors=[];
 for(const m of text.match(NUMBER)||[]){
  const t=m.trim(),d=digits(t);
  if(!d||seen.has(t))continue;seen.add(t);
  if(/^(19|20)\d{2}$/.test(t))continue; // a year
  if(okDigits.has(d))continue;
  errors.push({code:'unapproved_number',message:`"${t}" is on screen but not in the approved facts, copy or script.`,fixHint:'Use only numbers the user approved; otherwise show a neutral placeholder such as "Your price" or remove it.'});
  if(errors.length>=8)break;
 }
 return errors;
}
