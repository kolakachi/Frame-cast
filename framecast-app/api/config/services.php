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

    'pixabay' => [
        'api_key' => env('PIXABAY_API_KEY'),
    ],

];
