<?php

namespace App\Console\Commands;

use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 単一レースの出馬表をnetkeibaから取込
 *
 * 使い方:
 *   php artisan netkeiba:shutuba 202605030611
 */
class NetkeibaImportShutuba extends Command
{
    protected $signature = 'netkeiba:shutuba {race_id : netkeibaのrace_id (12桁)}';
    protected $description = 'netkeibaから単一レースの出馬表(エントリー)をインポート';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $raceId = $this->argument('race_id');

        if (!preg_match('/^\d{12}$/', $raceId)) {
            $this->error('race_idは12桁の数字である必要があります');
            return self::FAILURE;
        }

        $this->info("netkeiba出馬表取込開始: race_id={$raceId}");

        try {
            $data = $scraper->fetchShutuba($raceId);
            $race = $importer->importShutuba($data);
            $this->info("✓ 出馬表取込成功: {$race->full_name}");
            $this->info("  登録頭数: " . count($data['results'] ?? []));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ 取込失敗: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
