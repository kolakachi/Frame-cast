# herk-edit-kit review (RD-33, 2026-10-15)

Repo: github.com/nateherkai/herk-edit-kit (HyperFrames-based editing kit; 789 MB, mostly example videos).

**Licence.** Only the original kit material is MIT (2026 Nate Herk). The pipeline material (cut skills, short-form-edit,
motion-showreel, style library, templates, shared tools) is under `licenses/PIPELINE-USE-PERMISSION.txt`: use, copy,
modify and share with the notice kept, but not clearly cleared for a SaaS product. **We take ideas and rules only and
write our own code.** If any code is ever copied, keep both notices. Bundled HyperFrames/GSAP/registry skills are
upstream Apache-2.0 and already ours.

## Worth taking (all ideas, our own code)
| # | Part | Where in the kit | Ours today | Effort |
|---|------|------------------|-----------|--------|
| 1 | Retake / false-start detection: transcript segments compared by word overlap, the later take kept, cuts proposed for review | `.claude/skills/cut-mistakes/scripts/find-cut-candidates.mjs` | `suggestCuts` catches fillers, short repeats and pauses only | M |
| 2 | Phrase-safe Tighten: a filler goes only with silence on both sides; cut after a word fully decays (transcripts end words 40-80 ms early); never speed up speech | `docs/EDITING-PLAYBOOK.md` (Lessons) | `tightenRanges` cuts every filler with a 0.04 s pad | S |
| 3 | Pauses that keep a breath: 0.24 s after long pauses, 0.20 s at sentence ends, 0.14 s otherwise, a little more at head and tail | `.claude/skills/cut-silences/` | a fixed 0.1 s each side | S |
| 4 | Fit music to the video length on the beat: splice sections on the grid with 12 ms crossfades, optional half-beat gap before the drop | `motion-showreel/scripts/music-grid.mjs`, `splice-music.mjs` | `beat-grid.mjs` finds the grid; no splice op | M |
| 5 | Short-form rules: 1-3 word captions that swap hard, 2 frames early, never over the mouth (first 2.5 s especially); speaker off screen at most ~3.5 s; one shot per list item; every shot moves; SFX only on visible actions, no lasers; never fade the last spoken word | `EDITING-PLAYBOOK.md`, `short-form-edit/references/quality-gates.md` | contrast, safe area and loudness checks | S |
| 6 | Checks on the finished render: re-transcribe and compare words at every cut; ±10-frame strips around cuts; each check proven to fail on a broken case | `quality-gates.md` | none | M |
| 7 | Cut-vs-kept review page (red/green timeline, biggest cuts, before/after) for "what Tighten removed" | `scripts/build-edl-review.mjs` | none | M |
| 8 | Motion recipes: easing-graph draw-on; letters to dots to grid wave; lockup resolving from noise with a block cursor | `motion-showreel/references/chapter-library.md` | partly (WM) | M each |

Best value for effort: 1-3 (they improve My Footage's Tighten). Tracked as RD-43.

## Skip
- Style library (406 cards) and scene templates: named after creators, placeholder handles and stats, CDN fonts, pipeline licence, overlaps our registry.
- `kie.mjs`, `sfx.mjs`, `transcribe-elevenlabs.mjs`: paid clients we already have equivalents for.
- `MOTION_PHILOSOPHY.md` neon/glow/chromatic looks: against craft.md.
- `assets/`, `video-projects/`, `examples/`: third-party brands and a real person's likeness; no rights granted.
- Contrast, animation-map and beat-sync validators: covered by our live checks.
- The showreel's persistent HUD: specific to that reel.
