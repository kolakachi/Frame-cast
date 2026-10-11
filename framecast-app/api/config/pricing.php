<?php

/*
| What our vendors charge us, in US dollars (2026-10-11). One place for the usage recorder
| (App\Services\Usage\VendorUsageRecorder), which records the real cost of every finished Replicate prediction and
| every Claude reply in api_usage_events. Credit prices (what customers pay) live in CreditService and are separate.
|
| Replicate, by model:
|   per_second  dollars per second of output video (or audio); a map picks by the input's resolution (or 'by' key)
|   per_image   dollars per output image
|   per_run     dollars per prediction; a map picks by resolution
|   no_audio    the per-second rate when the input turns sound off (Veo)
| A model not listed is priced from its run time at time_rate, and recorded as an estimate.
*/

return [
    'replicate' => [
        'google/gemini-omni-1.1' => ['per_second' => 0.15],
        'google/veo-3.1' => ['per_second' => 0.40, 'no_audio' => 0.20],
        'google/veo-3.1-fast' => ['per_second' => 0.15, 'no_audio' => 0.10],
        'bytedance/seedance-2.5' => ['per_second' => ['480p' => 0.1028, '720p' => 0.2312], 'default' => '720p'],
        'bytedance/seedance-1-lite' => ['per_second' => ['480p' => 0.018, '720p' => 0.036, '1080p' => 0.072], 'default' => '720p'],
        'bytedance/seedance-1-pro' => ['per_second' => ['480p' => 0.03, '720p' => 0.06, '1080p' => 0.15], 'default' => '1080p'],
        'wan-video/wan-2.5-i2v' => ['per_second' => ['480p' => 0.05, '720p' => 0.10, '1080p' => 0.15], 'default' => '480p'],
        'minimax/hailuo-2.3-fast' => ['per_run' => ['768p' => 0.19, '1080p' => 0.33], 'default' => '768p'],
        'kwaivgi/kling-v2.1' => ['per_second' => ['standard' => 0.05, 'pro' => 0.09], 'by' => 'mode', 'default' => 'pro'],
        'veed/fabric-1.0' => ['per_second' => ['480p' => 0.08, '720p' => 0.15], 'default' => '480p'],
        'google/nano-banana' => ['per_image' => 0.039],
        'google/nano-banana-pro' => ['per_image' => 0.134],
        'google/imagen-4' => ['per_image' => 0.04],
        'black-forest-labs/flux-schnell' => ['per_image' => 0.003],
        'bytedance/sdxl-lightning-4step' => ['per_image' => 0.003],
    ],
    // Dollars per second of run time for a model priced by hardware or not listed above (an L40S-class rate).
    'replicate_time_rate' => 0.000725,
    // Models whose adapter already records its own usage row: the recorder skips them so nothing is counted twice.
    'replicate_self_recorded' => ['anthropic/claude-sonnet-5', 'google/gemini-3.1-flash-tts', 'resemble-ai/chatterbox'],

    /*
    | Money in (revenue_events). AppSumo keeps most of each licence sale; Kelviq, as merchant of record, may keep a fee.
    | AppSumo list prices per tier are those in config/appsumo.php's notes.
    */
    'revenue' => [
        'appsumo_cut' => (float) env('APPSUMO_CUT', 0.75),
        // AppSumo list prices per tier (owner's screenshot, 2026-10-11): Starter $49, Creator $147, Agency $339.
        'appsumo_price_usd' => [1 => 49, 2 => 147, 3 => 339],
        // AppSumo campaigns: a licence bought inside a window paid the list price less the discount. AppSumo's webhook
        // does not carry the price, so this is how the sale's amount is known. The start of the current 15% campaign
        // is assumed (the screenshot only shows its end, about 2026-10-31).
        'appsumo_campaigns' => [
            ['from' => '2026-10-11 00:00:00', 'to' => '2026-10-31 23:59:59', 'discount' => 0.15],
        ],
        'kelviq_fee_rate' => (float) env('KELVIQ_FEE_RATE', 0),
    ],
];
