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
            $this->testUrl($client, $label, $url, $ymd, $raceId);
        }

        $this->line('');
        $this->line('=== 判断ガイド ===');
        $this->line(' ・ いずれも 200 で size > 0 → 正常。レース取得不能なら別原因');
        $this->line(' ・ db のみ 403/406 → 既知 (新DBにフォールバック済)');
        $this->line(' ・ 全部 403/timeout → サーバ側 IP がブロックされている可能性大');
        $this->line(' ・ 200 だが race_id 0件 → エンコーディングや HTML 構造の問題');

        return Command::SUCCESS;
    }

    /**
     * 1つの URL を叩いて結果を表示
     */
    protected function testUrl(Client $client, string $label, string $url, string $ymd, string $raceId): void
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
            foreach (['アクセスが集中', 'お待ちください', 'access denied', 'forbidden', 'blocked'] as $kw) {
                if (mb_stripos($body, $kw) !== false) {
                    $blockHint = " ⚠ '{$kw}' 検出";
                    break;
                }
            }

            $statusColor = $status === 200 ? 'info' : ($status >= 500 ? 'error' : 'comment');
            $this->{$statusColor}(sprintf(
                ' %-16s HTTP %d  size=%-7d ids=%-3d  %dms%s',
                $label, $status, $size, $hits, $ms, $blockHint
            ));
            $this->line("    {$url}");
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->error(sprintf(' %-16s ERROR  %dms  %s', $label, $ms, $e->getMessage()));
            $this->line("    {$url}");
        }
    }
}
