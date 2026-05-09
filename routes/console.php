<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| スケジュール定義 (Phase 3-J)
|--------------------------------------------------------------------------
| cron に下記を 1 分毎に登録すれば自動発火する:
|     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| 各ジョブは scheduled:run でラップして実行ログ (scheduler_logs) を残す。
*/

// ------ オッズスナップショット (10分毎) ------
// 当日の出走前レースのオッズを取得。レース時間以外は対象 0 件で軽量に終わる。
Schedule::command('scheduled:run', ['--job=odds:capture', '--args=--minutes=60 --limit=50'])
    ->name('odds-capture')
    ->everyTenMinutes()
    ->between('09:00', '17:30')
    ->withoutOverlapping(15)
    ->onOneServer();

// ------ 当日結果取込 (毎日 18:30) ------
Schedule::command('scheduled:run', ['--job=netkeiba:date', '--args=--date=today'])
    ->name('netkeiba-today')
    ->dailyAt('18:30')
    ->withoutOverlapping(60)
    ->onOneServer();

// ------ 翌日出馬表取込 (毎日 21:00) ------
Schedule::command('scheduled:run', ['--job=netkeiba:shutuba-date', '--args=--date=tomorrow'])
    ->name('netkeiba-tomorrow-shutuba')
    ->dailyAt('21:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// ------ 未精算馬券の一括精算 (毎日 19:00 + 23:00) ------
Schedule::command('scheduled:run', ['--job=bets:resettle'])
    ->name('bets-resettle')
    ->twiceDaily(19, 23)
    ->withoutOverlapping(30)
    ->onOneServer();

// ------ 日次バックアップ (毎日 03:00) ------
Schedule::command('scheduled:run', ['--job=app:backup', '--args=--keep=14'])
    ->name('app-backup')
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// ------ 古い scheduler_logs / audit_logs の整理 (毎週日曜 04:00) ------
Schedule::call(function () {
    \App\Models\SchedulerLog::where('created_at', '<', now()->subDays(60))->delete();
    \App\Models\AuditLog::where('created_at', '<', now()->subDays(180))->delete();
})->name('cleanup-logs')->weeklyOn(0, '04:00');
