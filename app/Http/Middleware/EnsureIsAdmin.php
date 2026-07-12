<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * セキュリティ強化: 管理者専用機能へのアクセス制御
 *
 * DBビューア・運用ダッシュボード・データインポート・レース/馬マスタの
 * 作成編集削除など、アプリ全体に影響する操作は is_admin=true のユーザーのみ
 * 実行可能とする。一般会員登録ユーザー(is_admin=false)は 403 で拒否する。
 *
 * 前提: `auth` ミドルウェアの後段で使うこと (未ログイン時は auth 側で
 * ログイン画面へリダイレクトされる)。
 */
class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(!$user || !$user->is_admin, 403, 'この機能は管理者のみ利用できます。');

        return $next($request);
    }
}
