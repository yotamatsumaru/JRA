<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * netkeiba.com への接続を診断するコマンド
 *
 * サーバ環境から各エンドポイントが取得できるか・ブロックされていないかを
 * 簡易チェックする。
 *
 *   php artisan netkeiba:diagnose
 *   php artisan netkeiba:diagnose --date=2025-09-13
 */
class NetkeibaDiagnose extends Command
{
    protected $signature = 'netkeiba:diagnose
        {--date=2025-09-13 : チェックする開催日 (YYYY-MM-DD)}
        {--race-id=202506040305 : チェックする race_id (12桁)}';

    protected $description = 'netkeiba.com への接続診断 (各エンドポイントのステータスとサイズを確認)';

    public function handle(): int
    {
        $date    = $this->option('date');
        $raceId  = $this->option('race-id');
        $ymd     = str_replace('-', '', $date);

        $userAgent = config('services.netkeiba.user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');

        // ============ ベースクライアント (現在の UA / 最小ヘッダ) ============
        $client = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'headers' => [
                'User-Agent'      => $userAgent,
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en;q=0.8',
            ],
        ]);

        $this->line('');
        $this->line("=== netkeiba.com 接続診断 ===");
        $this->line("date    : {$date}  (ymd={$ymd})");
        $this->line("race_id : {$raceId}");
        $this->line("UA      : " . mb_substr($userAgent, 0, 80) . '...');
        $this->line('');

        // ============ STEP 1: 各エンドポイントを通常ヘッダでテスト ============
        $this->info('--- STEP 1: 各 netkeiba エンドポイント (現UA + 最小ヘッダ) ---');
        $endpoints = [
            ['db   list', "https://db.netkeiba.com/race/list/{$ymd}/"],
            ['db   race', "https://db.netkeiba.com/race/{$raceId}/"],
            ['race list (sub)', "https://race.netkeiba.com/top/race_list_sub.html?kaisai_date={$ymd}"],
            ['race list (top)', "https://race.netkeiba.com/top/race_list.html?kaisai_date={$ymd}"],
            ['race result',     "https://race.netkeiba.com/race/result.html?race_id={$raceId}"],
            ['db   top',  "https://db.netkeiba.com/"],
            ['race top',  "https://race.netkeiba.com/"],
        ];
        foreach ($endpoints as [$label, $url]) {
            $this->testUrl($client, $label, $url);
        }

        // ============ STEP 2: 「ブラウザ完全模倣」ヘッダで race トップを再テスト ============
        $this->line('');
        $this->info('--- STEP 2: race.netkeiba.com トップを「完全ブラウザ模倣」ヘッダで再テスト ---');
        $clientBrowser = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'headers' => [
                'User-Agent'                => $userAgent,
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'ja,en-US;q=0.9,en;q=0.8',
                'Accept-Encoding'           => 'gzip, deflate, br',
                'Cache-Control'             => 'no-cache',
                'Pragma'                    => 'no-cache',
                'Sec-Ch-Ua'                 => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
                'Sec-Ch-Ua-Mobile'          => '?0',
                'Sec-Ch-Ua-Platform'        => '"Windows"',
                'Sec-Fetch-Dest'            => 'document',
                'Sec-Fetch-Mode'            => 'navigate',
                'Sec-Fetch-Site'            => 'none',
                'Sec-Fetch-User'            => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ]);
        $this->testUrl($clientBrowser, 'race top (full)', 'https://race.netkeiba.com/');
        $this->testUrl($clientBrowser, 'race list (full)', "https://race.netkeiba.com/top/race_list_sub.html?kaisai_date={$ymd}");

        // ============ STEP 3: サーバの外向き IP / 一般 Web 疎通確認 ============
        $this->line('');
        $this->info('--- STEP 3: サーバの外向き IP / 一般 Web への疎通確認 ---');
        // 自分の外向き IP (これでブロック対象 IP が判明)
        $this->testUrl($client, 'my-ip',          'https://api.ipify.org/?format=text', true);
        // 一般的な Web サーバ (netkeiba 以外でも 400 になるなら根本的な何かが壊れてる)
        $this->testUrl($client, 'example.com',    'https://example.com/');
        $this->testUrl($client, 'google.com',     'https://www.google.com/');
        // ipinfo.io で UA echo 確認
        $this->testUrl($client, 'httpbin headers','https://httpbin.org/headers', true);

        // ============ 判断ガイド ============
        $this->line('');
        $this->line('=== 判断ガイド ===');
        $this->line(' [STEP3 で example.com / google.com も 400] → サーバの PHP / Guzzle / SSL 設定の問題');
        $this->line(' [example.com は 200, netkeiba だけ 400]    → netkeiba 側で IP/UAブロック');
        $this->line(' [STEP2 のフルヘッダで 200 になる]          → ヘッダ要件の問題 (Sec-Fetch等)');
        $this->line(' [STEP3 my-ip が表示された]                → その IP が netkeiba にブロックされている');
        $this->line('');
        $this->line('対処:');
        $this->line(' 1. STEP2 で 200 が出る → Scraper にヘッダ追加して恒久対策');
        $this->line(' 2. IP が原因 → XServer 別ホストで再試行 / VPN 経由 / netkeiba プレミアム会員');

        return Command::SUCCESS;
    }

    /**
     * 1つの URL を叩いて結果を表示
     *
     * @param bool $showBody  true ならレスポンス本文の冒頭も表示
     */
    protected function testUrl(Client $client, string $label, string $url, bool $showBody = false): void
    {
        $start = microtime(true);
        try {
            $res = $client->get($url);
            $ms  = (int) ((microtime(true) - $start) * 1000);
            $status = $res->getStatusCode();
            $body   = $res->getBody()->getContents();
            $size   = strlen($body);

            // race_id 出現数(リスト系で意味がある)
            $hits = 0;
            if (preg_match_all('/race_id=(\d{12})|\/race\/(\d{12})/', $body, $m)) {
                $ids = array_unique(array_filter(array_merge($m[1] ?? [], $m[2] ?? [])));
                $hits = count($ids);
            }

            // 警告文の有無 (アクセス制限らしき記載を検出)
            $blockHint = '';
            foreach (['アクセスが集中', 'お待ちください', 'access denied', 'forbidden', 'blocked', 'cloudflare', 'attention required'] as $kw) {
                if (mb_stripos($body, $kw) !== false) {
                    $blockHint = " ⚠ '{$kw}' 検出";
                    break;
                }
            }

            $statusColor = $status === 200 ? 'info' : ($status >= 500 ? 'error' : 'comment');
            $this->{$statusColor}(sprintf(
                ' %-18s HTTP %d  size=%-7d ids=%-3d  %dms%s',
                $label, $status, $size, $hits, $ms, $blockHint
            ));
            $this->line("    {$url}");

            // 主要ヘッダ表示
            $headerKeys = ['Server', 'Content-Type', 'cf-ray', 'CF-RAY', 'X-Cache', 'Set-Cookie'];
            $headerLines = [];
            foreach ($headerKeys as $hk) {
                if ($res->hasHeader($hk)) {
                    $val = $res->getHeaderLine($hk);
                    $headerLines[] = "{$hk}: " . mb_substr($val, 0, 100);
                }
            }
            if (!empty($headerLines)) {
                $this->line('    [headers] ' . implode(' / ', $headerLines));
            }

            // 本文の冒頭 (showBody=true、または空でない短い 4xx の時)
            if ($size > 0 && ($showBody || ($status !== 200 && $size < 2000))) {
                $preview = trim(preg_replace('/\s+/u', ' ', mb_substr($body, 0, 400)));
                $this->line('    [body]    ' . $preview);
            } elseif ($size === 0) {
                $this->line('    [body]    (empty body — 即時拒否されている可能性)');
            }
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->error(sprintf(' %-18s ERROR  %dms  %s', $label, $ms, $e->getMessage()));
            $this->line("    {$url}");
        }
    }
}
