import {readFile,writeFile,rename,realpath,lstat,readdir,mkdir,unlink} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
export const digest = value => createHash('sha256').update(value).digest('hex');
export class Workspace {
  constructor(root, assets = [], scratch = null) { this.root = root; this.assets = assets; this.scratch = scratch; }
  async resolve(relative, write = false) {
    // Deliberately flat pilot source bundle. No directories, assets or runtime edits.
    // work/<name> is the run's scratch folder: scripts and data for the run action, never part of the bundle.
    const scratch = this.scratch && relative.match(/^work\/([a-zA-Z0-9_-]+\.(mjs|js|cjs|json|txt|csv|svg))$/);
    if (!scratch && !/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(relative)) throw Error('Source path is not allowed');
    if (scratch && write) await mkdir(this.scratch, {recursive: true});
    const root = await realpath(scratch ? this.scratch : this.root), file = path.join(root, scratch ? scratch[1] : relative);
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
  async sourceFiles() { return (await readdir(this.root)).filter(f=>/^[a-zA-Z0-9_-]+\.(html|css|js)$/.test(f)).sort(); }
  async restoreSources(files) {
    for(const name of Object.keys(files))await this.resolve(name,true);
    for(const name of await this.sourceFiles())if(!(name in files))await unlink(await this.resolve(name));
    for(const [name,text] of Object.entries(files))await this.write(name,text);
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
