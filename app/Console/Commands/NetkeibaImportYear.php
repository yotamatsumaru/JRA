<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Models\Race;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 年単位でnetkeibaから全レースを一括取込
 *
 * 機能:
 *   - 月別カレンダー(/race/sum/YYYYMM/)から開催日を自動抽出
 *   - 進捗を storage/app/netkeiba_year_{YYYY}.json に永続化
 *   - 中断しても次回実行で処理済みrace_idをスキップして再開
 *   - 月単位・日単位で進捗表示
 *   - dry-run で件数だけ事前確認可能
 *
 * 使い方:
 *   php artisan netkeiba:year                          # 今年の頭から本日まで
 *   php artisan netkeiba:year 2026                     # 2026年すべて(本日まで)
 *   php artisan netkeiba:year 2026 --from-month=1 --to-month=5
 *   php artisan netkeiba:year 2026 --dry-run           # 件数のみ確認
 *   php artisan netkeiba:year 2026 --resume            # 進捗ファイルから再開
 *   php artisan netkeiba:year 2026 --reset             # 進捗ファイルを削除して最初から
 *   php artisan netkeiba:year 2026 --skip-existing     # DB既存race_idはAPI叩かずスキップ
 *   php artisan netkeiba:year 2026 --day-sleep=10      # 1日処理ごとに追加スリープ(秒)
 */
class NetkeibaImportYear extends Command
{
    protected $signature = 'netkeiba:year
                            {year? : 取得年 (YYYY)。省略時は今年}
                            {--from-month=1 : 開始月 (1-12)}
                            {--to-month= : 終了月 (1-12)。省略時は今年なら現在月、過去年なら12}
                            {--dry-run : 実際には取込まず件数のみ表示}
                            {--resume : 進捗ファイルから再開（処理済みrace_idはスキップ）}
                            {--reset : 進捗ファイルを削除して最初から}
                            {--skip-existing : DBに既存のrace_idはAPI呼び出しをスキップ}
                            {--day-sleep=0 : 1日処理ごとに追加で待機する秒数}
                            {--limit-per-day=200 : 1日あたりの最大処理レース数}';

    protected $description = 'netkeibaから指定年の全レースを一括インポート（進捗保存・再開対応）';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $year = (int) ($this->argument('year') ?: date('Y'));
        $fromMonth = max(1, min(12, (int) $this->option('from-month')));
        $today = date('Y-m-d');
        $thisYear = (int) date('Y');

        $defaultToMonth = ($year === $thisYear) ? (int) date('n') : 12;
        $toMonth = $this->option('to-month')
            ? max($fromMonth, min(12, (int) $this->option('to-month')))
            : $defaultToMonth;

        $dryRun = (bool) $this->option('dry-run');
        $resume = (bool) $this->option('resume');
        $reset = (bool) $this->option('reset');
        $skipExisting = (bool) $this->option('skip-existing');
        $daySleep = max(0, (int) $this->option('day-sleep'));
        $limitPerDay = max(1, (int) $this->option('limit-per-day'));

        $progressPath = "netkeiba_year_{$year}.json";
        if ($reset && Storage::exists($progressPath)) {
            Storage::delete($progressPath);
            $this->info("進捗ファイルをリセット: {$progressPath}");
        }

        $progress = $this->loadProgress($progressPath);
        if ($resume && !empty($progress['done'])) {
            $this->info('再開モード: 既処理 ' . count($progress['done']) . ' レースをスキップします');
        }
        $doneSet = array_flip($progress['done'] ?? []);

        $this->info("=================================================");
        $this->info(" netkeiba 年次取込: {$year}年 {$fromMonth}月〜{$toMonth}月");
        $this->info(" dry-run        : " . ($dryRun ? 'YES' : 'no'));
        $this->info(" skip-existing  : " . ($skipExisting ? 'YES' : 'no'));
        $this->info(" day-sleep      : {$daySleep}s / limit-per-day: {$limitPerDay}");
        $this->info(" 進捗ファイル   : storage/app/{$progressPath}");
        $this->info("=================================================");

        // ImportLog（dry-run以外）
        $log = null;
        if (!$dryRun) {
            $log = ImportLog::create([
                'source'     => 'netkeiba',
                'reference'  => "year:{$year}({$fromMonth}-{$toMonth})",
                'status'     => 'processing',
                'started_at' => now(),
            ]);
        }

        $totalSuccess = (int) ($progress['success'] ?? 0);
        $totalFailed  = (int) ($progress['failed']  ?? 0);
        $totalSkipped = 0;
        $totalCandidates = 0;

        try {
            for ($m = $fromMonth; $m <= $toMonth; $m++) {
                $this->info("\n────────────  {$year}/" . sprintf('%02d', $m) . " ────────────");

                // 月別開催日リスト取得
                try {
                    $dates = $scraper->fetchOpenDatesByMonth($year, $m);
                } catch (\Throwable $e) {
                    $this->error("  ✗ 月別カレンダー取得失敗: " . $e->getMessage());
                    continue;
                }

                // 未来日は除外
                $dates = array_values(array_filter($dates, fn($d) => $d <= $today));

                if (empty($dates)) {
                    $this->warn("  開催日なし");
                    continue;
                }
                $this->info("  開催日: " . count($dates) . " 日 — " . implode(', ', $dates));

                foreach ($dates as $date) {
                    // レースID一覧取得
                    try {
                        $raceIds = $scraper->fetchRaceIdsByDate($date);
                    } catch (\Throwable $e) {
                        $this->error("  ✗ {$date}: レース一覧取得失敗 — " . $e->getMessage());
                        $totalFailed++;
                        continue;
                    }
                    $raceIds = array_values(array_unique($raceIds));
                    $totalCandidates += count($raceIds);

                    if (empty($raceIds)) {
                        $this->line("  · {$date}: 0 レース");
                        continue;
                    }
                    $this->info("  ▼ {$date}: " . count($raceIds) . " レース");

                    if ($dryRun) {
                        // dry-runは件数のみ
                        continue;
                    }

                    $countDay = 0;
                    foreach ($raceIds as $raceId) {
                        if ($countDay >= $limitPerDay) {
                            $this->warn("    limit-per-day={$limitPerDay} 到達、{$date} 打ち切り");
                            break;
                        }

                        // 進捗ファイルに記録済み → スキップ
                        if (isset($doneSet[$raceId])) {
                            $totalSkipped++;
                            continue;
                        }

                        // DBに既存 → スキップ（オプション）
                        if ($skipExisting && Race::where('netkeiba_id', $raceId)->exists()) {
                            $doneSet[$raceId] = true;
                            $progress['done'][] = $raceId;
                            $totalSkipped++;
                            $countDay++;
                            // 100件ごとに進捗保存
                            if (count($progress['done']) % 100 === 0) {
                                $this->saveProgress($progressPath, $progress, $totalSuccess, $totalFailed);
                            }
                            continue;
                        }

                        $countDay++;

                        try {
                            $data = $scraper->fetchRace($raceId);
                            $race = $importer->importFromNetkeiba($data);
                            $totalSuccess++;
                            $doneSet[$raceId] = true;
                            $progress['done'][] = $raceId;

                            $this->line(sprintf(
                                "    ✓ [%d/%d] %s",
                                $countDay,
                                count($raceIds),
                                $race->full_name ?? $raceId
                            ));
                        } catch (\Throwable $e) {
                            $totalFailed++;
                            $progress['errors'][] = ['race_id' => $raceId, 'error' => $e->getMessage()];
                            $this->error("    ✗ {$raceId}: " . $e->getMessage());
                        }

                        // 都度進捗保存（10件ごと）
                        if (($totalSuccess + $totalFailed) % 10 === 0) {
                            $this->saveProgress($progressPath, $progress, $totalSuccess, $totalFailed);
                        }
                    }

                    // 1日処理後のクールダウン
                    if ($daySleep > 0 && !$dryRun) {
                        $this->line("    … {$daySleep}s クールダウン");
                        sleep($daySleep);
                    }
                }

                // 月終わりに進捗保存
                if (!$dryRun) {
                    $this->saveProgress($progressPath, $progress, $totalSuccess, $totalFailed);
                }
            }

            // 最終進捗保存
            if (!$dryRun) {
                $this->saveProgress($progressPath, $progress, $totalSuccess, $totalFailed);
                $log?->update([
                    'status'           => $totalFailed > 0 ? 'partial' : 'success',
                    'records_total'    => $totalSuccess + $totalFailed,
                    'records_imported' => $totalSuccess,
                    'records_failed'   => $totalFailed,
                    'finished_at'      => now(),
                ]);
            }

            $this->info("\n=================================================");
            if ($dryRun) {
                $this->info(" [dry-run] 取得対象レース総数: {$totalCandidates}");
            } else {
                $this->info(" 完了 — 成功: {$totalSuccess} / 失敗: {$totalFailed} / スキップ: {$totalSkipped}");
                $this->info(" 候補総数: {$totalCandidates}");
                $this->info(" 進捗ファイル: storage/app/{$progressPath}");
                if ($totalFailed > 0) {
                    $this->warn(" エラー詳細は進捗ファイル内 errors[] を参照");
                }
            }
            $this->info("=================================================");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->saveProgress($progressPath, $progress, $totalSuccess, $totalFailed);
            $log?->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at'   => now(),
            ]);
            $this->error("致命的エラー: " . $e->getMessage());
            $this->error("進捗は保存済み。--resume で再開できます");
            return self::FAILURE;
        }
    }

    protected function loadProgress(string $path): array
    {
        if (!Storage::exists($path)) {
            return ['done' => [], 'errors' => [], 'success' => 0, 'failed' => 0];
        }
        $json = Storage::get($path);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['done' => [], 'errors' => [], 'success' => 0, 'failed' => 0];
        }
        return array_merge(
            ['done' => [], 'errors' => [], 'success' => 0, 'failed' => 0],
            $data
        );
    }

    protected function saveProgress(string $path, array $progress, int $success, int $failed): void
    {
        $progress['success'] = $success;
        $progress['failed']  = $failed;
        $progress['updated_at'] = now()->toDateTimeString();
        // 重複除去
        $progress['done'] = array_values(array_unique($progress['done'] ?? []));
        Storage::put($path, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
