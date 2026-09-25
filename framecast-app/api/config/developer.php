<?php

return [
    // Enable only after migration and queue lifecycle verification.
    'recovery_attention_minutes' => (int) env('DEVELOPER_RECOVERY_ATTENTION_MINUTES', 30),
    'operation_accounting' => (bool) env('DEVELOPER_OPERATION_ACCOUNTING', false),
    /*
    |--------------------------------------------------------------------------
    | Developer API limits
    |--------------------------------------------------------------------------
    | Request limits are per minute. "reads" are GET/HEAD (capabilities,
    | status, result) so an integration can poll freely; "writes" are POST
    | (quotes, create). Both are counted twice: once against the key or user
    | making the call and once against the whole workspace, so several keys
    | cannot multiply a workspace's allowance.
    |
    | max_active_videos caps how many API-created projects may be generating
    | at once in a workspace. Rate limits bound request volume; this bounds
    | how much can be in flight, which is what actually bounds spend rate.
    */
    'limits' => [
        'reads_per_minute' => (int) env('DEVELOPER_API_READS_PER_MINUTE', 60),
        'writes_per_minute' => (int) env('DEVELOPER_API_WRITES_PER_MINUTE', 10),
        'workspace_reads_per_minute' => (int) env('DEVELOPER_API_WORKSPACE_READS_PER_MINUTE', 120),
        'workspace_writes_per_minute' => (int) env('DEVELOPER_API_WORKSPACE_WRITES_PER_MINUTE', 20),
        'max_active_videos' => (int) env('DEVELOPER_API_MAX_ACTIVE_VIDEOS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth for MCP connectors
    |--------------------------------------------------------------------------
    | An approved connector is a hidden API key owned by a grant; access tokens
    | are short-lived handles onto it. The issuer is the SPA origin because the
    | consent page lives there and, in production, the API shares the host.
    */
    'oauth' => [
        'issuer' => rtrim((string) env('OAUTH_ISSUER', env('FRONTEND_URL', 'https://app.wyvstudio.com')), '/'),
        'code_ttl_minutes' => 5,
        'access_ttl_minutes' => (int) env('OAUTH_ACCESS_TTL_MINUTES', 60),
        'refresh_ttl_days' => (int) env('OAUTH_REFRESH_TTL_DAYS', 90),
        'key_ttl_days' => (int) env('OAUTH_KEY_TTL_DAYS', 90),
        'scopes' => ['videos'],
    ],
];
