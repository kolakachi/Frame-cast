# WyvStudio craft rules (every build)

These rules apply whatever the style. A style pack adds character on top; it never overrides these.

## Story and reads
- Hook in the first 2 seconds: something moves or changes in the first frame. No slow logo reveal before anything happens.
- A payoff every 3 to 5 seconds: each beat has one event, something that is different at its end from its start.
- One read at a time. A read is one thing the viewer must understand (a word, a card landing, a reaction). Give each read time to be found and understood before the next starts; never start two important reads at once.
- Fast actions, slow meanings: a move can be quick, but hold what it means long enough to register.
- End on the offer and let it hold at least 2 s, in the hook's words. Echo the opening in the ending (same shape, place or motif, changed).

## Text on screen
- Never more than 8 words on screen at once, except a UI card's own labels.
- Each headline-sized text block stays fully on screen for its length at about 17 characters a second, plus 1 s, and never less than 1 s. The check measures this (reading_time).
- One accent per line at most (colour, italic serif or weight), on the word that carries the meaning.
- Keep text out of the bottom caption zone and at least 6% from every edge.

## Motion
- The pinned cards carry the motion law (seams: the vector law and the seam catalogue; principles: easing, duration, stagger, the 1/3 rules, beat sync). Build to them; a slideshow of fades fails review.
- Use the motion kit, loaded after GSAP as `<script src="wyv-motion.js"></script>`, rather than hand-rolling motion. It adds tweens to your timeline `tl` (at = seconds):
  - Eases: `WM.ease.snappy` (UI), `WM.ease.default` (cards, camera), `WM.ease.heavy` (big type), `WM.ease.playful` (characters).
  - `WM.cursor(tl,'#cursor',[{x,y,at},{x,y,at,click:true}])`, `WM.press(tl,'#btn',at,{glow:'rgba(255,107,53,.8)'})`, `WM.type(tl,'#field',text,at,16)`, `WM.toggle(tl,'#track','#knob',at,{distance:58,on:'#FF6B35'})`, `WM.count(tl,'#num',0,1200,at,1.4,fmt)` (approved numbers only).
  - `WM.morph(tl,'#shape',[{at,width,height,borderRadius,backgroundColor},...])`: one shape through states.
  - Transitions: `WM.whip(tl,'#a','#b',at)`, `WM.push(tl,'#a','#b',at,'left')`, `WM.wipe(tl,'#b',at,'up')`, `WM.leak(tl,'#leak',at)`; signature moves `WM.giantWipe`, `WM.stamp`, `WM.field`; hide the old scene after. Details: read kit/motion-kit.md.
  - A build with a character uses the kit for more than eases: `WM.ease.playful` on every pose change, a transition recipe between beats, and press or cursor on any UI it points at.
- Before hand-building a named visual (device frame, captions, CTA, counter, chart, chat UI, transition, texture, mascot), search the registry with the catalog action and wire the item (kit/registry.md).

## Character (when there is one)
- The same character throughout, big enough to read: a narrator is framed as a bust at about half the frame height, cropped at the chest and bleeding off the bottom edge like a presenter, never a small full figure standing in empty space. Never under a third of the frame in any beat.
- In landscape, use a two-column grid: the character in one column, the headline or UI in the other; in portrait, the character takes the lower half and the UI or headline the upper, overlapping on purpose.
- Pose changes are acted: a small anticipation, the change, a slight overshoot and settle. Never swap poses between two frames with nothing in between.
- Cause, then reaction: the character reacts after the thing it reacts to, not at the same moment.

## Layout and colour
- One focal point per beat. Subject never tiny or pushed against an edge.
- Never colour on the same colour (red text on red). Text contrast at least 4.5:1, large display text at least 3:1.
- At most two background colours in the whole video plus near-black and near-white, unless the style pack says otherwise.
- Change the composition between beats: scale, position or framing should differ, so the video does not sit in one layout for its whole length. Flip the colour field on at least one key beat.
- Every video has one signature move the viewer remembers (the plan names it: a giant-type wipe, a stamp, a morph, a field flip on the hit). Build it with the kit and land it on its beat.

## Sound (when there is audio)
- A sound on every visible action that has one: card landings, clicks, cuts.
- The voice sits clearly above the music. Let the music breathe in one short near-silence before the biggest reveal.
- Put cuts and pops on the music's strong beats when the music has a clear pulse.

## The presenter performs (when a talking shot or character poses exist)
- A talking take (the whole narration) or talking shot (the hook line) is the A-roll: the character speaks on camera at hero scale while the words are heard, its own audio as the voice track for its span (do not also play the narration file over it); never leave it as a muted clip or a still. B-roll beats (UI cards, the browser, the formats) cut in beside or over the A-roll and return to it; the close is A-roll.
- Between spoken lines the character reacts with the poses: a cut to the surprised, pointing or grinning pose on the beat, with a small settle (scale 1.04 to 1, a 2 to 3 degree tilt) so a still never sits dead. Every beat the character is on screen, something about them changes.
- Declare composition variables fully: each `data-composition-variables` entry needs `id`, `label`, `type` and `default`.

## Before finishing
- Every beat has an event; every read has time; nothing lingers from a previous beat; no blank frames in transitions.

## Close to the metal
- The fixed tools cover most builds. When they do not (a sprite sheet, a generated texture, a custom ffmpeg chain, a computed data file), write a script to work/<name>.mjs and run it, or run ffmpeg directly; put results in project/ under new names and use them like any asset.
