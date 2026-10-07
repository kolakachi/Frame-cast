<?php

return [
    // Local integration only. A production rollout needs its own acceptance gate.
    'enabled' => (bool) env('CREATE_ENABLED', false),
    // Require host/instance/slot identity only after API migration and worker rollout.
    'worker_ownership_required' => (bool) env('CREATE_WORKER_OWNERSHIP_REQUIRED', false),
    // Enable on every API/planning process only after the runtime-control migration.
    'runtime_controls_enabled' => (bool) env('CREATE_RUNTIME_CONTROLS_ENABLED', false),
    // Enable only after migration + dedicated planning worker + shared/private Create storage are ready.
    'durable_planning' => (bool) env('CREATE_DURABLE_PLANNING', false),
    // Plans one workspace may have running at once across the planning workers (worker-create-planning replicas).
    'planning_per_workspace' => (int) env('CREATE_PLANNING_PER_WORKSPACE', 2),
    // Email when the estimated Anthropic balance (create:model-balance) falls below this many dollars.
    'model_balance_warn_usd' => (float) env('CREATE_MODEL_BALANCE_WARN_USD', 40),
    // Separate from the public/MinIO-compatible b2 alias. Existing files keep their catalogued disk.
    'storage_disk' => env('CREATE_STORAGE_DISK', 'local'),
    'disk_min_free_bytes' => (int) env('CREATE_DISK_MIN_FREE_BYTES', 2147483648),
    'disk_min_free_ratio' => (float) env('CREATE_DISK_MIN_FREE_RATIO', .05),
    'disk_working_bytes' => (int) env('CREATE_DISK_WORKING_BYTES', 1073741824),
    'cache_retention_hours' => (int) env('CREATE_CACHE_RETENTION_HOURS', 24),
    'local_maintenance_enabled' => (bool) env('CREATE_LOCAL_MAINTENANCE_ENABLED', false),
    'workspaces' => array_filter(array_map('intval', explode(',', (string) env('CREATE_WORKSPACES', '')))),
    // Who may see and use Create (owner, 2026-10-06): accounts on these email domains, plus these addresses.
    'allowed_domains' => array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) env('CREATE_ALLOWED_DOMAINS', 'wyvstudio.com'))))),
    'allowed_emails' => array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) env('CREATE_ALLOWED_EMAILS', 'kolakachi@gmail.com'))))),
    'worker_token' => env('CREATE_WORKER_TOKEN', ''),
    'mode' => env('CREATE_MODE', 'fixture'),
    // Native script-to-speech video. Cloned voices use the separate audio-driven route.
    'native_talking_engine' => env('CREATE_NATIVE_TALKING_ENGINE', 'omni'),
    // Local-only opt-in; both a durable budget identity and a spending ceiling are required.
    'paid_execution_enabled' => (bool) env('CREATE_PAID_EXECUTION_ENABLED', false),
    // Local testing without limits (owner, 2026-10-03): call, repair, time, size and spend limits are lifted
    // so trajectories show where limits are needed. Ignored outside local/testing. Safety boundaries stay.
    'unlimited' => (bool) env('CREATE_UNLIMITED', false),
    // Reference study coverage: standard or every_look (one frame per distinct look, uncapped). Empty: every_look while unlimited.
    'reference_coverage' => (string) env('CREATE_REFERENCE_COVERAGE', ''),
    'pilot_budget_id' => env('CREATE_PILOT_BUDGET_ID', ''),
    'pilot_budget_microusd' => (int) env('CREATE_PILOT_BUDGET_MICROUSD', 0),
    // Planning is free to the user; WyvStudio funds up to three bounded calls with reference inspection.
    'inspection_node' => env('CREATE_INSPECTION_NODE', 'node'),
    // offline = deterministic planner (always used in fixture mode);
    // replicate = a Claude model on Replicate; anthropic = Claude API directly.
    'planner' => env('CREATE_PLANNER', 'offline'),
    // The build agent calls native tools (several per model call) instead of one JSON action per call.
    'tool_mode' => (bool) env('CREATE_TOOL_MODE', false),
    // Thinking effort for the planner (the beat sheet and script); the build agent has its own setting.
    'planner_effort' => env('CREATE_PLANNER_EFFORT', 'high'),
    'planner_model' => env('CREATE_PLANNER_MODEL', 'anthropic/claude-sonnet-5'),
    // The planner model by task (owner decision 2026-10-05, routed in code): new creative direction (a first plan, or a
    // follow-up that rewrites the brief) on the stronger model; short follow-up edits on planner_model.
    'planner_model_creative' => env('CREATE_PLANNER_MODEL_CREATIVE', 'claude-opus-5-5'),
    // Cheap vision checks (storyboard panels against their direction): a small model, never the creative one.
    'check_model' => env('CREATE_CHECK_MODEL', 'claude-haiku-4-5-20251001'),
    // How a reference video is read (todo G2): 'opus' sends every sheet to the creative model; 'split' has the check
    // model write per-frame facts and the creative model read them plus the motion frames. Default until the A/B decides.
    'study_mode' => env('CREATE_STUDY_MODE', 'opus'),
    // Planning is billed at half its cost, never free: a plan starts only when the balance covers a typical charge
    // (about 28 credits without a reference, 50 to 60 with a reference study).
    'planning_min_credits' => (int) env('CREATE_PLANNING_MIN_CREDITS', 60),
    // Owner decision 2026-09-29: jobs up to this many credits run without a
    // separate approval, after provider consent in the conversation.
    'auto_run_credits' => (int) env('CREATE_AUTO_RUN_CREDITS', 15),
    'auto_run_daily_limit' => (int) env('CREATE_AUTO_RUN_DAILY_LIMIT', 20),
    // Paid runs a workspace may start per day (free edits excluded).
    'run_daily_limit' => (int) env('CREATE_RUN_DAILY_LIMIT', 10),
    'free_edit_daily_limit' => (int) env('CREATE_FREE_EDIT_DAILY_LIMIT', 60),
    // Build agent provider for paid local runs: replicate (Sonnet 4.5) or
    // anthropic (Claude API through the app's gateway).
    'agent_provider' => env('CREATE_AGENT_PROVIDER', 'replicate'),
    'agent_model' => env('CREATE_AGENT_MODEL', 'claude-opus-5-5'),
    // Opus 5.5 cannot turn thinking off; effort bounds it (low, medium, high, xhigh, max).
    'agent_effort' => env('CREATE_AGENT_EFFORT', 'medium'),
    // Microdollars per token (= dollars per million tokens). Opus 5.5 list price.
    // Vendor alerts (VendorAlerts): who hears when our account with a vendor fails, besides the super admins, and how
    // long new work that needs that vendor is held after it does.
    'admin_alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALERT_EMAILS', 'kolakachi@gmail.com'))))),
    'vendor_hold_minutes' => (int) env('CREATE_VENDOR_HOLD_MINUTES', 10),
    'anthropic_rates' => ['input' => 4, 'output' => 20, 'cache_write' => 5, 'cache_read' => 0.2],
    // The smaller models planning uses besides the planner (questions, file roles), in micro-dollars a token.
    // Builder models a super admin may try on one conversation (settings.model_test), to compare speed and quality
    // without changing the model for everyone.
    'test_models' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'],
    // Haiku 5.5 costs more for a prompt over 100,000 tokens ('long', from 'long_above' input tokens).
    'model_rates' => ['claude-haiku-5-5' => ['input' => 0.1, 'output' => 0.5, 'cache_write' => 0.125, 'cache_read' => 0.01, 'long_above' => 100000, 'long' => ['input' => 0.5, 'output' => 2.5, 'cache_write' => 0.625, 'cache_read' => 0.05]],
        'claude-sonnet-5-5' => ['input' => 2, 'output' => 10, 'cache_write' => 2.5, 'cache_read' => 0.1], 'claude-sonnet-5' => ['input' => 3, 'output' => 15, 'cache_write' => 3.75, 'cache_read' => 0.3], 'claude-haiku-4-5-20251001' => ['input' => 1, 'output' => 5, 'cache_write' => 1.25, 'cache_read' => 0.1]],
    // Word-timed transcripts of supplied speech (free; OpenAI Whisper cost is about $0.006 a minute).
    'transcript_daily_limit' => (int) env('CREATE_TRANSCRIPT_DAILY_LIMIT', 30),
    'transcript_max_seconds' => 600,
    // Public posts pasted as style references (X, YouTube, TikTok). Fetched
    // privately, attached as reference only, never placed in an output.
    'reference_hosts' => ['x.com', 'twitter.com', 'mobile.twitter.com', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'tiktok.com', 'www.tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com'],
    'reference_daily_limit' => (int) env('CREATE_REFERENCE_DAILY_LIMIT', 20),
    'reference_max_seconds' => 300,
    'ytdlp_path' => env('CREATE_YTDLP_PATH', 'yt-dlp'),
    'chromium_path' => env('CREATE_CHROMIUM_PATH', 'chromium'),
    'lease_seconds' => 90,
    // Builds that may run at once across all workspaces, and per workspace. Builds mostly wait on model calls; renders
    // still take the worker's one sandbox slot in turn. Raise only after a measured load test on the worker host.
    'max_running' => (int) env('CREATE_MAX_RUNNING', 1),
    'max_running_per_workspace' => (int) env('CREATE_MAX_RUNNING_PER_WORKSPACE', 1),
    // Local upload storage per workspace (bytes); a heavy test workspace can be given more with this variable.
    'input_workspace_bytes' => (int) env('CREATE_INPUT_WORKSPACE_BYTES', 1024 * 1024 * 1024),
    'input_file_bytes' => 100 * 1024 * 1024,
    'input_total_bytes' => 200 * 1024 * 1024,
];
