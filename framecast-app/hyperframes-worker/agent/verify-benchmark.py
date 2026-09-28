"""Offline rendered-media evidence; requires ffmpeg/ffprobe on PATH. No provider calls."""
import array, hashlib, json, math, subprocess
from pathlib import Path
root=Path(__file__).resolve().parent.parent

def pcm(path):
    data=subprocess.check_output(['ffmpeg','-v','error','-i',str(path),'-vn','-ac','1','-ar','16000','-f','s16le','-'])
    result=array.array('h');result.frombytes(data);return result

rows=[]
for folder in sorted((root/'artifacts/live').glob('bench-*')):
    source=folder/'project/source.wav'
    for video in sorted(folder.glob('render/*/video.mp4')):
        probe=json.loads(subprocess.check_output(['ffprobe','-v','error','-show_streams','-show_format','-of','json',str(video)]))
        stream=next(s for s in probe['streams'] if s['codec_type']=='video')
        row={'case':folder.name,'artifact':str(video.relative_to(root)),'width':stream['width'],'height':stream['height'],'duration':float(probe['format']['duration']),'dimensionsTimingPass':stream['width']==1080 and stream['height']==1920 and abs(float(probe['format']['duration'])-15)<.15}
        if source.exists():
            a,b=pcm(source),pcm(video); n=min(len(a),len(b))
            denom=math.sqrt(sum(x*x for x in a[:n])*sum(x*x for x in b[:n]))
            corr=sum(x*y for x,y in zip(a,b))/denom if denom else 0
            row.update(audioCorrelation=corr,audioPass=len(b)>=len(a) and corr>.98)
        rows.append(row)
report={'renders':rows,'allPass':bool(rows) and all(r['dimensionsTimingPass'] and r.get('audioPass',True) for r in rows),'limitation':'Audio correlation checks timing and signal preservation, not semantic endorsement or human listening. Visual review is separate.'}
(root/'artifacts/benchmark/media-verification.json').write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2))
if not report['allPass']:raise SystemExit(1)
