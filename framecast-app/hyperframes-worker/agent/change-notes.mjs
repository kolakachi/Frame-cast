// RD-13 (the Opus Motion Design Harness, 2026-10-15): a change request with several notes is answered note by note.
// The latest message is split into its notes; the finish summary must end with a list holding one item per note
// (what changed, or why it did not). Language-agnostic: the list is counted, never its words.
const words=s=>String(s||'').trim().split(/\s+/).filter(Boolean).length;

/** The separate notes in one change message: its bullet or numbered items, its lines, or else its sentences. */
export function splitNotes(text){
 const t=String(text||'').replace(/\r/g,'').trim();if(!t)return [];
 let parts=t.split(/\n+/).map(l=>l.replace(/^\s*(?:[-*•–]|\d+[.)])\s*/,'').trim()).filter(l=>words(l)>=2);
 if(parts.length<2)parts=t.split(/(?<=[.!?;])\s+(?=\p{Lu}|\d)/u).map(s=>s.trim()).filter(s=>words(s)>=3);
 return parts.length>=2?parts.slice(0,12):[];
}

/** How many list items the summary has (bullets or numbers, on their own lines or inline after a colon). */
export function listItems(summary){
 const s=String(summary||'');
 const lines=s.split(/\n+/).filter(l=>/^\s*(?:[-*•]|\d+[.)])\s+\S/.test(l)).length;
 const inline=(s.match(/(?:^|\s)(?:-|\d+[.)])\s+(?=\S)/g)||[]).length;
 return Math.max(lines,inline);
}

export function unansweredNotes(summary,notes){return Array.isArray(notes)&&notes.length>=2&&listItems(summary)<notes.length?notes:[];}
