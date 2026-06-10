<?php

return [

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
        'topup_plans' => [
            env('KELVIQ_PLAN_TOPUP_SMALL',  'wyvstudio-topup-500')  => 500,
            env('KELVIQ_PLAN_TOPUP_MEDIUM', 'wyvstudio-topup-1200') => 1200,
            env('KELVIQ_PLAN_TOPUP_LARGE',  'wyvstudio-topup-2500') => 2500,
            env('KELVIQ_PLAN_TOPUP_XL',     'wyvstudio-topup-5000') => 5000,
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
];
