<?php

namespace App\Console\Commands;

use App\Models\Horse;
use App\Services\NetkeibaScraper;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 既存の馬データに血統(父/母/母父)を補完
 *
 * 使い方:
 *   php artisan netkeiba:fill-pedigree                # 血統未入力の馬を全件
 *   php artisan netkeiba:fill-pedigree --limit=100    # 100頭だけ
 *   php artisan netkeiba:fill-pedigree --all          # 既に入っていても再取得
 *   php artisan netkeiba:fill-pedigree --horse=12345  # netkeiba_id 指定で個別実行
 */
class NetkeibaFillPedigree extends Command
{
    protected $signature = 'netkeiba:fill-pedigree
                            {--limit=0 : 処理上限頭数(0=全件)}
                            {--all : 血統が既に入っている馬も再取得}
                            {--horse= : 個別の netkeiba_id (10桁)を指定}
                            {--sleep=0 : 1頭ごとの追加待機秒(scraper側のintervalに加算)}
                            {--interval= : リクエスト間隔(秒)。デフォルトは config(services.netkeiba.request_interval)。0で待機なし(自己責任)}';

    protected $description = '既存の馬データの血統(父/母/母父)を netkeiba から補完';

    public function handle(RaceImportService $importer, NetkeibaScraper $scraper): int
    {
        $limit = (int) $this->option('limit');
        $all   = (bool) $this->option('all');
        $horseFilter = $this->option('horse');
        $extraSleep  = (int) $this->option('sleep');

        // インターバル上書き(指定があれば)
        $intervalOpt = $this->option('interval');
        if ($intervalOpt !== null && $intervalOpt !== '') {
            $iv = max(0, (int) $intervalOpt);
            $scraper->setRequestInterval($iv);
            $this->info("リクエスト間隔: {$iv}秒");
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

        $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0];

        foreach ($query->cursor() as $horse) {
            $bar->setMessage(mb_strimwidth($horse->name ?? '?', 0, 20, '..'));

            try {
                $updated = $importer->fillHorsePedigree($horse);
                if ($updated) {
                    $stats['ok']++;
                } else {
                    $stats['skip']++;
                }
            } catch (\Throwable $e) {
                $stats['fail']++;
                $this->newLine();
                $this->warn("  ! {$horse->name} ({$horse->netkeiba_id}): " . $e->getMessage());
            }

            $bar->advance();

            if ($extraSleep > 0) {
                sleep($extraSleep);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('========== 完了 ==========');
        $this->line("  補完済 : {$stats['ok']} 頭");
        $this->line("  スキップ: {$stats['skip']} 頭 (取得済 or 取得項目なし)");
        $this->line("  失敗   : {$stats['fail']} 頭");

        return self::SUCCESS;
    }
}
