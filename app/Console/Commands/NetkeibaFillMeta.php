<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\NetkeibaScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 既存レースの馬場状態・天候・方向・距離など派生メタを netkeiba から再取得して補完
 *
 * 使い方:
 *   php artisan netkeiba:fill-meta                           # 馬場 or 天候が未入力のレース全件
 *   php artisan netkeiba:fill-meta --limit=100               # 100件だけ
 *   php artisan netkeiba:fill-meta --all                     # 既に入っていても再取得
 *   php artisan netkeiba:fill-meta --from=2025-01-01         # 期間指定
 *   php artisan netkeiba:fill-meta --to=2025-12-31
 *   php artisan netkeiba:fill-meta --race=202501010101       # 個別 race_id
 *   php artisan netkeiba:fill-meta --sleep=2                 # 1件ごとに追加待機
 */
class NetkeibaFillMeta extends Command
{
    protected $signature = 'netkeiba:fill-meta
                            {--limit=0 : 処理上限件数(0=全件)}
                            {--all : 既に入っていても再取得}
                            {--from= : 開始日 YYYY-MM-DD}
                            {--to= : 終了日 YYYY-MM-DD}
                            {--race= : netkeiba race_id (12桁)}
                            {--sleep=0 : 1件ごとの追加待機秒}';

    protected $description = '既存レースの馬場状態・天候・方向・距離を netkeiba から補完';

    /** 補完対象フィールド */
    private const META_FIELDS = [
        'course_condition',
        'weather',
        'direction',
        'distance',
        'track_type',
    ];

    public function handle(NetkeibaScraper $scraper): int
    {
        $limit       = (int) $this->option('limit');
        $all         = (bool) $this->option('all');
        $from        = $this->option('from');
        $to          = $this->option('to');
        $raceFilter  = $this->option('race');
        $extraSleep  = (int) $this->option('sleep');

        $query = Race::query()->whereNotNull('netkeiba_id');

        if ($raceFilter) {
            $query->where('netkeiba_id', $raceFilter);
        } else {
            if ($from) $query->whereDate('race_date', '>=', $from);
            if ($to)   $query->whereDate('race_date', '<=', $to);
            if (!$all) {
                $query->where(function ($q) {
                    $q->whereNull('course_condition')
                      ->orWhereNull('weather');
                });
            }
        }
        $query->orderByDesc('race_date');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('対象レースが見つかりませんでした。');
            return self::SUCCESS;
        }

        $process = $limit > 0 ? min($limit, $total) : $total;
        $this->info("メタ補完開始: 対象 {$total} 件 / 処理 {$process} 件");

        if ($limit > 0) {
            $query->limit($limit);
        }

        $bar = $this->output->createProgressBar($process);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');
        $bar->start();

        $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0];

        foreach ($query->cursor() as $race) {
            $label = mb_strimwidth(($race->race_date?->format('m/d') ?? '?').' '.($race->name ?? '?'), 0, 22, '..');
            $bar->setMessage($label);

            try {
                $data = $scraper->fetchRace($race->netkeiba_id);

                $updates = [];
                foreach (self::META_FIELDS as $key) {
                    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                        continue;
                    }
                    if ($all || empty($race->{$key})) {
                        $updates[$key] = $data[$key];
                    }
                }

                if (!empty($updates)) {
                    $race->fill($updates)->save();
                    $stats['ok']++;
                } else {
                    $stats['skip']++;
                }
            } catch (\Throwable $e) {
                $stats['fail']++;
                $this->newLine();
                $this->warn("  ! {$race->netkeiba_id}: " . $e->getMessage());
                Log::warning('fill-meta失敗', [
                    'race_id'     => $race->id,
                    'netkeiba_id' => $race->netkeiba_id,
                    'error'       => $e->getMessage(),
                ]);
            }

            $bar->advance();
            if ($extraSleep > 0) sleep($extraSleep);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('========== 完了 ==========');
        $this->line("  補完済 : {$stats['ok']} 件");
        $this->line("  スキップ: {$stats['skip']} 件 (取得項目なし)");
        $this->line("  失敗   : {$stats['fail']} 件");

        return self::SUCCESS;
    }
}
