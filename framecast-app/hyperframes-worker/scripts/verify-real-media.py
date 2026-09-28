"""Checks decoded audio and selected frames; not a substitute for listening."""
from array import array
from pathlib import Path
import hashlib, json, math, sys
root = Path(sys.argv[1] if len(sys.argv)>1 else 'artifacts/real-media')
def samples(name):
    a=array('h'); a.frombytes((root/name).read_bytes())
    if sys.byteorder!='little': a.byteswap()
    return a
source=samples('source-audio.pcm'); output=samples('original-audio.pcm'); edited=samples('cta-edit-audio.pcm')
n=min(len(source),len(output)); a=source[:n]; b=output[:n]
correlation=sum(x*y for x,y in zip(a,b))/math.sqrt(sum(x*x for x in a)*sum(y*y for y in b))
frames={str(t): hashlib.sha256((root/f'original-{t}.png').read_bytes()).hexdigest()==hashlib.sha256((root/f'cta-edit-{t}.png').read_bytes()).hexdigest() for t in [1,4]}
report={'source_samples':len(source),'rendered_samples':len(output),'aligned_audio_correlation':correlation,'variant_audio_identical':output==edited,'presenter_frames_identical_between_variants':frames}
(root/'media-verification.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
assert len(output)>=len(source),'Original audio truncated'
assert correlation>.98,'Audio alignment/fidelity needs investigation'
assert output==edited,'CTA edit changed audio'
assert all(frames.values()),'CTA edit changed sampled presenter frames'
