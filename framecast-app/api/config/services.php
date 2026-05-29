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
        // Pinned version of zsxkib/flux-pulid for character / face-consistency image generation.
        // Override REPLICATE_PULID_VERSION in env to swap to a newer revision.
        'pulid_model'   => env('REPLICATE_PULID_MODEL',   'zsxkib/flux-pulid'),
        'pulid_version' => env('REPLICATE_PULID_VERSION', '8baa7ef2255075b46f4d91cd238c21d31181b3e6a864463f967960bb0112525b'),
        // Identity strength: higher → output sticks closer to the reference photo.
        // Sweet spot 1.0–1.5; 0.8 is creative-friendly; 2.0+ tends to look overfit/plasticky.
        'pulid_id_weight'      => env('REPLICATE_PULID_ID_WEIGHT', 1.2),
        // Prompt-vs-reference weight: lower → reference photo dominates the result.
        'pulid_guidance_scale' => env('REPLICATE_PULID_GUIDANCE_SCALE', 3),
        // More steps = better fidelity, slower render. This flux-pulid version caps
        // num_steps at 20 in its model schema; raising it will hard-fail with HTTP 422.
        'pulid_num_steps'      => env('REPLICATE_PULID_NUM_STEPS', 20),

        // ── Image-to-video (rung 4) — three tiers, each a different upstream model.
        // All slugs + version hashes overridable in env. Versions left empty until pinned
        // by hand from the model page on replicate.com (REPLICATE_I2V_*_VERSION).
        'i2v_quick_model'      => env('REPLICATE_I2V_QUICK_MODEL',    'zsxkib/wan-2.1-i2v-480p'),
        'i2v_quick_version'    => env('REPLICATE_I2V_QUICK_VERSION',  ''),
        'i2v_balanced_model'   => env('REPLICATE_I2V_BALANCED_MODEL', 'minimax/hailuo-02'),
        'i2v_balanced_version' => env('REPLICATE_I2V_BALANCED_VERSION', ''),
        'i2v_premium_model'    => env('REPLICATE_I2V_PREMIUM_MODEL',  'kwaivgi/kling-v2.1-master-i2v'),
        'i2v_premium_version'  => env('REPLICATE_I2V_PREMIUM_VERSION', ''),
    ],

    'pixabay' => [
        'api_key' => env('PIXABAY_API_KEY'),
    ],

];
