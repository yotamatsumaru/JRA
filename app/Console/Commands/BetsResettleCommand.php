<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Services\BetTicketService;
use Illuminate\Console\Command;

/**
 * 未精算馬券を一括精算する CLI (Phase 2-F)
 *
 * 使い方:
 *   php artisan bets:resettle                 # 未精算 & 結果確定済を全件
 *   php artisan bets:resettle --user=1        # ユーザ指定
 *   php artisan bets:resettle --race=1234     # レース指定
 *   php artisan bets:resettle --force         # is_settled=true も再精算(再判定用)
 */
class BetsResettleCommand extends Command
{
    protected $signature = 'bets:resettle
                            {--user= : 対象ユーザID(省略時は全ユーザ)}
                            {--race= : 対象レースID}
                            {--force : すでに精算済の bets も再精算する}';

    protected $description = '未精算馬券を一括精算する(レース結果と払戻が揃っていれば自動判定)';

    public function handle(BetTicketService $service): int
    {
        $q = Bet::query()->with(['race.results', 'legs']);

        if (!$this->option('force')) {
            $q->where('is_settled', false);
        }
        if ($userId = $this->option('user')) {
            $q->where('user_id', (int) $userId);
        }
        if ($raceId = $this->option('race')) {
            $q->where('race_id', (int) $raceId);
        }

        // レース結果が確定しているものに限定
        $q->whereHas('race.results', fn($qq) => $qq->whereNotNull('finish_position_int'));

        $bets = $q->get();
        $this->info("対象: {$bets->count()} 件");

        $settled = 0; $hits = 0; $totalReturn = 0; $errors = 0;
        $bar = $this->output->createProgressBar($bets->count());
        $bar->start();

        foreach ($bets as $bet) {
            try {
                $r = $service->settle($bet);
                $settled++;
                $hits        += $r['hit_count'];
                $totalReturn += $r['total_return'];
            } catch (\Throwable $e) {
                $errors++;
                $this->error("\n  bet#{$bet->id} 精算失敗: {$e->getMessage()}");
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            '精算完了: %d 件(的中 %d 点 / 払戻合計 %s 円 / エラー %d 件)',
            $settled, $hits, number_format($totalReturn), $errors
        ));
        return self::SUCCESS;
    }
}
