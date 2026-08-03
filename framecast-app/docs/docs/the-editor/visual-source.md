---
sidebar_position: 4
title: Change the visuals
description: Swap stock video or images, generate and animate AI images, use library assets, or build an audiogram.
---

# Change the visuals

The **Visual Source** section of the Config panel controls what each scene shows. Select a scene, then pick one of five tabs: **Video**, **Image**, **✦ AI**, **Assets**, or **Audio** (audiogram).

## Video (stock video)

- **Type** — **Clip** (a matched stock clip) or **BG Loop** (a looping background).
- **Search query** — describe the footage you want.
- **↻ Swap Visual** — fetch a new clip.

## Image (stock image)

- **Search query** — describe the still you want.
- **↻ Swap Image** — fetch a new image.
- **⚡ Animate this image** — add motion (opens the [Animate](#animate-a-scene) modal).

## ✦ AI (AI image)

Generate a custom image for the scene.

- **Character** — bind a [character](/characters/create-a-character) so the person stays consistent, or leave it unbound.
- **Style** — pick a look: Cinematic, Photorealistic, Documentary, Anime, Minimalist, Realistic, Vintage, Neon, Cyberpunk 80s, Dark Fantasy, Comic, Film Noir, Watercolor, Cartoon, 3D Animated, and more. Choose **Custom** to describe your own style.
- **Model** — each model shows its cost and render time:

  | Model | Notes | Cost |
  |---|---|--:|
  | GPT Image 1 | OpenAI photoreal (default) | 16 cr |
  | GPT Image 2 | OpenAI, newer | 43 cr |
  | Nano Banana | Google, cheap | 10 cr |
  | Nano Banana Pro | Google, best identity | 35 cr |
  | Flux Schnell | cheapest | 1 cr |
  | SDXL Lightning | stylish | 1 cr |

- **Prompt override** *(optional)* — override the auto-generated image prompt.
- **✦ Generate** creates the image. Once generated you can **Regenerate**, **⚡ Animate**, or revert to the still. A **Versions** strip keeps the original still plus any animation clips.

![the AI image tab with the character, style, and model pickers](/img/howto/editor-ai-image.png)

## Assets

Use a file from your library. Pick or **Change Visual**, or **Open Library** to browse. Non-video assets can be animated with **⚡ Animate this image**.

## Audio (audiogram)

Turn a scene into an audio-reactive visual — great for podcasts and narration.

- **Design** — Classic, Mirror, Radial, or Minimal.
- **Color** — pick a swatch or a custom color.
- **Background** — Dark, Black, Purple, or Ocean.
- **Apply Audiogram to Scene**.

## Animate a scene

Animating turns a still image into motion. Open the **Animate** modal from an image scene (**⚡ Animate this image** / **Animate**). Choose:

- **Model** — the motion engine, each with a per-5-second cost:

  | Model | Notes | 5s cost |
  |---|---|--:|
  | Wan 2.5 | fast, cheap | 50 cr |
  | Seedance Lite | ByteDance, cheap | 30 cr |
  | Hailuo 2.3 | best for most (recommended) | 35 cr |
  | Seedance Pro | ByteDance, sharp | 125 cr |
  | Kling 2.1 | cinematic | 100 cr |
  | Spokesperson | lip-sync to your voice | length-based |

- **Quality** — resolution options per model (each shows its own credit cost).
- **Duration** — a **10-second** clip costs **2×** a 5-second one.
- **Motion prompt** *(optional)* — quick chips (Subtle motion, Slow push-in, Dolly back, Pan left, Wind & weather, Dramatic zoom) or your own text.

The live total is shown at the bottom before you click **⚡ Animate**.

:::tip Spokesperson
The **Spokesperson** tier lip-syncs a character to the scene's voiceover, so you need a generated voice first. Its cost scales with length (≤8s, ≤15s, or longer).
:::

## Motion (Ken Burns)

For a still image, the **Motion** section adds a subtle camera move without a full animation render:

- **Effect** — Zoom In/Out, Pan (Left/Right/Up/Down), Pan + Zoom, or Static.
- **Intensity** — Subtle, Moderate, or Dramatic (hidden when Static).

## What's next

- [Set the voice](/the-editor/voice)
- [Add sounds & music](/the-editor/sounds-and-music)
- [Use the assistant to change visuals](/the-editor/the-assistant)
