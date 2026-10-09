# R&D

What we found while researching, what we decided to take, and what is still waiting. One note per topic; the tracker
below is the to-do. Revisit when there is room. Status: **idea** (worth considering), **next** (decided, not built),
**doing**, **done** (shipped or built), **skipped** (decided against, with the reason in its note).

## Tracker

| # | Item | From | Status | Note |
|---|------|------|--------|------|
| 1 | Collage kit (tear, sticker, cards, grade, sunburst, confetti, circle text) + Collage / zine style | Higgsfield KICKS demo | done (local, 2026-10-09) | [higgsfield-claude-motion.md](higgsfield-claude-motion.md) |
| 2 | Gap report: reference moments no move reproduces (`create:move-gaps`) | Kit planning | done (local) | [kits-roadmap.md](kits-roadmap.md) |
| 3 | Kit guide split: core kit + specialty kits attached per video | Kit planning | done (local) | [kits-roadmap.md](kits-roadmap.md) |
| 4 | Render traps in the craft guide (blend modes, fromTo, filters, label swaps…) | product-launch-motion, hyperframes-motion-reel-skill | done (local) | [motion-skill-repos.md](motion-skill-repos.md) |
| 5 | Single-frame glitch check on the finished video | howseen-ai/claude-motion-design | done (local) | [motion-skill-repos.md](motion-skill-repos.md) |
| 6 | Energy arc, easing by material, sound matches weight, still before a cut | klik, LottieFiles, product-launch-motion | done (local) | [motion-skill-repos.md](motion-skill-repos.md) |
| 7 | Focus and letter type kit (blur-in text, letters rising, rack focus, glow flash) | Gap report (local) | next | [kits-roadmap.md](kits-roadmap.md) |
| 8 | Social-native kit (comments, like burst, stitch, chat, notes app, POV) | Kit planning | idea | [kits-roadmap.md](kits-roadmap.md) |
| 9 | Product hero kit (2.5D turn, floor reflection, spotlight, explode, before/after) | Kit planning | idea | [kits-roadmap.md](kits-roadmap.md) |
| 10 | UI morph kit (blur hand-off in morph, drawn chart + tooltip, palette, 60 fps option) | UI motion study clip on X | idea | [higgsfield-claude-motion.md](higgsfield-claude-motion.md) |
| 11 | Data and infographic kit | Kit planning | idea | [kits-roadmap.md](kits-roadmap.md) |
| 12 | One shared style line + several variants per image prompt | Higgsfield KICKS demo | idea | [higgsfield-claude-motion.md](higgsfield-claude-motion.md) |
| 13 | Every cut has a carrier (plan rule + check) | Opus Motion Design Harness | next | [opus-motion-harness.md](opus-motion-harness.md) |
| 14 | True peak at or below −1 dBTP alongside −14 LUFS | Opus Motion Design Harness | next | [opus-motion-harness.md](opus-motion-harness.md) |
| 15 | Change requests answer every note with exactly what changed | Opus Motion Design Harness | idea | [opus-motion-harness.md](opus-motion-harness.md) |
| 16 | A defect the checks missed becomes a new check (approved once, runs on every video) | Opus Motion Design Harness | idea | [opus-motion-harness.md](opus-motion-harness.md) |
| 17 | Original music written as code (Strudel) | Opus Motion Design Harness | idea | [opus-motion-harness.md](opus-motion-harness.md) |
| 18 | Better beat and drop detection for the user's own music | claude-motion `beats.py`, animate `beats.mjs` | idea | [motion-skill-repos.md](motion-skill-repos.md) |
| 19 | A small hand-licensed set of Lottie animations, with a start offset per scene | lottie-web review | idea | [motion-skill-repos.md](motion-skill-repos.md) |
| 20 | Premium effect recipes (tab edges, bars to line, dock falloff, glass focus, particle logo, ghost) | charlie947/motion-graphics-skills | idea | [motion-skill-repos.md](motion-skill-repos.md) |
| 21 | Illustrated looks in code: riso print, cut paper | cth9191/animate | idea | [motion-skill-repos.md](motion-skill-repos.md) |
| 22 | Studio mode: a gate at every stage (script, style frames, transitions, animatic) for agencies | Opus Motion Design Harness | idea | [opus-motion-harness.md](opus-motion-harness.md) |
| 23 | Text behind the subject (cut out the person, type between layers) | Barty object separation | idea | [motion-skill-repos.md](motion-skill-repos.md) |
| 24 | Onboarding: three questions, site to brand kit, ready first brief; dashboard by goal | Higgsfield onboarding | done (local) | [higgsfield-onboarding.md](higgsfield-onboarding.md) |
| 25 | Settle a never-sent provider call at zero; release a closed run's held credits | Dan rebuild (2026-10-09) | next | [follow-ups.md](follow-ups.md) |

Run `php artisan create:move-gaps` on production after a deploy to re-rank the kit items by what users actually ask for.
