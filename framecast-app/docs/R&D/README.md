# R&D

What we found while researching, what we decided to take, and what is still waiting. One list: this tracker, built
with the published progress page from `items.py` (`python3 build_tracker.py`). Status: **check** (said done, to be
confirmed), **next** (decided, not built), **open** (to build, not yet ordered), **idea** (worth considering, needs a
yes or no), **done**, **skipped** (decided against, reason in the note). Owner, 2026-10-15: clear this list.

## Tracker

| ID | Area | Item | From | Status | Note |
|----|------|------|------|--------|------|
| RD-01 | UGC | A shot blocked by moderation is rewritten more neutrally and retried once, instead of failing | Pixelle-Video | next | Today a decline only offers another model. |
| RD-02 | UGC | Character mix-up check: each skit clip shows the right speaker | Jellyfish | next | Pairs with face checks on skit speakers. |
| RD-08 | Create | True peak at or below -1 dBTP alongside -14 LUFS | Opus Motion Design Harness | next |  |
| RD-09 | Create | Every cut has a carrier: a plan rule and a check | Opus Motion Design Harness | next |  |
| RD-10 | Create | Focus and letter type kit: blur-in text, letters rising, rack focus, glow flash | Gap report | next |  |
| RD-12 | Create | Taste rules A/B: frontend-design, canvas-design and taste-skill as defaults | Anthropic skills, taste-skill | next | Paid test, about $5-8. |
| RD-03 | UGC | Cast sheet: every person, place and prop gets a reference and shots name them (@name); a ready check before any paid shot | Toonflow, huobao | open | Casting, dressing and the shared place photo are done; the sheet and @name are not. |
| RD-06 | Create | Motion vocabulary by scene purpose (386 unused blocks), a no-slideshow check and a plan lint | awesome-ai-motion, huashu, cinetic | open |  |
| RD-07 | Create | Finishing checks: constant-speed motion, repeated technique, text under phone UI, cues present, voice over music, contact sheet | animate, claude-motion-design | open | FS3 is partly built; one-frame pops done. |
| RD-11 | Create | Explainer styles (whiteboard, kinetic type, finance chart) and a timing breakdown for 'make it like this' | huashu, student-kit | open | Never marketed as a named channel's look. |
| RD-31 | Platform | Trim old tool output in the build loop to cut cost | arXiv harness study | open |  |
| RD-33 | Platform | Review herk-edit-kit and write down which parts to take | herk-edit-kit | open | Noted as 'mine for parts' without a list. |
| RD-04 | UGC | Two-shot opener or Seedance multi-shot, if skit rooms still split | OpenMontage | idea | Only if the next skit take shows two rooms. |
| RD-13 | Create | Change requests answer every note with exactly what changed | Opus Motion Design Harness | idea |  |
| RD-14 | Create | A defect the checks missed becomes a new check, approved once | Opus Motion Design Harness | idea |  |
| RD-15 | Create | Social-native kit: comments, like burst, stitch, chat, notes app, POV | Kit planning | idea |  |
| RD-16 | Create | Product hero kit: 2.5D turn, floor reflection, spotlight, explode, before/after | Kit planning | idea |  |
| RD-17 | Create | UI morph kit: blur hand-off, drawn chart with tooltip, palette, 60 fps option | UI motion study | idea |  |
| RD-18 | Create | Data and infographic kit | Kit planning | idea |  |
| RD-19 | Create | One shared style line and several variants per image prompt | Higgsfield KICKS demo | idea |  |
| RD-20 | Create | Spinning globe and 3D product-hero helpers | cobe, drei | idea | WebGL runs in software on the worker: 1-3 beats only. |
| RD-21 | Create | Shader backgrounds ported to WebGL: gradient, aurora, glass, grain | shaders | idea | Time each render first. |
| RD-22 | Create | Original music written as code | Opus Motion Design Harness | idea |  |
| RD-23 | Create | Better beat and drop detection for the user's own music | claude-motion, animate | idea |  |
| RD-24 | Create | A small hand-licensed Lottie set with a start offset per scene | lottie-web | idea |  |
| RD-25 | Create | Premium effect recipes: tab edges, bars to line, dock falloff, glass focus, particle logo | motion-graphics-skills | idea |  |
| RD-26 | Create | Illustrated looks in code: riso print, cut paper | animate | idea |  |
| RD-27 | Create | Studio mode: a gate at every stage, for agencies | Opus Motion Design Harness | idea |  |
| RD-28 | Create | Text behind the subject: cut out the person, type between layers | Barty object separation | idea |  |
| RD-29 | Create | Math-style explainer shots | manimgx | idea | Watch until it matures and runs on ARM. |
| RD-32 | Platform | Still-ad editor: logo and headline on a generated image, export 1.91:1 and 1:1 | fabric.js | idea | A new product. |
| RD-05 | Create | Builder rules in the prompt: HyperFrames effects that break renders, and the craft rules from the motion briefs | product-launch-motion, student-kit, skills/ | done | 2026-10-15: craft.md already had the traps, one camera, hand-offs and label swaps; added deterministic frames and fonts-before-measuring. |
| RD-30 | Money | A provider call never sent settles at zero; a closed run's held credits are released | Dan rebuild | done | 2026-10-15: a call with no dispatch claim, prediction or response settles at zero; held credits were already released at clear states since 2026-10-10 (CreditHolds). |
| RD-34 | Create | Collage kit and Collage / zine style | Higgsfield KICKS demo | done | 2026-10-09 |
| RD-35 | Create | Gap report: reference moments no move reproduces | Kit planning | done | create:move-gaps |
| RD-36 | Create | Kit guide split: core kit plus specialty kits per video | Kit planning | done |  |
| RD-37 | Create | Single-frame glitch check on the finished video | claude-motion-design | done |  |
| RD-38 | Create | Energy arc, easing by material, sound matches weight, still before a cut | klik, LottieFiles, product-launch-motion | done |  |
| RD-39 | Create | Onboarding: three questions, site to brand kit, first brief ready | Higgsfield onboarding | done |  |
| RD-40 | UGC | Outfits that hold: the presenter dressed once and that picture in every clip, skit speakers too | Jellyfish | done | 2026-10-15 |
| RD-41 | UGC | Skit speakers keep one side of the frame (180-degree rule) | OpenMontage | done | 2026-10-15 |
| RD-42 | Create | Taste rules written into the builder's doctrine | Anthropic skills, taste-skill | done | A/B is RD-12. |

Notes per topic: [higgsfield-claude-motion.md](higgsfield-claude-motion.md), [kits-roadmap.md](kits-roadmap.md), [motion-skill-repos.md](motion-skill-repos.md), [opus-motion-harness.md](opus-motion-harness.md), [higgsfield-onboarding.md](higgsfield-onboarding.md), [follow-ups.md](follow-ups.md); repo licences in `docs/product/priorities.md` (Research repos we borrow from).

Run `php artisan create:move-gaps` on production after a deploy to re-rank the kit items by what users actually ask for.
