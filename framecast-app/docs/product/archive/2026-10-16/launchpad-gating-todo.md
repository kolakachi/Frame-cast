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
| D-CHAN | Bundle channels | done | unlimited (owner, 2026-10-12) |
| D-FE-LEN | Base video length | done | FE 60 s, Pro 90 s, Bundle 90 s (+bump 180 s), Agency 300 s (owner approved the values table, 2026-10-12) |
| D-REFUND | Refund or chargeback | done | revoke that product's features and unspent credits; an FE refund with nothing else owned suspends the account (owner, 2026-10-12) |
| D-TOPUP | How launch buyers top up later | done | our Kelviq top-ups (owner, 2026-10-12) |
| D-UPSELL-UI | Locked features in the app link to the Launchpad offer pages (needs their URLs) | decide | marketer |
| D-HOOKS | "100 Hooks & CTAs" and the agency toolkit are content: delivered on the thank-you page by the marketer | decide | marketer |

## 1. Purchase intake (Launchpad IPN)

| ID | Item | Status |
|---|---|---|
| IPN-ROUTE | `POST /api/v1/webhooks/launchpad`: verify the signature, log every call (BillingWebhookLog, provider `launchpad`), idempotent per receipt and type (processed_webhook_events), always answer 200 once logged | open |
| IPN-STORE | `launch_purchases` table: receipt, product, source, email, status, credits granted/revoked, amount, payload | done |
| IPN-MAP | `config/launchpad.php`: Launchpad product id → product key (fe, bundle, pro, agency, bumps) | open |
| IPN-PROVISION | LaunchpadService::grant(email, product, receipt): finds or creates the account, sets the tier, grants credits once per receipt, sends the welcome; `php artisan launch:grant` until the IPN calls it | partly (IPN parsing waits on IN-IPN) |
| IPN-REFUND | LaunchpadService::revoke(receipt, product, status): features off, unspent credits forfeited (`forfeit:launch_*`), FE refund suspends | partly (IPN parsing waits on IN-IPN) |
| IPN-ADMIN | Admin: grant/revoke button and launch purchases per workspace in God Mode, webhook log filter `launchpad` (the artisan command covers it today) | open |

## 2. Tiers and entitlements

| ID | Item | Status |
|---|---|---|
| ENT-TIERS | Tier rows in PLAN_LIMITS (every gate spelled out), PLAN_CREDITS (0) and WorkspaceUsageService (LAUNCH_USAGE, no watermark) | done |
| ENT-STACK | LaunchCatalogue::combine → workspaces.plan_addons, merged generously in CreditService::limitsFor and WorkspaceUsageService::planFor (clients get their agency's); free/ugc_pass/launch tiers move to the highest launch tier, any other plan keeps its tier and only gains | done |
| ENT-GUARD | LaunchpadGatingTest: every non-launch tier resolves exactly as before; stacking, bumps, redelivery, refunds, LTD holders, agency clients | done |

## 3. Gates (new ones built plan-wide, default = today's behaviour)

| ID | Gate | Today | Status |
|---|---|---|---|
| G-CREATE | Creative Director (Create) by plan | `create` gate in ConversationService::authorize (402 upgrade_required); /create/capabilities answers `enabled:false, locked:plan` | done (backend) |
| G-UGC | UGC chat by plan | `ugc_ads` (false on launch_fe), already read by the chat, wizard and API | done |
| G-REF | Reference-video style matching | `reference_style`: refused on reference upload/attach; auto roles become footage instead | done (backend) |
| G-CHAR | Characters count | max_characters via limitFor (Pro bump adds 2) | done |
| G-VOICE | Cloned voices count | voice_cloning_limit via WorkspaceUsageService::planFor (Pro bump adds 2) | done |
| G-MCP | ChatGPT/Claude MCP and API keys | api_access false on launch_fe and launch_agency | done |
| G-PUB | Publishing and scheduling (YouTube, Facebook, Instagram, TikTok) | social_publishing false on launch_fe; check each platform end to end | partly (end-to-end check open) |
| G-KITS / G-CHAN | Brand kits, channels | values set; channels via planFor (Bundle unlimited) | done |
| G-SERIES | Series mode | `series` gate on SeriesController::store | done |
| G-BATCH | Batch generation, up to 3 variations | `batch_variations` caps Create variations and project variants (0 = none); the CSV source makes one project, so it is not gated | done |
| G-DFY | DFY template vault = Create's example videos | `sample_vault`: SampleLibrary::catalogueFor shows the first N (Bundle 10, +20 bump), using another is refused | done |
| G-CLIENTS | Client workspaces count (30 / 50, +10 bump) | Workspace::canOwnClients / maxClients read `max_client_workspaces`; client tiers keep at least the 50 guard | done |
| G-PRIORITY | Priority rendering | launch_bundle/launch_agency in PRIORITY_EXPORT_TIERS; a bought Bundle on another plan reads plan_addons | done (exports; Create builds and UGC have no priority lane) |
| G-LEN | Video length | max_duration_seconds via limitFor (Classic composer and API quotes); Create and UGC have their own caps | done |
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
| WEB-SALES | special.wyvstudio.com: server blocks built (framecast-app/launchpad/, its own mount, noindex, closed funnel, blocked on wyvstudio.com); FE, Bundle, Pro, Agency and thank-you shells live with the shared frame; copy per page from the owner; Cloudflare DNS record `launchpad` (proxied) to add | partly |
| WEB-REDIRECT | Dropped (2026-10-13): each audience has its own front door; wyvstudio.com is never redirected | done |
| WEB-LEGAL | The funnel's own terms, privacy and refund pages (Launchpad as retailer, not Kelviq) at special.wyvstudio.com | open (needs the Launchpad disclaimer) |
| WEB-CHECKOUT | Launchpad checkout and decline links in launchpad/launch.js (CHECKOUT, DECLINE); buttons stay disabled until set | open (needs IN-IDS) |
| WEB-ATTRIB | Affiliate protection: closed funnel (done), a .wyvstudio.com cookie with the affiliate, main-site buy buttons to the launch offer during the campaign, direct sales credited to the affiliate | proposed |
| WEB-ADS | A separate Google Ads landing page (keeps google-ads.js tracking and acquisition), not redirected | open |
| WEB-TY | special.wyvstudio.com/thank-you: sign-in steps (magic link) built; bonus links and lede pending | partly |

## 6. Test and launch

| ID | Item | Status |
|---|---|---|
| T-SANDBOX | Launchpad test purchases for every product and bump; refund and chargeback paths | open |
| T-REGRESS | AppSumo, direct lifetime and subscription accounts: unchanged (automated tests + one manual check each) | open |
| T-E2E | Buy FE → log in → first video → upsell → features unlock | open |
| L-WATCH | Launch day: IPN log, provisioning errors, support inbox | open |

## Done

- 2026-10-12 Gates (backend): Create, reference style, Series, batch, DFY vault, client workspaces, priority exports; PlanGate (402 upgrade_required with the feature).

- 2026-10-12 Decisions D-CHAN, D-FE-LEN, D-REFUND, D-TOPUP; entitlements core (tiers, launch_purchases, plan_addons, grant/revoke, `launch:grant`, guard tests).

- 2026-10-12 Code map of plans and gates (where the tier lives, AppSumo and Kelviq provisioning, webhook patterns,
  every gate and what is missing).
