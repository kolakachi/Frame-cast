Distilled from HyperFrames motion-doctrine, cut-the-curve, seam-craft and oversized-cursor skills (heygen-com/hyperframes, Apache-2.0).
# Seams: one continuous move, not slides

## The vector law
- How A exits decides how B enters: same axis, same direction, matched speed, cut mid-motion on both sides. On Z the direction is the sign of scale change (growing = push, shrinking = pull); a shrinking exit met by a grow-from-small entry is the usual bug.
- Mirrored eases match speed: exit `power4.in`, entry `power4.out`, same distance and duration. The exit is still moving at the cut; the entry ignites halfway along its path, never from rest.
- One current per video (default leftward) for ordinary seams. Upward = a conclusion rises; Z push = deeper in; Z pull = something bigger arrives. No opposing consecutive seams; a change needs a cause.
- Hand a carrier across the cut at matched position and speed (a cursor mid-path, a card docking into the next layout, a word group). Never crossfade.
- Causal motion: click → squash → release → flight → impact → reveal, each starting on the causing frame.

## Seams (2 or 3 kinds per video)
- Cut-the-curve, the default: partial travel ~12% of frame (230 px at 1920), never off-screen. Exit `x:0→-230` 0.3 s `power4.in`, faded by 30% of travel; entry `x:230→0` 0.34 s `power4.out` from `autoAlpha:.35`.
- Zoom-through (text swap, deeper): all grows, one text visible. Exit scale 1→1.2, blur 0→10px, 0.2 s `power3.in`; swap at peak blur; entry 0.75→1, 0.5 s `expo.out`.
- Inverse zoom-through (payoff only): all shrinks, exit 1→0.8, entry 1.25→1, ~0.7 s; the incoming frame arrives composed.
- Waterfall cut (text to text): cut-the-curve per word, gaps shrinking ×0.84.
- Rack-focus blur-cut (the one cut meant to be seen, ≤ once per 8 s): outgoing stays opaque, blur spikes to 8–12 px, swap at the peak.
- Blur 10 px on text, 18–20 px full-frame, equal both sides; blur the wrapper, never a `<video>`. WM.whip, giantWipe, push and wipe ride the current.

## Inside a scene
- Waterfall entry (titles, lists): elements whip in from below, overlapping 1–2 frames with shrinking gaps, opacity binary via `tl.set`; anchors travel further, punctuation snaps; `power4.out`.
- Nudge curve (a group slides to make room): 10% of the distance `power3.in`, 65% linear, 25% `power4.out` over most of the time; reveal new content during the burst.
- No idle wobble: breathe, float, drift and pulse loops are banned. At any second something meaningful is mid-flight: a staged reveal, a camera move, UI life, a cursor.
- Stillness before the climax: 0.3–0.75 s between the big action and its result. One entry ≤ 0.8 s (longer = a stagger); exit ≈ 75% of entry; stagger ≤ 0.5 s total; like elements share one ease; no `bounce`/`elastic`.

## The oversized cursor
- 7% of frame width, enters from off-screen below on one vector (0.4–0.9 s `power3.out`), never fades in. Tip on the target; press scale 0.84 for 0.1 s, back over 0.22 s, `transformOrigin:'21% 14%'`, the target reacting on the same frame; every click causes the next beat. Drifts aside between its beats; exits physically.

## Stage ground
- `#root` is opaque, or mid-cut windows flash white. A clip whose `data-start` precedes its entry tween shows at rest: `autoAlpha:0`, `data-start` = the cut.

## Never
- Crossfades; exits done before the cut; entries from rest; `.inOut` across a cut; off-screen travel; ping-pong directions; late reactions; wobble.
