# Higgsfield "Claude Motion" and a UI motion study (seen on X, 2026-10-09)

## KICKS (Higgsfield × Claude Motion, 15 s, 4K)
Split screen: Claude writes `kicks.py` against a vocabulary of named moves (`film.at(65).tear(RED, strips=3)`,
`.poster(...).flip3d`, `.sticker("FAST LANE", rotate=-6)`, `.unbox(girl, sunburst=True, copies=4)`,
`.pop(mascot, confetti=24)`, `.cards(variants, every=2, tilt=6)`), generating every picture with one shared `{STYLE}`
string and several variants per prompt. 15 scenes in 15 s, live preview with a scene strip.

- **Confirms our approach**: a named move vocabulary the model writes against is our motion kit.
- **Taken**: the whole collage look as our collage kit and the Collage / zine style (done locally 2026-10-09; test clip
  `scripts/collage-fixture.mjs`).
- **Not yet**: one shared style line for every bought picture, and several variants per prompt for flicker montages
  (cheap stills, one campaign look). Tracker #12.

## UI motion study (14 s, 60 fps)
One black shape never cuts: Generate button → spinner → now-playing pill → player → volume → toggle → Day/Week/Month →
chart card (number roll, line drawing with a tooltip) → command palette with typing → toast → back. A cursor drives
each change; old contents blur out as new ones blur in while the shape resizes; motion blur between states.

- We have most of it (`WM.morph`, cursor, type, count, toggle).
- **Missing**: blur hand-off of contents inside a morphing shape; a drawn line chart with a following tooltip; a 60 fps
  render option for product demos. Tracker #10.
