<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Client workspaces
    |--------------------------------------------------------------------------
    |
    | Which tiers may own client sub-accounts. This is the only thing that makes
    | Agency mean more than a larger credit allowance, so it is deliberately the
    | top of the range.
    |
    | All three agency tiers are the same product to the customer, however they
    | arrived at it — monthly, one-time here, or an AppSumo licence — so they
    | are all listed.
    */
    'client_tiers' => array_filter(array_map('trim', explode(',', (string) env(
        'WORKSPACE_CLIENT_TIERS',
        'agency,lifetime_agency,appsumo_agency',
    )))),

    // A guard rather than a product limit: an agency that has quietly created
    // hundreds is either automating against us or has made a mistake.
    'max_clients' => (int) env('WORKSPACE_MAX_CLIENTS', 50),
];
