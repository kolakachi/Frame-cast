# CRAFT-STYLES: sharper craft, colourways and new styles

Scope for the owner, 2026-10-24. Nothing here is built until approved.

Sources, studied 2026-10-23: video-shotcraft (Apache-2.0, a Remotion promo-film skill) and lemo-opuscar (MIT, 59 film
styles). Both were first studied on 2026-10-07 (`archive/create/create-from-scratch-plan.md`); this picks up what
that left open and what is new since. **Nothing is copied**: their code is React/Remotion or three.js and does not fit
HyperFrames, and every card and rule here is written in our own words. Sound files come from Mixkit directly, not from
either repo (shotcraft could not trace several of its own).

## Why

- **Ads go stale.** Our pitch is "fresh ads before your old ones die". Today a fresh ad means a new build. A colourway
  or a new style on the same idea is a fresh creative for almost nothing.
- **Small sellers have no brand kit.** The builder makes up a palette every build, so the quality varies.
- **Our sounds read as a game.** The 14 effects are synthesized blips, pops and chimes. Shotcraft's rule, learned
  from rework: ads want whooshes, impacts, risers and the real sound of the thing on screen.
- **Paid social runs on trending audio.** A seller on TikTok or Reels usually swaps our music for a platform sound,
  so they need the video without our music and with the voice and effects kept.
- **12 style packs are not enough** for the categories we sell to (beauty, jewellery, candles, food, apps), and the
  holiday season is coming.

## Slice A: craft (about 5–6 days)

### A1. Their rules as our checks (1 day)

From shotcraft's rework rules, the ones we do not enforce yet. This also finishes from-scratch slice 5 (FS3), whose
open items were the first four.

| Rule | Check |
|---|---|
| Whole-frame beat hits (camera pump, flash frame, shake) at most 3 per video, 16+ beats apart | Counted from the motion kit's cue log |
| No constant-speed motion on moves that carry meaning | Linear eases on transforms found in the code |
| A technique is the star only once; no repeated shot | Same lead move or same picture twice |
| The opening hero gets one complete move of 3 s or more | Opening beat's lead move length |
| The brand mark holds 1 s or more, start and end | Reads check (the end hold exists; add the opening) |
| Captions 5% of frame height or more, helper text 3% | Measured on rendered frames (today one 3% tier) |
| No handheld shake in product videos; one shine sweep per hero, clipped to its corners | Code check |
| A repeated effect alternates two samples and steps down in volume | Sound pass |

All are sent back to the builder once, like reading time; none is blocking on its own.

### A2. Real sound effects (1 day, plus listening)

- About 40 effects from Mixkit (free for commercial use, no credit needed), the source URL of each recorded as it is
  downloaded: whooshes, impacts, risers, shimmer, real Foley (camera shutter, switch, paper, glass, pour, keys).
- The game-like synthesized ones retire; the sound pass keeps its placement (peak on the hit, quieter under the voice).
- The riser → impact → shimmer phrase for the one big reveal.
- Measure our render's fixed audio delay once (shotcraft found about 1.3 frames from the AAC encoder) and correct it.

### A3. A version without our music (half a day)

- On a finished video: **Download without music**. The same composition rendered with the music muted, voice and
  effects kept. Free (render time only, no AI).

### A4. Colourways as a free swap (2–3 days)

- The builder writes every colour as one of 7 role colours, declared as composition colour fields: background,
  surface, line, text, secondary text, accent, second accent (5% of the frame at most). A check counts colours written
  outside them and sends the build back once.
- 8–10 curated palettes, each with its roles (dark: midnight, graphite, ember; light: paper, porcelain, sand, sage;
  bold: lime, coral). With a brand kit, the brand colour takes the accent role.
- On a finished video: **Try it in…** chips. A pick runs the existing free swap path (no rebuild, render only).
- Generated pictures and clips keep their own colours; only the design recolours. Only videos built after A4 have
  colourways.

### A5. A finishing layer (1 day)

Added to the motion runtime for every style: seeded film grain, vignette, two-layer soft shadows, hairlines and inner
highlights, a lit stage (a soft light pool behind the subject), and motion blur on fast moves (optional per style,
checked for render time on the worker).

### A paid canary after deploy

One Standard 15 s in-house build, then two colourway swaps and a no-music download from it.
**About 100–150 credits** (the last at-cost canary was 102); the swaps and the download are free.

## Slice B: four seasonal styles (about 2 days)

Black Friday and the holidays first:

| Style | For |
|---|---|
| **Countdown** (a live split-screen clock) | Drops, Black Friday, "last day" |
| **Art deco** | Jewellery, luxury, gifts, New Year |
| **Paper lantern** (2D: layered paper and backlight) | Gift brands, festive greetings |
| **Caption news** (a captioned news-style short) | Any seller on paid social |

Each is a style pack in our words, with the deeper card shape from opuscar:
- what it is and what it is not;
- colour logic;
- type;
- motion quality;
- a camera table (move, what it says, what it can serve);
- a sound palette.

Each pack also gets one worked example build plus the template audit.

**Cost:** each example is a paid build at cost, about 100–300 credits (the 2026-10-16 neo-brutalism and blueprint
proofs would be about 290 and 175 at today's rate). **About 800 credits for the four.**

## Slice C: eight more styles and deeper cards (about 4 days)

| Seller | Styles |
|---|---|
| Beauty and skincare | **Microscope** (the ingredient up close), **one-line drawing** |
| Food and drink | **Rubber-hose cartoon** (1930s), **risograph** |
| Sales and launches | **Game show** |
| Apps and SaaS | **Dark keynote**, **Swiss motion**, **living screencast** (folded into our product-UI pack if they overlap) |

- Our existing 12 packs are rewritten to the same card depth (text only, no builds).
- **About 1,600 credits** for the eight examples.
- Red paper-cut for Lunar New Year can follow in January.

**Left out:** the 13 opuscar styles that need real 3D in the browser (glass product, teardown, tilt-shift, brick toy
and others). Our build server renders 3D slowly, and our own rule allows it for one short beat only. Also left out:
the art-film styles that do not sell products (ink wash, newsreel, backrooms and similar).

## Decisions for the owner

1. **Colourway swaps free** (render only), like other swaps? Recommended: yes.
2. **The palette list** above: keep, cut or add.
3. **Without music**: a download on every finished video, or only on request? Recommended: on every video.
4. **Spend**:
   - about 100–150 credits for slice A's canary;
   - about 800 for slice B's examples;
   - about 1,600 for slice C's.

   In-house has about 100 credits, so it needs a top-up. Each slice is priced again before its builds run.
5. **3D styles** stay out for now.

## Order

1. A, then the canary.
2. B, before Black Friday (28 Nov).
3. C.

Shotcraft's motion recipes (the Pocketsflow-level goal) stay parked until the reference acceptance build is done.
