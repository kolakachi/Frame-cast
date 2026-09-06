<?php

return [

    /**
     * Internal / test accounts. Broadcast segments in the admin mail tool
     * always exclude these, whatever their plan tier or workspace status —
     * suspension happened to hide them so far, which is protection by
     * accident, not by design.
     */
    'internal_emails' => [
        'kolakachi@gmail.com',
        'cutestnavybrown@gmail.com',
        'testa2@gmail.com',
        'testa3@gmail.com',
        'testa@gmail.com',
        'googletest@wyvstudio.com',
        // Not customers — they were showing up in the `free` segment and had
        // to be unticked by hand every time.
        'hi@kelviq.com',              // our merchant of record, not a user
        'offeriq1@gmail.com',         // our own account
        'testingtester@mailinator.com', // disposable address used in testing
    ],

    /*
     * Comma-separated IPs or CIDR ranges allowed to access admin endpoints.
     * Empty string = no restriction (safe for dev/staging).
     * Example: "203.0.113.10,192.168.1.0/24"
     */
    'allowed_ips' => env('ADMIN_ALLOWED_IPS', ''),

    /*
     * How long an impersonation session token remains valid (minutes).
     */
    'impersonation_ttl_minutes' => (int) env('ADMIN_IMPERSONATION_TTL', 15),
];
