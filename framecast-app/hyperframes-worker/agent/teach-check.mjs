// Teaching videos (plan.creative_intent.format educational): on-screen text adds to the narration, it never repeats a
// spoken line word for word (the viewer reads and hears the same thing twice). Advisory: a note for the builder.
const words=s=>String(s||'').toLowerCase().normalize('NFKD').replace(/[^\p{L}\p{N}\s]/gu,' ').split(/\s+/).filter(Boolean);
// Longest common subsequence of two word lists, as a share of the on-screen text.
function overlap(text,line){
 const a=words(text),b=words(line);if(!a.length)return 0;
 const d=Array.from({length:a.length+1},()=>new Array(b.length+1).fill(0));
 for(let i=1;i<=a.length;i++)for(let j=1;j<=b.length;j++)d[i][j]=a[i-1]===b[j-1]?d[i-1][j-1]+1:Math.max(d[i-1][j],d[i][j-1]);
 return d[a.length][b.length]/a.length;
}
/** The visible text blocks of a page: text between tags, scripts and styles left out. */
export function textBlocks(html){
 return [...String(html||'').replace(/<(script|style)\b[\s\S]*?<\/\1>/gi,'').matchAll(/>([^<>]+)</g)].map(m=>m[1].replace(/&[a-z]+;/g,' ').trim()).filter(t=>words(t).length>=6);
}
export function repeatFindings({plan,html,settings={}}){
 if(plan?.creative_intent?.format!=='educational'||settings.captions==='provided')return [];
 const lines=(plan.narration||[]).filter(l=>words(l).length>=6),out=[];
 for(const t of textBlocks(html)){
  const line=lines.find(l=>overlap(t,l)>=0.8);
  if(line)out.push({code:'text_repeats_narration',severity:'warning',message:`On-screen "${t.slice(0,80)}" repeats the spoken line "${line.slice(0,80)}".`,
   fixHint:'Show what the line means (a label, a number, the key word) instead of the sentence the narration already says.'});
 }
 return out.slice(0,4);
}
