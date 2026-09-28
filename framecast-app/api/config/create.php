<?php

return [
    // Local integration only. A production rollout needs its own acceptance gate.
    'enabled' => (bool) env('CREATE_ENABLED', false),
    'workspaces' => array_filter(array_map('intval', explode(',', (string) env('CREATE_WORKSPACES', '')))),
    'worker_token' => env('CREATE_WORKER_TOKEN', ''),
    'mode' => env('CREATE_MODE', 'fixture'),
    'lease_seconds' => 90,
];
