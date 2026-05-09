<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\OddsSnapshotService;
use Illuminate\Console\Command;

/**
 * オッズスナップショット取得 (Phase 3-I)
 *
 * 使い方:
 *   php artisan odds:capture                  # 当日 出走前レースを一括スナップショット
 *   php artisan odds:capture --race=1234      # 単一レース
 *   php artisan odds:capture --minutes=60     # 発走 60分前までを対象
 */
class OddsCaptureCommand extends Command
{
    protected $signature = 'odds:capture
                            {--race= : 単一レースID}
                            {--minutes=60 : 発走何分前までを対象とするか}
                            {--limit=50 : 1回の最大処理件数}';

    protected $description = '当日の出走前レースのオッズを取得してスナップショット保存 (Phase 3-I)';

    public function handle(OddsSnapshotService $service): int
    {
        if ($id = $this->option('race')) {
            $race = Race::find((int) $id);
            if (!$race) {
                $this->error('レースが見つかりません: ' . $id);
                return self::FAILURE;
            }
            $snap = $service->captureForRace($race);
            if ($snap) {
                $this->info('OK: race#' . $race->id . ' ' . $snap->captured_at);
                return self::SUCCESS;
            }
            $this->warn('スナップショット作成不可 (発走後 / オッズなし): race#' . $race->id);
            return self::SUCCESS;
        }

        $r = $service->captureUpcoming(
            (int) $this->option('minutes'),
            (int) $this->option('limit')
        );

        $this->info(sprintf(
            '完了: 対象 %d 件 / 取得 %d / スキップ %d / エラー %d',
            $r['total'], $r['captured'], $r['skipped'], $r['errors']
        ));
        return self::SUCCESS;
    }
}
