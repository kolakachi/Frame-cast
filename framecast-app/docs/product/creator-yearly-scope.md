# Creator Yearly: build scope

One plan for our own traffic: **Creator, billed yearly**, sold on **sales.wyvstudio.com**. Agency is not part of it.
Status: scope for the owner's approval (2026-10-13). Nothing is built yet.

## Why

- Our own traffic (Google Ads, organic, referrals) gets one simple offer instead of a $9 pass, three one-time packs and
  four monthly plans.
- Yearly cash up front, and no direct lifetime deal that undercuts AppSumo or the Launchpad Bundle (today the
  direct lifetime Creator is $199 for 12,000 credits; the Launchpad Bundle is $247 for 8,000).
- It sits on sales.wyvstudio.com because AppSumo shoppers look at wyvstudio.com to judge the product. Our agreement
  with AppSumo requires their deal to stay the best one available, so wyvstudio.com shows nothing that competes with it.

## The offer (owner-approved terms, 2026-10-13)

| | |
|---|---|
| Price | **$590 a year**, shown as "$49 a month, billed yearly". No crossed-out price and no "months free" (we do not sell a monthly plan to compare it with). |
| Credits | **4,000 a month.** Unused credits roll over, up to 3 months' worth (12,000). |
| Features | The current Creator plan: composer, Creative Director, UGC ads, 10 characters, cloned voices, 3 brand kits, 3 publishing channels, videos up to 5 minutes, no watermark, API and MCP. No client workspaces. |
| Refund | Full refund within 14 days, no questions asked. |
| Cancel | One click, any time. Access continues to the end of the paid year. |
| Renewal | A reminder email 7 days before the charge. The price stays the same for as long as they stay subscribed. |
| After it ends | 30 days to download their videos. |
| Top-ups | Still on sale, and they never expire. |
| Existing customers | Lifetime, AppSumo, the launch tiers and monthly subscribers keep exactly what they have. |

## Build

| ID | Item | Notes |
|---|---|---|
| CY-TIER | New tier `creator_annual`: the `creator` limits row, PLAN_CREDITS 4,000, PLAN_ROLLOVER cap 3, a WorkspaceUsageService row, a plan label, and a Kelviq plan id (`KELVIQ_PLAN_CREATOR_ANNUAL`) mapped in `billing.kelviq.plan_tiers` | Its own tier key, so the monthly `creator` plan (no rollover today) stays as it is |
| CY-REFILL | Monthly refill on a yearly invoice: a `credits_refill_at` column; ResetMonthlyCreditsJob refills `creator_annual` workspaces each month while the subscription is active, applying the rollover cap; the yearly renewal invoice does not refill twice | Today the refill happens only when a monthly invoice arrives (SubscriptionRenewal), so a yearly plan would refill once a year without this |
| CY-REMIND | Renewal reminder email 7 days before `billing_renews_at`, sent once per term, with the amount, the date, and the cancel link | New; nothing sends one today |
| CY-CANCEL | Billing page: Cancel (the Kelviq portal, which exists) and "access until <date>"; check that a cancelled subscription keeps the plan until `subscription_ends_at` | Mostly a check of the existing behaviour |
| CY-REFUND | A refund within 14 days ends the plan and removes the unspent monthly credits; check what Kelviq's refund webhook does today, and record it in revenue_events | Check, then fill any gap |
| CY-AFTER | After the plan ends: downloads of finished videos still work for 30 days | Check whether downloads are plan-gated today |
| CY-LOCK | Same price at renewal | Set on the Kelviq side; record it in the plan's description |
| CY-APP | The app's plans page: new buyers see Creator Yearly, the $9 pass and top-ups; existing customers keep seeing and managing their own plan | PlansView, plan_labels |
| CY-SALES | sales.wyvstudio.com: a server block (as the explicit non-default, per the 2026-10-13 outage), pages under their own folder, the Google Ads tag and conversion, source tracking (cookies already span .wyvstudio.com), and sign-up with `intended_plan=creator_annual` going to the Kelviq checkout | The same pattern as special.wyvstudio.com |
| CY-MAIN | wyvstudio.com: remove the one-time packs and monthly prices; the pricing section becomes a link to the AppSumo deal while it is live, or no prices at all | Owner decides the wording (below) |
| CY-CREDITS | Credits shown as what they make ("4,000 credits a month ≈ N composer videos or N UGC ads"), from real averages in the ledger | Also used by the launch pages (UI-CREDITS) |
| CY-TEST | Tests: every existing tier unchanged; monthly refill timing and the rollover cap; one reminder per term; refund and cancel paths | |

## Needed from the owner

1. The Kelviq yearly product and its plan id ($590 a year, renewing at the same price).
2. The sales page copy, or approval for me to adapt the homepage copy.
3. A Cloudflare DNS record `sales`, proxied.
4. What wyvstudio.com's pricing section should say: a link to the AppSumo deal, a "see plans" link to sales.wyvstudio.com, or nothing.
5. Whether the $9 UGC Test Pass stays on sale on sales.wyvstudio.com as the low-cost first step. Recommended: yes.

## Not in this scope

A monthly option, the Agency plan, and any change to existing customers.
