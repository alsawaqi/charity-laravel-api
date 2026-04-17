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
        'key' => env('POSTMARK_API_KEY'),
    ],



    'scalefusion' => [
        'token' => env('SCALEFUSION_TOKEN'),
        'base_v3' => env('SCALEFUSION_BASE_V3', 'https://api.scalefusion.com/api/v3'),
        'base_v1' => env('SCALEFUSION_BASE_V1', 'https://api.scalefusion.com/api/v1'),
        'cache_store' => env('SCALEFUSION_CACHE_STORE', env('CACHE_STORE', 'database')),
        'summary_ttl_seconds' => (int) env('SCALEFUSION_SUMMARY_TTL', 60),
        'stale_ttl_seconds' => (int) env('SCALEFUSION_STALE_TTL', 900),
        'cache_lock_seconds' => (int) env('SCALEFUSION_CACHE_LOCK_SECONDS', 20),
        'http_timeout_seconds' => (int) env('SCALEFUSION_TIMEOUT_SECONDS', 8),
        'http_retry_attempts' => (int) env('SCALEFUSION_HTTP_RETRY_ATTEMPTS', 3),
        'per_page' => (int) env('SCALEFUSION_PER_PAGE', 200),
        'max_pages' => (int) env('SCALEFUSION_MAX_PAGES', 25),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
