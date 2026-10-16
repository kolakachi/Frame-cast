// The connector's style list must name every built-in style (2026-10-16: it still listed the first six after four
// new styles shipped, so connector users could not pick them). Run: node mcp/tests/weave-styles.mjs
import {readFileSync,readdirSync,existsSync} from 'node:fs';
import assert from 'node:assert/strict';
const here=new URL('.',import.meta.url).pathname;
const src=readFileSync(here+'../server.js','utf8');
const listed=JSON.parse(src.match(/const WEAVE_STYLES = (\[[^\]]+\])/)[1].replace(/'/g,'"')).sort();
const dir=here+'../../api/resources/create/styles';
const packs=readdirSync(dir).filter(d=>existsSync(dir+'/'+d+'/style.json')).sort();
assert.deepEqual(listed,packs);
console.log('ok: '+packs.length+' styles');
