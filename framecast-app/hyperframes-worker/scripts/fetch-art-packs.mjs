#!/usr/bin/env node
// Fetches the art packs the builder can use (runtime/art-packs/) and writes their search index.
// Only packs whose license allows commercial use, each kept with its license:
//   lucide   line icons (ISC)        tabler   outline and filled icons (MIT)
//   fluent3d Microsoft Fluent Emoji, 3D (MIT)
// Run on the worker host: node scripts/fetch-art-packs.mjs   (needs curl, tar and git)
import {execFileSync} from 'node:child_process';
import {mkdirSync,rmSync,readdirSync,readFileSync,writeFileSync,copyFileSync,existsSync,statSync} from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const out=path.join(root,'runtime','art-packs');
const tmp=path.join(os.tmpdir(),'art-packs-'+process.pid);
const PACKS={lucide:{npm:'lucide-static',version:'1.52.0',license:'ISC'},tabler:{npm:'@tabler/icons',version:'3.49.0',license:'MIT'},fluent3d:{git:'https://github.com/microsoft/fluentui-emoji.git',license:'MIT'}};
const run=(cmd,args,cwd)=>execFileSync(cmd,args,{cwd,stdio:['ignore','pipe','inherit']});
const words=s=>String(s||'').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
rmSync(tmp,{recursive:true,force:true});mkdirSync(tmp,{recursive:true});
rmSync(out,{recursive:true,force:true});mkdirSync(out,{recursive:true});
const index=[];

function npmPack(name,spec){
  const dir=path.join(tmp,name);mkdirSync(dir,{recursive:true});
  const meta=JSON.parse(run('curl',['-sfL',`https://registry.npmjs.org/${spec.npm}/${spec.version}`]).toString());
  run('curl',['-sfL','-o',path.join(dir,'pack.tgz'),meta.dist.tarball]);
  run('tar',['-xzf','pack.tgz'],dir);
  return path.join(dir,'package');
}

// Lucide: one line style; tags.json lists each icon's search words.
{
  const pkg=npmPack('lucide',PACKS.lucide),tags=JSON.parse(readFileSync(path.join(pkg,'tags.json'),'utf8'));
  mkdirSync(path.join(out,'lucide'),{recursive:true});copyFileSync(path.join(pkg,'LICENSE'),path.join(out,'lucide','LICENSE'));
  for(const f of readdirSync(path.join(pkg,'icons')).filter(f=>f.endsWith('.svg'))){
    const name=f.slice(0,-4);copyFileSync(path.join(pkg,'icons',f),path.join(out,'lucide',f));
    index.push({id:'lucide:'+name,pack:'lucide',style:'line',kind:'svg',file:'lucide/'+f,words:[...new Set([...words(name),...(tags[name]||[]).flatMap(words)])]});
  }
}
// Tabler: outline and filled; icons.json gives tags and a category.
{
  const pkg=npmPack('tabler',PACKS.tabler),meta=JSON.parse(readFileSync(path.join(pkg,'icons.json'),'utf8'));
  copyFileSync(path.join(pkg,'LICENSE'),path.join(out,'tabler-LICENSE'));
  for(const style of ['outline','filled']){
    const dir=path.join(pkg,'icons',style);if(!existsSync(dir))continue;
    mkdirSync(path.join(out,'tabler',style),{recursive:true});
    for(const f of readdirSync(dir).filter(f=>f.endsWith('.svg'))){
      const name=f.slice(0,-4),m=meta[name]||{};copyFileSync(path.join(dir,f),path.join(out,'tabler',style,f));
      index.push({id:`tabler:${style}:${name}`,pack:'tabler',style:style==='filled'?'filled':'line',kind:'svg',file:`tabler/${style}/${f}`,words:[...new Set([...words(name),...(m.tags||[]).flatMap(words),...words(m.category)])]});
    }
  }
}
// Fluent Emoji 3D: only the 3D images and their metadata (the full repository is large).
{
  const dir=path.join(tmp,'fluent');
  run('git',['clone','-q','--depth','1','--filter=blob:none','--sparse',PACKS.fluent3d.git,dir]);
  run('git',['sparse-checkout','set','--no-cone','/LICENSE','/assets/*/metadata.json','/assets/*/3D/*','/assets/*/Default/3D/*'],dir);
  mkdirSync(path.join(out,'fluent3d'),{recursive:true});copyFileSync(path.join(dir,'LICENSE'),path.join(out,'fluent3d','LICENSE'));
  for(const name of readdirSync(path.join(dir,'assets'))){
    const base=path.join(dir,'assets',name),png=[path.join(base,'3D'),path.join(base,'Default','3D')].filter(existsSync).flatMap(d=>readdirSync(d).filter(f=>f.endsWith('.png')).map(f=>path.join(d,f)))[0];
    if(!png||!existsSync(path.join(base,'metadata.json')))continue;
    const m=JSON.parse(readFileSync(path.join(base,'metadata.json'),'utf8')),slug=words(name).join('-');
    copyFileSync(png,path.join(out,'fluent3d',slug+'.png'));
    index.push({id:'fluent3d:'+slug,pack:'fluent3d',style:'3d',kind:'png',file:'fluent3d/'+slug+'.png',glyph:m.glyph||null,words:[...new Set([...words(name),...(m.keywords||[]).flatMap(words),...words(m.group)])]});
  }
}
writeFileSync(path.join(out,'index.json'),JSON.stringify({packs:Object.fromEntries(Object.entries(PACKS).map(([k,v])=>[k,{license:v.license,version:v.version||null}])),items:index}));
rmSync(tmp,{recursive:true,force:true});
const bytes=d=>readdirSync(d,{withFileTypes:true}).reduce((n,e)=>n+(e.isDirectory()?bytes(path.join(d,e.name)):statSync(path.join(d,e.name)).size),0);
console.log(`art packs: ${index.length} items, ${(bytes(out)/1048576).toFixed(0)} MB in ${out}`);
