# Create: reference-parity todo

**Goal.** From one brief, a reference video and the brand's website, Create makes a 15-second video of the same standard as the sample. Same kind of craft, never the same content.

**The standard.** The sample is the Pocketsflow explainer shared on X (`x.com/achxvi/status/2103918792845963545`). It is 15 s, 16:9 at 60 fps, and studied as reference asset 1339 in workspace 1.

Started 2026-10-01. Work one slice at a time. Each slice is done only when its **Done when** check passes on a real run in the owner's account (kolakachi@gmail.com, workspace 1) that the owner can inspect.

---

## The bar: what the sample does

| # | Quality | In the sample |
|---|---|---|
| Q1 | A voiceover that carries the story | About 35 words at −14 LUFS: problem, then product, then features, then tagline |
| Q2 | On-screen text timed to the voice | Words appear as they are spoken; one accent word per beat in italic serif |
| Q3 | One consistent narrator character | The same halftone 3D mascot in about 10 poses and expressions |
| Q4 | The character talks and reacts | Mouth moves with the voice; reactions between beats |
| Q5 | Real product UI built on screen | Storefront, checkout, plan, upsell and payout cards filling in as named |
| Q6 | A distinctive texture | Halftone grain on the character; flat colour fields; mono labels and timecodes |
| Q7 | Sound beyond the voice | Music bed and interface clicks |
| Q8 | Pacing | Hard cuts, about 5 s beats, a tagline and a logo lockup to close |

**Parity acceptance test.** Rebuild the sample's idea for WyvStudio, using wyvstudio.com and the X link. Pass when a reviewer who hasn't seen the brief rates Q1 to Q8 as present, and nothing from the original (mascot, name, script, UI content) is copied.

---

## Already in place

- Reference study of video posts, and web pages read with a screenshot and quoted claims.
- Kinetic type, UI cards, brand palette and six licensed fonts, including a serif for accent words.
- Word timings from Whisper, the timing check that text lands on spoken words, and cut suggestions.
- Plan media bought under one approval, with a free edit for text and colour-only asks.
- Delivery checks: safe area, edges, contrast and loudness.

---

## Slice A: voiceover that works and says something (Q1, Q2)

Every video type needs this, so it goes first.

- [x] **Fix the narration download.** The voiceover plan item fails: the TTS result is a managed-storage URL (`minio://…`) that the executor fetches over HTTP. Read managed URLs through `StorageService`. Covered by a test using a `minio://` result.
- [x] **Draft a narration script in the plan.** The planner proposes a voiceover script of 25 to 40 words for 15 s, built only from the brief, approved facts and page claims. Page claims are marked as needing approval.
- [x] **Edit and approve the script in the plan card.** Lines are editable like on-screen copy. Approving the plan approves the script as spoken copy, and nothing unapproved is spoken.
- [x] **Narration reads the approved script.** Not just the on-screen lines.
- [x] **Voice choice in the plan.** A catalogue voice by description, or the workspace's cloned voice.
- [x] **Timings for generated speech.** After narration is bought, the worker transcribes it with Whisper. The transcript tool already works on source files, so text is timed to the generated voice through the existing `data-spoken` check.
- [x] **Accent words.** The agent may set one word per line in an accent style; the fonts are already there.
- **Done when:** a build from a text-only brief has a voiceover of the approved script, and every on-screen line passes the timing check against the narration.
- **Status 2026-10-01: done.** Run `d29f317c` in workspace 1 spoke the approved four-line script, with nine lines tied to the words and passing the timing check (7 calls, $0.53, 137 credits). The voice said the brand as "Vive Studio", so **workspace pronunciations** were added; WyvStudio is now spoken as "Weave Studio", confirmed by transcribing the final mix.
- **Cost:** Gemini TTS is 3 credits a line, and Whisper is about $0.006 a minute.

## Slice B: music and sound effects (Q7)

- [x] **Decide the music source.** Owner: ElevenLabs. Used through the existing Replicate account (`elevenlabs/music`, $0.0083 per second of output); no new key needed. Commercial terms for ElevenLabs output bought through Replicate still need the owner's check.
- [x] **Music in the plan.** A `music` catalogue item generated to the video's length and mood, priced from measured cost, and bought under the one approval.
- [x] **Sound effects.** Built on Stable Audio 2.5 on Replicate ($0.20 a file): one sound sheet of up to 6 one-shot cues, cut into cues by silence detection. ElevenLabs Sound Effects is not on Replicate. The agent places the cues on UI beats.
- [~] **Mixing.** Music sits at a fixed 0.18 volume under the voice (about 15 dB down) and the final mix is levelled to −14 LUFS. Not built yet: true ducking that follows the voice, and a check that fails when music masks speech.
- [ ] **Library music.** Re-enable it in Create only once licensed tracks exist; it is withheld today.
- **Done when:** a build has voice, music and at least three timed sound effects, the delivery check reads −14 LUFS ±1, and speech stays intelligible.
- **Status 2026-10-01: done.** Run `cd2312b0` had voice, a music bed under the whole video, and three real cues (0.36 to 0.48 s) placed four times. Speech transcribed cleanly over the music. Fixes found on the way:
  - The ElevenLabs song ended at 10 s of 16, so the audible part is now looped with a crossfade.
  - The first sound sheet was a string of 0.08 s ticks; the prompt now asks for one-shots, fragments merge, and specks drop.
  - Loudness landed at −15.2 LUFS with a −0.4 dB peak; a gain-correction pass under a limiter now gives −14.2 LUFS with a −1.0 dB peak on that same file.
  - The run cost 8 calls, $0.79, and 289 credits including 87 for audio.
- **Local environment fixes.** The local API now runs several PHP workers (`--no-reload`) and keeps its private files on a volume. Before this, restarts wiped Create files.

## Slice C: consistent character and poses (Q3, Q6)

- [ ] **Character from the Characters library.** A brief can name a saved workspace character, or supply mascot images or clips as source.
- [x] **Pose sheet.** A `character_poses` item makes 4 to 8 poses of one character (neutral, talking, pointing, surprised, waving) from one reference image, with identity kept. Candidates: the existing gpt-image-2 character edits, or Flux Kontext on Replicate. Priced per pose.
- [x] **Transparent cut-outs.** Poses come out with transparent backgrounds so the agent can layer them over colour fields and UI. Add background removal: a media op, or a model such as `rembg`.
- [x] **Halftone look.** Add `halftone` and `grain` looks to the sandbox media tool, as ffmpeg filters on stills and clips, plus a CSS or SVG option the agent can use.
- [x] **Pose continuity rule.** The same character is used across beats and switched between poses on beats, as a narrator does; the agent is told this in its instructions.
- **Done when:** one build shows the same original character in at least 4 poses with the halftone look, and a reviewer reads it as one character.
- **Status 2026-09-30: done.** Run `b42e61ce` (conversation `8be58343`) showed one original film-reel mascot in four poses (talking, pointing, surprised, waving), cut out over the UI with the halftone look throughout. It took 13 calls, $1.35 and about 7.5 minutes; the pose sheet was 210 credits. Nano Banana Pro makes the poses and `851-labs/background-remover` cuts them out.
- **Fixes found on the way:** the background remover is a community model and must be run by version id; a dropped connection mid-sheet is retried per pose; failed plan items now log the real error.
- **Still open:** naming a saved character from the Characters library is built but not yet tested live. The layout barely changes between beats; that is slice F's job.

## Slice D: talking character shots (Q4)

- [ ] **Decide the provider and price.** Owner's decision. Candidates: Hedra Character-3 or OmniHuman (both handle stylised characters), Kling lip sync, Sync Labs lipsync-2. Measure cost per second first; the UGC photoreal path is about 140 credits a second.
- [ ] **A `talking_shot` item.** 2 to 4 s of the character speaking one approved line: pose image plus narration audio in, lip-synced clip out. Limited to a hook or call to action by default to keep cost down.
- [ ] **Timing.** The talking shot's audio is the matching narration segment, so text and voice stay in sync.
- [ ] **Fallback.** If generation fails, use the static talking pose with a subtle idle motion, and say so in the summary.
- [ ] **Option: generate then trace.** A video model (e.g. Seedance) renders the base motion, then the agent redraws the character in code on top, which keeps the look consistent. Heavier than lip sync; compare it when choosing the provider.
- **Done when:** the hook shows the character saying the first line with believable lip sync, and the cost of the shot is under the price set for it.

## Slice E: real product UI (Q5)

- [x] **UI from the page capture.** The agent rebuilds 2 to 4 of the brand's real screens (hero, feature, pricing, dashboard) as HTML cards, guided by the page screenshot and brand notes.
- [x] **Numbers only from approved facts.** Prices, counts and customer figures appear only if approved; otherwise neutral placeholders such as "Your product".
- [x] **Build-on-cue.** Cards fill in as their feature is spoken, checked with `data-spoken` like any timed text.
- [x] **Optional supplied screenshots.** The user may attach real product screenshots as source for exact UI.
- **Done when:** the WyvStudio build shows recognisable WyvStudio UI building in sync with the named features, with no unapproved numbers.
- **Status 2026-10-01: done.** Run `767540ee` (conversation `244ed471`) rebuilt WyvStudio's UI from the page capture: a browser window with Script and Link cards, a Voiced waveform, Captioned chips, a phone preview, and the logo with an orange CTA. Seven elements are tied to the spoken words, and the grounded-numbers check found nothing invented. It took 6 calls, $0.66 and 169 credits, with the call limit raised to 16 (owner).
- **Fixes found on the way:**
  - Replies were cut off at the output limit; compositions are now split into markup, `style.css` and `main.js`, each under about 6,000 characters.
  - Cut-off replies filled the context; they are now summarised in the history.
  - The script ran 16.9 s for a 15 s video; it is now sized at about 2 words a second minus 1.5 s.
  - The timeline registration now stays inline in `index.html`.
  - A dropped connection to Anthropic is retried, then recorded as not sent.
  - A checked draft is delivered at the call limit with its open issues.
  - The mix's true peak overshot after AAC encoding; the limiter is now at −3 dB with a corrective pass, giving −14.3 LUFS and a −2.3 dB peak.
- **Open from the delivery checks:** CTA text contrast is 2.84:1, under 3:1, and one tile runs outside its container at 10.8 s. Both are shown in "Before you post".

## Slice F: the harness (Q6, Q8, and every video type)

The prompt is 10% of a video and the harness is 90%. This slice gets the 90% right: what makes output stand out for motion, UGC and product videos alike. Five levers plus one test, in order.

- [ ] **0. Effort test.** Every viral Opus 5.5 build ran on xhigh or max; ours run on medium. Run one parity build on xhigh with the same brief and compare quality, calls, cost and cut-off replies. If it wins, use xhigh for new builds and medium for fixes.
- [ ] **1. Style packs.** One pack per look or format: rules, a worked example (our own source code, or study notes and key frames for outside references), a poster frame and tags (video type, look, pace, format). The planner picks one pack per brief and it backs the style picker. The build must differ from the pack's example on at least 4 of 6 points (structure, opening, signature shot, camera path, score shape, ending), so videos don't all look alike. Seed 6 to 10 packs across motion (kinetic type, product UI, editorial frame, halftone), UGC (hook formats and script shapes) and explainers. Model: lemo-opuscar's style packs.
- [ ] **2. Director's plan.** The plan carries a beat sheet: each beat's time, start and end state, and its *reads* (what the viewer must understand, in order, each with time to land and never two at once). A hook in the first 2 s, a payoff every 3 to 5 s, a closing that echoes the opening. The owner approves it with the script; the agent builds key frames as stills first, then motion.
- [ ] **3. Scored critique loop.** Visual review scores stills against one rubric, names the 3 worst problems and fixes them until every still scores 8 or more, or the call limit is reached. The rubric and checks cover the failures seen most:
  - subject too small or against the edge, colour on the same colour, captions covering the subject, a beat too fast to read;
  - reading time: each text block stays on screen for its length at about 15 characters a second plus 1.5 s;
  - slow drift under about 1 pixel per frame, which stutters;
  - black or blank frames in transitions.
- [ ] **4. Motion kit.** One sandbox helper plus guidance:
  - closed-form springs with presets, one spring per target change so motion stays continuous;
  - a cursor that moves, clicks and drags, and one element that morphs through states instead of new cards fading in;
  - named easings, ease in to exit and ease out to reveal across a cut, overlapping and staggered entrances, something moving during holds;
  - interaction recipes (button press, progress, toggle, slider, drag-and-drop, terminal typing, chart tooltip) and transition recipes (whip, push, mask wipe, light leak) in CSS/GSAP;
  - character acting: anticipation and overshoot on every pose change, no twinning, reactions after causes;
  - motion blur on the final render: render at 4× the frame rate and blend in ffmpeg (about 4× render time).
- [ ] **5. Sound pass.** Measure the music's tempo and beats and give the agent a beat grid so cuts land on beats. Duck the music under the voice (voice about 10 dB above it), a sound on every visible action, one real silence before the peak, and sound used as a transition (J- and L-cuts). Uses audio we already buy; also closes the open "true ducking" item in slice B.
- **Done when:** the parity acceptance test passes, and a UGC and a plain motion build made with the same harness are rated clearly better than builds made before this slice.
- **Later, off the main path:**
  - 60 fps output for UI-heavy explainers.
  - Every format from one timeline (9:16, 1:1, 16:9 reframed, not cropped).
  - A call limit and price for longer or premium films (the best-known Opus films took 163 calls and 7 to 12 hours).
  - Pricing anchor: agencies charged about $1,000 for this kind of video; achxvi sells music, a mascot, features, an offer, any language and 3 edits.
- **Sources (ideas only, no code copied):** Barty-Bart/motion-graphics and ClaudeAnimationBase (MIT), lemo-opuscar (MIT), Diffusion Studio's easing guide (MPL-2.0), Remotion's transition patterns, 0xMovez's Opus 5.5 course, athemeroy's production brief (CC-BY 4.0). shipvideo (launchvideo.io) is a competitor baseline our output must clearly beat.

---

## Decisions for the owner

| Decision | Blocks | Recommendation |
|---|---|---|
| Music source | Slice B | ElevenLabs Music (generated, commercial plan) |
| Sound effects source | Slice B | ElevenLabs Sound Effects |
| Pose generation model | Slice C | Try gpt-image-2 edits first (already integrated); Flux Kontext as a fallback |
| Talking-shot provider and price | Slice D | Hedra or OmniHuman; hooks of 2 to 4 s only |
| Parity tier pricing | Slice F | Price from measured cost after the first parity build |

## External tools, at a glance

| Need | Tool | Status |
|---|---|---|
| Voice | Gemini 3.1 Flash TTS; Chatterbox for cloned voices | In the app |
| Voice with word timings | ElevenLabs TTS with timestamps | Optional; Whisper on our own TTS is enough |
| Word timings | OpenAI Whisper | In the app |
| Music | ElevenLabs Music, Stable Audio 2.5, Soundstripe | To add (Slice B) |
| Sound effects | ElevenLabs Sound Effects | To add (Slice B) |
| Character poses | gpt-image-2 edits (in the app), Flux Kontext | Slice C |
| Background removal | rembg or a similar model | Slice C |
| Lip-synced talking shots | Hedra, OmniHuman, Kling lip sync, Sync Labs | Slice D |
| Motion and style ideas | motion-graphics, lemo-opuscar, ClaudeAnimationBase, Diffusion Studio, Remotion | Slice F, ideas only; HyperFrames stays the engine |
| 3D mascot, if ever | Meshy, Tripo, Rodin plus three.js in HyperFrames | Not planned |

## Order

1. **Slice A.** Needed by every video type, and it unblocks timing.
2. **Slice B.** The biggest "finished" feel for the lowest cost.
3. **Slice E.** Uses what already exists; big impact for SaaS and product videos.
4. **Slice C.** The narrator.
5. **Slice D.** Premium, and priced separately.
6. **Slice F**: the effort test, then style packs, director's plan, critique loop, motion kit and sound pass; then the parity acceptance test.
