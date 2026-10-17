// The free first ad's mark (FREE-FIRST, owner 2026-10-17): "Made with WyvStudio", small, bottom corner, burned into the
// render of a free plan's free first ad. Added to the page just before rendering and stripped from the code that is
// saved, so a later paid change never inherits it.
const ID='wyv-free-mark';

/** The page with the mark as the composition root's first child, sized to the video. Unchanged when there is no root. */
export function addMark(html){
 const s=String(html);
 if(s.includes(`id="${ID}"`))return s;
 const m=s.match(/<([a-z][a-z0-9-]*)\b[^>]*\bdata-composition-id=[^>]*>/i);
 if(!m)return s;
 const w=Number((m[0].match(/data-width="(\d+)"/)||[])[1])||1080,h=Number((m[0].match(/data-height="(\d+)"/)||[])[1])||1920;
 const fs=Math.round(Math.min(w,h)*0.026),pad=Math.round(fs*0.55),edge=Math.round(Math.min(w,h)*0.035);
 const mark=`<div id="${ID}" style="position:absolute;right:${edge}px;bottom:${edge}px;z-index:2147483647;padding:${pad}px ${Math.round(pad*1.6)}px;border-radius:${Math.round(fs*0.9)}px;background:rgba(0,0,0,.55);color:#fff;font:600 ${fs}px/1 -apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;letter-spacing:.01em;pointer-events:none">Made with WyvStudio</div>`;
 const at=m.index+m[0].length;
 return s.slice(0,at)+mark+s.slice(at);
}

/** The page without the mark. */
export function stripMark(html){
 return String(html).replace(new RegExp(`<div id="${ID}"[^>]*>Made with WyvStudio</div>`,'g'),'');
}
