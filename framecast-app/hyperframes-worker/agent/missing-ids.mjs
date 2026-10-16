// Fix B (2026-10-16 proof: a neo-brutalism build spent 5 preview rounds on "Cannot read properties of null"): after a
// page or script is written, name the element ids its scripts use that no element has, in the same reply, so the
// builder fixes them before spending a preview. Ids made in code (createElement + .id, id="…" in a template) count.
// Block comments, HTML comments and whole-line // comments only: a "//" inside markup or a string ("<span>// 01</span>")
// is not a comment, and cutting the rest of its line would hide the element defined after it.
const strip=s=>String(s||'').replace(/\/\*[\s\S]*?\*\//g,'').replace(/^[ \t]*\/\/[^\n]*/gm,'').replace(/<!--[\s\S]*?-->/g,'');
export function missingIds(files){
 const defined=new Set(),used=new Map();
 for(const [name,raw] of Object.entries(files)){
  const text=strip(raw);
  for(const m of text.matchAll(/\bid\s*=\s*\\?["'`]([A-Za-z][\w-]*)\\?["'`]/g))defined.add(m[1]);
  for(const m of text.matchAll(/\.id\s*=\s*["'`]([A-Za-z][\w-]*)["'`]/g))defined.add(m[1]);
  for(const m of text.matchAll(/setAttribute\(\s*["']id["']\s*,\s*["'`]([A-Za-z][\w-]*)["'`]/g))defined.add(m[1]);
  for(const m of text.matchAll(/\bid\s*:\s*["'`]([A-Za-z][\w-]*)["'`]/g))defined.add(m[1]);
  if(!/\.(js|mjs)$/.test(name))continue;
  const add=id=>{if(!used.has(id))used.set(id,name);};
  for(const m of text.matchAll(/getElementById\(\s*["'`]([A-Za-z][\w-]*)["'`]\s*\)/g))add(m[1]);
  // A selector string that starts with #id ('#card', "#card .title", '#a,#b').
  for(const m of text.matchAll(/["'`]((?:#[A-Za-z][\w-]*[^"'`]*?))["'`]/g))for(const s of m[1].matchAll(/(?:^|[\s,>+~(])#([A-Za-z][\w-]*)/g))add(s[1]);
 }
 // Colour codes ('#FF6B35', '#fff') and one-letter fragments are not ids; ids built in code from a prefix
 // ('t-'+name, `card-${i}`) are skipped by that prefix.
 const prefixes=[];for(const raw of Object.values(files))for(const m of strip(raw).matchAll(/\bid\s*=\s*\\?["'`]([A-Za-z][\w-]*)(?:\$\{|["'`]\s*\+)/g))prefixes.push(m[1]);
 for(const raw of Object.values(files))for(const m of strip(raw).matchAll(/\.id\s*=\s*["'`]([A-Za-z][\w-]*)(?:\$\{|["'`]\s*\+)/g))prefixes.push(m[1]);
 // An id passed around as a bare string ('cur11' to a helper that writes the element) may be made in code.
 const all=Object.values(files).map(strip).join('\n').replace(/getElementById\(\s*["'`][\w-]*["'`]\s*\)/g,'');
 const bare=id=>new RegExp('["\'`]'+id.replace(/[-]/g,'\\-')+'["\'`]').test(all);
 const notId=id=>id.length<2||/^[0-9a-f]{3,8}$/i.test(id)||prefixes.some(p=>p&&id.startsWith(p))||bare(id);
 return [...used].filter(([id])=>!defined.has(id)&&!notId(id)).map(([id,file])=>({id,file}));
}
export function missingIdWarning(files){
 const miss=missingIds(files);
 if(!miss.length)return null;
 const byFile={};for(const {id,file} of miss)(byFile[file]??=[]).push('#'+id);
 return Object.entries(byFile).map(([f,ids])=>`${f} uses ${ids.slice(0,8).join(', ')}, but no element has ${ids.length>1?'those ids':'that id'}: the preview will fail ("Cannot read properties of null"). Add the element${ids.length>1?'s':''} or fix the selector${ids.length>1?'s':''} before previewing.`).join(' ');
}
