# Layla pilot: three videos (PRESENTER, CMO plan experiment 7)

For the owner's approval, 2026-10-18. Layla is our stock presenter, character 101 (owner, 2026-10-17). The pilot checks
two things before she becomes the main channel: **her face holds** across three videos (same person, no drift), and
**the hooks earn views** when posted. Rules from `brand-story.md` §5: openly AI, shows rather than sells, never claims to
be human, a customer or the founder, says WyvStudio as "weave studio", says what things cost and how long they take,
no comparison with avatar tools, never mentions Series.

## How they're made

The UGC director with Layla as the presenter, vertical 9:16, 20–25 seconds, captions on, our own account. Each video:
Layla on camera for the hook and her lines, and a real WyvStudio screen or a finished template ad as the demo (the
HOOK-PACK idea: the presenter only where she's needed). The caption on the post says she's an AI presenter made in
WyvStudio.

## The three scripts

**1. "This ad took four minutes"** (belief 3: AI can, if it's checked; shows a template becoming a real ad)
- Hook (Layla): "This ad took four minutes, and nobody filmed anything."
- Demo: a free template (the candle-style or food template) becoming a seller's ad: product photo in, words changed,
  the finished ad playing.
- Layla: "Pick a template, add your product, and WyvStudio, said 'weave studio', makes it with your words and your
  photo. Your first one is free."
- Close: "Link in bio. Try it on your product."

**2. "You don't need a better ad, you need twelve"** (belief 1: one ad isn't enough)
- Hook: "Your ad didn't stop working because it's bad. Everyone's just seen it."
- Layla: "Platforms show ads to people who look like your audience, and they get tired of the same one fast. The fix
  isn't one perfect ad, it's fresh ones every week."
- Demo: three different finished ads for the same product, cut together quickly.
- Close: "Fresh ads before your old ones die. Link in bio."

**3. "Shop owners running the same ad since summer"** (callout; belief 2: shoots can't keep up)
- Hook: "If you're a shop owner still running the ad you made in summer, this is for you."
- Layla: "A shoot every week costs more than most small shops make. So here's the shortcut: your product link in, a
  week of different ads out."
- Demo: a product link pasted, the plan shown, the ad playing.
- Close: "Paste your link, see the plan before you pay anything. Link in bio."

## Cost

From prod's UGC charges (last 45 days): a UGC ad averages about 485 credits (most 450–850).

| | Credits | Real cost to us |
|---|---|---|
| Three videos | **about 1,500–2,500** | **about $9–15** |
| One redo, if a face drifts or a line is wrong | about 450–850 | about $3–5 |

Spent from the staff workspace on prod. Each video's exact price is shown before it's made; nothing runs without the
owner's yes.

## Checks before posting

- Face: the three side by side; same face shape, eyes, skin, hair. If one drifts, redo that one only.
- Lines: said as written; "weave studio" pronounced right; captions match.
- Rules above: no human claims, no competitor names, any cost she states is true.
- The marketer posts (CMO plan): hooks tested first as Instagram trial reels.

## Decision for the owner

| | |
|---|---|
| Approve the three scripts (or change any line) | ? |
| Approve about 1,500–2,500 credits (about $9–15 real cost) | ? |

## Result (2026-10-18)

Made on prod (staff workspace): videos 2 and 3 on Omni held Layla's face and cut in the demo; video 1 on Seedance drifted (a different-looking woman) and skipped the demo, so it was remade on Omni (face right, demo skipped again by the shot planner) and the food ad was cut in by hand. Credits: 3,161 (759, 846, 914, plus the 642 redo). Real cost: $25.28, about $5 over the approved estimate because of the discarded first take and Omni's higher cost per video. Files for the marketer: `~/Desktop/layla-pilot/`. Lines not machine-checked: listen for "weave studio" and the exact wording before posting. No burned-in captions: add them in the posting app.

## Runway test (2026-10-18, owner)

The owner heard video 1 (Omni) say WyvStudio wrong: the director's "said as" note never reaches Omni's own speech. Remade video 1 a
different way: Gemini TTS (voice Sulafat) with "Weave Studio" spelled out in the spoken line; two 5 s Runway Gen-4.5 clips via
OpenRouter (720p 9:16, first frame = a still of Layla from the Omni video, $0.12/s); Sync lipsync-2-pro on each; the food ad
between them. 15 s. Real cost about $2 ($1.20 OpenRouter, about $0.80 lip-sync). File: `~/Desktop/layla-pilot/layla-1-runway-test.mp4`.
Runway has no audio and runs 2–10 s per clip, so longer videos need several clips.

## Model test (2026-10-18, owner)

Same lines on OpenRouter models, Layla's still as the first frame (files in `~/Desktop/layla-pilot/model-test/`). Owner's
ranking: **HeyGen Video 1 best, then Hailuo 3**; Runway Gen-4.5 looked cartoonish. A fresh still from our character image
model (gpt-image-2 from her reference, "phone selfie, real skin, no glam") made HeyGen look like a real person: the start
image matters as much as the model. Day-in-the-life ("a day making ads for small shops", three stills) made on Hailuo 3
and Veo 3.1 Lite; Veo accepted her face. Only Avatar IV and Seedance 2+ take our own voice; the others speak in a voice
they make up. OpenRouter spend for all tests: $9.18 of $20.

**Veo 3.1 Fast (2026-10-18):** with natural image-model stills (same hoodie all day), Veo 3.1 Fast accepted Layla's face on
both OpenRouter (3 clips) and our Replicate route (1 clip), which refused her glam studio still on 2026-10-14 (E005). So the
refusal tracked the photo, not the route. Before re-opening Veo Fast for presenters (fix f6dbabba blocks it), test more
faces. Both Veo models add a second mug when the prompt says "sips her coffee" and the still already shows one: name the
single mug in the prompt. Veo ends each clip with a short fade; trim about 0.4 s.

## Description benchmark (2026-10-18, owner)

Presenter description only: the production `CharacterAppearanceService` prompt on one close-up of Layla, two reads each by
Opus 5.5 (current, Anthropic direct), Gemini 4 Argon and GPT-6.1 Sol (OpenRouter); each read drawn back into a face by
gpt-image-2 from the text alone and scored by `UgcFaceCheck` (Haiku 4.5) against the close-up, twice.

| | Read time | Cost per read | Face score (4 checks) |
|---|---|---|---|
| Opus 5.5 | 7 s | about $0.02 | 92, 92, 92, 88 |
| Gemini 4 Argon | 11–12 s | about $0.006 | 92, 85, 92, 92 |
| GPT-6.1 Sol | 12–14 s | about $0.004 | 92, 85, 92, 85 |

No winner: the scores sit at the checker's ceiling, and by eye every drawn face is a close relative, not Layla. Words
alone don't carry identity; a photo (first frame or reference) does. Files: `~/Desktop/layla-pilot/description-bench/`.
