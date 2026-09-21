<?php

return [
    // Only explicitly managed plans refill without provider invoice evidence.
    'manual_monthly_tiers' => ['enterprise'],


    /*
     * Registration gate. While marketing campaigns are running, a new signup
     * must arrive having chosen a plan: /register refuses a visit with no plan
     * and the free credit grant is withheld, so nothing is usable until a
     * purchase lands. Flip to false to open the free tier back up — nothing
     * else has to change.
     *
     * Does NOT affect AppSumo activation (its own workspace-creation path) or
     * anyone who already has an account.
     */
    'require_plan_on_register' => (bool) env('REQUIRE_PLAN_ON_REGISTER', true),

    /* Credits granted to a brand-new workspace. Withheld while the gate above
     * is on: a free bucket is exactly what removes the reason to pay. */
    'registration_credits' => (int) env('REGISTRATION_CREDITS', 200),

    /*
     * Accounts that existed before the gate went up are not subject to it.
     * They signed up under a free tier that was genuinely on offer, so they
     * keep their credits and are never bounced to checkout at sign-in —
     * including the ones who started a purchase and decided against it.
     * Only workspaces created on or after this date can be held at the door.
     */
    'gate_from' => env('BILLING_GATE_FROM', '2026-09-09'),

    // Accounts that are never held at the checkout door, whatever their tier.
    // Team members who signed up like anyone else live here — comma separated.
    'gate_exempt_emails' => env('BILLING_GATE_EXEMPT_EMAILS', 'kenoyekitoye@gmail.com'),

    /*
    |--------------------------------------------------------------------------
    | Billing provider
    |--------------------------------------------------------------------------
    |
    | Kelviq is our sole Merchant of Record. (Paddle + FastSpring were removed
    | once Kelviq approved us.) Kept as a key so the frontend/status can read it.
    |
    */
    'provider' => 'kelviq',

    /*
    |--------------------------------------------------------------------------
    | Kelviq (Merchant of Record)
    |--------------------------------------------------------------------------
    |
    | Auth: Bearer <server key>. Base: https://api.kelviq.com/api/v1.
    | Webhooks: Svix scheme (webhook-id/timestamp/signature). Checkout:
    | POST /checkout/. Portal: POST /portal/session/. See docs.kelviq.com +
    | spec/KELVIQ_INTEGRATION.md.
    |
    */
    'kelviq' => [
        'api_base'       => env('KELVIQ_API_BASE', 'https://api.kelviq.com/api/v1'),
        'server_api_key' => env('KELVIQ_SERVER_API_KEY', ''),   // Bearer token (server key)
        'webhook_secret' => env('KELVIQ_WEBHOOK_SECRET', ''),   // kq_whsec_... from Settings → Webhooks

        // Kelviq PLAN identifier (planIdentifier / data.object.plan.identifier)
        // => our plan_tier.
        'plan_tiers' => [
            env('KELVIQ_PLAN_STARTER', 'wyvstudio-starter') => 'starter',
            env('KELVIQ_PLAN_CREATOR', 'wyvstudio-creator') => 'creator',
            env('KELVIQ_PLAN_PRO',     'wyvstudio-pro')     => 'pro',
            env('KELVIQ_PLAN_AGENCY',  'wyvstudio-agency')  => 'agency',
        ],

        // Top-up PLAN identifier => credit grant (one-time checkout.completed).
        // These live under the separate Kelviq product "wyvstudio-top-up", whose
        // plan identifiers carry the "new-" prefix.
        'topup_plans' => [
            env('KELVIQ_PLAN_TOPUP_SMALL',  'wyvstudio-new-topup-500')  => 500,
            env('KELVIQ_PLAN_TOPUP_MEDIUM', 'wyvstudio-new-topup-1200') => 1200,
            env('KELVIQ_PLAN_TOPUP_LARGE',  'wyvstudio-new-topup-2500') => 2500,
            env('KELVIQ_PLAN_TOPUP_XL',     'wyvstudio-new-topup-5000') => 5000,
        ],

        // Lifetime (one-time) plans sold from our own checkout. identifier =>
        // [tier, credits]. Credits land in the one-time bucket and the tier
        // never renews, exactly like an AppSumo licence.
        //
        // Priced ABOVE the AppSumo tiers ($49/$139/$299) so their deal stays
        // the best available, which their agreement requires — and a direct
        // sale still nets several times more after their revenue share.
        /*
        | How a chosen plan is named back to the customer.
        |
        | Server-side on purpose: the register page passes a plan *key*, and
        | the label is looked up here. Rendering a display string supplied by
        | the client would let anyone put arbitrary text — a different price,
        | say — into an email that arrives from us.
        */
        'plan_labels' => [
            // Without an entry here the magic-link endpoint refuses to record
            // intended_plan — which is how a $9 pass buyer reached an empty
            // free account instead of Kelviq.
            'ugc_pass'         => 'UGC Test Pass — $9 one-time, 600 credits',
            'lifetime_starter' => 'Starter — $89 one-time, 4,000 credits',
            'lifetime_creator' => 'Creator — $199 one-time, 12,000 credits',
            'lifetime_agency'  => 'Agency — $399 one-time, 20,000 credits',
            'starter' => 'Starter — $29/month',
            'creator' => 'Creator — $59/month',
            'pro'     => 'Pro — $99/month',
            'agency'  => 'Agency — $199/month',
        ],

        // The $9 UGC Test Pass rides the one-time path so it can be bought
        // straight from the site with no account yet — but its tier grants
        // only the UGC gate, so nothing else is unlocked by paying.
        'ugc_pass_plan' => env('KELVIQ_PLAN_UGC_PASS', 'wyvstudio-ugc-pass'),
        'ugc_pass_credits' => 600,

        'lifetime_plans' => [
            env('KELVIQ_PLAN_LIFETIME_STARTER', 'wyvstudio-lifetime-starter') => ['tier' => 'lifetime_starter', 'credits' => 4000],
            env('KELVIQ_PLAN_LIFETIME_CREATOR', 'wyvstudio-lifetime-creator') => ['tier' => 'lifetime_creator', 'credits' => 12000],
            env('KELVIQ_PLAN_LIFETIME_AGENCY',  'wyvstudio-lifetime-agency')  => ['tier' => 'lifetime_agency',  'credits' => 20000],
        ],

        'lifetime_packs' => [
            ['key' => 'lifetime_starter', 'name' => 'Starter',  'credits' => 4000,  'price_usd' => 89],
            ['key' => 'lifetime_creator', 'name' => 'Creator',  'credits' => 12000, 'price_usd' => 199],
            ['key' => 'lifetime_agency',  'name' => 'Agency',   'credits' => 20000, 'price_usd' => 399],
        ],

        // Top-up pack display metadata for the Settings grid (key drives the
        // checkout call). Prices locked in CREDIT_CALIBRATION.md §10.
        'topup_packs' => [
            ['key' => 'small',  'credits' => 500,  'price_usd' => 8],
            ['key' => 'medium', 'credits' => 1200, 'price_usd' => 18],
            ['key' => 'large',  'credits' => 2500, 'price_usd' => 36],
            ['key' => 'xl',     'credits' => 5000, 'price_usd' => 70],
        ],
    ],

    /*
    | What an affiliate's percentage is applied to.
    |
    | Top level, not under `kelviq`: every reader asks for
    | billing.affiliate_basis, and nested here it silently resolved to null —
    | so the defaults applied, tax and fee both came out 0, and commission was
    | paid on the full gross while each row was stamped `net`.
    |
    | Kelviq reports one figure on checkout.completed — the gross total,
    | tax included, with no breakdown — so the taxable and net portions
    | cannot be read from the webhook. These two settings state the
    | assumption explicitly rather than leaving it implied by whichever
    | field happened to be used.
    |
    | tax_rate_estimate: the share of gross that is tax. Set to 0 where
    | prices are tax-inclusive or tax is not charged.
    | platform_fee_percent: what the merchant of record (Kelviq) keeps. Not
    | ours — it never reaches us — which is why anything shown to an
    | affiliate names Kelviq rather than calling it "a platform fee". To
    | the person being paid, an unnamed platform is the one paying them.
    |
    | Both are estimates until reconciled against a Kelviq payout report.
    | Conversions record the gross, the basis and the method, so figures
    | can be restated if the assumption turns out wrong.
    */
    'affiliate_basis' => [
        'method' => env('AFFILIATE_BASIS', 'net'), // gross | ex_tax | net
        'tax_rate_estimate' => (float) env('AFFILIATE_TAX_RATE', 0.20),
        'platform_fee_percent' => (float) env('AFFILIATE_PLATFORM_FEE', 5.0),
    ],
];
