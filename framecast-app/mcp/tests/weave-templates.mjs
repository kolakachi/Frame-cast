// The connector's template kinds and follow options must be the API's (TemplateLibrary::CATEGORIES and MATCHES), so an
// assistant can never send one the API refuses. Run: node mcp/tests/weave-templates.mjs
import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const here=new URL('.',import.meta.url).pathname;
const src=readFileSync(here+'../server.js','utf8');
const php=readFileSync(here+'../../api/app/Services/Create/TemplateLibrary.php','utf8');
const enumAfter=(name,field)=>JSON.parse(src.slice(src.indexOf(`'${name}'`)).match(new RegExp(field+": z\\.enum\\((\\[[^\\]]+\\])"))[1].replace(/'/g,'"'));
const categories=[...php.match(/CATEGORIES = \[([^\]]+)\]/)[1].matchAll(/'([a-z]+)' =>/g)].map(m=>m[1]);
const matches=[...php.match(/MATCHES = \[([^\]]+)\]/)[1].matchAll(/'([a-z]+)'/g)].map(m=>m[1]);
assert.deepEqual(enumAfter('weave_templates','category'),categories);
assert.deepEqual(enumAfter('weave_from_template','match'),matches);
assert.deepEqual(enumAfter('weave_start','reference_match'),matches);
console.log('ok: '+categories.length+' kinds, '+matches.length+' follow options');
