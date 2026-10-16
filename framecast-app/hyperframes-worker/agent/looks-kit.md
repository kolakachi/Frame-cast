# Named looks (recipes)

When the brief or the plan names one of these looks, build it this way. A look sets the palette, type and motion;
the approved plan, the brand's colours and fonts and the user's words still win. A look the user names lifts the
"avoid generated looks" rule for that look only (neon is allowed in a neon video, not everywhere). Fonts listed are
fetched for the build; the bundled faces are the fallback. Everything stays seeded and seek-safe.

## particles
Thousands of tiny dots (canvas 2D, seeded positions; never Math.random) drift in, gather into the name or shape, hold
a beat, then scatter as the camera pushes through. Dark field, one cool accent. Draw dots per frame from the
timeline's time, not from a running simulation. Font: Space Grotesk. Avoid: lens flares, rainbow trails.

## liquid
One glossy blob that morphs into the next shape (wallet, chart, tick): SVG paths morphed with WM.morph, or circles
merged by a gooey filter (blur 12 + alpha threshold). A soft top highlight and a floor shadow sell the gloss.
Off-white field, one saturated colour, no hard cuts. Font: Fredoka.

## holographic
A card, ring or product photo tilting a few degrees (WM.productTurn) with a rainbow sheen sliding across it: a
linear-gradient of 5-6 pastel hues at 110deg, mix-blend-mode overlay, masked to the object. Dark space, a reflection
(WM.floor). Real 3D only for one short beat (WebGL renders slowly here). Font: Syne.

## neon
Tube letters: the text in a thin stroke (or Monoton) with three text-shadows (2, 12, 40 px) in the tube colour on a
dark brick or charcoal wall. Each word lights with a short seeded flicker (opacity 1, .3, 1, .6, 1 over .4 s), then
holds; a faint reflection on the floor. Two tube colours at most (pink and cyan). Font: Monoton or Bebas Neue.

## wireframe
A white or cyan wireframe object drawn in 2D: a globe as rotating ellipses (meridians scaleX, latitudes fixed), a
part as SVG lines that draw on (stroke dash). Parts snap in with labels on leader lines; the camera orbits with a
slow scale and skew. Black field, one accent for highlights. Font: Space Mono.

## glass
Frosted cards over a soft blurred colour field (WM.mesh behind): background rgba(255,255,255,.14), backdrop-filter
blur(24px) saturate(140%), a 1.5 px white border at 30%, a soft shadow. Cards slide in with depth (scale .96 to 1,
y 30 to 0), the camera drifts. Inter, white text; build the real UI. One card in focus at a time.

## kinetic
Use the Kinetic type style (huge display type, one accent word per line, cuts on the beat). Font: Anton or Bebas Neue.

## isometric
Simple isometric objects drawn in code at 30 degrees (a box is three rhombi: top light, left mid, right dark), soft
pastel palette, thin dark outlines optional. A glowing item travels a path between stops while the camera glides;
a short label pops at each stop. A detailed world (a full warehouse) is a generated isometric picture with a few
parts animated over it. Font: Inter.

## clay
Soft rounded 3D objects: W3D props or the 3D mascot in the clay finish, pastel colours, a low soft shadow,
squash-and-stretch bounces (scaleY .85 on landing). Font: Fredoka.

## ascii
Everything in monospace characters on black: a terminal types the command (WM.type), ASCII art builds row by row
(a picture sampled to a character grid: dark to light ' .:-=+*#%@'), the logo resolves out of scrolling text.
Green or amber on black, a faint scanline. Font: IBM Plex Mono.

## mesh
WM.mesh fills the frame with soft colour fields that drift (3-4 colours, the first the base); the title fades or
blurs in (WM.blurIn) in a clean serif or sans and the fields settle. Calm and premium. Font: Playfair Display or Inter.

## comic
Panels with thick black borders (8-12 px) and gutters; the camera moves panel to panel (WM.camera). Halftone
shading (WM.grade halftone), speech bubbles with tails, a burst star behind sound words ("POW!", "CLICK!") stamped
on (WM.stamp). Primary colours, black outlines everywhere. Font: Bangers.

## splitflap
WM.splitFlap builds a departures board that flips to the words and numbers (approved figures only), one tick per
tile. Charcoal tiles, off-white letters, one amber or green accent for a status light. A thin glare sweeps across
the board once. Font: Bebas Neue.

## vhs
An 80s tape: scanlines (a repeating-linear-gradient overlay at 3 px), colour bleed (the text drawn twice, red and
cyan, offset 3 px), a short tracking glitch at the start (seeded), a PLAY marker and a running timecode in the
corners, chrome or gradient text. 30 fps feels right. Font: VT323 for the HUD.

## halftone
WM.grade halftone on pictures, a limited palette (black, cream and one red), big year or number labels, pages
sliding like a magazine (WM.push or WM.book). Font: Anton.

## bauhaus
Circles, squares and triangles in red, yellow and blue on cream with black; a strict grid; shapes build the
content (bars made of stacked squares, a circle that becomes the O). Hard, rhythmic moves on the beat, no
gradients, no shadows. Font: Jost or Archivo Black.

## pixel
A small pixel world on a 4-8 px grid (image-rendering: pixelated for any picture; shapes snapped to the grid),
a sprite that runs on stepped frames (registry stop-motion-cadence), coins and a level-up banner. Bright game
colours, a score HUD. Font: Press Start 2P or Silkscreen.

## blueprint
White technical line drawings on a deep blue grid (#0b3a8c with a 40 px grid at 15% white), dimension lines with
arrows and numbers, parts separating into an exploded view (WM.explode) with leader-line labels. Lines draw on
(stroke dash). Font: Space Mono.

## deco
Gold geometric lines and fan shapes that draw themselves on (stroke dash, symmetric from the centre) on black or
deep green; stepped frames, sunbursts, chevrons; the title in tall elegant caps with wide tracking. Slow,
luxurious motion. Fonts: Poiret One and Limelight.

## brutal
Chunky flat blocks in yellow, pink and black with 6-8 px black borders and hard offset shadows (8-12 px, no blur)
that slam into place (WM.stamp, WM.toss); oversized text, a marquee strip, stickers and a price burst. Snappy,
confident motion. Fonts: Archivo Black and Space Mono.
