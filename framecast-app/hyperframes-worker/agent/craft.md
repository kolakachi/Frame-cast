# WyvStudio craft rules (every build)

These rules apply whatever the style. A style pack adds character on top; it never overrides these.

## Story and reads
- Hook in the first 2 seconds: something moves or changes in the first frame. No slow logo reveal before anything happens.
- A payoff every 3 to 5 seconds: each beat has one event, something that is different at its end from its start.
- One read at a time. A read is one thing the viewer must understand (a word, a card landing, a reaction). Give each read time to be found and understood before the next starts; never start two important reads at once.
- Fast actions, slow meanings: a move can be quick, but hold what it means long enough to register.
- End on the offer and let it hold at least 1.5 s. Echo the opening in the ending (same shape, place or motif, changed).

## Text on screen
- Never more than 8 words on screen at once, except a UI card's own labels.
- Each headline-sized text block stays fully on screen for its length at about 17 characters a second, plus 1 s, and never less than 1 s. The check measures this (reading_time).
- One accent per line at most (colour, italic serif or weight), on the word that carries the meaning.
- Keep text out of the bottom caption zone and at least 6% from every edge.

## Motion
- Motion is the medium, not a slideshow: fading in, sitting still and fading out reads as slides. Overlap entrances and exits, stagger siblings by 2 to 4 frames, and keep something moving during holds (a slow drift, scale or counter).
- Never use linear or default easing. Entrances ease out (for example power3.out or expo.out); exits ease in; A-to-B moves ease in and out. Across a cut, ease in to the exit and ease out of the reveal so objects are fastest at the cut.
- Things with weight settle: a small overshoot on UI and playful elements (back.out(1.4)), none on large type.
- Moves slower than about 1 pixel per frame stutter. Move further, move faster, or carry the hold with opacity, blur, colour or a counter instead.
- Change a thing's state instead of replacing it where you can: one card that grows into the next idea reads better than a new card fading in.
- No twinning: offset the timing of repeated elements; never animate a row of items in perfect unison.
- Use the motion kit, loaded after GSAP as `<script src="wyv-motion.js"></script>`, rather than hand-rolling motion. It adds tweens to your timeline `tl` (at = seconds):
  - Eases: `WM.ease.snappy` (UI), `WM.ease.default` (cards, camera), `WM.ease.heavy` (big type), `WM.ease.playful` (characters).
  - `WM.cursor(tl,'#cursor',[{x,y,at},{x,y,at,click:true}])`, `WM.press(tl,'#btn',at,{glow:'rgba(255,107,53,.8)'})`, `WM.type(tl,'#field',text,at,16)`, `WM.toggle(tl,'#track','#knob',at,{distance:58,on:'#FF6B35'})`, `WM.count(tl,'#n',0,to,at,1.4,fmt)`.
  - `WM.morph(tl,'#shape',[{at,width,height,borderRadius,backgroundColor},...])`: one shape through states.
  - Transitions: `WM.whip(tl,'#a','#b',at)`, `WM.push(tl,'#a','#b',at,'left')`, `WM.wipe(tl,'#b',at,'up')`, `WM.leak(tl,'#leak',at)`; hide the old scene after.
  - Details and examples: read kit/motion-kit.md.

## Character (when there is one)
- The same character throughout, big enough to read (at least a quarter of the frame height in its beats).
- Pose changes are acted: a small anticipation, the change, a slight overshoot and settle. Never swap poses between two frames with nothing in between.
- Cause, then reaction: the character reacts after the thing it reacts to, not at the same moment.

## Layout and colour
- One focal point per beat. Subject never tiny or pushed against an edge.
- Never colour on the same colour (red text on red). Text contrast at least 4.5:1, large display text at least 3:1.
- At most two background colours in the whole video plus near-black and near-white, unless the style pack says otherwise.
- Change the composition between beats: scale, position or framing should differ, so the video does not sit in one layout for its whole length.

## Sound (when there is audio)
- A sound on every visible action that has one: card landings, clicks, cuts.
- The voice sits clearly above the music. Let the music breathe in one short near-silence before the biggest reveal.
- Put cuts and pops on the music's strong beats when the music has a clear pulse.

## Before finishing
- Every beat has an event; every read has time; nothing lingers from a previous beat; no blank frames in transitions.
