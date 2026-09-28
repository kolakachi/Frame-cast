<?php

return [
    // Local integration only. A production rollout needs its own acceptance gate.
    'enabled' => (bool) env('CREATE_ENABLED', false),
    'workspaces' => array_filter(array_map('intval', explode(',', (string) env('CREATE_WORKSPACES', '')))),
    'worker_token' => env('CREATE_WORKER_TOKEN', ''),
    'mode' => env('CREATE_MODE', 'fixture'),
    // No environment switch until provider receipts and customer pricing pass acceptance.
    'paid_execution_enabled' => false,
    'lease_seconds' => 90,
    'input_workspace_bytes' => 1024 * 1024 * 1024,
    'input_file_bytes' => 100 * 1024 * 1024,
    'input_total_bytes' => 200 * 1024 * 1024,
];
