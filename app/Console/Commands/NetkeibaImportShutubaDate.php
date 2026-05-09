<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 指定日の全レース出馬表をnetkeibaから一括取込
 *
 * 使い方:
 *   php artisan netkeiba:shutuba-date 2026-05-10
 *   php artisan netkeiba:shutuba-date 2026-05-10 --to=2026-05-11
 *   php artisan netkeiba:shutuba-date 2026-05-10 --venue=05
 *
 * 用途:
 *   レース確定前(前日〜当日朝)に当日の全出馬表を一気に取り込んでおき、
 *   推奨機能(Phase 3)で予想に活用する。
 *   レース後に `netkeiba:date` を再実行すると、同じ (race_id, horse_number) 行に
 *   着順・タイム・払戻が UPSERT される。
 */
class NetkeibaImportShutubaDate extends Command
{
    protected $signature = 'netkeiba:shutuba-date
                            {date : 開始日 (YYYY-MM-DD)}
                            {--to= : 終了日 (YYYY-MM-DD)。指定なしなら開始日のみ}
                            {--include-nar : 地方競馬(NAR)も対象に含める（デフォルトはJRA中央のみ）}
                            {--venue= : 競馬場コードで絞込(2桁、複数可カンマ区切り) 例: --venue=05,06}
                            {--limit=200 : 1日あたり最大取込レース数}
                            {--interval= : リクエスト間隔(秒)。デフォルトは config(services.netkeiba.request_interval)}';

    protected $description = 'netkeibaから指定日(範囲)の全レース出馬表をインポート(レース確定前のエントリー登録)';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $start = $this->argument('date');
        $end   = $this->option('to') ?: $start;
        $limit = (int) $this->option('limit');
        $includeNar = (bool) $this->option('include-nar');

        // 競馬場フィルタ(--venue=05,06)
        $venueFilter = null;
        $venueOpt = $this->option('venue');
        if ($venueOpt !== null && $venueOpt !== '') {
            $venueFilter = array_values(array_filter(array_map(
                fn($v) => str_pad(trim($v), 2, '0', STR_PAD_LEFT),
                explode(',', $venueOpt)
            )));
            if (!empty($venueFilter)) {
                $this->info('競馬場フィルタ: ' . implode(',', $venueFilter));
            }
        }

        // インターバル上書き
        $intervalOpt = $this->option('interval');
        if ($intervalOpt !== null && $intervalOpt !== '') {
            $iv = max(0, (int) $intervalOpt);
            $scraper->setRequestInterval($iv);
            $this->info("リクエスト間隔: {$iv}秒");
        }

        // JRA中央競馬の venue_code（race_id の5-6文字目）: 01〜10
        $jraVenueCodes = ['01','02','03','04','05','06','07','08','09','10'];

        $log = ImportLog::create([
            'source'     => 'netkeiba_shutuba',
            'reference'  => "shutuba {$start} - {$end}",
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        $totalSuccess = 0;
        $totalFailed  = 0;

        try {
            $current = strtotime($start);
            $endTs   = strtotime($end);

            if ($current === false || $endTs === false) {
                $this->error('日付フォーマットが不正です (YYYY-MM-DD)');
                return self::FAILURE;
            }

            while ($current <= $endTs) {
                $date = date('Y-m-d', $current);
                $this->info("\n=== {$date} のレース一覧取得中 ===");

                try {
                    $raceIds = $scraper->fetchRaceIdsByDate($date);
                } catch (\Throwable $e) {
                    $this->error("  ✗ レース一覧取得失敗: " . $e->getMessage());
                    $totalFailed++;
                    $current = strtotime('+1 day', $current);
                    continue;
                }

                // 地方競馬を除外（デフォルト動作）
                $narFiltered = 0;
                if (!$includeNar) {
                    $before = count($raceIds);
                    $raceIds = array_values(array_filter($raceIds, function ($rid) use ($jraVenueCodes) {
                        $vc = substr((string) $rid, 4, 2);
                        return in_array($vc, $jraVenueCodes, true);
                    }));
                    $narFiltered = $before - count($raceIds);
                }

                // 競馬場フィルタ
                if ($venueFilter !== null) {
                    $raceIds = array_values(array_filter($raceIds, function ($rid) use ($venueFilter) {
                        $vc = substr((string) $rid, 4, 2);
                        return in_array($vc, $venueFilter, true);
                    }));
                }

                if (empty($raceIds)) {
                    $this->warn("  対象レースなし");
                    $current = strtotime('+1 day', $current);
                    continue;
                }

                $this->info("  対象レース数: " . count($raceIds) . ($narFiltered > 0 ? " (NAR {$narFiltered}件除外)" : ''));

                $count = 0;
                foreach ($raceIds as $raceId) {
                    if ($count >= $limit) {
                        $this->warn("  limit={$limit}に達したので {$date} は打ち切り");
                        break;
                    }
                    $count++;

                    try {
                        $data = $scraper->fetchShutuba($raceId);
                        $race = $importer->importShutuba($data);
                        $entries = count($data['results'] ?? []);
                        $this->info("  ✓ [{$count}/" . count($raceIds) . "] {$race->full_name} ({$entries}頭)");
                        $totalSuccess++;
                    } catch (\Throwable $e) {
                        $this->error("  ✗ {$raceId}: " . $e->getMessage());
                        $totalFailed++;
                    }
                }

                $current = strtotime('+1 day', $current);
            }

            $log->update([
                'status'           => $totalFailed > 0 ? 'partial' : 'success',
                'records_total'    => $totalSuccess + $totalFailed,
                'records_imported' => $totalSuccess,
                'records_failed'   => $totalFailed,
                'finished_at'      => now(),
            ]);

            $this->info("\n=== 完了 ===");
            $this->info("出馬表取込: 成功 {$totalSuccess}件 / 失敗 {$totalFailed}件");
            $this->line("\nレース後に以下を実行すると同じ行に結果が UPSERT されます:");
            $this->line("  php artisan netkeiba:date {$start}" . ($end !== $start ? " --to={$end}" : ''));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at'   => now(),
            ]);
            $this->error("致命的エラー: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
