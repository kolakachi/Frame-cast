# Ad Creative Builder — cookbook

What a WyvStudio lane for performance ad creative has to cover, and what
already exists to build it from. Written to be picked up cold.

Status: **not started.** This is scope and specification, not a plan of
record — no dates, no sequencing, no commitment.

---

## 1. What it is, in one line

**An ad-variant engine, not a design tool.**

A seller does not need one beautiful ad. They need twenty decent ones this
week, and to find out which of them works. Everything below follows from that
sentence; anything that does not serve it belongs in the video editor instead.

The failure mode to design against is being read as "Canva with AI." That
happens if the product only wraps an uploaded photo in a nice frame. Two
things prevent it:

1. **Generation when the library cannot answer the brief.** The common case
   for a small seller is three flat product photos on white and no video at
   all. Wrapping cannot help them; generating a presenter, a UGC take or a
   demo can. Nobody wrapping images can do that.
2. **Variant economics.** Twenty variants have to be cheap enough to actually
   make. See §4.

---

## 2. The layer model

The spine of the whole thing. Every decision about cost, speed and prompt
behaviour comes from which layer a change lands on.

| Layer | What it holds | Cost of a change |
|---|---|---|
| **Base** | The generated or uploaded pixels — product shot, presenter take, demo clip, B-roll | Model credits. Expensive. |
| **Composition** | Headline, subhead, CTA, price badge, logo, review stars, frames, colourway, safe-zone padding, captions | CPU only. **Free.** |
| **Variant axes** | Which combinations of the above to emit | Free per combination once the base exists |

The rule that makes the product viable: **a change lands on the composition
layer wherever it possibly can.** "Try a shorter CTA", "make the headline
bolder", "give me ten hooks", "same ad on dark" are all composition. Only
"different presenter", "new product angle", "a scene we don't have" reaches
the base layer and costs credits.

Get this inverted and a marketer iterating thirty times on copy burns their
plan, which kills the product on its own pricing.

### Where composition is rendered

HTML/CSS → headless Chrome → frames → ffmpeg, i.e. the Hyperframes shape.
Relevant existing infrastructure:

- `renderer/` already runs **Node 20 + Chromium + puppeteer-core**, with
  `shm_size: 512m` and `init: true` to reap Chromium children — the part that
  is usually got wrong is already solved and running in production. It has
  **no ffmpeg**, and today only scrapes rendered page text for URL grounding.
- The API container has ffmpeg (used by `GenerateCharacterSheetJob` and the
  demo-embed post-composite).

A single-frame render (static image ads) avoids the expensive part entirely.
A 60s video at 30fps is 1,800 browser screenshots — real time and CPU on top
of exports that already take minutes. **Start with static.**

---

## 3. Platform specs to cover

Verified September 2026. These move; re-check before relying on them.

### Canvas and ratio

| Placement | Ratio | Pixels | Notes |
|---|---|---|---|
| Meta Feed (FB/IG) | **4:5** | 1080 × 1350 | The Feed workhorse. **Not currently supported** — see §7 |
| Meta Feed square | 1:1 | 1080 × 1080 | |
| Stories / Reels (FB/IG) | 9:16 | 1080 × 1920 | |
| TikTok | 9:16 | 1080 × 1920 | H.264 MP4, under 500 MB |
| YouTube Shorts | 9:16 | 1080 × 1920 | |
| YouTube in-stream | 16:9 | 1920 × 1080 | |

### Duration

| Platform | Hard limit | What actually performs |
|---|---|---|
| Meta | 4 GB file | 15–30s awareness, 30–60s consideration |
| TikTok | up to 10 min | 15–30s; completion rate falls off fast |
| YouTube Shorts | 60s | 10–30s for action campaigns; under 30s gets lower CPM |

### Safe zones — the part most tools get wrong

Vertical placements bury the top and bottom of the frame under platform UI.
Anything that must be read goes in the middle.

**Meta vertical (9:16):**
- Top **14%** — profile icon, username, "Sponsored"
- Bottom **35%** — CTA button, engagement icons, captions

**TikTok (1080 × 1920):**
- Organic safe area: **900 × 1492** centred
- Paid (CTA button present): **900 × 1442**
- Margins: 108px top, 320px bottom (370px with CTA), 60px left, 120px right

Implication: the composition layer needs **per-placement safe-zone
templates**, not one layout scaled. A headline that reads on TikTok can sit
under the CTA button on Reels.

### File

- Video: MP4 or MOV, H.264
- Image: JPG or PNG

---

## 4. Variant axes

What a variant run is allowed to vary, cheapest first.

| Axis | Layer | Examples |
|---|---|---|
| Headline / hook copy | Composition | 10 hooks over one base |
| CTA text and style | Composition | "Shop now" / "Get 20% off" / "See sizes" |
| Offer badge | Composition | price, % off, free shipping |
| Colourway | Composition | brand palette variations |
| Layout / safe-zone template | Composition | per placement |
| Ratio | Composition (+ outpaint, §5) | 4:5, 1:1, 9:16, 16:9 |
| Social proof | Composition | star rating, review quote |
| Opening shot | **Base** | a different 3s hook take |
| Presenter | **Base** | different character |
| Scene / setting | **Base** | new generation |

Everything above the line is free. A run should default to varying only those
and require an explicit, quoted step to cross into the base layer.

**Existing precedent to fix, not repeat:** the UGC lane already has
"Alternative openings", and the code is blunt about the cost —
*"Each is a whole take per character, so they multiply… which is how a
careless run becomes thirty."* ([`UgcController.php:442`](../../api/app/Http/Controllers/Api/V1/Ugc/UgcController.php))
Every hook variant regenerates the entire ad. On a 20s cast-presenter ad at
58 cr/s that is 1,160 credits per take — base plus three openings exceeds an
entire Starter plan. Splitting hook from body, and preferring text hooks over
regenerated ones, is the single biggest cost change available.

---

## 5. Generators needed

Each one is an adapter class plus a registry entry in
`ImageAdapterFactory::AVAILABLE` (label, sub, cost, render, adapter), which
`/api/v1/image-models` reads so the picker stays in sync automatically.

### Have

`gpt-image-1`, `gpt-image-2`, `nano-banana`, `nano-banana-pro`,
`flux-schnell`, `sdxl-lightning`; video via `ReplicateVeoAdapter::ENGINES`
(`seedance25`, `veo`, `veo_hq`, `omni`); `luma/modify-video` for restyle.

### Missing, in value order

| Need | Why it matters here |
|---|---|
| **Outpaint / aspect extension** | Sellers have square photos; ads need 4:5, 9:16, 16:9. Generates the missing canvas instead of letterboxing. Makes "every placement" real. |
| **Background removal / cutout** | Cut the product out once, and every background, colourway and ratio after that is composition — free. Pairs with outpaint as the core unlock. |
| **Relight / scene placement** | Product into a lifestyle setting without a shoot. |
| **Upscale** | Higher-res placements and print. |

**Architectural note.** The Replicate adapters are per-model classes
implementing `ImageGenerationAdapter`, and `ReplicateImageAdapter` itself is
hardcoded to a pinned `stability-ai/sdxl` version. Fine for six models;
friction for fifteen. Adding four more is the moment to introduce a generic
Replicate adapter taking a **model slug plus an input-mapping closure**, so
new generators arrive as registry config rather than new classes.

---

## 6. Prompt surface

"Prompt your way to the result" is a new tool set on architecture that already
runs, not a new architecture.

`CruiseControlService` does natural-language prompt → tool selection →
`estimateCost($project, $params)` per tool → approve → apply, with twenty
tools today (`UpdateSceneScriptTool`, `RegenerateImageTool`,
`ApplyBrandKitTool`, `UpdateCaptionsTool`, …).

Two things must change for the ads lane:

1. **Most tools must cost nothing.** The current set is mostly generative
   because most video edits genuinely need new pixels. Here the opposite is
   true (§2). `estimated_cost` already comes back per tool and zero is as
   expressible as a number — the UI shows "free" for composition edits and a
   quote for generative ones, which is the same quote-before-spend contract
   enforced everywhere else.
2. **Direct manipulation for the last mile.** Prompting is excellent for copy
   and variants — "ten hooks for this" is a language task. It is poor for
   precision — "nudge the CTA left" is a mouse task, and pure-prompt editors
   frustrate people on the final ten percent. Prompt for structure and bulk;
   handles for position.

---

## 7. Gap analysis against what exists

| Capability | State |
|---|---|
| Asset library (image, video, audio, music, sound) | ✅ `list_library` |
| Brand kits (colours, fonts, caption style, voice) | ✅ drives captions; **not** layout templates |
| Characters + identity sheets | ✅ sheets shipped 28 Sep |
| Shot plans with `on_camera` / `b_roll` / `reaction` | ✅ `plan_ugc` |
| Generative video with presenters | ✅ four engines |
| Demo clip spliced into an ad | ✅ ffmpeg post-composite |
| Captions, 16 animated presets | ✅ ASS via `BuildsAnimatedCaptions` |
| Social publishing (organic) | ✅ YouTube, TikTok, IG, FB |
| Prompt-driven editing with per-tool costs | ✅ `CruiseControlService` |
| Headless Chrome in production | ✅ `renderer/` (no ffmpeg) |
| **4:5 ratio** | ❌ `ASPECT_RATIOS = ['9:16','1:1','16:9']` — Meta Feed's main placement is missing |
| **Safe-zone templates per placement** | ❌ nothing |
| **HTML composition layer** | ❌ nothing |
| **Free (non-generative) variant axis** | ❌ variants are full regenerations |
| **Outpaint / cutout / relight / upscale** | ❌ none |
| **Bulk export with ad-manager naming** | ❌ nothing |
| **Which variant won** | ❌ no ads-manager integration anywhere |

---

## 8. Open questions

- **Feedback loop.** Social publishing is organic posting, not an ads-manager
  integration. Without it this is a variant *generator*, not a creative
  optimiser, and "templates based on formats that are working" is an
  assertion rather than a fact. Either build the loop or do not make the
  claim.
- **Lane or app.** Current answer: a lane inside WyvStudio sharing credits,
  characters, brand kits and export, with its own entrance and tool set —
  the shape `UgcAdsView` already proves. A separate SaaS doubles the
  marketing site, billing, MOR product, support and deploy surface. Revisit
  when the buyer, the pricing model and the support burden diverge, and when
  there is someone to run it.
- **Render budget.** Static image ads are one frame. Video composition is
  ~1,800 frames a minute. Whether video variants are viable depends on
  measured render cost, which nobody has measured yet.
- **Caption strategy.** 16 ASS presets exist and work. HTML would be more
  expressive but the export path is the most critical lane in the product.
  Do not move captions to HTML to prove a point.

---

## Sources

Platform specs verified September 2026 from
[Hootsuite](https://blog.hootsuite.com/facebook-ad-sizes/),
[Sprout Social](https://sproutsocial.com/insights/social-media-video-specs-guide/),
[Google Ads Help — Shorts asset specs](https://support.google.com/google-ads/answer/16041697?hl=en),
and TikTok safe-zone figures corroborated across
[Vizup](https://www.tryvizup.com/blog/tiktok-ad-specs-2026-video-sizes-spark-ads-and-safe-zones)
and [Recharm](https://www.recharm.com/blog/tiktok-video-ad-specs).
