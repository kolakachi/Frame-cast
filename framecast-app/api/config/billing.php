<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paddle Billing
    |--------------------------------------------------------------------------
    |
    | All values are read from environment variables so secrets never live
    | in source control. The price IDs map each workspace plan_tier to the
    | corresponding Paddle price. Set PADDLE_SANDBOX=true in development.
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
