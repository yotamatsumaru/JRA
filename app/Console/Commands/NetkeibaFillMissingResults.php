<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Models\Race;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 発走済みなのに結果 (race_results) が未取込のレースを netkeiba から穴埋めするコマンド
 *
 * 対象:
 *   - netkeiba_id が登録されている (JRA 中央対象)
 *   - 発走時刻を過ぎている (post_time < now、post_time が NULL なら race_date < 今日)
 *   - 以下のいずれかに該当する:
 *       ① race_results レコードが 0 件
 *       ② race_results はあるが finish_position_int が全部 NULL (=着順未確定)
 *
 * デフォルトは 2026 年のみ対象。--from/--to や --year で切替可能。
 *
 * 使い方:
 *   # 2026年で未取込のレースを最大100件取り込む
 *   php artisan netkeiba:fill-missing-results
 *
 *   # 年指定
 *   php artisan netkeiba:fill-missing-results --year=2025
 *
 *   # 期間指定
 *   php artisan netkeiba:fill-missing-results --from=2026-01-01 --to=2026-03-31
 *
 *   # 件数上限とスリープ間隔を指定
 *   php artisan netkeiba:fill-missing-results --limit=50 --sleep=5
 *
 *   # 対象一覧だけ表示 (取り込みは行わない)
 *   php artisan netkeiba:fill-missing-results --dry-run
 */
class NetkeibaFillMissingResults extends Command
{
    protected $signature = 'netkeiba:fill-missing-results
                            {--from= : 対象開始日 (YYYY-MM-DD)。--year より優先}
                            {--to= : 対象終了日 (YYYY-MM-DD)。--year より優先}
                            {--year=2026 : 対象年 (YYYY)。--from/--to 未指定時に使用}
                            {--limit=100 : 最大処理レース数}
                            {--sleep=3 : 1レース処理ごとの待機秒数 (netkeiba への負荷軽減)}
                            {--dry-run : 対象一覧を表示するだけで取り込みは行わない}';

    protected $description = '発走済みだが race_results が未取込のレースを netkeiba から穴埋め';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        // ─── 対象期間の決定 ─────────────────────────
        $from = $this->option('from');
        $to   = $this->option('to');
        $year = (int) $this->option('year');

        if (!$from && !$to) {
            $from = sprintf('%04d-01-01', $year);
            $to   = sprintf('%04d-12-31', $year);
        } elseif (!$from) {
            $from = substr($to, 0, 4) . '-01-01';
        } elseif (!$to) {
            $to   = substr($from, 0, 4) . '-12-31';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->error('--from / --to は YYYY-MM-DD 形式で指定してください');
            return self::FAILURE;
        }

        $limit  = max(1, (int) $this->option('limit'));
        $sleep  = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        // ─── 対象レース抽出 ─────────────────────────
        $now      = now();
        $todayStr = $now->toDateString();

        $query = Race::query()
            ->whereNotNull('netkeiba_id')
            ->whereBetween('race_date', [$from, $to])
            // 発走済みのみ (post_time があればそれ、無ければ race_date < 今日)
            ->where(function ($q) use ($now, $todayStr) {
                $q->where('post_time', '<', $now)
                  ->orWhere(function ($q2) use ($todayStr) {
                      $q2->whereNull('post_time')
                         ->whereDate('race_date', '<', $todayStr);
                  });
            })
            // 未取込 or 着順未確定
            ->where(function ($q) {
                $q->whereDoesntHave('results')
                  ->orWhereDoesntHave('results', function ($q2) {
                      $q2->whereNotNull('finish_position_int');
                  });
            })
            ->with('venue')
            ->orderBy('race_date')
            ->orderBy('race_number');

        $totalCount = (clone $query)->count();
        $targets    = $query->limit($limit)->get();

        $this->info("========================================");
        $this->info(" netkeiba:fill-missing-results");
        $this->info("========================================");
        $this->info("期間        : {$from} 〜 {$to}");
        $this->info("該当レース数: {$totalCount} 件");
        $this->info("処理対象    : " . $targets->count() . " 件 (limit={$limit})");
        $this->info("スリープ    : {$sleep} 秒 / レース");
        $this->info("モード      : " . ($dryRun ? 'DRY RUN' : '実行'));
        $this->line('');

        if ($targets->isEmpty()) {
            $this->info('穴埋め対象のレースはありません。');
            return self::SUCCESS;
        }

        // dry-run: 一覧表示のみ
        if ($dryRun) {
            $this->line(sprintf("  %-10s | %-8s | %3s | %-25s | netkeiba_id", '日付', '競馬場', 'R', 'レース名'));
            $this->line('  ' . str_repeat('-', 78));
            foreach ($targets as $r) {
                $this->line(sprintf(
                    "  %-10s | %-8s | %3d | %-25s | %s",
                    $r->race_date->format('Y-m-d'),
                    $r->venue->name ?? '?',
                    $r->race_number,
                    mb_strimwidth($r->name ?? '-', 0, 25, '…'),
                    $r->netkeiba_id
                ));
            }
            if ($totalCount > $targets->count()) {
                $this->line('  ... (残り ' . ($totalCount - $targets->count()) . ' 件は今回スキップ)');
            }
            return self::SUCCESS;
        }

        // ─── 実処理 ─────────────────────────
        $log = ImportLog::create([
            'source'     => 'netkeiba',
            'reference'  => "fill-missing-results {$from} - {$to}",
            'status'     => 'processing',
            'started_at' => $now,
        ]);

        $success = 0;
        $failed  = 0;
        $errors  = [];

        // 血統同期取得は激重なので OFF
        $importer->setFetchPedigreeOnImport(false);

        foreach ($targets as $i => $race) {
            $idx = $i + 1;
            $prefix = sprintf(
                "[%d/%d] %s %s %sR (netkeiba_id=%s)",
                $idx,
                $targets->count(),
                $race->race_date->format('Y-m-d'),
                $race->venue->name ?? '?',
                $race->race_number,
                $race->netkeiba_id
            );

            $this->line($prefix);

            try {
                $data = $scraper->fetchRace($race->netkeiba_id, $race->race_date->format('Y-m-d'));

                if (empty($data['results'])) {
                    $this->warn("  ⚠ 結果データが取得できませんでした (スキップ)");
                    $failed++;
                    $errors[] = "{$race->id}: no results";
                    continue;
                }

                if (empty($data['race_date'])) {
                    $data['race_date'] = $race->race_date->format('Y-m-d');
                }

                $updated  = $importer->importFromNetkeiba($data);
                $resCount = $updated->results()->count();

                $this->info("  ✓ 取込成功 (results={$resCount})");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  ✗ 取込失敗: " . $e->getMessage());
                Log::warning('netkeiba:fill-missing-results failed', [
                    'race_id'     => $race->id,
                    'netkeiba_id' => $race->netkeiba_id,
                    'error'       => $e->getMessage(),
                ]);
                $failed++;
                $errors[] = "{$race->id}: " . $e->getMessage();
            }

            if ($sleep > 0 && $idx < $targets->count()) {
                sleep($sleep);
            }
        }

        // 集計
        $log->update([
            'status'           => $failed === 0 ? 'success' : 'partial',
            'finished_at'      => now(),
            'records_total'    => $targets->count(),
            'records_imported' => $success,
            'records_failed'   => $failed,
            'error_message'    => $errors ? implode("\n", array_slice($errors, 0, 20)) : null,
        ]);

        $this->line('');
        $this->info("========================================");
        $this->info(" 完了: 成功 {$success} 件 / 失敗 {$failed} 件");
        $this->info("========================================");

        if ($totalCount > $targets->count()) {
            $remain = $totalCount - $targets->count();
            $this->comment("※ 未処理レースがまだ {$remain} 件あります。もう一度コマンドを実行してください。");
        }

        return $failed === 0 ? self::SUCCESS : self::SUCCESS;
    }
}
