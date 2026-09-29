import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {readFile} from 'node:fs/promises';
const id=process.argv[2];if(!/^app-[a-f0-9-]{36}$/.test(id))throw Error('Invalid run');
const root='/output/live/'+id,exec=promisify(execFile);
const {stdout}=await exec('ffprobe',['-v','error','-show_streams','-show_format','-of','json',root+'/animation.mp4'],{timeout:20000});
const data=JSON.parse(stdout),video=data.streams.find(s=>s.codec_type==='video'),settings=JSON.parse(await readFile(root+'/output-settings.json','utf8'));
if(!video||video.width>2048||video.height>2048||Math.abs(Number(data.format.duration)-settings.duration_seconds)>.25)throw Error('Animation output does not match the approved duration');
// Silent b-roll. Discard generated audio; never present model speech as original audio.
await exec('ffmpeg',['-v','error','-i',root+'/animation.mp4','-map','0:v:0','-c:v','copy','-an','-movflags','+faststart',root+'/animation-silent.mp4'],{timeout:60000});
