<?php

namespace App\Console\Commands;

use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 単一レースをnetkeibaから取込
 *
 * 使い方:
 *   php artisan netkeiba:race 202405021211
 */
class NetkeibaImportRace extends Command
{
    protected $signature = 'netkeiba:race {race_id : netkeibaのrace_id (12桁)}';
    protected $description = 'netkeibaから単一レースをインポート';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $raceId = $this->argument('race_id');

        if (!preg_match('/^\d{12}$/', $raceId)) {
            $this->error('race_idは12桁の数字である必要があります');
            return self::FAILURE;
        }

        $this->info("netkeiba取込開始: race_id={$raceId}");

        try {
            $data = $scraper->fetchRace($raceId);
            $race = $importer->importFromNetkeiba($data);
            $this->info("✓ 取込成功: {$race->full_name}");
            $this->info("  出走馬数: " . $race->results()->count());
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ 取込失敗: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
