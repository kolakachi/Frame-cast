// Named looks (kit/looks.md): a brief or plan that names a style ("glassmorphism", "neon", "art deco") gets that
// look's recipe pinned for the builder and its fonts fetched. Matching is on whole words, case-insensitive.
import {readFile} from 'node:fs/promises';

export const LOOKS={
 particles:{match:/\bparticles?\b/i,fonts:[]},
 liquid:{match:/\bliquid (morph|blob)|\bgooey\b|\bblob morph/i,fonts:['Fredoka']},
 holographic:{match:/\bholograph|\biridescen/i,fonts:['Syne']},
 neon:{match:/\bneon\b/i,fonts:['Monoton']},
 wireframe:{match:/\bwire-?frame\b/i,fonts:['Space Mono']},
 glass:{match:/\bglass-?morphism\b|\bfrosted glass\b|\bglass cards?\b/i,fonts:[]},
 kinetic:{match:/\bkinetic typ/i,fonts:[]},
 isometric:{match:/\bisometric\b/i,fonts:[]},
 clay:{match:/\bclay(mation| 3d| style)?\b/i,fonts:['Fredoka']},
 ascii:{match:/\bascii\b/i,fonts:['IBM Plex Mono']},
 mesh:{match:/\b(gradient mesh|mesh gradient|aurora gradient)\b/i,fonts:[]},
 comic:{match:/\bcomic(-| )?(book|style|panel)|\bpop art\b/i,fonts:['Bangers']},
 splitflap:{match:/\bsplit-?flap\b|\bdepartures? board\b|\bsolari\b/i,fonts:[]},
 vhs:{match:/\bvhs\b|\bvideo ?tape\b|\b80s (tv|commercial|ad)\b/i,fonts:['VT323']},
 halftone:{match:/\bhalftone\b/i,fonts:[]},
 bauhaus:{match:/\bbauhaus\b/i,fonts:['Jost','Archivo Black']},
 pixel:{match:/\bpixel(-| )?art\b|\b8-?bit\b|\bretro game\b/i,fonts:['Press Start 2P']},
 blueprint:{match:/\bblueprint\b/i,fonts:['Space Mono']},
 deco:{match:/\bart deco\b|\bgatsby\b/i,fonts:['Poiret One','Limelight']},
 brutal:{match:/\b(neo-?)?brutalis[mt]/i,fonts:['Archivo Black','Space Mono']},
};

/** The looks a text names, in the card's order. */
export function looksIn(text){const t=String(text||'');return Object.keys(LOOKS).filter(k=>LOOKS[k].match.test(t));}
/** Google font families the named looks bring (at most three). */
export function lookFonts(ids){return [...new Set(ids.flatMap(k=>LOOKS[k]?.fonts||[]))].slice(0,3);}
/** The card's intro plus the sections for the named looks. */
export async function lookRecipes(file,ids){
 if(!ids.length)return '';
 const text=await readFile(file,'utf8');
 const parts=text.split(/\n(?=## )/),intro=parts[0].trim();
 const picked=parts.slice(1).filter(p=>ids.includes(p.slice(3,p.indexOf('\n')).trim()));
 return picked.length?'\n\n# kit/looks.md (pinned: the looks this video names)\n'+intro+'\n\n'+picked.map(p=>p.trim()).join('\n\n'):'';
}
