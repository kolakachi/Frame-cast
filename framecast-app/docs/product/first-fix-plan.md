# FIRST-FIX plan: the first session, and the free first ad

Plan for the owner, 2026-10-17 (`cmo-decisions.md` §0, `cmo-plan.md` experiments 3 and 5). Nothing here is built until
the owner says so. Measured by `growth:scoreboard`: the share of new sign-ups with a video of their own on day one.

## What the code does today

| Piece | Today | Source |
|---|---|---|
| **The sample** | Every new workspace gets a copy of project 48, "Wyvstudio Ad" (an ad about WyvStudio), from `ONBOARDING_SAMPLE_PROJECT_ID=48` in prod `api/.env`. It costs nothing, but it was the first "video" in 15 of 23 buyers' accounts, and every export of it looked like a first video in our numbers | `CloneSampleProjectJob`, `SampleProjectCloner` |
| **Where buyers land** | Since 2026-10-12 the dashboard opens with "Make your first ad" (describe it or paste a link → Create, plan and price first). Paying buyers land there | `DashboardHome.vue`, `PaymentConfirmationView.vue` |
| **Free sign-ups** | Create refuses free plans outright: the quote returns 402, "requires a plan with unwatermarked exports". So a free sign-up who uses "Make your first ad" hits a wall | `ConversationService` (approve), `CompositionOutputService` (save) |
| **The Classic product form** | "Product name / description / link / audience" sends `Product Name: \nProduct Description: ` when left empty; 12 videos were made from nothing | `NewVideoWizard.vue` `buildSourceContentRaw` |
| **Email** | Sign-up is by magic link, so every account has a verified email | `AuthController::magicLink` |

**Baseline:** 14 of 48 sign-ups since August (29%) made a video of their own on day one; 0 of 3 in the latest week.

## The changes

### 1. Switch off the sample (config, minutes)
Remove `ONBOARDING_SAMPLE_PROJECT_ID` from prod `api/.env` (and recreate the API container, checking no build is
running). New workspaces start empty; the dashboard's "Make your first ad" box is the start. Existing copies stay in
old accounts. The dashboard already ignores the sample when deciding whether someone has made a video.

### 2. No empty product form (small fix)
In the Classic wizard, "Generate" stays disabled until the product form has a name and either a description or a
link, with a plain hint ("Add your product's name and a line about it, or paste its link"). The API refuses the same
empty form, so a script or the connector can't send it either.

### 3. The first-ad box offers templates (small)
Under "Make your first ad", add "Or start from a template →" (the public Templates page). Templates are the fastest
route to a finished ad and are what the free first ad uses.

### 4. The free first ad (FREE-FIRST, about a day)
One free ad per workspace, for free plans, only from a template:

- **Path:** Templates → a template → "Use this template" → change the words, photo and colours → the free swap (no AI
  builder; render only). Today a swap needs a quote, which free plans can't get; the free first ad passes the 402 gate
  only when all of these hold: free plan, no free first ad used yet, the conversation started from a template, and the
  quote is a swap with nothing paid in it (no generated media, no voice, no builder).
- **Planning:** the plan that turns "my product, my words" into the swap is one Sonnet call; we absorb it (cents).
- **Watermark:** a small "Made with WyvStudio" mark burned into the video's corner. Download allowed. A paid plan
  makes the next ones without it.
- **After it:** the version card says "Your first ad is ready. Your next one can use your own presenter, voice or
  footage" with the Test Pass ($9) and packs.
- **Limits:** one per workspace (magic-link sign-up means one per email address); a counter on the workspace; staff
  can see and reset it in God Mode.
- **Cost to us:** the planning call plus a render, cents per free ad. At 20 free ads in two weeks: under a few dollars.

### 5. Measure
`growth:scoreboard` each Friday: day-one own videos and free first ads → purchases.

## Decisions for the owner

| Decision | Recommendation |
|---|---|
| Switch off the sample (1) | Yes |
| The empty-form guard (2) and the templates link (3) | Yes; small, no new surface |
| The free first ad only from a template, render-only (4) | Yes; cents per ad, no paid generation |
| The watermark: "Made with WyvStudio", bottom corner, small | Yes |
| Free first ads per workspace | One |

## Order of work once approved

1, 2 and 3 the same day (with tests); 4 next (API gate, the free counter, the watermark in the render, the version-card
upgrade prompt, God Mode reset), tested locally end to end on a free test workspace before any push; then the
scoreboard shows the effect from the following week.
