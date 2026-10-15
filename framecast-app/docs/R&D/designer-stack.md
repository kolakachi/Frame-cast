# designer-stack review (2026-10-15)

Folder: ~/Downloads/designer-stack (8 Claude skills; MIT, © 2026 Bles Software; tastegate's references adapted from
impeccable, Apache-2.0, and taste-skill, MIT; bundled fonts are OFL). Looks studied from named people's posts: ideas only.

**Verdict: a little better, not much.** Its core ideas (hook on frame 0, one carrier shape, springs, frame review,
contrast and overlap checks, taste rules) are already in Create. What it adds mostly catches faults we cannot see today.

## Worth taking (in order)
1. **Stutter and freeze check on the finished file** (smooth-web-video): unique frames per second, the worst hitch and a
   frozen tail measured on the encoded MP4 (ffmpeg mpdecimate), plus "opens on content" on frame 0. We check stillness on
   the page, not the file. final-checks.mjs, advisory at first. S. (RD-44)
2. **Hook and copy cliché lint** (story-hooks, tastegate): "Introducing", "Unlock the power of", "Game changer",
   placeholder names (Acme, John Doe, example.com) refused in the plan and flagged on screen. S. (RD-45)
3. **Design-tool selection move** (showreel-motion): a selection box with handles, a W×H label and a cursor click; our
   own code in wyv-motion.js. M. (RD-46)

## Skip or already have
- motion-story: covered (frame 1, carriers, reading time, contact sheet, critic).
- promo-motion: its own canvas engine; looks copied from named creators.
- visual-qa: covered by text_overlap and the garbled-lettering check.
- tastegate: duplicates the taste rules (RD-12, RD-42); its page gate is for websites. Its "signature detail" line can be an extra arm of the RD-12 A/B.
- figma-bridge: not video. The showreel's score in code: RD-22, skipped.
