Distilled from HyperFrames hyperframes-creative motion-principles and hyperframes-animation rules (heygen-com/hyperframes, Apache-2.0) and iart-ai animation-principles, beat-sync-editing (MIT).

## Easing is the adverb
- Enter: ease-out (WM.ease.default for cards, WM.ease.snappy for UI, WM.ease.heavy for big type). Exit: ease-in (power3.in), faster than the entry. Move between positions: ease-in-out. WM.ease.playful (overshoot) once or twice per video, never on type.
- Linear only for continuous loops. At most 2 tweens with one ease per scene; 3+ eases and directions per scene; never every element from y:30 + opacity.
- Offset the first tween 0.1-0.3 s into a beat; t=0 reads as a jump cut.

## Duration follows size and distance
- Micro (toggle, press) 0.1-0.2 s; UI (card, panel) 0.2-0.4 s; hero (big type, full-frame) 0.4-0.8 s; camera 0.8-2 s. Scale with distance travelled.
- Entrances longer than exits (0.4 s in, 0.25 s out). The slowest beat is about 3x slower than the fastest.

## Stagger and the 1/3 rules
- Groups arrive in order of importance: 40-80 ms per item (dense grids 20-40), the whole group inside 0.5-0.7 s; beyond that, stagger by distance from a focal point.
- Distance: nothing travels more than a third of the frame without a mid keyframe or a scale/opacity change.
- Simultaneity: with 3+ elements, at most a third move at once; the rest hold or breathe.

## Weight
- Anticipation 60-120 ms before the main move (dip to 0.95 before the pop; lean before the point). Follow-through: attached parts settle 40-80 ms after the body.
- Combine transforms on entrances (x + scale + opacity); overlap entries. Organic things move on arcs.
- Settle stills: scale 1.04 to 1 with a 2-3 degree tilt, so nothing sits dead.

## Three layers, build / breathe / resolve
- Primary: the hero move. Secondary: what reacts to it (shadow, label, badge settling late). Ambient: slow life that never asks for attention, different per beat, only on things that earned motion.
- Each beat: build (first 30%, staggered entries), breathe (one ambient motion), resolve (exit or a decisive hold). Stillness before the climax is the strongest accent.

## Beat sync
- Use the grid from the beats media op (beat_seconds, bars, strong_hits): hits, cuts and pops land on strong_hits or on the beat, never between. Phrases of 2, 4 or 8 beats; every beat only at the climax.
- Arc: establish (long holds), develop (shorter), climax (1-2 beats, the biggest visual on the drop), resolve (one long hold for the lockup).
- Cut on action, mid-motion, matched direction and speed. Hard cut is the default; a dissolve says "this continues"; a whip says energy.

## Spring vs duration
- Springs for interactive feel (press, toggle, mascot); duration + ease for choreographed sequences. Both on the one paused timeline.

## Rules the renderer needs
- fromTo with explicit from-states (seek-safe both ways); absolute values, never +=; no Math.random or Date.now; finite repeats; no CSS transitions on animated elements.
- Transforms and paint only (scale/translate proxies, masks), never width/height/top/left. Ambient loops on the timeline, never bare gsap.to.
- Never two transform tweens on one element at once: one fromTo, or parent (entrance) and child (zoom). tl.set kills exiting inner elements after their fade.
- Diagnose: stiff = linear or symmetric enter; floaty = too long or soft (cut 30%); cheap = no stagger or one duration for all; mechanical = no anticipation, no arcs.
