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
        // ⚠ 'JRAAnalyzer/1.0' のような独自UAは netkeiba 側で 400 ブロックされるため、
        //   実在ブラウザ風 UA をデフォルトに採用。.env で上書き可。
        'user_agent' => env('NETKEIBA_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
        'timeout' => (int) env('NETKEIBA_TIMEOUT', 30),
    ],

];
