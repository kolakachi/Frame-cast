// The runtime files a composition loads (GSAP, the motion and mascot scripts, the vendored fonts and the registry
// items it wires), staged into the page's folder the same way for the render, its checks and the template audit.
import {copyFile,readFile} from 'node:fs/promises';

export async function stageRuntime(root){
 await copyFile('/opt/worker/node_modules/gsap/dist/gsap.min.js',root+'/gsap.min.js');await copyFile('/opt/worker/runtime/wyv-motion.js',root+'/wyv-motion.js');await copyFile('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',root+'/font.ttf');
 for(const f of ['barty-motion.js','barty-hyperframes.js','wyv-mascot.js','three-wyv.js','wyv-3d.js','wyv-illos.js'])await copyFile('/opt/worker/runtime/'+f,root+'/'+f);
 // Licensed display and text fonts (SIL OFL), vendored in runtime/fonts.
 for(const f of ['inter.ttf','anton.ttf','bebas-neue.ttf','playfair.ttf','space-grotesk.ttf','caveat.ttf'])await copyFile('/opt/worker/runtime/fonts/'+f,root+'/'+f);
 // Registry items the composition wires (data-composition-src) are staged from the vendored registry, with the libraries they load.
 {
  const {loadCatalog,stageRegistryFiles}=await import('./registry.mjs');
  const items=await loadCatalog('/opt/worker/runtime/registry-catalog.json').catch(()=>[]);
  const html=await readFile(root+'/index.html','utf8').catch(()=>'');
  const staged=await stageRegistryFiles({root,registryRoot:'/opt/worker/runtime/registry',html,items,libs:{'lottie_light.min.js':'/opt/worker/node_modules/lottie-web/build/player/lottie_light.min.js','gsap/CustomEase.min.js':'/opt/worker/node_modules/gsap/dist/CustomEase.min.js','gsap/MotionPathPlugin.min.js':'/opt/worker/node_modules/gsap/dist/MotionPathPlugin.min.js'}});
  return staged;
 }
}
