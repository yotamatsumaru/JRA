<?php

namespace App\Console\Commands;

use App\Models\Horse;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 既存の馬データに血統(父/母/母父)を補完
 *
 * 使い方:
 *   php artisan netkeiba:fill-pedigree                # 血統未入力の馬を全件
 *   php artisan netkeiba:fill-pedigree --limit=100    # 100頭だけ
 *   php artisan netkeiba:fill-pedigree --all          # 既に入っていても再取得
 *   php artisan netkeiba:fill-pedigree --horse=12345  # netkeiba_id 指定で個別実行
 *   php artisan netkeiba:fill-pedigree --reset        # 進捗ファイルを削除して最初から
 *   php artisan netkeiba:fill-pedigree --interval=2   # リクエスト間隔2秒
 */
class NetkeibaFillPedigree extends Command
{
    protected $signature = 'netkeiba:fill-pedigree
                            {--limit=0 : 処理上限頭数(0=全件)}
                            {--all : 血統が既に入っている馬も再取得}
                            {--horse= : 個別の netkeiba_id (10桁)を指定}
                            {--sleep=0 : 1頭ごとの追加待機秒(scraper側のintervalに加算)}
                            {--interval= : リクエスト間隔(秒)。デフォルトは config(services.netkeiba.request_interval)。0で待機なし(自己責任)}
                            {--reset : 進捗ファイルを削除して最初から}';

    protected $description = '既存の馬データの血統(父/母/母父)を netkeiba から補完(レジューム対応)';

    /** 進捗ファイル */
    protected const PROGRESS_PATH = 'netkeiba_pedigree_progress.json';

    public function handle(RaceImportService $importer, NetkeibaScraper $scraper): int
    {
        $limit = (int) $this->option('limit');
        $all   = (bool) $this->option('all');
        $horseFilter = $this->option('horse');
        $extraSleep  = (int) $this->option('sleep');
        $reset = (bool) $this->option('reset');

        // インターバル上書き(指定があれば)
        $intervalOpt = $this->option('interval');
        if ($intervalOpt !== null && $intervalOpt !== '') {
            $iv = max(0, (int) $intervalOpt);
            $scraper->setRequestInterval($iv);
            $this->info("リクエスト間隔: {$iv}秒");
        }

        // 進捗ファイル
        // 個別馬 (--horse) 指定時はレジュームを使わない
        $useResume = !$horseFilter;
        if ($useResume && $reset && Storage::exists(self::PROGRESS_PATH)) {
            Storage::delete(self::PROGRESS_PATH);
            $this->info('進捗ファイルをリセットしました');
        }
        $progress = $useResume ? $this->loadProgress() : ['done' => [], 'failed' => []];
        $doneSet = array_flip($progress['done'] ?? []);
        if ($useResume && !empty($doneSet)) {
            $this->info('進捗ファイル検出: ' . count($doneSet) . ' 頭処理済み(自動スキップ)');
        }

        $query = Horse::query()->whereNotNull('netkeiba_id');

        if ($horseFilter) {
            $query->where('netkeiba_id', $horseFilter);
        } elseif (!$all) {
            // 血統が空の馬のみ
            $query->where(function ($q) {
                $q->whereNull('father')
                  ->orWhereNull('mother')
                  ->orWhereNull('mother_father');
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('対象の馬が見つかりませんでした。');
            return self::SUCCESS;
        }

        $process = $limit > 0 ? min($limit, $total) : $total;
        $this->info("血統取込開始: 対象 {$total} 頭 / 処理 {$process} 頭");

        if ($limit > 0) {
            $query->limit($limit);
        }

        $bar = $this->output->createProgressBar($process);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $bar->start();

        $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0, 'resume_skip' => 0];

        // ETA 計算用
        $sessionStart = microtime(true);
        $sessionFetched = 0;

        foreach ($query->cursor() as $horse) {
            $netkeibaId = $horse->netkeiba_id;

            // 進捗ファイル経由でスキップ
            if ($useResume && isset($doneSet[$netkeibaId])) {
                $stats['resume_skip']++;
                $bar->advance();
                continue;
            }

            $bar->setMessage(mb_strimwidth($horse->name ?? '?', 0, 20, '..'));

            try {
                $updated = $importer->fillHorsePedigree($horse);
                if ($updated) {
                    $stats['ok']++;
                } else {
                    $stats['skip']++;
                }
                $sessionFetched++;
                $progress['done'][] = $netkeibaId;
                $doneSet[$netkeibaId] = true;
            } catch (\Throwable $e) {
                $stats['fail']++;
                $progress['failed'][] = ['netkeiba_id' => $netkeibaId, 'error' => $e->getMessage()];
                $this->newLine();
                $this->warn("  ! {$horse->name} ({$netkeibaId}): " . $e->getMessage());
            }

            $bar->advance();

            // ETA を 50件ごとに表示
            if ($useResume && $sessionFetched > 0 && $sessionFetched % 50 === 0) {
                $elapsed = microtime(true) - $sessionStart;
                $perHorse = $elapsed / $sessionFetched;
                $remaining = max(0, $process - $stats['ok'] - $stats['skip'] - $stats['fail'] - $stats['resume_skip']);
                if ($remaining > 0) {
                    $etaSec = (int) ($perHorse * $remaining);
                    $bar->setMessage(sprintf('ETA: %s (%.1fs/頭)', $this->formatDuration($etaSec), $perHorse));
                }
            }

            // 進捗保存(50件ごと)
            if ($useResume && ($sessionFetched > 0) && ($sessionFetched % 50 === 0)) {
                $this->saveProgress($progress);
            }

            if ($extraSleep > 0) {
                sleep($extraSleep);
            }
        }

        $bar->finish();
        $this->newLine(2);

        // 最終進捗保存
        if ($useResume) {
            $this->saveProgress($progress);
        }

        $sessionElapsed = (int) (microtime(true) - $sessionStart);
        $perHorse = $sessionFetched > 0
            ? (microtime(true) - $sessionStart) / $sessionFetched
            : 0.0;

        $this->info('========== 完了 ==========');
        $this->line("  補完済   : {$stats['ok']} 頭");
        $this->line("  スキップ : {$stats['skip']} 頭 (取得項目なし)");
        $this->line("  再開スキ : {$stats['resume_skip']} 頭 (進捗ファイル経由)");
        $this->line("  失敗     : {$stats['fail']} 頭");
        $this->line("  実行時間 : " . $this->formatDuration($sessionElapsed)
            . ($sessionFetched > 0 ? sprintf(' (%.1fs/頭 × %d頭)', $perHorse, $sessionFetched) : ''));
        if ($useResume) {
            $this->line("  進捗ファイル: storage/app/" . self::PROGRESS_PATH);
        }
        if ($stats['fail'] > 0) {
            $this->warn("  失敗馬の詳細は進捗ファイル内 failed[] を参照");
        }

        return self::SUCCESS;
    }

    /**
     * 進捗ファイルをロード
     */
    protected function loadProgress(): array
    {
        if (!Storage::exists(self::PROGRESS_PATH)) {
            return ['done' => [], 'failed' => []];
        }
        $json = Storage::get(self::PROGRESS_PATH);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['done' => [], 'failed' => []];
        }
        return array_merge(['done' => [], 'failed' => []], $data);
    }

    /**
     * 進捗ファイルを保存
     */
    protected function saveProgress(array $progress): void
    {
        $progress['done'] = array_values(array_unique($progress['done'] ?? []));
        $progress['updated_at'] = now()->toDateTimeString();
        Storage::put(self::PROGRESS_PATH, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 秒数を "1h 23m 45s" 形式に整形
     */
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
