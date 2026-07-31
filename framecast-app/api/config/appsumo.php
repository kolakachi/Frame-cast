<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AppSumo Licensing API v2 (lifetime-deal / LTD accounts)
    |--------------------------------------------------------------------------
    |
    | Credentials come from the AppSumo Partner Portal once both the webhook URL
    | and OAuth redirect URL are validated. Until they're set, the webhook
    | endpoint stays inert (acknowledges but does not provision) so the routes
    | can be deployed ahead of go-live.
    |
    | Docs: https://docs.licensing.appsumo.com
    |
    */

    // Shared secret used to sign webhooks (HMAC-SHA256 over timestamp+body) and
    // to authenticate Licensing API calls (X-AppSumo-Licensing-Key header).
    'api_key' => env('APPSUMO_API_KEY', ''),

    // OAuth (customer login after purchase).
    'client_id'     => env('APPSUMO_CLIENT_ID', ''),
    'client_secret' => env('APPSUMO_CLIENT_SECRET', ''),
    // Must match the redirect URL registered in the Partner Portal exactly.
    'redirect_uri'  => env('APPSUMO_REDIRECT_URI', 'https://app.wyvstudio.com/api/v1/appsumo/oauth/callback'),

    // AppSumo OpenID / Licensing endpoints.
    'token_url'       => env('APPSUMO_TOKEN_URL', 'https://appsumo.com/openid/token/'),
    'license_key_url' => env('APPSUMO_LICENSE_KEY_URL', 'https://appsumo.com/openid/license_key/'),

    // Where to send the buyer after we've resolved their license, to collect an
    // email/password and finish account creation (SPA route).
    'activate_redirect' => env('APPSUMO_ACTIVATE_REDIRECT', 'https://app.wyvstudio.com/appsumo/activate'),

    /*
    |--------------------------------------------------------------------------
    | Tier map — AppSumo tier number → our plan + one-time credit bucket
    |--------------------------------------------------------------------------
    |
    | The webhook's integer `tier` (1,2,3) maps here. `plan_tier` drives gating
    | via CreditService::PLAN_LIMITS (appsumo_* keys). `credits` is granted ONCE
    | per license into credits_topup (the non-expiring pool) — LTD buckets never
    | refresh; when they run out the buyer buys top-ups at the normal rate.
    |
    | Pricing (AppSumo LTD):
    |   Tier 1 — Starter — $49  — 4,000 credits — 1 channel  / 1 workspace  / 2 characters
    |   Tier 2 — Creator — $139 — 12,000 credits — 3 channels / 5 workspaces / 5 characters
    |   Tier 3 — Agency  — $299 — 20,000 credits — ∞ channels / ∞ workspaces / 10 characters
    |
    */
    'tiers' => [
        1 => ['plan_tier' => 'appsumo_starter', 'credits' => 4000],
        2 => ['plan_tier' => 'appsumo_creator', 'credits' => 12000],
        3 => ['plan_tier' => 'appsumo_agency',  'credits' => 20000],
    ],
];
