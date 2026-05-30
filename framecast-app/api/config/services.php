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
    ],

    'pexels' => [
        'api_key' => env('PEXELS_API_KEY'),
    ],

    'replicate' => [
        'api_token' => env('REPLICATE_API_TOKEN'),
        // Character / face-consistency image generation. ideogram-character is
        // purpose-built ("Generate consistent characters from a single reference
        // image. Outputs can be in many styles.") and produces noticeably more
        // natural identity preservation than the older flux-pulid we used before.
        // Uses the official-model endpoint so no version pinning is needed.
        'character_model'   => env('REPLICATE_CHARACTER_MODEL',   'ideogram-ai/ideogram-character'),
        'character_version' => env('REPLICATE_CHARACTER_VERSION', ''),

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
    ],

    'pixabay' => [
        'api_key' => env('PIXABAY_API_KEY'),
    ],

];
