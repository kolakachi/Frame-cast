# Launchpad launch: plan gating — the todo

Only what is still open. Add new items here and nowhere else; when one is done, mark it and note it under Done (date and
commit). Progress tracker: https://claude.ai/artifact/PDDFo1baSTPae1d4bHb4xp (republished as items change).

Last updated: 2026-10-12.

**Goal:** sell WyvStudio through Launchpad (JVZoo-style launch: front end, upsells, bumps, one-time), gate features for
those buyers only, and leave AppSumo, direct lifetime (Kelviq) and subscription customers exactly as they are. Built for
this launch; anything that helps every plan later (stackable add-ons, the new gates) is built plan-wide but defaults to
today's behaviour for every existing tier.

**How the separation works:** every gate already reads one field, `workspaces.plan_tier` (CreditService::PLAN_LIMITS,
WorkspaceUsageService::plans). Launch buyers get new tier keys (`launch_fe`, `launch_pro`, `launch_bundle`,
`launch_agency`) and `plan_source = 'launchpad'`, set only by the Launchpad IPN. AppSumo (`appsumo_*`, AppSumoService)
and Kelviq lifetime (`lifetime_*`, KelviqService) never read or write those keys. New gates get a value on every
existing tier equal to today's behaviour, so nothing changes for them.

**Status:** `open` · `partly` · `ready` (built, needs a run) · `done` · `blocked` (waiting on an input) · `decide`.

## The offers (as proposed by the marketer, 2026-10-12)

| Product | Price | Credits (one-time) | Unlocks | Bumps |
|---|---|---|---|---|
| FE | $7 | 200 | Classic composer only: URL, script or idea; AI script, voice, visuals, captions; stock; scene editing; export; no watermark | Credit Booster +600 ($17); 100 Hooks & CTAs ($7, content) |
| Bundle (main OTO) | $247 | 8,000 | Pro features + Creative Director and reference style; UGC; 10 characters, 5 cloned voices; DFY template vault (10 = Create's example videos); MCP; publishing to YouTube, Facebook, Instagram, TikTok; batch (3 variations); Series; 5 brand kits; channels (5 or unlimited: decide); priority rendering; 50 client workspaces; agency controls | +20 DFY templates ($17); length up to 3 min ($12) |
| Pro (downsell) | $67 | 1,500 | Creative Director; URL, script, idea, reference inputs; UGC; 1 character, 1 cloned voice; voice, captions, editing; MCP; 1 brand kit, 1 channel; publishing calendar; multi-aspect export; no watermark | +1,000 credits ($37); +2 characters & +2 cloned voices ($27) |
| Agency | $347 | 3,000 | Core creation and publishing; Creative Director; UGC; 50 characters, 100 cloned voices; 30 client workspaces; unlimited channels; videos 3–5 min; agency dashboard; priority rendering; toolkit (marketer content) | +10 client accounts ($47); +2,000 credits ($67) |
| OfferIQ | $47 | — | The marketer's own product: no WyvStudio gating, no credits; we take 50% | — |

Agency is deliberately less attractive than the Bundle (the marketer wants buyers on the Bundle). Purchases stack: a
buyer with FE + Bundle + bumps gets the union (the larger of each limit) and the sum of credits.

## 0. Inputs and decisions

| ID | Item | Status | Next |
|---|---|---|---|
| IN-IPN | Launchpad IPN spec: fields, transaction types (sale, refund, chargeback), signature / secret key, retries, test mode | blocked | marketer to get from Launchpad |
| IN-IDS | Launchpad product IDs for FE, each OTO, each bump | blocked | marketer |
| IN-DATE | Launch date and campaign end (sets the build deadline and when the redirect comes off) | blocked | marketer / owner |
| D-CHAN | Bundle: "5 publishing channels" and "unlimited publishing channels" are both listed | decide | marketer |
| D-FE-LEN | FE and Bundle base video length (the Bundle sells a "up to 3 min" bump, Agency says 3–5 min) | decide | proposal: FE 60 s, Pro 90 s, Bundle 90 s (+bump 180 s), Agency 300 s |
| D-REFUND | Refund or chargeback: revoke that product's features and unspent credits (recommended), suspend if FE itself is refunded? | decide | owner |
| D-TOPUP | How launch buyers top up later (our Kelviq top-ups, or Launchpad offers) | decide | owner |
| D-UPSELL-UI | Locked features in the app link to the Launchpad offer pages (needs their URLs) | decide | marketer |
| D-HOOKS | "100 Hooks & CTAs" and the agency toolkit are content: delivered on the thank-you page by the marketer | decide | marketer |

## 1. Purchase intake (Launchpad IPN)

| ID | Item | Status |
|---|---|---|
| IPN-ROUTE | `POST /api/v1/webhooks/launchpad`: verify the signature, log every call (BillingWebhookLog, provider `launchpad`), idempotent per receipt and type (processed_webhook_events), always answer 200 once logged | open |
| IPN-STORE | `launch_purchases` table: receipt, product key, buyer email and name, amount, status, raw payload | open |
| IPN-MAP | `config/launchpad.php`: Launchpad product id → product key (fe, bundle, pro, agency, bumps) | open |
| IPN-PROVISION | On a sale: find or create the user by email, create the workspace (`acquisition_channel = launchpad`, `plan_source = launchpad`), grant the product's credits once (bucket per receipt), welcome email with a set-password link (copy Kelviq's provisionFromEvent / WelcomeMail) | open |
| IPN-REFUND | On a refund or chargeback: revoke that product's entitlement and its unspent credits (per D-REFUND) | open |
| IPN-ADMIN | Admin: launch purchases per workspace, webhook log filter `launchpad` | open |

## 2. Tiers and entitlements

| ID | Item | Status |
|---|---|---|
| ENT-TIERS | Tier rows `launch_fe`, `launch_pro`, `launch_bundle`, `launch_agency` in PLAN_LIMITS, PLAN_CREDITS (0 monthly) and WorkspaceUsageService::plans (render, voice, cloning, channel, watermark) | open |
| ENT-STACK | Entitlements (plan-wide design): owned products + add-ons merged into one set of limits (largest of each, add-ons add); the workspace tier is the highest launch tier owned | open |
| ENT-GUARD | Tests proving appsumo_*, lifetime_*, subscription and free tiers resolve exactly as before | open |

## 3. Gates (new ones built plan-wide, default = today's behaviour)

| ID | Gate | Today | Status |
|---|---|---|---|
| G-CREATE | Creative Director (Create) by plan | env only (CREATE_OPEN_TO_ALL), no plan check | open |
| G-UGC | UGC chat by plan | env only (UGC_CHAT_OPEN_TO_ALL); ugc_ads exists | open |
| G-REF | Reference-video style matching | inside Create | open |
| G-CHAR | Characters count | exists (max_characters) | open (values) |
| G-VOICE | Cloned voices count | exists (voice_cloning_limit) | open (values) |
| G-MCP | ChatGPT/Claude MCP and API keys | exists (api_access, true everywhere) | open (values) |
| G-PUB | Publishing and scheduling (YouTube, Facebook, Instagram, TikTok) | exists (social_publishing); check each platform end to end | open |
| G-KITS / G-CHAN | Brand kits, channels | exist (max_brand_kits, channel limit) | open (values) |
| G-SERIES | Series mode | missing | open |
| G-BATCH | Batch generation, up to 3 variations | missing (CSV batch and variants ungated) | open |
| G-DFY | DFY template vault = Create's example videos (SampleLibrary, 32 today): 10 for the Bundle, +20 bump | missing | open |
| G-CLIENTS | Client workspaces count (30 / 50, +10 bump) | partly (canOwnClients by tier list, no count) | open |
| G-PRIORITY | Priority rendering | exports only (PRIORITY_EXPORT_TIERS) | open |
| G-LEN | Video length | exists (max_duration_seconds) | open (values) |
| G-WM | No watermark | free tier only today | done (launch tiers are not free) |

## 4. App (web)

| ID | Item | Status |
|---|---|---|
| UI-CAPS | Capabilities payload carries the new gates; locked features show why and the right upgrade | open |
| UI-UPSELL | Upgrade buttons for launch buyers go to the Launchpad offers, never our Kelviq plans | open |
| UI-CREDITS | Credits shown as what they make ("≈ 2 UGC ads or 30 narrated videos") | open |
| UI-FE-FIRST | FE first session: the composer path works first time (pasted-script detection shipped 2026-10-12) | open |

## 5. Sales site

| ID | Item | Status |
|---|---|---|
| WEB-SALES | sales.wyvstudio.com: DNS (Cloudflare) + a server block in nginx/default.conf + the page | open |
| WEB-REDIRECT | wyvstudio.com → sales.wyvstudio.com during the campaign only (nginx-marketing), easy to undo | open |
| WEB-ADS | A separate Google Ads landing page (keeps google-ads.js tracking and acquisition), not redirected | open |
| WEB-TY | Thank-you / access page (marketing/ty.html exists for JVZoo): how to log in, where the bonuses are | open |

## 6. Test and launch

| ID | Item | Status |
|---|---|---|
| T-SANDBOX | Launchpad test purchases for every product and bump; refund and chargeback paths | open |
| T-REGRESS | AppSumo, direct lifetime and subscription accounts: unchanged (automated tests + one manual check each) | open |
| T-E2E | Buy FE → log in → first video → upsell → features unlock | open |
| L-WATCH | Launch day: IPN log, provisioning errors, support inbox | open |

## Done

- 2026-10-12 Code map of plans and gates (where the tier lives, AppSumo and Kelviq provisioning, webhook patterns,
  every gate and what is missing).
