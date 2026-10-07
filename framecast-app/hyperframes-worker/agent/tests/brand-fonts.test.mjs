import test from 'node:test';import assert from 'node:assert/strict';
import {mkdtemp,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';
import {namedFamilies,fetchBrandFonts,googleFamilies} from '../brand-fonts.mjs';

const FAMILIES=['DM Sans','Space Mono','Space Grotesk','Inter','Roboto','Roboto Mono','Lato','Open Sans'];
test('the brief\'s own font names are found exactly; bundled faces and ordinary words are not fetched',()=>{
 assert.deepEqual(namedFamilies('Use our real brand: DM Sans for words and Space Mono for small labels.',[],FAMILIES),['Space Mono','DM Sans']);
 assert.deepEqual(namedFamilies('Headlines in Space Grotesk and body in Inter.',[],FAMILIES),[],'bundled already');
 assert.deepEqual(namedFamilies('an interesting, open sans-serif look; lots of lato',[],FAMILIES),[],'lower-case words are not font names');
 assert.deepEqual(namedFamilies('Roboto Mono for code',[],FAMILIES),['Roboto Mono'],'the longer family, not Roboto inside it');
 assert.deepEqual(namedFamilies('',['Lato'],FAMILIES),['Lato'],'the brand kit\'s font');
});
test('a family\'s regular and bold TTFs are downloaded into the project; a failure is skipped',async()=>{
 const css=f=>`@font-face {\n font-family: '${f}';\n font-style: normal;\n font-weight: 400;\n src: url(https://fonts.gstatic.com/s/x/${f.replace(' ','')}-400.ttf) format('truetype');\n}\n@font-face {\n font-family: '${f}';\n font-style: normal;\n font-weight: 700;\n src: url(https://fonts.gstatic.com/s/x/${f.replace(' ','')}-700.ttf) format('truetype');\n}\n`;
 const fetchImpl=async url=>{
  if(url.startsWith('https://fonts.googleapis.com/css2?family=DM+Sans'))return {ok:true,text:async()=>css('DM Sans')};
  if(url.startsWith('https://fonts.googleapis.com/'))return {ok:false,text:async()=>'nope'};
  return {ok:true,arrayBuffer:async()=>Buffer.alloc(4000,url.endsWith('700.ttf')?7:4)};
 };
 const dir=await mkdtemp(tmpdir()+'/fonts-');
 const got=await fetchBrandFonts(['DM Sans','Nope Font'],dir,{fetchImpl});
 assert.deepEqual(got.map(f=>[f.path,f.weight]),[['brand-dm-sans-400.ttf',400],['brand-dm-sans-700.ttf',700]]);
 assert.equal((await readFile(dir+'/brand-dm-sans-700.ttf'))[0],7);
 assert.match(got[0].purpose,/brand font the brief names/);
});
test('the Google Fonts list is read once and cached',async()=>{
 let calls=0;const cacheFile=(await mkdtemp(tmpdir()+'/gf-'))+'/list.json';
 const fetchImpl=async()=>{calls++;return {ok:true,text:async()=>")]}'\n"+JSON.stringify({familyMetadataList:[{family:'DM Sans'},{family:'Lato'}]})};};
 assert.deepEqual(await googleFamilies({fetchImpl,cacheFile}),['DM Sans','Lato']);
 assert.deepEqual(await googleFamilies({fetchImpl,cacheFile}),['DM Sans','Lato']);
 assert.equal(calls,1);
});
