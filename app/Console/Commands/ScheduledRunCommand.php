<?php

namespace App\Console\Commands;

use App\Models\SchedulerLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * スケジューラ用ラッパー (Phase 3-J)
 *
 *  指定の artisan コマンドをラップして scheduler_logs にログを残す。
 *  cron からは
 *      * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 *  を 1 分毎に動かしておけば、routes/console.php に書いた
 *  Schedule::command('scheduled:run --job="netkeiba:date" ...')
 *  が時刻通りに発火する。
 *
 * 使い方:
 *   php artisan scheduled:run --job="netkeiba:date" --args="--date=today"
 *   php artisan scheduled:run --job="bets:resettle"
 *   php artisan scheduled:run --job="odds:capture" --args="--minutes=60"
 *   php artisan scheduled:run --job="app:backup"
 */
class ScheduledRunCommand extends Command
{
    protected $signature = 'scheduled:run
                            {--job= : 実行する artisan コマンド名}
                            {--args= : コマンド引数 (シェル風文字列)}';

    protected $description = 'artisan コマンドをラップして scheduler_logs に記録しながら実行 (Phase 3-J)';

    public function handle(): int
    {
        $job = (string) $this->option('job');
        if ($job === '') {
            $this->error('--job が必要です');
            return self::FAILURE;
        }
        $argsRaw = (string) $this->option('args');

        $log = SchedulerLog::create([
            'job'        => $job,
            'status'     => SchedulerLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $startMs = microtime(true) * 1000;
        $output  = '';
        $err     = null;
        $exit    = 0;

        try {
            // args を parse_str ではなく単純トークン分割
            $params = $this->parseArgs($argsRaw);

            $exit = Artisan::call($job, $params);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            $err  = $e->getMessage();
            $exit = 1;
        }

        $endMs = microtime(true) * 1000;

        $log->update([
            'status'      => $err === null && $exit === 0 ? SchedulerLog::STATUS_SUCCESS : SchedulerLog::STATUS_FAILED,
            'finished_at' => now(),
            'duration_ms' => (int) round($endMs - $startMs),
            'exit_code'   => $exit,
            'output'      => mb_substr($output, 0, 60000),
            'error'       => $err,
        ]);

        if ($err) {
            $this->error("scheduled:{$job} 失敗: {$err}");
            return self::FAILURE;
        }
        $this->info("scheduled:{$job} 完了 (exit={$exit}, " . round(($endMs - $startMs)) . 'ms)');
        return self::SUCCESS;
    }

    /**
     *  "--date=today --force --foo=bar baz"
     *    → ['--date' => 'today', '--force' => true, '--foo' => 'bar', 0 => 'baz']
     */
    protected function parseArgs(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $params = [];
        $idx = 0;
        foreach ($tokens as $tok) {
            if ($tok === '') continue;
            if (str_starts_with($tok, '--')) {
                $eq = strpos($tok, '=');
                if ($eq === false) {
                    $params[$tok] = true;
                } else {
                    $key = substr($tok, 0, $eq);
                    $val = substr($tok, $eq + 1);
                    // --opt="value with space" 形式は preg_split で壊れるが、
                    // 実用上は = 以降をそのまま渡す
                    $params[$key] = trim($val, "\"'");
                }
            } else {
                $params[$idx++] = $tok;
            }
        }
        return $params;
    }
}
