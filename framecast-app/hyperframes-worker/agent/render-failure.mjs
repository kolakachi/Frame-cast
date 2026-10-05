import {readFile} from 'node:fs/promises';import path from 'node:path';
// The real reason a render failed, for the trace and the failure ledger: the page check, or what the renderer said.
export async function renderFailure(report,root){
 if(/^check failed/.test(String(report.error||'')))return 'The layout did not pass render checks. Correct the saved draft before rendering again.';
 // The sandbox's /output is this host's artifacts folder.
 const dir=String(report.directory||'').startsWith('/output/')?path.join(root,'artifacts',report.directory.slice('/output/'.length)):String(report.directory||'');
 const log=await readFile(dir+'/render.log','utf8').catch(()=>'');
 const said=(log.match(/Failure summary (\{.*\})/)||[])[1];let why='';try{why=JSON.parse(said).error;}catch{/* no summary */}
 return 'The render failed: '+String(why||report.error||'unknown').slice(0,200);
}
