Distilled from HyperFrames hyperframes-animation and motion-vocabulary (heygen-com/hyperframes, Apache-2.0).

## Scene blueprints
- Four phases per scene: settle (0.3–0.8 s, the stage reads first), action (the beat's one event), payoff (the result lands and holds for a read), exit (the seam, cut mid-motion).
- Compose 2–4 atomic moves per scene; take a blueprint shape when the beat matches one: kinetic-type beats (the words changing are the motion), typewriter reveal into a brand payoff, spatial pan stations (a virtual camera centres each station on one big canvas), camera journey (dive, beat fires, travel to the consequence, landing push), zoom-out workspace reveal (tight on a detail, one zoom-out shows the whole), grid-card assemble, logo assemble lockup, cursor-ui demo (a cursor changes a rebuilt UI shot to shot).
- Virtual camera: one `#world` wrapper, tween only its `x/y/scale`; zoom to an off-centre target with scale outside and a counter-translate inside. One camera move per beat.

## Motion vocabulary (GSAP, to the CSS end state)
- Enter: slide `from {y:150|x:200, opacity:0, "power4.out"}`; scale_grow `from {scale:0, opacity:0}`; scale_punch `from {scale:.6, "back.out(2.2)"}`; fade_blur `from {opacity:0, filter:"blur(14px)"}`; bounce_in `from {y:-120, "bounce.out"}`; slam `from {y:-300, "power4.out"}` + a 2-frame shake; word_reveal per word `from {opacity:0, y:24}` stagger .08; wave per letter .04; typewriter `WM.type`.
- Emphasis on a beat: scale_pulse `to {scale:1.12, yoyo:true, repeat:1}`; shake x −9/9/0; glow (textShadow yoyo); colour shift to the accent; underline_sweep `scaleX 0→1` from the left; hold_breath `scale 1.015`.
- Exit: fade_out .4 s power2.in; slide_out off the nearest edge; scale_out `{scale:1.06, opacity:0}`.
- Morph and react: card morph (`WM.morph`); scale-swap (exit shrinks as the entrant grows at one centre); reactive displacement (the entrant's tween pushes the exiting element); `WM.press`; click ripple (`WM.cursor` click + a ring `scale 0→2.5, opacity 1→0`, .5 s); spring-pop `scale 0→1, WM.ease.playful`; waterfall entry (each item starts before the previous settles); nudge curve (slide 10/65/25 %: power3.in, linear, power4.out); blur streak (peaks at max speed).
- Draw and reveal: SVG dasharray = dashoffset = `getTotalLength()`, tween offset to 0; clip-path `inset(0 100% 0 0)→inset(0)`; font axes via `fontVariationSettings`; counters `WM.count`.

## Lottie in this sandbox
- lottie_light.min.js is served at the project root. Pattern (the registry's lottie-character-walk): `lottie.loadAnimation({container, renderer:"svg", loop:false|true, autoplay:false, path:"assets/x.json"})`, then `window.__hfLottie.push(anim)`; the runtime seeks every player to composition time (a loop cycles modulo its length), so the character walks, stops and points on seek; time the GSAP stage to the Lottie's moments. Fixed container size.

## Determinism
- One paused `gsap.timeline` on `window.__timelines["<id>"]`; every move is a tween on it. No CSS `transition`/`animation` on timed elements; no `Date`, `performance.now`, `Math.random` (derive from an index); no `repeat:-1`.
- `fromTo` with explicit from-states so t=0 and backward seeks are right. Tween `x, y, scale, rotation, opacity`, colours, `borderRadius`; never `width/height/top/left`. Staggers under ~0.5 s.
- Positions once at setup after `document.fonts.ready`, never `getBoundingClientRect` inside a tween; no page-load `gsap.set` on later-scene elements.
