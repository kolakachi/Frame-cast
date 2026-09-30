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

- [ ] **Fix the narration download.** The voiceover plan item fails: the TTS result is a managed-storage URL (`minio://…`) that the executor fetches over HTTP. Read managed URLs through `StorageService`. Covered by a test using a `minio://` result.
- [ ] **Draft a narration script in the plan.** The planner proposes a voiceover script of 25 to 40 words for 15 s, built only from the brief, approved facts and page claims. Page claims are marked as needing approval.
- [ ] **Edit and approve the script in the plan card.** Lines are editable like on-screen copy. Approving the plan approves the script as spoken copy, and nothing unapproved is spoken.
- [ ] **Narration reads the approved script.** Not just the on-screen lines.
- [ ] **Voice choice in the plan.** A catalogue voice by description, or the workspace's cloned voice.
- [ ] **Timings for generated speech.** After narration is bought, the worker transcribes it with Whisper. The transcript tool already works on source files, so text is timed to the generated voice through the existing `data-spoken` check.
- [ ] **Accent words.** The agent may set one word per line in an accent style; the fonts are already there.
- **Done when:** a build from a text-only brief has a voiceover of the approved script, and every on-screen line passes the timing check against the narration.
- **Cost:** Gemini TTS is 3 credits a line, and Whisper is about $0.006 a minute.

## Slice B: music and sound effects (Q7)

- [ ] **Decide the music source.** This is the owner's decision. Recommendation: ElevenLabs Music for a generated bed per video, commercial use on a paid plan. Alternatives: Stable Audio 2.5, Soundstripe, or a bought royalty-free pack.
- [ ] **Music in the plan.** A `music` catalogue item generated to the video's length and mood, priced from measured cost, and bought under the one approval.
- [ ] **Sound effects.** An `sfx` item for up to 6 short cues such as clicks, whooshes and pops. Candidate: ElevenLabs Sound Effects. The agent places them on UI beats.
- [ ] **Mixing.** Music ducks under the voice by 10 to 14 dB. The final mix is levelled by the existing delivery loudness step. A check fails if speech is masked.
- [ ] **Library music.** Re-enable it in Create only once licensed tracks exist; it is withheld today.
- **Done when:** a build has voice, music and at least three timed sound effects, the delivery check reads −14 LUFS ±1, and speech stays intelligible.

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
- **Done when:** the hook shows the character saying the first line with believable lip sync, and the cost of the shot is under the price set for it.

## Slice E: real product UI (Q5)

- [ ] **UI from the page capture.** The agent rebuilds 2 to 4 of the brand's real screens (hero, feature, pricing, dashboard) as HTML cards, guided by the page screenshot and brand notes.
- [ ] **Numbers only from approved facts.** Prices, counts and customer figures appear only if approved; otherwise neutral placeholders such as "Your product".
- [ ] **Build-on-cue.** Cards fill in as their feature is spoken, checked with `data-spoken` like any timed text.
- [ ] **Optional supplied screenshots.** The user may attach real product screenshots as source for exact UI.
- **Done when:** the WyvStudio build shows recognisable WyvStudio UI building in sync with the named features, with no unapproved numbers.

## Slice F: craft and format (Q6, Q8)

- [ ] **Editorial frame kit.** Mono corner labels, timecodes, beat counters and hard cuts to flat colour, as reusable guidance for the agent. It is a style option, not a default.
- [ ] **16:9 at 60 fps.** Offer 60 fps output for UI-heavy explainers where the renderer supports it; confirm render time and file size.
- [ ] **Reference-matching pass.** Opus's visual review compares the build's pacing and structure with the reference notes (beats, cut rhythm, closing lockup) and repairs if they are clearly off.
- [ ] **Longer builds when needed.** Parity builds may need 12 calls or more; measure, then set the call limit and price for this tier.
- **Done when:** the parity acceptance test passes.

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
| 3D mascot, if ever | Meshy, Tripo, Rodin plus three.js in HyperFrames | Not planned |

## Order

1. **Slice A.** Needed by every video type, and it unblocks timing.
2. **Slice B.** The biggest "finished" feel for the lowest cost.
3. **Slice E.** Uses what already exists; big impact for SaaS and product videos.
4. **Slice C.** The narrator.
5. **Slice D.** Premium, and priced separately.
6. **Slice F**, then the parity acceptance test.
