<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | OpenAI (画像認識用)
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Netkeiba スクレイピング設定
    |--------------------------------------------------------------------------
    */
    'netkeiba' => [
        'base_url' => env('NETKEIBA_BASE_URL', 'https://db.netkeiba.com'),
        'request_interval' => (int) env('NETKEIBA_REQUEST_INTERVAL', 5),
        'user_agent' => env('NETKEIBA_USER_AGENT', 'JRAAnalyzer/1.0 (Personal Use)'),
        'timeout' => (int) env('NETKEIBA_TIMEOUT', 30),
    ],

];
