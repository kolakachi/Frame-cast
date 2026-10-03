import {parse} from '@babel/parser';
import {allowedRemotionImport} from './remotion-contract.mjs';
export function validateRemotionSource(text){
 const localImports=new Set();
 const ast=parse(text,{sourceType:'unambiguous',plugins:['jsx']});
 const check=node=>{if(!node||node.type!=='StringLiteral'||!allowedRemotionImport(node.value))throw Error('Unsupported Remotion import: '+(node?.value??'dynamic expression'));if(node.value.startsWith('./'))localImports.add(node.value.slice(2));};
 const visit=node=>{
  if(!node||typeof node!=='object')return;
  if(['ImportDeclaration','ExportNamedDeclaration','ExportAllDeclaration'].includes(node.type)&&node.source)check(node.source);
  if(node.type==='ImportExpression')check(node.source);
  if(node.type==='CallExpression'&&(node.callee?.type==='Import'||node.callee?.name==='require'))check(node.arguments[0]);
  for(const [key,value] of Object.entries(node))if(key!=='loc'&&value&&typeof value==='object')Array.isArray(value)?value.forEach(visit):visit(value);
 };
 visit(ast);return [...localImports];
}
