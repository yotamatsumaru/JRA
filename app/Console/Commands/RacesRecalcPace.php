<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\RaceImportService;
use Illuminate\Console\Command;

/**
 * 既存レースのペース(H/M/S)を結果の通過順から再計算して races.pace に保存
 *
 * 使い方:
 *   php artisan races:recalc-pace                       # pace が NULL のレースを全件
 *   php artisan races:recalc-pace --all                 # 既に入っていても再計算
 *   php artisan races:recalc-pace --from=2025-01-01     # 期間指定
 *   php artisan races:recalc-pace --to=2025-12-31
 *   php artisan races:recalc-pace --limit=500           # 件数上限
 */
class RacesRecalcPace extends Command
{
    protected $signature = 'races:recalc-pace
                            {--all : pace が既に入っていても再計算}
                            {--from= : 開始日 YYYY-MM-DD}
                            {--to= : 終了日 YYYY-MM-DD}
                            {--limit=0 : 処理上限件数(0=全件)}';

    protected $description = '既存レースのペース(H/M/S)を結果の通過順から再計算';

    public function handle(RaceImportService $importer): int
    {
        $all   = (bool) $this->option('all');
        $from  = $this->option('from');
        $to    = $this->option('to');
        $limit = (int) $this->option('limit');

        $query = Race::query();
        if ($from)  $query->whereDate('race_date', '>=', $from);
        if ($to)    $query->whereDate('race_date', '<=', $to);
        if (!$all)  $query->whereNull('pace');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->warn('対象レースが見つかりませんでした。');
            $diag = Race::query();
            if ($from) $diag->whereDate('race_date', '>=', $from);
            if ($to)   $diag->whereDate('race_date', '<=', $to);
            $this->line('  期間内総数      : ' . (clone $diag)->count() . ' 件');
            $this->line('  pace 入力済    : ' . (clone $diag)->whereNotNull('pace')->count() . ' 件');
            $this->line('  pace 未入力    : ' . (clone $diag)->whereNull('pace')->count() . ' 件');
            $this->line('既に全件入力済の場合は --all で再計算してください。');
            return self::SUCCESS;
        }

        $process = $limit > 0 ? min($limit, $total) : $total;
        $this->info("ペース再計算開始: 対象 {$total} 件 / 処理 {$process} 件");

        if ($limit > 0) {
            $query->limit($limit);
        }

        $bar = $this->output->createProgressBar($process);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $bar->start();

        $stats = ['H' => 0, 'M' => 0, 'S' => 0, 'null' => 0, 'fail' => 0];
        $start = microtime(true);

        foreach ($query->cursor() as $race) {
            $bar->setMessage(mb_strimwidth(
                ($race->race_date?->format('m/d') ?? '?').' '.($race->name ?? '?'),
                0, 22, '..'
            ));

            try {
                $pace = $importer->recalcPace($race);
                $stats[$pace ?? 'null']++;
            } catch (\Throwable $e) {
                $stats['fail']++;
                $this->newLine();
                $this->warn("  ! race_id={$race->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = (int) (microtime(true) - $start);

        $this->info('========== 完了 ==========');
        $this->line("  ハイペース   (H) : {$stats['H']} 件");
        $this->line("  ミドルペース (M) : {$stats['M']} 件");
        $this->line("  スローペース (S) : {$stats['S']} 件");
        $this->line("  判定不可  (null) : {$stats['null']} 件 (通過順データなし or 少頭数)");
        $this->line("  失敗            : {$stats['fail']} 件");
        $this->line("  実行時間        : {$elapsed} 秒");

        return self::SUCCESS;
    }
}
