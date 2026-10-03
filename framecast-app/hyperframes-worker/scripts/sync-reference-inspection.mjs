// API builds from ./api, so it needs a dependency-free copy of the same host inspector.
// Run after changing either inspector module; --check is used by tests/CI.
import {readFile,writeFile} from 'node:fs/promises';
const names=['reference-inspection.mjs','reference-sequence.mjs'];
for(const name of names){
 const source=await readFile(new URL('../agent/'+name,import.meta.url));
 const destination=new URL('../../api/resources/create-reference-inspection/'+name,import.meta.url);
 if(process.argv.includes('--check')){
  if(!source.equals(await readFile(destination)))throw Error(`API reference inspector out of sync: ${name}`);
 }else await writeFile(destination,source);
}
