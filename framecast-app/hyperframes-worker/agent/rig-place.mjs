// A prepared character rig is placed in the page by name: <div id="maya" data-rig-src="asset-…svg"></div>
// becomes that inline SVG (keeping the placeholder's id, class and style) whenever the project is staged for a
// check, a review or the render. The saved source stays small and the SVG is never retyped by the agent.
export function placeRigs(html,read){
 return String(html).replace(/<div\b([^>]*?)\bdata-rig-src\s*=\s*["']([a-zA-Z0-9_.-]+\.svg)["']([^>]*)>\s*<\/div>/g,(whole,before,file,after)=>{
  let svg;try{svg=read(file);}catch{return whole;}
  if(typeof svg!=='string'||!/<svg[\s>]/i.test(svg))return whole;
  const attrs=(before+' '+after);
  const pick=n=>attrs.match(new RegExp('\\b'+n+'\\s*=\\s*"([^"]*)"'))?.[1]??attrs.match(new RegExp('\\b'+n+"\\s*=\\s*'([^']*)'"))?.[1];
  const add=[pick('id')&&`id="${pick('id')}"`,pick('class')&&`class="${pick('class')}"`,pick('style')&&`style="${pick('style')}"`,`data-rig-file="${file}"`].filter(Boolean).join(' ');
  // The SVG's own id is replaced by the placeholder's, so scripts address the rig by the id the agent chose.
  return svg.replace(/^\s*(<\?xml[^>]*>\s*)?/,'').replace(/<svg\b([^>]*)>/i,(m,a)=>`<svg ${add} ${a.replace(/\s(id|class|style)\s*=\s*("[^"]*"|'[^']*')/gi,'')}>`);
 });
}
