// Vendor readable upstream guidance from a locally checked-out, reviewed commit.
// Usage: node scripts/vendor-skills.mjs /path/to/checkout <40-char commit> [package-id]
import {readFile,writeFile,mkdir,readdir,copyFile,lstat} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
const [source,commit,packageId='hyperframes']=process.argv.slice(2);
const specialists=JSON.parse(await readFile(new URL('./specialist-skills.json',import.meta.url),'utf8'));
const specialist=specialists[packageId];
if(packageId!=='hyperframes'&&!specialist)throw Error('Unknown skill package');
if(specialist&&commit!==specialist.commit)throw Error('Package pin does not match');
if(!source||!/^[a-f0-9]{40}$/.test(commit||''))throw Error('Source checkout and exact commit required');
if(execFileSync('git',['-C',source,'rev-parse','HEAD'],{encoding:'utf8'}).trim()!==commit)throw Error('Checkout does not match requested commit');
const root=path.resolve(import.meta.dirname,'../agent/guidance/upstream');
const names=specialist?.names??['hyperframes','product-launch-video','general-video','motion-graphics','hyperframes-core','hyperframes-creative','hyperframes-animation','hyperframes-keyframes','hyperframes-registry','hyperframes-cli','media-use'];
const files={};
async function copy(from,relative){
 const stat=await lstat(from);if(stat.isSymbolicLink())throw Error('Symlink in guidance');
 if(stat.isDirectory()){for(const name of (await readdir(from)).sort())await copy(path.join(from,name),relative+'/'+name);return;}
 // Scripts and examples are reference text only, not installed executable tools.
 if(!/\.(md|html|css|js|mjs|cjs|json|ts|tsx|py|sh|txt|yaml)$/.test(relative)&&!relative.endsWith('/LICENSE'))return;
 const bytes=await readFile(from);await mkdir(path.dirname(path.join(root,relative)),{recursive:true});await copyFile(from,path.join(root,relative));
 files[relative]={sha256:createHash('sha256').update(bytes).digest('hex'),bytes:bytes.length};
}
for(const name of names)await copy(path.join(source,'skills',name),packageId+'/'+name);
await copy(path.join(source,'LICENSE'),packageId+'/LICENSE');
if(specialist){
 await copy(path.join(source,'README.md'),packageId+'/README.md');
 try{await lstat(path.join(source,'scripts'));await copy(path.join(source,'scripts'),packageId+'/scripts');}catch(e){if(e.code!=='ENOENT')throw e;}
}
let previous={packages:[],files:{}};
try{previous=JSON.parse(await readFile(path.join(root,'manifest.json'),'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
const entry={id:packageId,repository:specialist?.repository??'https://github.com/heygen-com/hyperframes',commit,license:specialist?.license??'Apache-2.0',runtime:specialist?.runtime??'hyperframes',mode:'reference-only',
 routes:specialist?.routes??['product','motion','ad','mascot'],
 compatibility:specialist?'Creative guidance and source examples are available. The upstream renderer, installers and script dependencies are NOT installed by this package. Use existing host tools for supported techniques; request a missing capability rather than executing upstream commands. Approved colour treatment overrides example palettes; preserve the selected engine and approved plan. Binary assets/fonts are excluded.':'Guidance revision differs from installed runtime 0.8.82. Use only host-advertised tools. No automatic installs, shell commands, subagents, capture or publishing from these files. Scripts/examples are readable references; binary assets are not vendored.',
 workflows:names.map(name=>({name,path:'skills/'+packageId+'/'+name+'/SKILL.md'}))};
const manifest={packages:[...previous.packages.filter(p=>p.id!==packageId),entry].sort((a,b)=>a.id.localeCompare(b.id)),
 files:{...Object.fromEntries(Object.entries(previous.files).filter(([name])=>!name.startsWith(packageId+'/'))),...files}};
await writeFile(path.join(root,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
console.log('Vendored',Object.keys(files).length,'reference files at',commit);
