import {inspectReference} from './reference-inspection.mjs';
let input='';
for await (const chunk of process.stdin) input+=chunk;
const request=JSON.parse(input);
try {
 const result=await inspectReference({runDir:process.argv[2],request,timeoutMs:30000});
 process.stdout.write(JSON.stringify(result));
} catch {
 process.stderr.write('Reference inspection failed or exceeded its limits.');
 process.exitCode=1;
}
