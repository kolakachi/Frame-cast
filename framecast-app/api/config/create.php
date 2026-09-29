<?php

return [
    // Local integration only. A production rollout needs its own acceptance gate.
    'enabled' => (bool) env('CREATE_ENABLED', false),
    'workspaces' => array_filter(array_map('intval', explode(',', (string) env('CREATE_WORKSPACES', '')))),
    'worker_token' => env('CREATE_WORKER_TOKEN', ''),
    'mode' => env('CREATE_MODE', 'fixture'),
    // Local-only opt-in; both a durable budget identity and a spending ceiling are required.
    'paid_execution_enabled' => (bool) env('CREATE_PAID_EXECUTION_ENABLED', false),
    'pilot_budget_id' => env('CREATE_PILOT_BUDGET_ID', ''),
    'pilot_budget_microusd' => (int) env('CREATE_PILOT_BUDGET_MICROUSD', 0),
    'lease_seconds' => 90,
    'input_workspace_bytes' => 1024 * 1024 * 1024,
    'input_file_bytes' => 100 * 1024 * 1024,
    'input_total_bytes' => 200 * 1024 * 1024,
];
