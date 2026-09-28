import path from 'node:path';
import {realpath} from 'node:fs/promises';
export async function scopedPath(root, relative) {
  if (typeof relative !== 'string' || !relative || path.isAbsolute(relative) || relative.includes('\\') || relative.split('/').includes('..')) throw Error('Path must stay inside the run workspace');
  const base = await realpath(root);
  const result = await realpath(path.join(base, relative));
  if (!result.startsWith(base + path.sep)) throw Error('Path escapes the run workspace');
  return result;
}
