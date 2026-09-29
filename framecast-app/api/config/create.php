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
    // Planning is free to the user; WyvStudio pays for one model call per plan.
    // offline = deterministic planner (always used in fixture mode);
    // replicate = a Claude model on Replicate; anthropic = Claude API directly.
    'planner' => env('CREATE_PLANNER', 'offline'),
    'planner_model' => env('CREATE_PLANNER_MODEL', 'anthropic/claude-sonnet-5'),
    'plan_daily_limit' => (int) env('CREATE_PLAN_DAILY_LIMIT', 40),
    // Owner decision 2026-09-29: jobs up to this many credits run without a
    // separate approval, after provider consent in the conversation.
    'auto_run_credits' => (int) env('CREATE_AUTO_RUN_CREDITS', 15),
    'auto_run_daily_limit' => (int) env('CREATE_AUTO_RUN_DAILY_LIMIT', 20),
    'free_edit_daily_limit' => (int) env('CREATE_FREE_EDIT_DAILY_LIMIT', 60),
    // Build agent provider for paid local runs: replicate (Sonnet 4.5) or
    // anthropic (Claude API through the app's gateway).
    'agent_provider' => env('CREATE_AGENT_PROVIDER', 'replicate'),
    'agent_model' => env('CREATE_AGENT_MODEL', 'claude-opus-5-5'),
    // Microdollars per token (= dollars per million tokens). Opus 5.5 list price.
    'anthropic_rates' => ['input' => 4, 'output' => 20, 'cache_write' => 5, 'cache_read' => 0.2],
    'lease_seconds' => 90,
    'input_workspace_bytes' => 1024 * 1024 * 1024,
    'input_file_bytes' => 100 * 1024 * 1024,
    'input_total_bytes' => 200 * 1024 * 1024,
];
