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

        /*
        |---------------------------------------------------------------
        | プロキシ設定 (netkeiba が IP ブロックされた場合に使用)
        |---------------------------------------------------------------
        | NETKEIBA_PROXY        : http://user:pass@host:port  (http/https/socks5 対応)
        | NETKEIBA_PROXY_HTTPS  : 任意。https専用プロキシを別指定したい時
        | NETKEIBA_PROXY_NO     : 'localhost,127.0.0.1' のように除外ホストをカンマ区切り
        | NETKEIBA_PROXY_VERIFY : 自己署名証明書プロキシなら false にする (デフォ true)
        |
        | 例: NETKEIBA_PROXY="http://user:pass@proxy.example.com:8080"
        | 例: NETKEIBA_PROXY="socks5://user:pass@127.0.0.1:1080"
        */
        'proxy'         => env('NETKEIBA_PROXY'),
        'proxy_https'   => env('NETKEIBA_PROXY_HTTPS'),
        'proxy_no'      => env('NETKEIBA_PROXY_NO'),
        'proxy_verify'  => filter_var(env('NETKEIBA_PROXY_VERIFY', true), FILTER_VALIDATE_BOOLEAN),

        // 任意の Cookie (netkeiba 会員ログイン後の Cookie をそのまま貼る用)
        // 例: NETKEIBA_COOKIE="nkauth=xxxxx; nkuser=yyyyy"
        'cookie' => env('NETKEIBA_COOKIE'),

        // デバッグ: 取得した HTML を storage/logs/ に保存する
        // 注: env() 直接呼び出しは config:cache 後に動作しないため必ず config 経由で読む
        'debug_save' => filter_var(env('NETKEIBA_DEBUG_SAVE', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
