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

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'default_model' => env('AI_VISIBILITY_MODEL', 'gpt-5.6-luna'),
        'reasoning_effort' => env('AI_VISIBILITY_REASONING_EFFORT', 'low'),
        'country' => env('AI_VISIBILITY_COUNTRY', 'IT'),
        // Used for models without a price below: deliberately high, so the budget cap errs on the safe side.
        'fallback_price' => ['input' => 2.00, 'output' => 10.00],
        // USD per web search call and per million tokens, from the OpenAI pricing page (11/2026).
        'web_search_price' => 0.01,
        'prices' => [
            'gpt-5.6-luna' => ['input' => 0.20, 'output' => 1.20],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
        ],
    ],

    'meta_ads' => [
        'api_version' => env('META_ADS_API_VERSION', 'v27.0'),
    ],

    'google_ads' => [
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        'customer_id' => env('GOOGLE_ADS_CUSTOMER_ID'),
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v26'),
        'daily_operations_limit' => (int) env('GOOGLE_ADS_DAILY_OPERATIONS_LIMIT', 1000),
        'cache_days' => (int) env('GOOGLE_ADS_CACHE_DAYS', 30),
        // languageConstants/1004 = Italian, geoTargetConstants/2380 = Italy.
        'default_language' => env('GOOGLE_ADS_DEFAULT_LANGUAGE', '1004'),
        'default_geo' => env('GOOGLE_ADS_DEFAULT_GEO', '2380'),
        'languages' => [
            'it' => '1004',
            'en' => '1000',
            'de' => '1001',
            'fr' => '1002',
            'es' => '1003',
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

];
