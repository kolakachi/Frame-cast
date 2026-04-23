<?php

return [
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
