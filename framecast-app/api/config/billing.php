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

        // Paddle price IDs — one price per plan (monthly)
        'price_ids' => [
            'studio'     => env('PADDLE_PRICE_STUDIO', ''),
            'scale'      => env('PADDLE_PRICE_SCALE', ''),
            'enterprise' => env('PADDLE_PRICE_ENTERPRISE', ''),
        ],

        // Base URL differs between sandbox and production
        'api_base' => env('PADDLE_SANDBOX', true)
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com',
    ],
];
