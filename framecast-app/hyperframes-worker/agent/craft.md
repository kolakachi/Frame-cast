# WyvStudio craft rules (every build)

The approved user intent takes precedence over stylistic recipes. Infer the output format, not just the topic. Educational text/UI, footage edits, talking heads, slideshows, still images and animation are all valid. The guidance below is conditional: use a technique only when it serves the brief. Static holds and minimal design are legitimate; do not buy animation to satisfy this guide.

## Story and reads
- Make the opening communicate the requested purpose. A social hook may move quickly; a tutorial, interview or still image may open calmly.
- Pace ideas for the intended format and measured speech. Longer reading or demonstration holds are valid; do not force a new event every few seconds.
- One read at a time. A read is one thing the viewer must understand (a word, a card landing, a reaction). Give each read time to be found and understood before the next starts; never start two important reads at once.
- Fast actions, slow meanings: a move can be quick, but hold what it means long enough to register.
- Preserve the requested conclusion or CTA and enough time to understand it. Not every video sells an offer.

## Text on screen
- For fast social titles, aim for at most 8 words at once. Educational slides, captions and UI labels can need more; preserve requested content and allow enough reading time.
- Each headline-sized text block stays fully on screen for its length at about 17 characters a second, plus 1 s, and never less than 1 s. The check measures this (reading_time).
- One accent per line at most (colour, italic serif or weight), on the word that carries the meaning.
- Keep text out of the bottom caption zone and at least 6% from every edge.

## Motion
- Motion cards and kits are optional for animated scenes. Apply their techniques when requested; simple cuts, fades, stable footage and intentional still slides do not fail review for being simple.
- When useful, the motion kit is loaded after GSAP as `<script src="wyv-motion.js"></script>`, rather than hand-rolling motion. It adds tweens to your timeline `tl` (at = seconds):
  - Eases: `WM.ease.snappy` (UI), `WM.ease.default` (cards, camera), `WM.ease.heavy` (big type), `WM.ease.playful` (characters).
  - `WM.cursor(tl,'#cursor',[{x,y,at},{x,y,at,click:true}])`, `WM.press(tl,'#btn',at,{glow:'rgba(255,107,53,.8)'})`, `WM.type(tl,'#field',text,at,16)`, `WM.toggle(tl,'#track','#knob',at,{distance:58,on:'#FF6B35'})`, `WM.count(tl,'#num',0,1200,at,1.4,fmt)` (approved numbers only).
  - `WM.morph(tl,'#shape',[{at,width,height,borderRadius,backgroundColor},...])`: one shape through states.
  - Transitions: `WM.whip(tl,'#a','#b',at)`, `WM.push(tl,'#a','#b',at,'left')`, `WM.wipe(tl,'#b',at,'up')`, `WM.leak(tl,'#leak',at)`; signature moves `WM.giantWipe`, `WM.stamp`, `WM.field`; hide the old scene after. Details: read kit/motion-kit.md.
  - For an explicitly animated illustrated character, optional kit techniques include: `WM.ease.playful` on every pose change, a transition recipe between beats, and press or cursor on any UI it points at.
- Before hand-building a named visual (device frame, captions, CTA, counter, chart, chat UI, transition, texture, mascot), search the registry with the catalog action and wire the item (kit/registry.md).

## Character (when there is one)
- Keep the same approved character throughout, at a readable scale. Choose bust, full-body or inset framing from the brief and reference; walking and hand gestures need the relevant body parts visible. No universal bust framing or minimum screen fraction overrides the approved design.
- Choose the layout from the approved design; two columns or a presenter below the UI are options, not compulsory templates.
- Animate pose transitions when the brief asks for performed motion; deliberate image cuts or held illustrations remain valid when that is the chosen format.
- Cause, then reaction: the character reacts after the thing it reacts to, not at the same moment.

## Layout and colour
- One focal point per beat. Subject never tiny or pushed against an edge.
- Never colour on the same colour (red text on red). Text contrast at least 4.5:1, large display text at least 3:1.
- Choose a coherent palette for the approved treatment; there is no fixed two-background-colour quota. Keep explicit brand locks, follow user overrides of saved defaults, and vary background balance and accent coverage intentionally. Do not default unrelated customer videos to WyvStudio orange and dark panels.
- Change composition when it aids comprehension or follows the reference; stable framing is appropriate for interviews, demonstrations or still slides. A colour-field change is one option when it suits the approved direction; never force it into every video.
- A signature move is optional. Use it only when the approved direction calls for one; do not invent it for a footage edit or restrained explainer.

## Transitions and teaching
- Each beat's transition_out says what already on screen becomes the next scene and with which move: build exactly that (the named move is checked); a cut only where the plan says cut.
- A teaching video (scenes carry arc): open on the hook's question, show before naming, one new idea per beat, and end on an image that answers the opening question. On-screen text adds to the narration; it never repeats a spoken line word for word.

## Sound (when there is audio)
- Motion-kit moves bring their own effects (kit/motion-kit.md, Sound); keep them unless the brief or reference is silent (then `data-sounds="off"`). Neither every cut nor every text reveal needs a sound: words, writeOn and rise are silent, and moves closer than 0.25 s share one.
- The voice sits clearly above any music. A short near-silence can emphasize a reveal when that suits the requested style; it is not mandatory for educational or source-footage edits.
- Put cuts and pops on the music's strong beats when the music has a clear pulse.

## The presenter performs (when a talking shot or character poses exist)
- A talking take (the whole narration) or talking shot (the hook line) is the A-roll: the character speaks on camera at hero scale while the words are heard, its own audio as the voice track for its span (do not also play the narration file over it); never leave it as a muted clip or a still. B-roll beats (UI cards, the browser, the formats) cut in beside or over the A-roll and return to it; the close is A-roll.
- Pose cuts and image transforms can support a deliberately illustrated sequence, but do not satisfy a request for blinking, facial performance, continuous gestures or speech. Use actual performance footage or prepared articulated artwork. For approved layered SVG mascots, read kit/mascot.md and use wyv-mascot.js for timed blinks, gaze, head tilt and mouth expressions. Flat images cannot be automatically rigged; missing layers/performance are an unmet requirement. Do not fake lip-sync with random mouth changes. Deliberate holds are allowed when the brief calls for them.
- Declare composition variables fully: each `data-composition-variables` entry needs `id`, `label`, `type` and `default`.

## Generated shots and UGC takes (when the plan bought them)
- Each generated_shot is made for its beat and slot (plan media lists its beat, seconds, aspect and engine): place it there as a timed clip, object-fit cover, full length of its beat; text, captions and UI go over or beside it, never baked in. Its own sound is ambience: keep it low under narration (about 0.25 volume), or mute it when the beat has its own sound design; a shot with a spoken line plays that line at full volume.
- A ugc_take is the voice track and the A-roll, like a talking take: one clip from start to end (never re-cut its words), audio at full volume, no narration file over it; narrationTiming comes from its own words. B-roll and UI cut in beside or over it.
- Mixed layouts: keep the take playing and reshape it with WM.layout(tl, '#take', at, 'top' | 'bottom' | 'left' | 'right' | 'pip' | 'full') while the other region carries motion graphics or a generated shot; change layouts on the take's line breaks, and use duration 0 for a hard switch on a cut. The region beside it is a full composed panel (its own field colour), not leftover space.
- The real app on an in-world screen (a generated shot with screen: true): run the media op screen on the clip. If it is found and stable (confidence 0.8 or more), build the real UI as an HTML panel the screen's shape and pin it with WM.pinToClip(panel, clip, screen.quad, {width: screen.width, height: screen.height}) while the clip fills its slot and does not move (no WM.layout on it during the pin); the UI's own motion plays inside the panel. If the screen is not found or not stable, show the UI as a framed panel beside or over the shot and report_limitation that the screen could not be tracked.
- The cast sheet's images are references for the shots, never shown in the final video unless the plan says so.
- The storyboard's panels (Panel 1, Panel 2, ... in plan media) are each generated shot's opening frame: in the storyboard stage, each generated beat shows its panel as the still, with that beat's text and overlays composed over it; in the full video the generated clip (which starts from the panel) takes its place.

## The agreement (plan.agreement)
- It is what the user approved: keep everything in preserve, swap what replace names, reinterpret only what flexible allows. Every required item must be visible or audible in the final video at a clear moment; a required item you cannot deliver is reported with report_limitation, never left out silently.

## Before finishing
- Every requested idea is present; every read has time; intentional holds remain intact. Inspect the full timeline for unintended blank intervals and missing content.

## Close to the metal
- The fixed tools cover most builds. When they do not (a sprite sheet, a generated texture, a custom ffmpeg chain, a computed data file), write a script to work/<name>.mjs and run it, or run ffmpeg directly; put results in project/ under new names and use them like any asset.
