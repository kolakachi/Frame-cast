// Doctrine cards: short, distilled craft (seams, principles, design, launch,
// demo loop, ad anatomy, animation) pinned into the build agent's guidance by
// route, hash-pinned like the core guidance. The full source skills stay out of
// the context; a card says the rule, not the essay.
import {readFile,writeFile,readdir} from 'node:fs/promises';
import path from 'node:path';
import {digest} from './workspace.mjs';

export const ALWAYS=['seams','principles'];
// Two more cards by the kind of video; the rest stay readable on demand.
export const BY_ROUTE={product:['launch','demo-loop'],ad:['ad-anatomy','design'],mascot:['design','animation'],motion:['design','animation'],editorial:['intent'],footage:['intent'],still:['intent'],general:['intent']};
export const PINNED_BUDGET=16500;

// Approved intent wins; older plans can use their selected pack, otherwise stay neutral.
export function cardRoute({stylePack,plan,brief='',settings={}}={}){
 const intent=plan?.creative_intent;
 if(intent?.format){
  if(intent.motion==='none'||intent.format==='still_image'||intent.format==='slideshow')return 'still';
  if(intent.format==='educational')return 'editorial';
  if(['footage_edit','talking_head'].includes(intent.format))return 'footage';
  if(intent.format==='character_animation')return 'mascot';
  if(intent.format==='motion_graphics'&&intent.motion==='kinetic')return 'motion';
  return 'general';
 }
 const slug=String(stylePack?.slug||stylePack?.name||'').toLowerCase();
 if(/mascot|character|explainer/.test(slug))return 'mascot';
 if(/launch|product|data|saas|demo/.test(slug))return 'product';
 if(/kinetic|editorial|type|logo/.test(slug))return 'motion';
 // Topic words cannot distinguish "a UGC ad" from "a tutorial about UGC ads".
 // Legacy/unclassified briefs get neutral guidance; the agent infers from the full context.
 return settings.output_kind==='image'?'still':'general';
}
export function cardsFor(route){return ['editorial','footage','still','general'].includes(route)?BY_ROUTE[route]:[...ALWAYS,...(BY_ROUTE[route]||BY_ROUTE.general)];}

// Writes cards/manifest.json with a hash per card (run after editing a card).
export async function pinCards(directory){
 const dir=path.join(directory,'cards');
 const files={};
 for(const name of (await readdir(dir)).filter(n=>/^[a-z-]+\.md$/.test(n)).sort())files[name]=digest(await readFile(path.join(dir,name)));
 await writeFile(path.join(dir,'manifest.json'),JSON.stringify({files},null,1)+'\n');
 return files;
}
async function manifest(directory){return JSON.parse(await readFile(path.join(directory,'cards','manifest.json'),'utf8')).files||{};}
export async function readCard(directory,name){
 if(!/^[a-z-]+$/.test(name))throw Error('Card not allowed');
 const files=await manifest(directory);
 if(!files[name+'.md'])throw Error('Card not installed');
 const data=await readFile(path.join(directory,'cards',name+'.md'));
 if(digest(data)!==files[name+'.md'])throw Error('Card hash mismatch');
 return data.toString();
}
export async function availableCards(directory){try{return Object.keys(await manifest(directory)).map(n=>n.replace(/\.md$/,''));}catch{return [];}}
// The pinned text for a route: each card under its heading, within the budget.
export async function loadCards(directory,route){
 const pieces=[];let bytes=0;
 for(const name of cardsFor(route)){
  let text;try{text=await readCard(directory,name);}catch(e){if(/not installed/.test(e.message))continue;throw e;}
  const piece='CARD '+name+'\n'+text.trim();
  if(bytes+Buffer.byteLength(piece)>PINNED_BUDGET)throw Error('Pinned cards exceed the budget');
  pieces.push(piece);bytes+=Buffer.byteLength(piece);
 }
 return {text:pieces.join('\n\n'),names:pieces.map(p=>p.slice(5,p.indexOf('\n')))};
}
