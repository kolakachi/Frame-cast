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
- [ ] **Pose sheet.** A `character_poses` item makes 4 to 8 poses of one character (neutral, talking, pointing, surprised, waving) from one reference image, with identity kept. Candidates: the existing gpt-image-2 character edits, or Flux Kontext on Replicate. Priced per pose.
- [ ] **Transparent cut-outs.** Poses come out with transparent backgrounds so the agent can layer them over colour fields and UI. Add background removal: a media op, or a model such as `rembg`.
- [ ] **Halftone look.** Add `halftone` and `grain` looks to the sandbox media tool, as ffmpeg filters on stills and clips, plus a CSS or SVG option the agent can use.
- [ ] **Pose continuity rule.** The same character is used across beats and switched between poses on beats, as a narrator does; the agent is told this in its instructions.
- **Done when:** one build shows the same original character in at least 4 poses with the halftone look, and a reviewer reads it as one character.

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

## Slice F: craft and format (Q6, Q8)

- [ ] **Effort test first.** Every viral Opus 5.5 build ran on xhigh or max effort; ours run on medium to avoid cut-off replies and hold cost. Run one parity build on xhigh with the same brief and compare quality, calls, cost and cut-offs. If it wins, use xhigh for new builds and medium for fixes and re-renders.
- [ ] **Beat sheet with states.** The plan carries a beat-by-beat state list: each beat's time, what is on screen and its state, a hook in the first 2 s and a payoff every 3 to 5 s. The owner approves it with the script, and the agent builds against it.
- [ ] **Cuts on the music's beat.** Measure the tempo and beat times of the music bed after it is bought, and give the agent a beat grid so cuts and pops land on beats. A timeline check flags cuts well off the grid.
- [ ] **Scored critique loop.** Visual review renders stills, scores each one out of 10, names the 3 worst problems and fixes them. Repeat until every still scores 8 or more, or the call limit is reached.
- [ ] **Build in stages.** Stills first, then a rough timing pass, then the full pass, so a wrong direction is caught before a full build is spent on it.
- [ ] **Editorial frame kit.** Mono corner labels, timecodes, beat counters and hard cuts to flat colour, as reusable guidance for the agent. It is a style option, not a default.
- [ ] **16:9 at 60 fps.** Offer 60 fps output for UI-heavy explainers where the renderer supports it; confirm render time and file size.
- [ ] **Reference-matching pass.** Opus's visual review compares the build's pacing and structure with the reference notes (beats, cut rhythm, closing lockup) and repairs if they are clearly off.
- [ ] **Longer builds when needed.** Parity builds may need 12 calls or more; measure, then set the call limit and price for this tier.
- [ ] **Motion blur on the final render.** HyperFrames 0.8.82 has no blur option, but it renders up to 240 fps. Render at 4× the output frame rate and blend each 4 frames into 1 with ffmpeg (`tmix`), a 180° shutter as in Barty-Bart/motion-graphics. Final render only, since it takes about 4× the render time; measure time and file size first.
- [ ] **Spring and cursor motion kit.** A small helper in the sandbox runtime next to GSAP: closed-form springs with presets (fast, slow, soft, camera) and a cursor that moves along a path, clicks and drags. Agent guidance: one element morphs through states (pill → card → terminal → chart) with the cursor driving each change, instead of new cards fading in. Ideas from motion-graphics (MIT).
- [ ] **Interaction recipes.** Named recipes in the agent instructions: button press, progress, toggle, slider, drag-and-drop, terminal typing, chart tooltip and chapter card, so product UI (slice E) looks used, not shown.
- [ ] **Easing and energy rules.** Six named easings with when to use each; ease in to exit and ease out to reveal across a cut; overlap and stagger entrances; keep something moving during holds. From Diffusion Studio's easing guide (MPL-2.0, ideas only).
- [ ] **Slow-drift check.** A timeline check that flags moves under about 1 pixel per frame, which stutter instead of glide.
- [ ] **Transitions and light leaks.** Whip, push, mask wipe and light-leak transitions as CSS/GSAP recipes, modelled on Remotion's transitions and light-leaks patterns. No Remotion dependency; its licence needs a paid company licence above 3 people.
- **Done when:** the parity acceptance test passes.
- **Notes, not yet scheduled:**
  - **Call budget for longer films.** The best-known Opus films took 163 calls and 7 to 12 hours. Sixteen calls fit a 15 s parity build; longer or premium films need their own limit and price.
  - **Every format from one timeline.** Lay scenes out with a layout function and render 9:16, 1:1 and 16:9 in parallel, reframing type and UI rather than cropping.
  - **Pricing anchor.** Agencies charged about $1,000 for this kind of video a year ago. achxvi, who made the Pocketsflow sample, sells music, a mascot in any style, product features, a closing offer, any language and up to 3 edits.
- Source for the items above: 0xMovez, "How to build motion design studio with Opus 5.5" (x.com/0xMovez/status/2104216919033192746).


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
| Motion ideas | Barty-Bart/motion-graphics (MIT), Diffusion Studio easing guide, Remotion patterns | Slice F, ideas only; HyperFrames stays the engine |
| 3D mascot, if ever | Meshy, Tripo, Rodin plus three.js in HyperFrames | Not planned |

## Order

1. **Slice A.** Needed by every video type, and it unblocks timing.
2. **Slice B.** The biggest "finished" feel for the lowest cost.
3. **Slice E.** Uses what already exists; big impact for SaaS and product videos.
4. **Slice C.** The narrator.
5. **Slice D.** Premium, and priced separately.
6. **Slice F**, then the parity acceptance test.
