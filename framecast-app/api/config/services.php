<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'youtube' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri'  => env('YOUTUBE_REDIRECT_URI', env('APP_URL').'/api/v1/social/youtube/callback'),
    ],

    'tiktok' => [
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect_uri'  => env('TIKTOK_REDIRECT_URI', env('APP_URL').'/api/v1/social/tiktok/callback'),
    ],

    'meta' => [
        'app_id'                  => env('META_APP_ID'),
        'app_secret'              => env('META_APP_SECRET'),
        'graph_version'           => env('META_GRAPH_VERSION', 'v21.0'),
        'instagram_redirect_uri'  => env('META_INSTAGRAM_REDIRECT_URI', env('APP_URL').'/api/v1/social/instagram/callback'),
        'facebook_redirect_uri'   => env('META_FACEBOOK_REDIRECT_URI', env('APP_URL').'/api/v1/social/facebook/callback'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'timestamp_transcription_model' => env('OPENAI_TIMESTAMP_TRANSCRIPTION_MODEL', 'whisper-1'),
        // Character / reference-image generation via /v1/images/edits. gpt-image-2's
        // image[] input takes one or more reference photos and produces a new image
        // that respects the reference identity. Replaced the earlier Replicate
        // ideogram-character path (better scene/style adherence, same vendor as our
        // text-only image path).
        'character_model' => env('OPENAI_CHARACTER_MODEL', 'gpt-image-2'),
    ],

    'pexels' => [
        'api_key' => env('PEXELS_API_KEY'),
    ],

    'replicate' => [
        'api_token' => env('REPLICATE_API_TOKEN'),

        // ── Image-to-video (rung 4) — three tiers, each a different upstream model.
        // Slugs use Replicate's official model-versioned endpoint (no hash needed —
        // the adapter calls /v1/models/{slug}/predictions and gets the current
        // official version automatically). Set REPLICATE_I2V_*_VERSION to pin a
        // specific community-model hash instead.
        'i2v_quick_model'      => env('REPLICATE_I2V_QUICK_MODEL',    'wan-video/wan-2.5-i2v'),
        'i2v_quick_version'    => env('REPLICATE_I2V_QUICK_VERSION',  ''),
        'i2v_balanced_model'   => env('REPLICATE_I2V_BALANCED_MODEL', 'minimax/hailuo-2.3-fast'),
        'i2v_balanced_version' => env('REPLICATE_I2V_BALANCED_VERSION', ''),
        'i2v_premium_model'    => env('REPLICATE_I2V_PREMIUM_MODEL',  'kwaivgi/kling-v2.1'),
        'i2v_premium_version'  => env('REPLICATE_I2V_PREMIUM_VERSION', ''),
        // ByteDance Seedance — two flavors. Lite for cheap iteration,
        // Pro for higher fidelity. Both versionless endpoints work
        // (official Replicate models, no version pin required).
        'i2v_seedance_lite_model'   => env('REPLICATE_I2V_SEEDANCE_LITE_MODEL', 'bytedance/seedance-1-lite'),
        'i2v_seedance_lite_version' => env('REPLICATE_I2V_SEEDANCE_LITE_VERSION', ''),
        'i2v_seedance_pro_model'    => env('REPLICATE_I2V_SEEDANCE_PRO_MODEL',  'bytedance/seedance-1-pro'),
        'i2v_seedance_pro_version'  => env('REPLICATE_I2V_SEEDANCE_PRO_VERSION', ''),

        // MusicGen for the one-shot prompt flow's background music.
        // 'meta/musicgen' is the cheap workhorse; switch to 'meta/musicgen-melody-large'
        // for higher-quality output at ~5x the cost.
        //
        // The versionless endpoint (/v1/models/meta/musicgen/predictions)
        // returns 404 for this model — Replicate only routes a subset of
        // "official models" via that path, and meta/musicgen isn't one.
        // Default to a known-good version hash so the flow works out of
        // the box; override via env if you want a newer pin.
        'musicgen_model'   => env('REPLICATE_MUSICGEN_MODEL',   'meta/musicgen'),
        'musicgen_version' => env('REPLICATE_MUSICGEN_VERSION', '671ac645ce5e552cc63a54a2bbff63fcf798043055d2dac5fc9e36a837eedcfb'),
    ],

    'pixabay' => [
        'api_key' => env('PIXABAY_API_KEY'),
    ],

];
