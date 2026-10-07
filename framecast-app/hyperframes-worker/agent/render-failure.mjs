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

// A render that failed only because the page did not load in time (a busy host), not because of the draft: the
// check's runtime finding is a navigation or load timeout. Worth one more render; a real layout failure is not.
export async function renderTransient(report,root){
 const dir=String(report.directory||'').startsWith('/output/')?path.join(root,'artifacts',report.directory.slice('/output/'.length)):String(report.directory||'');
 const check=await readFile(dir+'/check.log','utf8').catch(()=>'');
 const render=await readFile(dir+'/render.log','utf8').catch(()=>'');
 const timeout=/(Navigation timeout|Timeout \d+ ?ms exceeded|timed out waiting|Protocol error .*timed out)/i;
 if(/^check failed/.test(String(report.error||''))){
  let doc=null;try{doc=JSON.parse(check.slice(check.indexOf('{')));}catch{/* not JSON */}
  const failing=doc?Object.values(doc).filter(v=>v&&typeof v==='object'&&v.ok===false):[];
  // Only the runtime part failed, and only with a timeout.
  return failing.length===1&&(failing[0].findings||[]).length>0&&failing[0].findings.every(f=>f.code==='check_runtime_failure'&&timeout.test(String(f.message||'')));
 }
 return timeout.test(render)&&!/Failure summary/.test(render);
}
