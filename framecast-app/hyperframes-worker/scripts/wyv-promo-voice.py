# Narration for the WyvStudio promo proof, made with macOS speech (free, local): each line fitted to the reference's
# speech slot, word times estimated from syllables, then mixed into one track. Writes timing.json and narration.wav.
import subprocess, json, re, os, sys
out = sys.argv[1]; os.makedirs(out, exist_ok=True)
VOICE = 'Samantha'
# (key, text, start, latest end): the reference speaks in these windows.
LINES = [('psst', 'psst', 0.62, 0.95), ('hook', 'Want a video that sells?', 1.1, 2.2),
  ('cards', 'No camera. No editor. No time?', 2.78, 4.25), ('turns', 'Wiv Studio turns it into a ready-to-post video.', 4.68, 6.95),
  ('w1', 'Script.', 7.5, 7.95), ('w2', 'Voice.', 8.05, 8.6), ('w3', 'Style.', 8.85, 9.4), ('w4', 'Formats.', 9.9, 10.45),
  ('ready', 'Ready.', 10.66, 11.1), ('you', 'You dream it.', 11.58, 12.35), ('we', 'We make the video.', 12.48, 13.6)]
def dur(f): return float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).decode())
def syl(w): return max(1, len(re.findall(r'[aeiouy]+', w.lower())))
timing = {'lines': [], 'words': []}; parts = []
for key, text, start, end in LINES:
    f = f'{out}/{key}.aiff'; rate = 185
    while True:
        subprocess.run(['say', '-v', VOICE, '-r', str(rate), '-o', f, text], check=True)
        # Trim leading and trailing silence so the line starts on its slot.
        subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', f, '-af', 'silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse', '-ar', '44100', '-ac', '1', f'{out}/{key}.wav'], check=True)
        d = dur(f'{out}/{key}.wav')
        if d <= (end - start) * 1.02 or rate > 320: break
        rate += 15
    words = [w for w in re.findall(r"[A-Za-z][A-Za-z'\-]*", text)]
    weights = [syl(w) + 0.35 for w in words]; total = sum(weights); t = start
    for w, k in zip(words, weights):
        span = d * k / total; timing['words'].append({'text': w, 'start': round(t, 3), 'end': round(t + span * 0.88, 3), 'line': key}); t += span
    timing['lines'].append({'key': key, 'text': text, 'start': start, 'end': round(start + d, 3), 'rate': rate}); parts.append((f'{out}/{key}.wav', start))
inputs = sum([['-i', p] for p, _ in parts], [])
filt = ''.join(f'[{i}]adelay={int(s * 1000)}|{int(s * 1000)}{",volume=0.55" if i == 0 else ""}[a{i}];' for i, (_, s) in enumerate(parts)) + ''.join(f'[a{i}]' for i in range(len(parts))) + f'amix=inputs={len(parts)}:normalize=0,apad=whole_dur=15,atrim=0:15[out]'
subprocess.run(['ffmpeg', '-v', 'error', '-y', *inputs, '-filter_complex', filt, '-map', '[out]', '-ar', '44100', '-ac', '2', f'{out}/narration.wav'], check=True)
json.dump(timing, open(f'{out}/timing.json', 'w'), indent=1)
for l in timing['lines']: print(l['key'], l['start'], '->', l['end'], 'rate', l['rate'])
