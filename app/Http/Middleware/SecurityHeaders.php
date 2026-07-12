<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * セキュリティ強化: レスポンスへ標準的なセキュリティHTTPヘッダーを付与する。
 *
 *  - X-Frame-Options: クリックジャッキング対策 (自ドメイン内の埋め込みのみ許可)
 *  - X-Content-Type-Options: MIME スニッフィング対策
 *  - Referrer-Policy: 外部サイトへの遷移時にURL全体を送らない
 *  - Permissions-Policy: 使用しないブラウザ機能を無効化
 *  - Strict-Transport-Security: 本番(HTTPS)環境のみ HSTS を有効化
 *  - X-XSS-Protection: legacy ブラウザ向け (現代ブラウザでは無害)
 *
 * 画面の表示・動作には影響しない、純粋な防御強化のみを行う。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=()'
        );

        // 本番環境かつ HTTPS 通信時のみ HSTS を付与
        // (ローカル開発 / HTTP環境で誤って強制すると開発が止まるため限定)
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
