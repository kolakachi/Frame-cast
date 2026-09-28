import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {digest} from './workspace.mjs';
const selection={compose:['hyperframes/SKILL.md','hyperframes-cli/SKILL.md'],footage:['hyperframes/SKILL.md','hyperframes-cli/SKILL.md','media-use/SKILL.md']};
export async function loadGuidance({skillsRoot,manifestPath,workflow='compose',maxBytes=100000}) {
  if(!selection[workflow])throw Error('Unknown workflow');
  const manifest=JSON.parse(await readFile(manifestPath,'utf8'));
  const pieces=[];
  for(const file of selection[workflow]) {
    const data=await readFile(path.join(skillsRoot,file));
    if(digest(data)!==manifest[file])throw Error('Pinned guidance hash mismatch');
    pieces.push(`REFERENCE ${file}\n${data.toString()}`);
  }
  const text=pieces.join('\n\n');if(Buffer.byteLength(text)>maxBytes)throw Error('Guidance exceeds context budget');
  return text;
}
// Author-created evaluation briefs, not claims that these visual styles have passed review.
export const creativeBenchmarks=[
 {id:'educational',brief:'Create a clean educational edit from the supplied footage. Use restrained callouts grounded only in the transcript.'},
 {id:'energetic',brief:'Create an energetic social short from the same footage. Preserve speech and product identity; emphasize a clear opening.'},
 {id:'restrained',brief:'Create a restrained product presentation. Give product details time to read; avoid unnecessary motion.'},
 {id:'mismatched-product',brief:'The presenter holds a different bottle from the product photo. Do not imply an endorsement or identical products. Ask or separate the sources.'},
];
export const followUpBenchmark='Keep everything else, but simplify the opening.';
export async function loadCoreGuidance(directory) {
  const manifest=JSON.parse(await readFile(path.join(directory,'manifest.json'),'utf8'));
  const data=await readFile(path.join(directory,'hyperframes-core.md'));
  if(digest(data)!==manifest.files['hyperframes-core.md'])throw Error('Core guidance changed');
  return data.toString();
}
export async function readGuidanceReference(directory,relative) {
 if(!/^references\/[a-z-]+\.md$/.test(relative))throw Error('Reference not allowed');
 const manifest=JSON.parse(await readFile(path.join(directory,'manifest.json'),'utf8'));
 if(!manifest.files[relative])throw Error('Reference not installed');
 const data=await readFile(path.join(directory,relative));if(digest(data)!==manifest.files[relative])throw Error('Reference hash mismatch');return data.toString();
}
