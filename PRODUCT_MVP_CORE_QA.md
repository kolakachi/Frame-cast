# Framecast MVP Core QA

## Purpose

This is the QA gate for the focused MVP described in `PRODUCT_MVP_CORE.md`.
All items must pass before private beta.

Status key:

- `[ ]` — not tested
- `[x]` — passed
- `[!]` — failed, with note
- `[-]` — skipped / not applicable, with reason

---

## M1 — Creation Flow

| # | Test | Expected Result | Status |
|---|---|---|---|
| M1.1 | New user starts from dashboard primary CTA | Create flow opens without confusion | `[x]` |
| M1.2 | Pick a listed niche | Niche is saved into project setup | `[x]` |
| M1.3 | Enter a custom niche | Custom niche is accepted and carried into generation prompts | `[x]` |
| M1.4 | Create from script/idea text | Generation starts and shows phase progress | `[x]` |
| M1.5 | Create from uploaded images | Each selected image becomes usable scene media | `[x]` |
| M1.6 | Create with no uploaded images and AI Image selected | AI visuals are generated or fail gracefully with rewrite guidance | `[x]` |
| M1.7 | Refresh during generation | Progress resumes; app does not get stuck on stale phase | `[x]` |

---

## M2 — Generation Reliability

| # | Test | Expected Result | Status |
|---|---|---|---|
| M2.1 | Generate a 5-scene project | Script, scenes, visuals, voice, captions, and music complete | `[ ]` |
| M2.2 | Generate a 15-scene project | No timeout; any long-running phase reports status | `[ ]` |
| M2.3 | OpenAI policy refusal occurs | User sees friendly message, not raw provider JSON | `[ ]` |
| M2.4 | OpenAI quota/rate limit occurs | User sees clear product message, not raw `429` | `[ ]` |
| M2.5 | One scene voice generation fails | Failed scene can be retried without regenerating all voices | `[ ]` |
| M2.6 | One scene image generation fails | Failed scene can be retried or replaced manually | `[ ]` |

---

## M3 — Editor

| # | Test | Expected Result | Status |
|---|---|---|---|
| M3.1 | Open generated project in editor | All scenes appear with script, media, and status | `[x]` |
| M3.2 | Edit scene script | Autosave completes and persists after refresh | `[x]` |
| M3.3 | Swap visual | New visual appears and old visual is not shown stale | `[x]` |
| M3.4 | Generate AI image for a scene | Image loads without media-unavailable errors | `[x]` |
| M3.5 | Regenerate voice for a scene | New audio plays and stale generating state clears | `[x]` |
| M3.6 | Change caption settings | Caption on/off, style, font, highlight, and position persist | `[x]` |
| M3.7 | Change caption settings | Voice is not regenerated unless user explicitly requests voice regeneration | `[x]` |
| M3.8 | Change music | Music selection persists and selected state remains visible | `[x]` |

---

## M4 — Preview

| # | Test | Expected Result | Status |
|---|---|---|---|
| M4.1 | Play scene preview | Active scene plays with media, voice, captions, and music where available | `[x]` |
| M4.2 | Play full-video preview | Preview advances scenes in order | `[x]` |
| M4.3 | Preview with background music | Music is audible under voice | `[x]` |
| M4.4 | Preview while media is still loading | Captions do not show over blank/missing media | `[x]` |
| M4.5 | Preview after refresh | Scene/full-video playback still works | `[x]` |

---

## M5 — Captions And Fonts

| # | Test | Expected Result | Status |
|---|---|---|---|
| M5.1 | Select `Luckiest Guy` and export | Exported MP4 uses `Luckiest Guy`, not fallback font | `[ ]` |
| M5.2 | Select one font from each font category and export | Each selected font renders correctly | `[ ]` |
| M5.3 | Disable captions and export | No captions appear in output | `[ ]` |
| M5.4 | Change caption preset/style and export | Output matches editor preview closely enough to trust | `[ ]` |
| M5.5 | Export with highlighted words | Highlight color/style appears in output | `[ ]` |

---

## M6 — Music

| # | Test | Expected Result | Status |
|---|---|---|---|
| M6.1 | Select a music category/track | Selection persists and is visible even if list scrolls | `[ ]` |
| M6.2 | Play selected music in editor | Music preview is audible | `[ ]` |
| M6.3 | Export with selected background music | Music spans full video at background volume | `[ ]` |
| M6.4 | Export without selected music | Video exports without background music and without failure | `[ ]` |

---

## M7 — Export

| # | Test | Expected Result | Status |
|---|---|---|---|
| M7.1 | Export completed 5-scene project | Export completes; MP4 downloadable/playable | `[ ]` |
| M7.2 | Export 15+ scene project | Export completes without timeout | `[ ]` |
| M7.3 | Export with missing voice asset | Export is blocked before processing with clear message | `[ ]` |
| M7.4 | Export with missing visual asset | Export is blocked before processing with clear message | `[ ]` |
| M7.5 | Observe export progress | User sees queued, processing, percentage, completed/failed | `[ ]` |
| M7.6 | Force export failure | Export status becomes failed with human-readable reason | `[ ]` |
| M7.7 | Retry failed export | Export completes without duplicate output assets | `[ ]` |
| M7.8 | Export 9:16 | Output dimensions are 1080x1920 | `[ ]` |
| M7.9 | Export 16:9 | Output dimensions are 1920x1080 | `[ ]` |
| M7.10 | Check temp export files after complete/failure | `/tmp/framecast-export-*` files are cleaned up | `[ ]` |

---

## M8 — Series Lite

| # | Test | Expected Result | Status |
|---|---|---|---|
| M8.1 | Create a series | Series saves name, niche, voice, visual style, caption style, music, and aspect ratio | `[ ]` |
| M8.2 | Click "Create next episode" | Wizard opens with series defaults pre-filled | `[ ]` |
| M8.3 | Generate episode from series | Project is linked to series and uses defaults | `[ ]` |
| M8.4 | Create second episode | Episode number/order increments correctly | `[ ]` |
| M8.5 | Edit series defaults | New episodes use updated defaults; existing episodes remain stable | `[ ]` |

---

## M9 — Usage Limits And Admin Safety

| # | Test | Expected Result | Status |
|---|---|---|---|
| M9.1 | New workspace is created | Workspace has default plan and usage summary | `[ ]` |
| M9.2 | Workspace hits export limit | Action is blocked with plan name, usage, limit, and upgrade path | `[ ]` |
| M9.3 | Workspace hits AI/TTS budget | Expensive provider action is blocked before request is made | `[ ]` |
| M9.4 | Open god-mode as admin | Workspace list, plan, usage, estimated spend, and failures are visible | `[ ]` |
| M9.5 | Open god-mode as regular user | Route returns 403 and nav item is hidden | `[ ]` |
| M9.6 | Provider call succeeds | API usage event records operation, provider, model, and estimated cost | `[ ]` |
| M9.7 | Provider call fails | API usage event records failure without raw user-facing error | `[ ]` |

---

## M10 — Security And Media Access

| # | Test | Expected Result | Status |
|---|---|---|---|
| M10.1 | Request project from another workspace by ID | 404 or forbidden; no data leakage | `[ ]` |
| M10.2 | Request asset from another workspace by ID | 404 or forbidden; no data leakage | `[ ]` |
| M10.3 | Request signed media URL | URL works during signed window | `[ ]` |
| M10.4 | Use expired signed media URL | URL no longer serves media | `[ ]` |
| M10.5 | Asset stored outside configured bucket | Product-safe error appears; no raw bucket/provider internals shown | `[ ]` |

---

## M11 — Private Beta Exit Gate

All must be true:

- [ ] First publishable video completed in under 10 minutes
- [ ] Second episode from saved defaults completed in under 5 minutes
- [ ] Exported MP4 has visuals, voice, captions, selected font, and music
- [ ] Failed generation/export paths are retryable
- [ ] No refresh hacks required to finish generation, preview, or export
- [ ] Admin can see workspace usage, spend, and failures
- [ ] Cost per completed export is visible or calculable
- [ ] Core flow passes on the production-like Docker stack
