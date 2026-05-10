<?php

namespace App\Console\Commands;

use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * netkeiba:year で失敗したレースだけを再取込する
 *
 * netkeiba:year は進捗ファイル (storage/app/netkeiba_year_{YYYY}.json) の
 * errors[] に失敗 race_id を記録するので、それを読んで該当レースだけ再実行する。
 *
 * デッドロック失敗 (SQLSTATE 40001) は RaceImportService 側で 3回まで
 * 自動リトライされるため、本コマンドは「自動リトライでも復旧しなかったレース」
 * のための最終救済手段。
 *
 * 使い方:
 *   php artisan netkeiba:retry-failed 2025                    # 2025年の失敗分を再取込
 *   php artisan netkeiba:retry-failed 2025 --deadlock-only   # デッドロック失敗だけ再取込
 *   php artisan netkeiba:retry-failed 2025 --dry-run          # 件数のみ表示
 *   php artisan netkeiba:retry-failed 2025 --clear-errors     # 成功したものを errors[] から除去
 */
class NetkeibaRetryFailed extends Command
{
    protected $signature = 'netkeiba:retry-failed
                            {year? : 進捗ファイルの年 (YYYY)。省略時は今年}
                            {--deadlock-only : デッドロックエラーのみ対象 (推奨)}
                            {--dry-run : 件数だけ表示して実行しない}
                            {--clear-errors : 成功した race_id を errors[] から除去}
                            {--interval= : リクエスト間隔(秒)}';

    protected $description = 'netkeiba:year の失敗レースだけを再取込（進捗ファイル errors[] を参照）';

    public function handle(NetkeibaScraper $scraper, RaceImportService $importer): int
    {
        $year = (int) ($this->argument('year') ?: date('Y'));
        $deadlockOnly = (bool) $this->option('deadlock-only');
        $dryRun       = (bool) $this->option('dry-run');
        $clearErrors  = (bool) $this->option('clear-errors');

        $progressPath = "netkeiba_year_{$year}.json";
        if (!Storage::exists($progressPath)) {
            $this->error("進捗ファイルが見つかりません: storage/app/{$progressPath}");
            return self::FAILURE;
        }

        // インターバル上書き
        $intervalOpt = $this->option('interval');
        if ($intervalOpt !== null && $intervalOpt !== '') {
            $iv = max(0, (int) $intervalOpt);
            $scraper->setRequestInterval($iv);
            $this->info("リクエスト間隔: {$iv}秒");
        }

        $progress = json_decode(Storage::get($progressPath), true) ?: [];
        $errors   = $progress['errors'] ?? [];

        if (empty($errors)) {
            $this->info('errors[] が空です。再取込対象なし');
            return self::SUCCESS;
        }

        // race_id ごとに最後のエラーだけ残す（同 race_id が複数回 errors[] に積まれている可能性がある）
        $latestByRaceId = [];
        foreach ($errors as $idx => $err) {
            $rid = $err['race_id'] ?? null;
            if (!$rid) continue;
            $latestByRaceId[$rid] = ['idx' => $idx, 'error' => $err['error'] ?? ''];
        }

        // フィルタ: --deadlock-only ならデッドロックのみ
        $targets = [];
        foreach ($latestByRaceId as $rid => $info) {
            if ($deadlockOnly) {
                if (!preg_match('/Deadlock|SQLSTATE\[40001\]|1213/i', (string) $info['error'])) {
                    continue;
                }
            }
            $targets[] = $rid;
        }

        $this->info("=================================================");
        $this->info(" netkeiba 失敗レース再取込: {$year}年");
        $this->info(" 進捗ファイル : storage/app/{$progressPath}");
        $this->info(" errors[] 総数: " . count($errors));
        $this->info(" 一意 race_id : " . count($latestByRaceId));
        $this->info(" 対象         : " . count($targets) . ($deadlockOnly ? ' (deadlock-only)' : ' (all errors)'));
        $this->info(" dry-run      : " . ($dryRun ? 'YES' : 'no'));
        $this->info("=================================================");

        if ($dryRun) {
            foreach ($targets as $rid) {
                $err = $latestByRaceId[$rid]['error'];
                $shortErr = mb_substr($err, 0, 80);
                $this->line("  · {$rid}  {$shortErr}");
            }
            return self::SUCCESS;
        }

        if (empty($targets)) {
            $this->info('対象が0件です');
            return self::SUCCESS;
        }

        $success = 0;
        $failed  = 0;
        $stillFailing = []; // 再試行後も失敗した race_id
        $resolvedIdx  = []; // 成功した errors[] のインデックス

        $start = microtime(true);
        foreach ($targets as $i => $raceId) {
            $no = $i + 1;
            $total = count($targets);
            try {
                $data = $scraper->fetchRace($raceId);
                $race = $importer->importFromNetkeiba($data);
                $success++;
                $resolvedIdx[] = $latestByRaceId[$raceId]['idx'];
                $this->line(sprintf(
                    "  ✓ [%d/%d] %s — %s",
                    $no, $total, $raceId, $race->full_name ?? '(name?)'
                ));
            } catch (\Throwable $e) {
                $failed++;
                $stillFailing[$raceId] = $e->getMessage();
                $this->error(sprintf(
                    "  ✗ [%d/%d] %s — %s",
                    $no, $total, $raceId, mb_substr($e->getMessage(), 0, 100)
                ));
            }
        }

        $elapsed = (int) (microtime(true) - $start);

        $this->info("\n=================================================");
        $this->info(" 完了 — 成功: {$success} / 失敗: {$failed} / 対象: " . count($targets));
        $this->info(" 実行時間: " . $this->formatDuration($elapsed));

        // --clear-errors: 成功した分を errors[] から除去
        if ($clearErrors && $success > 0) {
            $newErrors = [];
            foreach ($errors as $idx => $err) {
                if (in_array($idx, $resolvedIdx, true)) continue;
                $newErrors[] = $err;
            }
            $progress['errors'] = $newErrors;
            // success/failed カウンタも更新
            $progress['success'] = (int) ($progress['success'] ?? 0) + $success;
            $progress['failed']  = max(0, (int) ($progress['failed'] ?? 0) - $success);
            $progress['updated_at'] = now()->toDateTimeString();
            Storage::put($progressPath, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info(" 進捗ファイル更新: errors[] から {$success} 件を除去");
        }

        if ($failed > 0) {
            $this->warn(' まだ失敗が残っています:');
            foreach ($stillFailing as $rid => $msg) {
                $this->warn("   - {$rid}: " . mb_substr($msg, 0, 80));
            }
        }
        $this->info("=================================================");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 0) $seconds = 0;
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) return sprintf('%dh %dm %ds', $h, $m, $s);
        if ($m > 0) return sprintf('%dm %ds', $m, $s);
        return sprintf('%ds', $s);
    }
}
