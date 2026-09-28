import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
const expected=JSON.parse(await readFile('/opt/worker/runtime/skills-manifest.json','utf8'));
for(const [file,digest] of Object.entries(expected)) {
 const actual=createHash('sha256').update(await readFile('/opt/worker/node_modules/hyperframes/dist/skills/'+file)).digest('hex');
 if(actual!==digest)throw Error('Bundled skill changed: '+file);
}
console.log(`Verified ${Object.keys(expected).length} pinned skill files`);
