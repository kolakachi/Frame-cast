<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active billing provider
    |--------------------------------------------------------------------------
    |
    | Drives which service class is bound to BillingServiceContract in the
    | container. 'paddle' keeps the existing PaddleService active (default
    | for backwards compatibility with the historical code path). 'fastspring'
    | switches over to FastSpringService once we have the sandbox/live
    | credentials. Hot-swappable via env without any code change.
    |
    */
    'provider' => env('BILLING_PROVIDER', 'paddle'),

    /*
    |--------------------------------------------------------------------------
    | FastSpring (Merchant of Record)
    |--------------------------------------------------------------------------
    |
    | Sandbox URL is api.fastspring.com routed to the test storefront on the
    | account; production is the same host with a different store_domain.
    | The HMAC secret is set per-webhook-endpoint in the FastSpring dashboard
    | (Webhooks -> Add Endpoint -> HMAC SHA256 secret).
    |
    */
    'fastspring' => [
        'sandbox'      => env('FASTSPRING_SANDBOX', true),
        'api_base'     => env('FASTSPRING_API_BASE', 'https://api.fastspring.com'),
        'api_user'     => env('FASTSPRING_API_USER', ''),
        'api_password' => env('FASTSPRING_API_PASSWORD', ''),
        'hmac_secret'  => env('FASTSPRING_HMAC_SECRET', ''),
        'store_domain' => env('FASTSPRING_STORE_DOMAIN', ''), // e.g. 'wyvstudio'

        // Plan -> product path (human-readable identifiers configured in
        // the FastSpring dashboard). Monthly variants.
        'product_paths' => [
            'starter' => env('FASTSPRING_PRODUCT_STARTER', 'wyvstudio-starter-monthly'),
            'creator' => env('FASTSPRING_PRODUCT_CREATOR', 'wyvstudio-creator-monthly'),
            'pro'     => env('FASTSPRING_PRODUCT_PRO',     'wyvstudio-pro-monthly'),
            'agency'  => env('FASTSPRING_PRODUCT_AGENCY',  'wyvstudio-agency-monthly'),
        ],

        // Yearly variants — same plan tier, billed annually.
        'product_paths_yearly' => [
            'starter' => env('FASTSPRING_PRODUCT_STARTER_YEARLY', 'wyvstudio-starter-yearly'),
            'creator' => env('FASTSPRING_PRODUCT_CREATOR_YEARLY', 'wyvstudio-creator-yearly'),
            'pro'     => env('FASTSPRING_PRODUCT_PRO_YEARLY',     'wyvstudio-pro-yearly'),
            'agency'  => env('FASTSPRING_PRODUCT_AGENCY_YEARLY',  'wyvstudio-agency-yearly'),
        ],

        // One-time credit top-up packs — product path => credit grant size.
        'topup_products' => [
            env('FASTSPRING_PRODUCT_TOPUP_SMALL',  'wyvstudio-topup-500')  => 500,
            env('FASTSPRING_PRODUCT_TOPUP_MEDIUM', 'wyvstudio-topup-1200') => 1200,
            env('FASTSPRING_PRODUCT_TOPUP_LARGE',  'wyvstudio-topup-3000') => 3000,
            env('FASTSPRING_PRODUCT_TOPUP_XL',     'wyvstudio-topup-8000') => 8000,
        ],

        // Frontend metadata for the top-up pack picker.
        'topup_packs' => [
            ['key' => 'small',  'credits' => 500,  'price_usd' => 5,  'product_path' => env('FASTSPRING_PRODUCT_TOPUP_SMALL',  'wyvstudio-topup-500')],
            ['key' => 'medium', 'credits' => 1200, 'price_usd' => 10, 'product_path' => env('FASTSPRING_PRODUCT_TOPUP_MEDIUM', 'wyvstudio-topup-1200')],
            ['key' => 'large',  'credits' => 3000, 'price_usd' => 22, 'product_path' => env('FASTSPRING_PRODUCT_TOPUP_LARGE',  'wyvstudio-topup-3000')],
            ['key' => 'xl',     'credits' => 8000, 'price_usd' => 55, 'product_path' => env('FASTSPRING_PRODUCT_TOPUP_XL',     'wyvstudio-topup-8000')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paddle Billing
    |--------------------------------------------------------------------------
    |
    | All values are read from environment variables so secrets never live
    | in source control. The price IDs map each workspace plan_tier to the
    | corresponding Paddle price. Set PADDLE_SANDBOX=true in development.
    |
    | Kept active so we can fall back if FastSpring approval doesn't land.
    |
    */

    'paddle' => [
        'sandbox'         => env('PADDLE_SANDBOX', true),
        'api_key'         => env('PADDLE_API_KEY', ''),
        'webhook_secret'  => env('PADDLE_WEBHOOK_SECRET', ''),
        'client_token'    => env('PADDLE_CLIENT_TOKEN', ''), // used by Paddle.js in frontend

        // Paddle price IDs — monthly variants (used by checkout default)
        'price_ids' => [
            'starter'    => env('PADDLE_PRICE_STARTER', ''),
            'creator'    => env('PADDLE_PRICE_CREATOR', ''),
            'pro'        => env('PADDLE_PRICE_PRO', ''),
            'agency'     => env('PADDLE_PRICE_AGENCY', ''),
            // Legacy
            'studio'     => env('PADDLE_PRICE_STUDIO', ''),
            'scale'      => env('PADDLE_PRICE_SCALE', ''),
            'enterprise' => env('PADDLE_PRICE_ENTERPRISE', ''),
        ],

        // Annual variants — same plan tier, billed yearly
        'price_ids_yearly' => [
            'starter' => env('PADDLE_PRICE_STARTER_YEARLY', ''),
            'creator' => env('PADDLE_PRICE_CREATOR_YEARLY', ''),
            'pro'     => env('PADDLE_PRICE_PRO_YEARLY', ''),
            'agency'  => env('PADDLE_PRICE_AGENCY_YEARLY', ''),
        ],

        // Paddle price IDs for credit top-up packs (price_id => credit amount)
        'topup_prices' => [
            env('PADDLE_PRICE_TOPUP_SMALL', '')  => 500,    // $5
            env('PADDLE_PRICE_TOPUP_MEDIUM', '') => 1200,   // $10
            env('PADDLE_PRICE_TOPUP_LARGE', '')  => 3000,   // $22
            env('PADDLE_PRICE_TOPUP_XL', '')     => 8000,   // $55
        ],

        // Top-up packs metadata for the frontend (label, credits, price)
        'topup_packs' => [
            ['key' => 'small',  'credits' => 500,  'price_usd' => 5,  'price_id' => env('PADDLE_PRICE_TOPUP_SMALL', '')],
            ['key' => 'medium', 'credits' => 1200, 'price_usd' => 10, 'price_id' => env('PADDLE_PRICE_TOPUP_MEDIUM', '')],
            ['key' => 'large',  'credits' => 3000, 'price_usd' => 22, 'price_id' => env('PADDLE_PRICE_TOPUP_LARGE', '')],
            ['key' => 'xl',     'credits' => 8000, 'price_usd' => 55, 'price_id' => env('PADDLE_PRICE_TOPUP_XL', '')],
        ],

        // Base URL differs between sandbox and production
        'api_base' => env('PADDLE_SANDBOX', true)
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com',
    ],
];
