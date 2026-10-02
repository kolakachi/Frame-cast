// Pins the doctrine cards: writes agent/guidance/cards/manifest.json with a hash per card.
//   node scripts/pin-cards.mjs
import {pinCards} from '../agent/cards.mjs';
import path from 'node:path';import {fileURLToPath} from 'node:url';
const dir=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../agent/guidance');
const files=await pinCards(dir);
console.log('pinned',Object.keys(files).length,'cards:',Object.keys(files).join(', '));
