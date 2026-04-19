# Framecast MVP Core

## Purpose

This file is the source of truth for the first shippable Framecast product.
Anything not listed here is roadmap, not MVP.

The MVP should prove one valuable promise:

**A user can create a good faceless short video series without filming or editing.**

---

## MVP Positioning

Framecast helps faceless creators turn an idea, script, or images into a short-form video with script, scenes, visuals, voice, captions, music, preview, and export.

The focused pitch:

**"Create repeatable faceless short videos with voice, captions, visuals, and music in one workflow."**

The MVP is not a full social scheduler, analytics suite, agency portal, or advanced video editor.

---

## Core Audience

Launch for creators who make repeatable short-form content:

- Horror / dark stories
- Weird facts
- History mysteries
- Motivation
- Devotional / Bible shorts

Finance, Reddit-style stories, agency workflows, and multi-platform publishing can come later because they add compliance, copyright, and integration risk.

---

## MVP Scope

### 1. Create Video

- New video / quick start flow
- Niche selection with custom niche fallback
- Source input:
  - script / idea text
  - uploaded images
  - AI-generated visuals when no images are uploaded
- Aspect ratio selection
- Language selection
- Duration target

### 2. Generate Project

- Script generation
- Scene breakdown
- Hook generation/scoring where already supported
- Visual generation or visual matching
- Voice generation
- Caption settings
- Background music selection
- Phase-by-phase progress
- Friendly failure states

### 3. Edit Scenes

- Scene list
- Edit scene script
- Swap visual
- Generate AI image for a scene
- Regenerate voice for a scene
- Caption controls:
  - on/off
  - preset/style
  - font
  - position
  - highlight mode/color
- Music controls:
  - category/track
  - play preview
  - volume/fade where supported

### 4. Preview

- Scene preview
- Full-video preview
- Voice audible in preview
- Background music audible in preview
- Captions do not show over unloaded/missing media
- Preview should be close enough to export that users trust it

### 5. Export

- Export completed project to MP4
- Export progress visible
- Export failure reason visible
- Retry failed export
- Output has correct aspect ratio
- Output includes voice, captions, visuals, and music
- Selected caption fonts render in export
- Missing required media blocks export before expensive processing

### 6. Repeat With Series Lite

Series Lite is part of MVP because repeatability is the subscription hook.

Minimum Series Lite:

- Series name
- Niche
- Default voice
- Default visual style
- Default caption style/font
- Default music mood/category
- Default aspect ratio
- "Create next episode"

Full calendar, batch scheduling, analytics, and auto-publishing are not part of Series Lite.

### 7. Usage, Limits, and Admin Safety

- Workspace plan tier
- Usage summary
- Limits before expensive actions
- Friendly over-limit messages
- God-mode/admin view for:
  - workspace list
  - plan tier/status
  - API usage events
  - estimated spend
  - failed provider calls
- Admin access restricted to platform/admin roles

---

## Explicitly Out Of MVP

These are valuable, but not required for first launch:

- Auto-publishing to YouTube/TikTok/Instagram
- Posting calendar
- Platform analytics pull-back
- Team roles and invites
- Client approval links
- Agency sub-workspaces
- White-label client approval workflow
- Bulk CSV publishing
- Full batch series generation
- Advanced localization workflows
- Full social account OAuth

If a feature does not improve creation, editing, export, repeatability, or cost safety, it waits.

---

## MVP Value Gates

The MVP is ready for private beta when:

- A new user can create a publishable video in under 10 minutes.
- A user can create a second episode from saved defaults in under 5 minutes.
- The exported video has working visuals, voice, captions, music, and selected font.
- Failed provider calls show friendly messages and are retryable.
- Expensive actions are gated by usage limits.
- Admin can see spend, failures, and workspace usage.
- The product does not require refresh hacks to finish generation, preview, or export.

---

## MVP Metrics

Track these from the start:

- First export completed
- Time from new project to first export
- Second video created within 7 days
- Series Lite created
- Cost per completed export
- Failed jobs per completed project
- Retry success rate
- Provider spend by workspace

---

## Build Rule

If the feature does not help a user create, trust, export, or repeat a faceless video, it is not MVP core.
