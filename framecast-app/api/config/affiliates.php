<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payout policy
    |--------------------------------------------------------------------------
    |
    | Stated to affiliates on their dashboard and on every statement, so these
    | are a promise rather than an implementation detail. Changing one changes
    | what people have been told.
    */

    // A commission cannot be paid until it has survived the refund window.
    //
    // 21 days is not a round number, it is the policy: the refund page offers
    // 14 days to ask and up to 5 business days to process, so a refund can
    // legitimately land three weeks after the sale. Paying sooner guarantees
    // clawbacks — asking a marketer to return money is a worse conversation
    // than asking them to wait.
    'hold_days' => (int) env('AFFILIATE_HOLD_DAYS', 21),

    // How often runs go out. Maturity and cadence are independent: the cycle
    // keeps its schedule and simply pays whatever has matured by then, so a
    // steady affiliate is still paid fortnightly.
    'cycle_days' => (int) env('AFFILIATE_CYCLE_DAYS', 14),

    // Anything owed is paid, however small. A threshold saves transfer fees at
    // the cost of telling someone their money exists but is not yet theirs.
    'minimum_payout' => (float) env('AFFILIATE_MINIMUM_PAYOUT', 0),

    // Commissions accrue in USD; money arrives in naira.
    'commission_currency' => env('AFFILIATE_COMMISSION_CURRENCY', 'USD'),
    'payout_currency' => env('AFFILIATE_PAYOUT_CURRENCY', 'NGN'),

    // Details must exist and be checked before a run can include someone.
    'require_verified_details' => (bool) env('AFFILIATE_REQUIRE_VERIFIED_DETAILS', true),

    /*
    |--------------------------------------------------------------------------
    | Exchange rate
    |--------------------------------------------------------------------------
    |
    | The published mid-market rate is not what a transfer into a Nigerian
    | account actually achieves. Showing it unadjusted would quote an affiliate
    | a figure we cannot hit, and the shortfall would come out of the payout or
    | out of margin — either way discovered at the worst moment.
    |
    | So the displayed rate is mid-market less a spread, and the rate actually
    | used is recorded on the payout itself and never recomputed.
    */
    'fx' => [
        // open.er-api.com: free, keyless, and — unlike the ECB reference set,
        // which publishes only 30 major currencies and no naira at all — it
        // actually quotes the pair we need. The base currency is appended to
        // this path.
        'endpoint' => env('AFFILIATE_FX_ENDPOINT', 'https://open.er-api.com/v6/latest'),
        'spread_percent' => (float) env('AFFILIATE_FX_SPREAD', 2.0),
        'cache_minutes' => (int) env('AFFILIATE_FX_CACHE_MINUTES', 180),
        // Set to pin the rate by hand and stop calling out entirely.
        'override' => env('AFFILIATE_FX_OVERRIDE') !== null
            ? (float) env('AFFILIATE_FX_OVERRIDE')
            : null,
    ],
];
