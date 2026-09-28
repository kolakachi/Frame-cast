import path from 'node:path';
import {scopedPath} from './paths.mjs';
const cli='/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs';
// Only trusted local jobs call this adapter. No shell or caller-provided CLI flags.
export async function commandFor(project, operation, times=[]) {
  await scopedPath(project,'index.html');
  if(!['check','timeline','snapshot'].includes(operation))throw Error('Unsupported inspection operation');
  if(times.some(t=>!Number.isFinite(t)||t<0||t>30))throw Error('Snapshot time must be between 0 and 30 seconds');
  const args=operation==='timeline' ? ['timeline','--json'] : operation==='check' ? ['check',project,'--json'] : ['snapshot',project,'--at',(times.length?times:[0,1,5]).join(','),'--no-end','--describe','false','--output',path.join(project,'snapshots')];
  return {executable:process.execPath,args:[cli,...args],cwd:project};
}
