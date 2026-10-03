<?php

return [
    // Local integration only. A production rollout needs its own acceptance gate.
    'enabled' => (bool) env('CREATE_ENABLED', false),
    'workspaces' => array_filter(array_map('intval', explode(',', (string) env('CREATE_WORKSPACES', '')))),
    'worker_token' => env('CREATE_WORKER_TOKEN', ''),
    'mode' => env('CREATE_MODE', 'fixture'),
    // Native script-to-speech video. Cloned voices use the separate audio-driven route.
    'native_talking_engine' => env('CREATE_NATIVE_TALKING_ENGINE', 'omni'),
    // Local-only opt-in; both a durable budget identity and a spending ceiling are required.
    'paid_execution_enabled' => (bool) env('CREATE_PAID_EXECUTION_ENABLED', false),
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
    'plan_daily_limit' => (int) env('CREATE_PLAN_DAILY_LIMIT', 40),
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
    'anthropic_rates' => ['input' => 4, 'output' => 20, 'cache_write' => 5, 'cache_read' => 0.2],
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
    'input_workspace_bytes' => 1024 * 1024 * 1024,
    'input_file_bytes' => 100 * 1024 * 1024,
    'input_total_bytes' => 200 * 1024 * 1024,
];
