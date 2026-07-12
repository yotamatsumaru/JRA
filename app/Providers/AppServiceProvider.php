<?php

namespace App\Providers;

use App\Services\NotificationService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Tailwind ベースのページネーション
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');

        // 本番環境ではHTTPSを強制（XServer用）
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        // セキュリティ強化: パスワードポリシーの既定値
        //   - 8文字以上 / 大文字・小文字・数字を混在必須
        //   - 本番環境のみ、漏洩済みパスワードDB(Have I Been Pwned)照合も追加
        //     (ローカル/テスト環境ではネットワーク疎通が無い場合があるため無効化)
        Password::defaults(function () {
            $rule = Password::min(8)->mixedCase()->numbers();
            if (app()->environment('production')) {
                $rule = $rule->uncompromised();
            }
            return $rule;
        });

        // Phase 6-A: ヘッダーベル用の未読件数を全ビューで共有
        View::composer('layouts.app', function ($view) {
            $count = 0;
            try {
                if (Auth::check() && Schema::hasTable('app_notifications')) {
                    $count = app(NotificationService::class)->unreadCount((int) Auth::id());
                }
            } catch (\Throwable $e) {
                $count = 0;
            }
            $view->with('headerUnreadNotifications', $count);
        });
    }
}
