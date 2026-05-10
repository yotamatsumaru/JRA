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
    protected $signature = 'netkeiba:race
                            {race_id : netkeibaのrace_id (12桁)}
                            {--date= : 開催日 (YYYY-MM-DD)。指定すると HTML 抽出より優先される}';
    protected $description = 'netkeibaから単一レースをインポート';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $raceId = $this->argument('race_id');
        $date   = $this->option('date');

        if (!preg_match('/^\d{12}$/', $raceId)) {
            $this->error('race_idは12桁の数字である必要があります');
            return self::FAILURE;
        }
        if ($date !== null && $date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date は YYYY-MM-DD 形式で指定してください');
            return self::FAILURE;
        }
        $expectedDate = ($date !== null && $date !== '') ? $date : null;

        $this->info("netkeiba取込開始: race_id={$raceId}" . ($expectedDate ? " (date={$expectedDate})" : ''));

        try {
            $data = $scraper->fetchRace($raceId, $expectedDate);
            if (empty($data['race_date']) && $expectedDate !== null) {
                $data['race_date'] = $expectedDate;
            }
            $race = $importer->importFromNetkeiba($data);
            $this->info("✓ 取込成功: {$race->full_name}");
            $this->info("  出走馬数: " . $race->results()->count());
            if (!empty($data['race_date'])) {
                $this->info("  開催日: " . $data['race_date']);
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ 取込失敗: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
