# From scratch: plan

Drafted 2026-10-06. Nothing here is built. Covers FS1 to FS4 on the pending sheet. Ideas only from OpenMontage
(AGPL v3) and lemo-opuscar (MIT): nothing is copied. **Decide** marks the owner's choices.

## Scope (owner, 2026-10-07)

From scratch can be **anything**, not only ads in the eight formats below: a motion-graphics promo like the WyvStudio
launch video built live on 2026-10-07 (kinetic type, orange accents, voiceover, music), an explainer, a product
story. What decides the result is the **toolset** the builder has to work with. Variety comes from concepts and
structures (FS1, FS2) *and* from enough tools: the art library (icons, 3D objects), the registry blocks, brand kits,
device stages, generated images and clips, cutouts, captions, sound. Each new tool should widen what a from-scratch
video can be, not add another card style.

## Ideas from lemo-opuscar (MIT, studied 2026-10-07)

A library of 43 film styles for Claude Code (long, art-led films; its tooling duplicates ours). Ideas to take, written
in our own words:

- **Variation space (FS1):** every style lists three structures, three openings and three endings far from its
  example. Directions are built from different structure × opening × ending combinations, so the three cards really
  differ. The planner also does this internally when the user skips the cards: three candidate structures, pick one
  with a reason ("the first idea is usually the cliché"), then a shot list with the **why** of each shot.
- **Use-case tables (FS2):** "information order | hold per layer | length" for each use (spec walkthrough, setup
  guide, launch teaser). This is the playbook skeleton.
- **Slideshow signals (FS3):** at least four different camera moves and real framing changes; one signature shot;
  transitions made inside the medium, not default fades; one acceleration and one held breath; the subject filling at
  least a third of the frame at key moments.
- **Rules apart from the example (FS4):** a style's rules live apart from its one worked example, and a new video
  must differ from the example in at least four of six dimensions (structure, opening, signature shot, camera path,
  score shape, ending). The same test can keep a customer's videos from repeating each other.
- **Concept prompt (FS1):** "find the invisible thing the product does and make it visible."
- **Style cards:** about 12 of its styles suit brands (dark keynote, glass product, living screencast, hologram HUD,
  mid-century toon, iso infographic, data viz, Swiss motion, whiteboard, microgame, halftone dossier, game show). Write
  our own cards from them for the style picker.

## The problem

With a reference, Create has a study to follow: moments, systems, pacing, captions and sound. Without one, the
planner invents structure from the brief alone. The result tends to be a slideshow: a card of text per beat, the same
layout repeated, little that a viewer would stop for. A from-scratch brief needs three things a reference gives for
free: a concept, a proven structure for its format, and examples of what good looks like.

## FS1. Direction cards before the plan

- **When:** a new brief with no reference video whose brief does not already state a concept. A skip, or "you choose",
  plans the strongest one.
- **What:** three short directions, side by side as cards. Each has:
  - a name;
  - the idea in one line;
  - the hook (the first 2 seconds);
  - the look (palette, type, motion feel);
  - the format it follows.

  They must be genuinely different (for example a demo-led ad, a story, a bold kinetic statement).
- **Then:** the user picks one (or says "mix 1 and 3"), and the planner plans that direction in full. Changing
  direction later is a re-plan.
- **Cost:** one Sonnet call, about 2 to 5 credits, counted in planning.
- **Done when:** a vague from-scratch brief gets three distinct directions in under 20 seconds, and the plan follows the
  one picked.

## FS2. Format playbooks

- **What:** one short guide per format, for the planner and the builder:
  - UGC ad, product demo, explainer, launch promo, testimonial, listicle, before/after, tutorial;
  - for each: its structure (beats and their jobs), timing per beat, must-haves (a hook in 2 s, proof, one call to
    action), common failures, and which of our systems fit (kinetic type, device stage, presenter, generated world).
- **Where they come from:** written by us from the exemplar studies (FS4) and our own runs, never copied.
- **Used:** the plan names its format; the planner gets that playbook; the builder gets its beat guidance.
- **Cost:** free at run time (a few thousand tokens of context).
- **Done when:** each format's plan follows its playbook's beats, and the bench briefs plan with the right format.

## FS3. Slideshow-risk check before the render

- **What:** a free check on the built composition, before the final render: it flags a video that is mostly static
  cards. The signals:
  - most beats are text on a flat background;
  - the same layout repeats beat after beat;
  - long holds with nothing moving (the existing stillness check);
  - no element that carries across beats.
- **Then:** like reading time, it is sent back to the builder once, with which beats and why; the builder fixes them or
  says why each is intentional.
- **Done when:** a deliberately card-only draft is sent back, and a good motion build passes.

## FS4. Exemplar library

- **What:** 15 to 25 videos you consider the standard, across formats, each studied once with the reference study
  (moments, systems, pacing, captions, sound). Kept privately as our library, never shown to users or copied into
  output.
- **Used:** for a from-scratch brief, the closest exemplars by format and video type are given to the planner as
  inspiration (structure, rhythm, techniques), the way a reference is, but with "inspired" rules: develop a distinct
  execution, never reproduce.
- **Cost:** about $0.45 each to study, once (about $10 for 25).
- **Blocked on:** the videos, and your confirmation that we may study them internally.

## Order

1. **FS3** first: no dependencies, free, and it catches the worst outcome.
2. **FS1**: the biggest single change to from-scratch quality, and small.
3. **FS2**: start with the 3 formats used most (UGC ad, product demo, explainer), then the rest.
4. **FS4**: when the videos arrive; it improves FS2 and FS1.

## Decide

- **FS1:** directions for every from-scratch brief, or only when the brief has no concept? Recommended: only when it
  has none, and always skippable.
- **FS1:** each card with a small preview frame (one image each, about 10 credits more), or text only? Recommended:
  text only at first.
- **FS4:** send 15 to 25 exemplar videos, with the formats you care about most.
