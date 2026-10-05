import {spawn} from 'node:child_process';
import {mkdir, writeFile, readFile, rename, rm} from 'node:fs/promises';
import path from 'node:path';
import {randomUUID} from 'node:crypto';
import {commandFor} from './commands.mjs';
import {inspectionReport} from '../../agent/inspection-report.mjs';

// Local trusted-fixture adapter. Artifact consumers must require status=ready.
// Never reuse a run directory or replace a previous revision's output.
export async function renderRun({motionBlur=false,fps=24,project, outputRoot, signal, timeoutMs = 120000, expected, onStage = () => {}}) {
  const started = Date.now();
  const id = randomUUID();
  const directory = path.join(outputRoot, id);
  await mkdir(directory, {recursive:true});
  const partial = path.join(directory, 'pending.mp4');
  const artifact = path.join(directory, 'video.mp4');
  const state = {id, status:'running', artifact:null};
  const save = async () => {
    await writeFile(path.join(directory, 'state.tmp'), JSON.stringify(state, null, 2));
    await rename(path.join(directory, 'state.tmp'), path.join(directory, 'state.json'));
  };
  await save();
  let child, timedOut = false;
  const stop = () => { if (child?.pid) { try { process.kill(-child.pid, 'SIGKILL'); } catch (e) { if(e.code !== 'ESRCH') throw e; } } };
  const timer = setTimeout(() => { timedOut = true; stop(); }, timeoutMs);
  signal?.addEventListener('abort', stop);
  const assertActive = () => { if(signal?.aborted || timedOut) throw Error(timedOut ? 'Render deadline exceeded' : 'Render cancelled'); };
  const command = async (executable, args, label, {allowFailure = false} = {}) => {
    assertActive();
    const chunks = []; let length = 0;
    const result = await new Promise((resolve, reject) => {
      // Heavy pages (a 3D bundle, many canvases) can take longer than HyperFrames' 10 s diagnostic page load.
      child = spawn(executable, args, {detached:true, stdio:['ignore','pipe','pipe'], env:{...process.env, PRODUCER_PAGE_NAVIGATION_TIMEOUT_MS:'45000'}});
      onStage(label);
      for (const stream of [child.stdout, child.stderr]) stream.on('data', chunk => { length += chunk.length; if(length <= 2*1024*1024) chunks.push(chunk); });
      child.once('error', reject);
      child.once('close', (code, termination) => resolve({code, termination}));
      if(signal?.aborted || timedOut) stop();
    });
    child = undefined;
    const log = Buffer.concat(chunks).toString();
    await writeFile(path.join(directory, `${label}.log`), log);
    assertActive();
    if(result.code !== 0 && !allowFailure) throw Error(`${label} failed; inspect ${label}.log`);
    return log;
  };
  try {
    const cli = '/opt/worker/node_modules/hyperframes/bin/hyperframes.mjs';
    const check = await commandFor(project, 'check');
    // Layout, contrast and motion findings are notes for the user, as in the build's own check: only what breaks
    // the video (code that renders differently each time, a page that throws) stops the render.
    const checkLog = await command(check.executable, check.args, 'check', {allowFailure: true});
    const report = inspectionReport(checkLog.slice(checkLog.indexOf('{')));
    if(!report.ok) throw Error('check failed; inspect check.log');
    // Motion blur: render sub-frames per output frame (a 180-degree shutter: four at 24 or 30 fps, two at 60) and blend them.
    const k = motionBlur ? (fps >= 60 ? 2 : 4) : 1, sub = partial + '.sub.mp4';
    await command(process.execPath, [cli,'render',project,'--output',k > 1 ? sub : partial,'--fps',String(fps*k),'--workers','1','--quality','draft','--strict','--no-best-effort'], 'render');
    if (k > 1) {
      await command('ffmpeg', ['-v','error','-y','-i',sub,'-vf',`tmix=frames=${k}:weights=${Array(k).fill(1).join(' ')},select=not(mod(n\\,${k})),setpts=N/(${fps}*TB)`,'-r',String(fps),'-c:v','libx264','-preset','medium','-crf','18','-pix_fmt','yuv420p','-c:a','copy','-movflags','+faststart',partial], 'blur');
      await rm(sub, {force:true});
    }
    const probe = JSON.parse(await command('ffprobe',['-v','error','-show_streams','-show_format','-of','json',partial], 'probe'));
    const video = probe.streams.find(s => s.codec_type === 'video');
    if(!video || video.r_frame_rate !== fps+'/1' || video.width !== expected.width || video.height !== expected.height || Math.abs(Number(probe.format.duration)-expected.duration) > .15 || (expected.audio && !probe.streams.some(s => s.codec_type === 'audio'))) throw Error('Rendered media does not match the approved dimensions, duration or audio requirement');
    await command('ffmpeg',['-v','error','-xerror','-i',partial,'-f','null','-'], 'decode');
    assertActive();
    await rename(partial, artifact);
    assertActive();
    state.status = 'ready'; state.artifact = 'video.mp4';
    state.elapsedMs = Date.now() - started;
    state.memoryPeakBytes = Number(await readFile('/sys/fs/cgroup/memory.peak','utf8'));
    await save();
  } catch(error) {
    stop();
    await rm(partial, {force:true}); await rm(artifact, {force:true});
    state.status = signal?.aborted ? 'cancelled' : 'failed'; state.artifact = null; state.error = error.message;
    await save();
  } finally {
    clearTimeout(timer); signal?.removeEventListener('abort', stop);
  }
  return {...state, directory};
}
