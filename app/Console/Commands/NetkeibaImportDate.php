<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 指定日の全レースをnetkeibaから一括取込
 *
 * 使い方:
 *   php artisan netkeiba:date 2025-05-04
 *   php artisan netkeiba:date 2025-05-04 --to=2025-05-05
 */
class NetkeibaImportDate extends Command
{
    protected $signature = 'netkeiba:date
                            {date : 開始日 (YYYY-MM-DD)}
                            {--to= : 終了日 (YYYY-MM-DD)。指定なしなら開始日のみ}
                            {--limit=200 : 1日あたり最大取込レース数}';

    protected $description = 'netkeibaから指定日(範囲)の全レースをインポート';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $start = $this->argument('date');
        $end = $this->option('to') ?: $start;
        $limit = (int) $this->option('limit');

        $log = ImportLog::create([
            'source' => 'netkeiba',
            'reference' => "{$start} - {$end}",
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $totalSuccess = 0;
        $totalFailed = 0;

        try {
            $current = strtotime($start);
            $endTs = strtotime($end);

            while ($current <= $endTs) {
                $date = date('Y-m-d', $current);
                $this->info("\n=== {$date} のレース一覧取得中 ===");

                try {
                    $raceIds = $scraper->fetchRaceIdsByDate($date);
                    $this->info("  取得レース数: " . count($raceIds));
                } catch (\Throwable $e) {
                    $this->error("  ✗ レース一覧取得失敗: " . $e->getMessage());
                    $totalFailed++;
                    $current = strtotime('+1 day', $current);
                    continue;
                }

                $count = 0;
                foreach ($raceIds as $raceId) {
                    if ($count >= $limit) {
                        $this->warn("  limit={$limit}に達したので {$date} は打ち切り");
                        break;
                    }
                    $count++;

                    try {
                        $data = $scraper->fetchRace($raceId);
                        $race = $importer->importFromNetkeiba($data);
                        $this->info("  ✓ [{$count}/" . count($raceIds) . "] {$race->full_name}");
                        $totalSuccess++;
                    } catch (\Throwable $e) {
                        $this->error("  ✗ {$raceId}: " . $e->getMessage());
                        $totalFailed++;
                    }
                }

                $current = strtotime('+1 day', $current);
            }

            $log->update([
                'status' => $totalFailed > 0 ? 'partial' : 'success',
                'records_total' => $totalSuccess + $totalFailed,
                'records_imported' => $totalSuccess,
                'records_failed' => $totalFailed,
                'finished_at' => now(),
            ]);

            $this->info("\n=== 完了 ===");
            $this->info("成功: {$totalSuccess}件 / 失敗: {$totalFailed}件");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            $this->error("致命的エラー: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
