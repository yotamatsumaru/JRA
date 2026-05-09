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
                            {--sleep=0 : 1件ごとの追加待機秒}
                            {--interval= : リクエスト間隔(秒)。デフォルトは config(services.netkeiba.request_interval)。0で待機なし(自己責任)}';

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

        // インターバル上書き(指定があれば)
        $intervalOpt = $this->option('interval');
        if ($intervalOpt !== null && $intervalOpt !== '') {
            $iv = max(0, (int) $intervalOpt);
            $scraper->setRequestInterval($iv);
            $this->info("リクエスト間隔: {$iv}秒");
        }

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
            $this->warn('対象レースが見つかりませんでした。');

            // 診断情報: ユーザーにどの条件で 0 件になったか分かりやすく示す
            if (!$raceFilter) {
                $diag = Race::query()->whereNotNull('netkeiba_id');
                if ($from) $diag->whereDate('race_date', '>=', $from);
                if ($to)   $diag->whereDate('race_date', '<=', $to);

                $rangeTotal     = (clone $diag)->count();
                $alreadyFilled  = (clone $diag)
                    ->whereNotNull('course_condition')
                    ->whereNotNull('weather')
                    ->count();
                $needFill       = (clone $diag)
                    ->where(function ($q) {
                        $q->whereNull('course_condition')
                          ->orWhereNull('weather');
                    })->count();

                $this->newLine();
                $this->line('---------- 診断 ----------');
                $rangeLabel = ($from || $to)
                    ? '期間 '.($from ?: '----').' 〜 '.($to ?: '----')
                    : '全期間';
                $this->line("  {$rangeLabel} のレース総数 : {$rangeTotal} 件");
                $this->line("  うち馬場/天候が既に入力済 : {$alreadyFilled} 件");
                $this->line("  うち未入力 (補完対象)     : {$needFill} 件");
                $this->newLine();

                if ($rangeTotal === 0) {
                    $this->warn('→ この期間のレースがそもそも DB に存在しません。');
                    $this->line('  まず取り込みコマンドを実行してください。例:');
                    $this->line('    php artisan netkeiba:import-year 2025');
                    $this->line('  もしくは特定日付:');
                    $this->line('    php artisan netkeiba:import-date 2025-01-05');
                } elseif ($needFill === 0 && $alreadyFilled > 0) {
                    $this->warn('→ 該当レースは全て馬場/天候が入力済のためスキップされました。');
                    $this->line('  既存値を上書きして再取得したい場合は --all を付けてください:');
                    $cmd = 'php artisan netkeiba:fill-meta --all';
                    if ($from) $cmd .= " --from={$from}";
                    if ($to)   $cmd .= " --to={$to}";
                    $this->line("    {$cmd}");
                }
            }

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
