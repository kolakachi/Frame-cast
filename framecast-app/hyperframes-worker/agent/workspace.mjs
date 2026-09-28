import {readFile,writeFile,rename,realpath,lstat,readdir} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
export const digest = value => createHash('sha256').update(value).digest('hex');
export class Workspace {
  constructor(root, assets = []) { this.root = root; this.assets = assets; }
  async resolve(relative, write = false) {
    // Deliberately flat pilot source bundle. No directories, assets or runtime edits.
    if (!/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(relative)) throw Error('Source path is not allowed');
    const root = await realpath(this.root), file = path.join(root, relative);
    try { if ((await lstat(file)).isSymbolicLink()) throw Error('Symlinks are not allowed'); }
    catch (e) { if (!(write && e.code === 'ENOENT')) throw e; }
    return file;
  }
  async read(relative) { const text = await readFile(await this.resolve(relative), 'utf8'); if(Buffer.byteLength(text)>128_000)throw Error('Source too large'); return text; }
  async write(relative, text) {
    if (Buffer.byteLength(text) > 128_000) throw Error('Source too large');
    const file = await this.resolve(relative, true);
    const temp = `${file}.tmp`;
    // Exclusive temp creation avoids following a pre-existing temp symlink.
    await writeFile(temp, text, {flag:'wx',mode:0o600}); await rename(temp,file);
  }
  async fingerprint() {
    const files=(await readdir(this.root)).filter(f=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(f)).sort();
    return digest(JSON.stringify(await Promise.all(files.map(async f=>[f,digest(await this.read(f))]))));
  }
  async verifyAssets() {
    const root = await realpath(this.root);
    for (const asset of this.assets) {
      if (!/^[a-zA-Z0-9_.-]+$/.test(asset.path) || asset.path === '..') throw Error('Invalid asset path');
      const file = await realpath(path.join(root,asset.path));
      if (!file.startsWith(root+path.sep) || digest(await readFile(file)) !== asset.sha256) throw Error('Protected asset changed or escaped workspace');
    }
  }
}
