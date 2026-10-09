# Kits roadmap (2026-10-09)

A kit = named moves in `wyv-motion.js`, a test clip that proves them, a style pack when it is a look, and a line that
tells the planner when to use it. Specialty kits are attached only to the videos that use them
(`hyperframes-worker/agent/*-kit.md`), so the builder carries the core kit plus at most what it needs.

## Built
- **Documents**: book (paper page turn), slides, page focus. `documents-kit.md`.
- **Collage**: tear, sticker, cards, grade (silver, ink, duotone, halftone), sunburst, confetti, circle text.
  `collage-kit.md` + style pack `collage-zine`.

## Candidates, in the order we would build them
1. **Focus and letter type** (top of the local gap report): text sharpening in from blur, letters rising one by one,
   rack focus on UI, glow flash on highlights.
2. **Social-native** (DTC wedge): comments popping in, like burst, stitch split screen, chat thread, notes app, tweet
   card, POV caption.
3. **Product hero**: 2.5D turn of a product photo, reflective floor and shadow, spotlight sweep, ingredient explode,
   before/after wipe.
4. **UI morph** (SaaS): see `higgsfield-claude-motion.md`.
5. **Data and infographic**: bar race, donut, line draw, comparison bars, timeline, route on a map; pairs with document
   charts.
6. **Looks**: retro/analog, hand-drawn (with a 2 s line boil), paper craft, editorial/luxury, glitch/tech, liquid,
   testimonial.
7. **By industry**: e-commerce (price tag swing, add to cart, discount burst, sold-out stamp), fashion (lookbook,
   swatches), food (top-down assembly, steam), real estate (floorplan draw, room tour, map pin), events (ticket stub,
   countdown, lineup), apps (store page, ratings, notification), education (whiteboard, step builds).

## Where to look
- Our own data: `php artisan create:move-gaps` (reference moments no move reproduces, by kind). Locally 82 of 219 on
  2026-10-09, led by blur-in text, letters rising and rack focus. Run on production to rank by real demand.
- Our registry (386 blocks; Create used about 20% of them in the October audit).
- What is working in ads: TikTok Creative Center (Top Ads by industry), Meta Ad Library, Foreplay.
- Craft: Ben Marriott, School of Motion, Motion Design School, Behance/Dribbble "motion".
- Code to learn from (check each licence): Codrops, CodePen (public pens MIT by default), GSAP showcase, Awwwards.
- Menus of looks that sell (ideas only, never their files): VideoHive and Motion Array categories.
- SaaS launches: Apple, Linear, Stripe, Vercel, Arc.
