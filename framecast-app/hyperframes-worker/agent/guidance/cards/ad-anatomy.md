Distilled from iart-ai ad-creative, testimonial, promo, short-form, caption-animation, kinetic-typography and shot-composition skills (MIT).

## Ad anatomy
- 15 s: hook 0–2.5, agitate 2.5–6, payoff 6–11 (one benefit, product as the answer), proof 11–13 (one approved number or a demo moment), CTA 13–15. 30 s: 0–3, 3–8, 8–18, 18–25, 25–30. Stretch the body, never the hook.
- The hook's trigger lands before 2 s, as on-screen text from frame one (most viewers are muted); the strongest visual is frame one, no fade-up.
- Hook shapes: problem-first, pattern interrupt, curiosity gap, immediate benefit, direct callout. Pick one; the payoff closes the loop last.
- The CTA pays off the hook in the hook's words ("Save 2 hours — try free", not "Shop now"): one action, held still at least 2 s.
- Variants change one thing (hook, offer or CTA), everything else held. Hook, product and CTA live inside the centre square so every crop keeps them.

## Testimonial / UGC proof
- Trust before motion: lines reveal in reading order (stagger 60–100 ms, one enter move, `WM.ease.heavy`), quote verbatim, emphasise at most one phrase after its line settles (highlight sweep, weight shift or accent).
- Stars fill left to right to the real score, after the words. Ratings, counts and names only when approved; a real avatar or none.
- The author block signs off last and holds still: name loudest, role and company lighter.

## Offer reveal
- Brand + occasion 0–1.5 s; the discount lands 1.5–4 (loudest thing on screen); was→now 4–7: strike the old price first, then `WM.count` the new one in with a small overshoot; product under it; code and deadline 7–9 held still; CTA last.
- One offer, one code, one CTA. Numbers from approved facts only; urgency is a real deadline, never a flashing fake timer.

## Short-form (9:16)
- One idea per video. A visual change every 2–4 s at uneven intervals: hard cut, punch-in (scale 1→1.12 on `#stage`), `WM.field`, caption pop, b-roll, `WM.stamp`.
- Hook text and subject inside the centre 900×1400 of 1080×1920; top 120 px, bottom 320 px and right 120 px belong to the platform. Match last frame to first when a loop fits.
- Captions carry the content: 1–4 words a page, word-timed from the transcript action (never evenly split), bold sans 56–80 px with a 2–6 px stroke or shadow, in the band 62–70 % down, one accent for the live word. Registry: caption-highlight, caption-pill-karaoke, caption-*.

## Kinetic typography
- Static type first: display line-height 1.1, tracking −0.02 em, two families at most.
- Split by line (calm), word (energetic) or character (playful, short strings). Mask reveal is the workhorse: parent `overflow:hidden`, child `y:"110%"→0`, `WM.ease.heavy`, 0.4–0.6 s; stagger lines 60–100 ms, words 40–70, characters 20–40; total under 0.8 s.
- Weight shift: tween `fontVariationSettings` "wght" 300→800 (Inter, Space Grotesk). One-word slam: `from {y:-300, ease:"power4.out"}` + a 2-frame shake on a strong beat. Registry: kinetic-center-build, kinetic-type-swap.

## Composition for vertical
- One focal point on a thirds power point in the upper-middle third; hierarchy by size, then contrast, colour, position. 8 px rhythm, 100 px margins, negative space around the hero, caption band clear.
- New content enters from the right or below, reverse to go back; exit through the nearest edge, ease-in.
