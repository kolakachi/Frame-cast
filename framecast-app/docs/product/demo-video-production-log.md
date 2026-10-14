# Demo video: production log (made by hand, for teaching Create)

The WyvStudio demo video is made outside Create, by the owner and Claude, on purpose: every step we take, and why, is
written here so Create's planner and builder can learn the flow. Newest steps at the bottom. Started 2026-10-14.

## 1. Study references before writing

- **Memogram demo** (2:22, a hired spokesperson, keyword captions): presenter on screen ~49% (opening 20 s, emotional core
  13 s, turn 8 s, close 27 s); the middle 38 s is product only. Order follows the classical speech: call out the audience
  and win goodwill → the problem as specific people and a loss → the turn ("that's where X comes in") → proof by doing
  (real screens) → uses beyond the obvious → recap, social proof, one call to action.
- **Claude Haiku 5.5 launch animation** (20 s, no words): the problem as a character in a setting; the product as a helper
  character; a list of capabilities as a rhythm ("repeat with variation", one stamp per beat, a cut every 0.5 s on the
  music); relief as the payoff; one honest comparative stat as the only text.
- **Checked whether the reference presenter was real** before copying the approach: no looped body footage, natural hands
  and teeth → a real hired presenter. Matters for what we can honestly imitate.

*For Create:* study a reference's anatomy (who is on screen when, how long, what each part does) and its rhetoric,
not just its look; measure it (cut times, presenter time) instead of guessing.

## 2. Write the script, then review it hard

- v1 followed the reference's structure. The owner's review (v2) moved proof forward: the finished result by second 3,
  the app by second 7, one product followed from start to finish, a specific edit with before/after, the download, a
  close tailored to where the video is watched. It also removed claims that are not always true ("every line is heard",
  "only that part changes").
- v3 kept one concrete pain line in the opening (the feeling the demo can't give) and the presenter reveal as the close.

*For Create:* show the outcome first; follow one subject; never promise universally what the product only does
sometimes; keep a single pain line for emotion even in a short demo.

## 3. Cast the presenter

- Picked from the cast by role, not looks alone: composed, direct gaze, brand-host feel (Layla) over a casual café
  customer (Lucia) or a selfie framing (Linh).
- Made one new look for the whole video with image generation (Nano Banana Pro, $0.134 an image, two variants), from her
  cast photo as identity reference, dressed and placed by these principles: solid mid-tone clothes, no fine patterns or
  logos, smart-casual, one warm tone beside the brand colour; a real working space with depth, window light, a lamp, one
  brand-colour object, no screens or text or people behind her; 16:9, waist-up, eye level, hands visible, room for captions.
- Face-checked both against the cast photo (Claude Haiku, a fraction of a cent). The check is lenient; the owner's eye
  decided (variant B: camel blazer, both hands visible).

*For Create:* a presenter look is made once per video and reused by every clip; the clothing/background rules above
belong in the look prompt.

## 4. Pick the voice

- ElevenLabs v3 preset "Sarah" (confident, warm, reassuring), chosen by the owner; brand name written phonetically in the
  TTS text ("Weave Studio"), shown correctly in captions.

## 5. Find the presenter engine (same image, same 8-second line, six tests)

| Test | Result |
|---|---|
| Fabric (image + audio) | Exact words, lively gestures, a little stiff. Second best. ~$1.20 |
| Google Omni (speaks itself) | Most natural look, but its own voice, and it **repeated the end** ("…deal below, included in the deal below") when given 10 s for an 8 s line |
| Omni sized ("about 8 seconds", ends on a held smile) | Fixed the length but **dropped two of three sentences** |
| OmniHuman 1.5 (image + audio) | Exact words, sharpest (1080p), natural; not preferred |
| Wan 2.7 I2V (image + audio) | Exact words, poor look |
| Veo 3.1 Fast | **Refused** the photoreal face (E005) |
| **Omni + Sync Lipsync-2-Pro** (Sarah's read lip-synced onto Omni's motion) | **Chosen** with the sized Omni motion: Omni's natural motion, exact words, one voice. Lip-sync $0.66 per 8 s (~$0.083/s) |

*For Create and UGC:* Omni's speech is unreliable for scripted lines (repeats when the clip is longer than the line,
drops sentences when it's tight). Generate the voice first, then either lip-sync it onto Omni's motion or drive the
face from the audio. Always transcript-check a take for missing and repeated words.

## 6. Build the video around one voice (planned)

- The master narration is generated first, as one continuous read (see 7).
- Presenter clips are made from each presenter beat's own audio and placed muted at that beat's start in the master
  track. Product screens play under the same voice: L-cuts out of the presenter, J-cuts back in. Music under everything,
  lowered while she speaks; captions follow the master track.
- Product screens come from real app captures (Create plan and result from a real run, the editor, UGC chat, the
  sample gallery), with the test account's identity replaced by demo labels and test-run warnings hidden.

*For Create:* the voice track is the backbone of a presenter-led video; pictures are cut to it, not the other way round.

## 7. Record the narration (done)

- ElevenLabs v3 on Replicate lists previous/next-text context but rejects it ("not yet supported with eleven_v3"), so
  beat-by-beat generation can't be kept continuous. Instead: **the whole script in one call** (one read, one session,
  54.8 s, 154 words), then **split at the beat boundaries from Whisper word timings** (~a cent), with a little air at
  each edge. Whisper also confirms the brand name is said right ("Weave Studio").
- The alternative close (YouTube) is a separate short call with the same voice settings.

*For Create:* generate a presenter-led video's narration as one take and cut it by word timings; never stitch
separately generated lines when the model can't carry context.

## 8. Make the presenter clips (done)

- Beat 8: Sarah's beat-8 read lip-synced onto the 10 s Omni motion from test 2 (`cut_off`, so the clip ends with the
  last word; the end card holds the two seconds after). Beat 1: a new Omni clip (asked for ~9 s, same image, a gesture on
  the second sentence), trimmed to the read plus 0.4 s, then lip-synced. About $2.70 for both.
- Once the voice is lip-synced on, Omni's own speech doesn't matter, so its motion clip can run a little long; trim it to
  the read before lip-syncing, because lip-sync is billed by length.
- Omni returned a transient E004 once; a retry a minute later worked.

*For Create:* when a lip-sync pass replaces the mouth, size the motion clip to the read and treat Omni's spoken words as
irrelevant; retry E004 once before failing the shot.

## 9. Check the example before recording it

- Before capturing the run we planned to follow, we read its stored final checks: **blocked** (one "must appear" item
  not seen, and three words still on screen too briefly), while its last message says "the check now passes". The demo
  cannot show that message as proof, and the brief is ~150 words, not "short".

*For Create:* a reply must never say checks pass when the stored result says otherwise; for a demo or showcase, pick
the example by its stored check result, not by its message.
