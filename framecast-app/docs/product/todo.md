# WyvStudio todo

The one list (owner, 2026-10-16: "we have to work with one markdown"). Everything pending across Create, UGC, templates,
the API and connector, R&D, the sites and the business, in the order to work in. The old todo files are in
`archive/2026-10-16/` for their history and detail; nothing new goes there.

How to use it: work top down. When something is done, delete its row and say what shipped in the commit. New work goes
in the section it belongs to. **build** = engineering, **owner** = a decision or something only you can give,
**marketer** = waiting on the marketer.

## 1. Now

The visibility rows follow `strategy.md` (the loop in §3, the presenter in §4, the channel order in §6; research in `study.md`).

| ID | Item | Next |
|---|---|---|
| CMO-EMAILS | **Sent 2026-10-18** (owner, from hello@, one at a time; refunders left out). When someone replies, the owner says who: Claude adds 500 credits that day and puts their words in `cmo-evidence.md` §5. **Fri Oct 23:** one one-question follow-up to anyone who hasn't replied ("What would make you make another video this month?", 500 credits still on), then stop | wait for replies; follow-up Oct 23 |
| FIRST-FIX | Done 2026-10-17 (`first-fix-plan.md`): sample off, product-form guard, templates link, and the free first ad (deploy the worker before pushing: the API asks it for the mark) | measure on the scoreboard |
| CMO-3 | Run the two-week plan in `cmo-plan.md` (Oct 19 – Nov 1): tracking, FIRST-FIX, customer emails, listings, FREE-FIRST, Layla pilot, community answers, OUTREACH-5, message test; scoreboard reviewed Fridays | starts Mon Oct 19 |
| FUNNEL | **Built 2026-10-19:** `php artisan growth:funnel` (`--since`, `--hours`, `--json`): each week's new workspaces through their first 24 hours (signed up → started → approved → delivered → downloaded or shared), by week and by channel, with the biggest drop named. Read on Fridays with `growth:scoreboard` | read Fridays |
| TPL-LINK | **Live** (2026-10-17): a link that opens one template's free ad directly (sign up free, land on that template, UTM kept); format in `tracking.md` | use it in every post, answer and outreach message |
| HTTPS-FIX | http://wyvstudio.com answers 200 instead of redirecting to https, and no HSTS header is sent. In Cloudflare → SSL/TLS → Edge Certificates: turn on Always Use HTTPS and HSTS. Five minutes | owner, in Cloudflare |
| LINK-FIRST | "Paste your shop link → your first ad" as the free-first way in (after gojiberry.ai's "enter your website"): read the site's name, headline, colours, logo and a product photo, fill a template, make the free first video. Needs FREE-FIRST | owner OK on the design, then build |
| LINK-FALLBACK | Firecrawl (hosted) as the fallback when a shop blocks our own fetch (429/403, empty JavaScript pages); our fetch stays first. Test on 10 links that failed on prod, with a monthly cost cap; needs an owner Firecrawl account | test, then build |
| AI-PAGES | A page each for ChatGPT and Claude (what the connector does, how to add it), an "about WyvStudio for AI assistants" page and llms.txt, so assistants can name us. Also from the GEO audit (2026-10-17, score 60/100, "fair"): structured data on the homepage (Organization, SoftwareApplication with prices; schema scored 0), a visible "last updated" date on pages, and turn off Cloudflare's email obfuscation (its link 404s). Re-run the audit after (tool in study.md §8). Free, about a day | build |
| HOME-CTA | **Live 2026-10-19** on wyvstudio.com + sales.wyvstudio.com (not special): every main button (top bar, hero, Enter in the link box, example week, a line above pricing, the closing card) is "Make my first ad free"; prices one click away ("or see prices", Pricing link). PostHog `free_cta` with `where` per button; read on the Friday scoreboard. Revert if paid sales drop | done |
| SALES-AB | A/B test on sales.wyvstudio.com (owner, 2026-10-22). Split: `/` gives a new visitor A (today's page) or B (`sales/index2.html`) 50/50 and keeps it by cookie; `/index2` links B directly; same mechanism special used before Launchpad ran its own split (fc5afd00). Measured to money (owner): PostHog events carry the version, and the version is saved with each new account's source so God Mode shows sign-ups, paying customers and money per version (app + API change, full deploy). Open: what B tests. Recommended: the CMO message test, B rewritten for small sellers around "Fresh ads before your old ones die" and its three pillars, same offer, design and media (no paid generation). Other options: headline only, or B leads with "$69 once, 4,000 credits" instead of the free first ad | owner picks what B tests, then build |
| OUTREACH-5 | Weekly batch of 5 brands, each sent a real ad made for them from a template (cents per ad, no builder): scout (stale ads in the Meta Ad Library, new launches), research, pick a template or skip, make the ad, draft the message; owner or marketer approves and sends every message from a WyvStudio address. Tracked on a private board (prospect data never in the repo); measured on sent, replies, customers. Supersedes PROOF's 330-credit builder version | **Batch 1 sent 2026-10-19** (5 US/UK sellers, ads made from free templates, private board on the owner's Desktop); nudge Thu Oct 22; batch 2 (emerging markets, Shopify-first) next week |
| PRESENTER | Layla (character 101, owner 2026-10-17) as the brand face (strategy.md §4): 3 pilot videos (cost estimate first) to check her face holds; then the main channel, 3–5 short videos a week on **TikTok and Reels only** (cmo-plan §6), each posted for search (phrase in first frame, title, caption, hashtags) and ending with the one ask line, test hooks first with Instagram trial reels (shown to non-followers, no risk to the account); judged after 100 videos | owner picks her, then build |
| LISTINGS | Free listings this week (study.md §3): G2 free profile (then ask Bob and neimar for reviews), SaaSHub, AlternativeTo, Future Tools, the ChatGPT/Claude connector directories; Product Hunt once the free first video exists. Apply to Online Geniuses and DTC FAM. The big AI directories are paid: skipped | entries ready to paste in `listings.md` (2026-10-18); owner creates the accounts and submits |
| COMMUNITY | Helpful answers with example videos in the checked communities (study.md), inside each group's rules; **3-day Reddit warm-up first** (5 useful comments a day, no links), subreddits Google ranks for our phrases, the one ask line (cmo-plan §6); judged after 100 answers | marketer |
| EXPORT-DROP | **Closed 2026-10-19, not a product problem**: of 80 finished Classic videos since Aug 24 (staff left out), 35 were never exported, but the owners of 33 came back and kept working (drafts replaced by a later version); only 6 of 27 workspaces never exported anything, several since refunded. The funnel's 2% "kept" is mostly a measuring gap: downloads are logged only from Oct 16. The real gap is the top: 9 sign-ups in three weeks, none since Oct 14 | closed |
| LENGTH-TARGET | **Built 2026-10-19**: the chosen length is a target; a voice that runs past it sets the length (up to 30% over), the render follows the page, the real length is shown on the version and noted on the plan card; price unchanged. Plan: length-target-plan.md | live after deploy |
| PRESENTER-DRESS | **Live 2026-10-20**: a Create presenter take carries outfit, setting and holding. With the user's cloned voice the presenter is first photographed that way from their photo (UgcLook, face-checked, +50 cr on the quote), then that photo is lip-synced; Omni/Seedance takes get the same fields in their prompt. Plan card: "Presenter" note. Live check pending: the partner's Beats video (ws 19) | first real use to watch |
| SWAP-SAFE | **Live + canary passed 2026-10-20**: Beelite's long words swapped for 3 credits; fixed on the way: text fit for morphed pills (418dd882), free re-render quoted as a build (759cc94e), re-render note (d8f3808f). Planning gate now follows real plan cost (2026-10-21). Long swapped words (2026-10-21, owner: "the agent can come out with something creative to fit"): each text field carries read_chars (its current letters); a longer swap goes back to the planner once for a creative shorter version, named in left_out; words the user quoted are kept with a note. See swap-safety-plan.md | done |
| STORYTIME | **Built 2026-10-21, staff-only**: a Story time UGC type (hook "Storytime: …", setup, product turning point, payoff + CTA; claims only from what was supplied). Short route: Seedance or Omni, up to 30 s. Long route: "Talking photo (Fabric)", the dressed look plus each line's read synced by VEED Fabric 720p, all clips started at once, 30 cr/s; also offered on Testimonial, Founder story and Listicle with a cast presenter. Open: sample takes (≈1,500 cr short, ≈1,800 for 60 s Fabric) → add `storytime` to UGC_TYPES_LIVE and set UGC_FABRIC_LIVE; a 60 s cap per talking shot today (90 s needs the cap raised for every engine). | owner |
| TREND-LIB | **Next session** (owner, 2026-10-21: after the CMO work). Scope `trend-library-scope.md`, mockup + flow `trend-library-mockup.html` (artifact ACKHcoWwHWkouMcXrYYQ48). Start with the half-day pilot: one week, two categories, 10 briefs blind-rated; build the weekly job only if pattern hooks win 7 of 10. Merges UGC-PLAYBOOK (1). Open: provider (ScrapeCreators; confirm its free calls), the two categories, apply for TikTok/Meta ad APIs. | owner |
| STORE-LINK | The free first ad starts from a store or product link (Shopify stores expose product names, prices and photos publicly; the UGC product-link reader already exists): paste a link, pick a template, get the ad. Plan first | plan next |
| SHOPIFY-APP | Shopify App Store listing: a small app that turns a product into a video; plan first (requirements in study.md) | plan, after the loop |

## 2. Next: build

**Create**

| ID | Item | Next |
|---|---|---|
| OMNI-CREATE | Omni talking takes in Create repeat words: lip-sync in Create (about $0.08/s), a transcript-based repeat cut when lip-sync fails, one UGC test take (200–300 credits) | owner decides, then build |
| SWAP-MEDIA | Swaps without the builder use photos as they are: add background removal (cutouts) and an AI picture when the user has no photo | build |
| STYLE-PROOF | Prove the remaining styles (3 done: neo-brutalism, glassmorphism, blueprint); per look, "known limits" and a "does it match?" check | build, paid proofs asked first |
| HF-CASES | Hard test briefs: mismatched product photo, long text, missing claims, contradictory ask, footage too short, a new spoken hook (use the motion briefs in `skills/`) | build |
| HF-BILL | Reconcile real provider invoices against our cost records for a week | build |
| FIRST-SESSION | The first session ends with a finished ad: review sign-up to first video and remove what stalls it (refunds were 8 of 18 AppSumo sales) | build |
| FS3 | Finishing checks still to add: constant-speed motion, the same technique repeated, full-frame hits; text under a phone's UI, every sound cue in the file | build |
| MOTION-VOCAB | A motion vocabulary (scene purpose to effect, tied to the registry's blocks) and a "no slideshow" check | build |
| D7 | Pick the best seconds of a clip longer than its slot | build |
| AB3 / AB4 | The builder gets WyvBear and the GSAP plugins; it searches the art library when a brief asks for icons | build |
| S10 | Pause and ask inside a step (at most 2 questions), then carry on without approving again | build |
| CHANGE-LOCAL | Change… never plans locally (durable planning is off locally): follow the flag | build |
| P2-ASPECT / EXP-WARN | Every size from one video, and a warning when a 16:9 export of a vertical video gets side bars | owner decides, then build |
| WEAVE-60 | Create up to 60 s before falling back to Classic: render cap and tests | build |
| CAST-ALL | Skits with two or more people: a cast and place sheet, shots refer to people by name, a ready check per shot | build |
| SHOT-VOCAB | A shot vocabulary for generated clips (owner, 2026-10-16, after filmvision.ai's shot glossary; our own words, theirs is all rights reserved): the planner names each shot's size (wide, medium, close-up, extreme close-up), angle (eye level, low, high, overhead, three-quarter, profile, over the shoulder, POV) and role (establishing, insert, cutaway, reaction); a sequence varies its framing (wide, then medium, then a close insert); a check flags three clips in a row with the same framing | build, after the templates |
| RD-49 | Logo animation format (3–6 s, becomes the logo, last second still) and a transparent export (WebM with alpha) | plan first |
| AI-MARK | Mark exported videos as AI-made (C2PA Content Credentials). The EU AI Act asks AI tools to mark outputs since Aug 2, 2026, possibly by Dec 2, 2026 for tools already on sale (unconfirmed); platforms also label marked videos automatically. Confirm the date, then plan | check, then plan |

**UGC**

| ID | Item | Next |
|---|---|---|
| VEO-TEST | Veo 3.1 Fast and HQ are in the model picker, untested on the new pipeline | test takes, then judge |
| SWEEP | Varied briefs: skincare, app demo, try-on, drink outdoors, plain testimonial, problem/solution (about 2,500–3,500 credits) | owner OK on spend |
| ASMR-TEST | ASMR as a silent one-take on Seedance (built 6dde4af4, untested) | owner ASMR take |
| REVIEWER | The yes/no reviewer only records: noisy on clothes and talking blocks | calibrate on the sweep |
| FACE-RETRY / FACE-STRICT | Face remakes are off; the face check is too lenient (a different woman scored 75–85) | stricter check, then switch on |
| WIDE-ID | Identity slips in wider shots (glasses gone, face 72) | face remakes, or keep propped shots waist-up |
| SEEDANCE-CONT | Does Seedance accept its own earlier video with a face in it? One call; a refusal is free | test |
| LEAN-SHEET | Is the full sheet over-directing? A/B a lean sheet | after the sweep |
| P3-TYPES / P3-PLAYBOOKS | Assembly for the newer types (green screen over the page, before/after and comparison splits, skit) and per-type playbooks | build |
| P4-CHANGE / P4-MCP | Change a delivered UGC video by chat; chat tools in the API and connector | build, after P3 |
| CUTOUT-PC | Presenter cutout as a post-production step | build |

**API and connector**

| ID | Item | Next |
|---|---|---|
| API-VERIFY | Release checks: reservations, settlement, key attribution and rotation (A1–A3); the check-to-apply race (A4); recovery, concurrency, cancellation (A6–A8); editor options and narration through the connector (B1–B2, C3); delivery handoffs (E1–E3). Detail in `api-mcp-gap-backlog.md` | build |

**Small fixes (fit in between)**

| ID | Item |
|---|---|
| SAMPLE-CTA | Sample pages' "Make one like this" goes to the homepage; point it at special.wyvstudio.com (one line) |
| UNDO-GAPS | "Re-record all voices" not busy-guarded; a failing step can't be discarded; a voice re-record can't be undone; history not deleted with the workspace |
| P2-POLISH | The pitch names a picked presenter only when the summary starts "A presenter/creator…" |
| ORPHAN-PARTS | Leftover clip files after some project deletes |
| 0.4 | An image in progress is lost when the worker dies (video is already recovered) |
| I2 | Local stack: rebuild reverb |

## 3. Waiting on the owner

| ID | Decision or input |
|---|---|
| OMNI-CREATE | Lip-sync in Create and the repeat cut (see Next) |
| O9 / O10 / O11 | Open Create to the two real customers (ws 28, ws 27); does the GTM-1 round count as acceptance; pricing, limits and quality bar |
| AGENCIES | Ship the /agencies page (`marketing/agencies.html`, untracked): feedback or "ship" |
| ACADEMY | The academy's 30% coupon conflicts with AppSumo's best-deal rule |
| OFFER-69 | The $69 sales offer |
| AD-BUDGET | Ads test budget (recommended $1,000–1,500) |
| MONTHLY | A monthly plan for teams that need ads every week (decide once about 10 teams buy a second pack) |
| UGC-DEFAULTS | Confirm (built that way): an explicit type beats the sample's; a pasted script's length wins; Rewrite with AI is free; saved characters from Library; old-form captions on by default, cuts re-anchored, shorter resumable timeout |
| IN-APP-CHECKS | In the app: "never more than" shows a real hold (CR4); a vague change gets one question (S11); captions in Details (RF3); a screenshot reads as "change this moment" (FS7); a tab open across a deploy, the directions drawer, Details (O8) |
| RF2 / LABAN | Listen to the Laban baseline's sound effects; show Laban efforts to users or use them to direct takes? |
| BENCH-INPUTS | For the test bench: a consented photo of you (O1), a UGC reference video (O2), a product photo (O3), a budget cap (O4, 7,000 credits?), 15–25 exemplar videos (O6) |
| TASTE-SITE | A taste review of our pages (free); you pick the fixes |
| SKILLS-LIST | List a WyvStudio skill on skills.marketing (an instruction file over our connector; check they take submissions) |
| BYTEPLUS | BytePlus trusted-asset library so Seedance takes the exact cast face: check account and region requirements |
| POSTHOG | A PostHog personal key (query:read) if you want visit numbers from it |
| NEIMAR / BOB | Portuguese top-up message for neimar; reply to Bob, mature voices |

## 4. Waiting on the marketer

| ID | Item |
|---|---|
| EMAIL | Send the updated email (utm_source=aweber on the AWeber list); share send size, opens and clicks |
| ADS-LP | Keyword groups for one Google Ads landing page per group |
| LAUNCHPAD | Launchpad IPN spec and secret (IN-IPN), product IDs (IN-IDS), launch date (IN-DATE), offer page URLs for locked features (D-UPSELL-UI), who delivers Hooks & CTAs and the agency toolkit (D-HOOKS). Then build: the IPN route, product map, refunds, admin grant/revoke |

## 5. Measuring (closes itself as runs come in)

| ID | What closes it |
|---|---|
| SEEDANCE-OR | Seedance 2.5 through OpenRouter for UGC, live for everyone since 2026-10-19 (UGC_SEEDANCE_OPENROUTER): presenters as pencil sheets (Admin → Presenters), skits up to 30 s in one take, Omni when Seedance declines a face. Watch for a week: refusals and Omni fallbacks, faces, voice consistency, cost (about $3.50–3.90 per 15–16 s; 528 credits at about $0.014 a credit pays, AppSumo credits at about $0.0036 lose money). Keep OpenRouter topped up with auto top-up. Decide at the end of the week |
| CR1 | Thorough costs about 1.8×: more Thorough runs |
| CR2 / G3 / G4 | Estimates per video kind (footage changes need 3 runs); Sonnet builds; cost per accepted result per route |
| TPL-COST | Template runs learn their own estimate after 3 (the template kind) |
| E1 | App screens on in-world devices: a run with a screen |
| ASK-FILE | No product photo: a generated image is the fallback; prove on a product brief |
| END-ACTION | UGC endings are self-contained: watch LEARN for ending pitfalls |

## 6. Later

| ID | Item |
|---|---|
| HOOK-PACK | UGC hook pack (owner, 2026-10-18; after Jason Zhou's AI UGC guide): one demo plus 5–10 short presenter hooks to A/B test. Only the hook (5–8 s) is generated with the presenter; the demo uses the seller's product photos, footage or a screen recording with our voice and captions, made once and reused for every hook; one-call talking video where the model speaks natively (no separate lip-sync when not needed); our checks and captions on each. Priced per hook. Aim: a UGC variant from about $3 of our cost to about $1. Plan first (owner approves scope and price). At the Nov 1 review |
| UGC-PLAYBOOK | Fold the AI UGC workflow from Jason Zhou's guide (treg, 2026-10-18) into the UGC director, alongside HOOK-PACK: (1) pull trending videos in the seller's niche and read their hooks and the kinds of faces that work; (2) presenters from a structured portrait spec (face structure, skin, light, camera, expression, clothes, background spelled out) so the model can't fall back on doll-face defaults, compared across image models on a few test faces (cost estimate first); (3) one-call talking video from the presenter image where the model speaks natively; (4) the demo made once (product photos, footage or a screen recording) and reused under every hook; (5) hooks written as a striking claim, a specific number and a result, 5–10 per demo. Never: endpoints that get around a model's guard on realistic people, or copying another creator's voice or delivery (our consent rules). At the Nov 1 review |
| PRICE-FEEL | Prices stay; make them feel fair (owner, 2026-10-18): lead with the likely price everywhere (connector, Classic, UGC, the can't-afford message), and planning counts toward the build. Step-by-step in `cookbook-price-feel.md` for an agent to follow. After the CMO plan |
| UGC-FOOTAGE-PROMISE | Found in the Layla pilot (2026-10-18): the UGC director's pitch said the attached footage would play as the demo, but the shot planner chose one unbroken take and left it out (twice, video 1; it used it in videos 2 and 3). The footage is only offered to the planner (`UgcController` plan, `footage_asset_ids` as a library). Fix: when the user attaches footage and the pitch says it plays, require a shot that uses it (or the pitch must not promise it); add a check before delivery. Also: the Seedance engine drifted Layla's face from her reference while Omni held it. After the CMO plan |
| MOTION-BUNDLE | How Create's videos move (owner, 2026-10-19: "put the lottie in nov 1 bundle"). Three open-source, MIT pieces for the builder: (1) LottieFiles motion-design-skill (github.com/LottieFiles/motion-design-skill): markdown rules for timing, easing, choreography and a motion-quality checklist, added to the builder's doctrine; (2) cinetic (github.com/Leonxlnx/cinetic): its 273 measured motion techniques with the weighted planner, and its "no AI look" lint (glow, neon, generic effects); not its 95-minute pipeline; (3) earshot (github.com/Leonxlnx/earshot): sound effects as code timed to Create's cuts, and an audio check in review (a measure, not a judge). Test with one before/after paid build per piece (cost estimate first). At the Nov 1 review |
| AGENCY-LOOP | One-person agencies as a later audience: they run a monthly creative loop for clients and need fresh ads on a schedule; our connector lets their agent make them ("your agent makes the fresh ads"). From Yarchi's guide (2026-10-18). At the Nov 1 review |
| TPL-AUDIT-WORDS | **Done 2026-10-19**: the audit tries one long word per field; field text shrinks to fit its box (WM.fitFields, all templates and copies); 15/16 free pass, sale countdown off the free list (flip board); blocks template builds. See template-audit.md | done |
| TPL-PAGES | Public template pages on wyvstudio.com: 39 indexable, shareable pages (video, description, "Make this for your product"). At the Nov 1 review (`cmo-plan.md` §5 keeps template pages out until then; owner 2026-10-17) |
| SHARE-TPL | Share pages' "Make a video like this" opens the template behind the video (or the closest one). At the Nov 1 review |
| CHECKOUT-SAVE | A follow-up when someone leaves checkout ("your $9 pass is still waiting", or a small offer); once traffic comes |
| BENCH | The bench rounds: baseline (G1), speed targets (F1), motion regression (H1), engine bake-off (B2), re-render keeps the performance (B3), sequence route (B1), builder starts while clips render (F2), local acceptance (R1), wider audience behind a switch (R2) |
| LOOP-COST | Creative Director loop cost levers: trim old tool output by rule; does planning earn its cost on Opus |
| WEEKLY | Weekly ad batches, the habit a lean team needs |
| STILL-AD | Still-ad editor: a logo or headline on a generated image, exported 1.91:1 and 1:1 |
| WYV-ADS | 2–3 short ads about WyvStudio made by the Creative Director (on hold) |
| MANIM | ManimGX for math-style shots: watch until it runs on our ARM worker |
| RD-43 | My Footage Tighten: retake and false-start detection, phrase-safe filler cuts, pauses that keep a breath |
| RD-44 | Stutter and freeze check on the finished file: unique fps, worst hitch, frozen tail, frame 0 on content |
| RD-45 | Hook and copy cliché lint ("Introducing", "Unlock the power of", "Game changer", placeholder names) |
| RD-46 | Design-tool selection move: a selection box with handles, a W×H label, a cursor click |
| PRICING | Pricing review rework, last |
