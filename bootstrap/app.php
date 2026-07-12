<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Flutterアプリ(モバイル)用APIトークン認証 (Laravel Sanctum)
        // 既存のWeb画面(セッション認証)には影響しない
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // セキュリティ強化: 全レスポンスに標準セキュリティヘッダーを付与
        // (クリックジャッキング対策・MIMEスニッフィング対策等。web/api 両方に適用)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // セキュリティ強化: 管理者専用機能(DBビューア/運用ダッシュボード/インポート等)
        // へのアクセス制御用ミドルウェアエイリアス。ルート側で ->middleware('admin') と指定する。
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
