<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Models\ImportLog;
use App\Models\Payout;
use App\Models\Race;
use App\Models\RaceResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * netkeibaから取り込んだデータを削除する
 *
 * 対象:
 *   - races (netkeiba_id IS NOT NULL)
 *   - race_results / payouts (races のcascadeで自動削除)
 *   - import_logs (source='netkeiba')
 *   - storage/app/netkeiba_year_*.json (進捗ファイル)
 *
 * 保護対象（デフォルト）:
 *   - bets / bet_legs (ユーザーが登録した馬券)
 *     → 馬券が紐づくレースは削除をブロック（--force-bets で強制削除可）
 *   - horses / jockeys / trainers
 *     → --orphans オプションで孤立したものだけ削除可
 *
 * 使い方:
 *   php artisan netkeiba:purge --dry-run                  # 削除対象だけ表示
 *   php artisan netkeiba:purge                            # 確認プロンプト付き全削除
 *   php artisan netkeiba:purge --year=2026                # 2026年分だけ削除
 *   php artisan netkeiba:purge --year=2026 --month=1      # 2026年1月分だけ削除
 *   php artisan netkeiba:purge --force                    # 確認プロンプトをスキップ
 *   php artisan netkeiba:purge --force-bets               # 馬券があっても削除（馬券も連動削除される）
 *   php artisan netkeiba:purge --orphans                  # 孤立した馬・騎手・調教師も削除
 *   php artisan netkeiba:purge --wipe-all                 # 馬・騎手・調教師も含めて全部消す（最強）
 *   php artisan netkeiba:purge --keep-progress            # 進捗ファイルは残す
 */
class NetkeibaPurge extends Command
{
    protected $signature = 'netkeiba:purge
                            {--year= : 削除対象年 (YYYY)。省略時は全期間}
                            {--month= : 削除対象月 (1-12)。--year と併用}
                            {--dry-run : 削除せず対象件数のみ表示}
                            {--force : 確認プロンプトをスキップ}
                            {--force-bets : 馬券が紐づくレースも削除（馬券も連動削除）}
                            {--orphans : 孤立した馬・騎手・調教師も削除}
                            {--wipe-all : 馬・騎手・調教師テーブルを TRUNCATE で全削除（最強。--force-bets を含意）}
                            {--keep-progress : 進捗ファイル(netkeiba_year_*.json)は残す}
                            {--keep-logs : import_logs は残す}';

    protected $description = 'netkeibaから取り込んだレース・払戻・取込ログを削除';

    public function handle(): int
    {
        $year = $this->option('year');
        $month = $this->option('month');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $forceBets = (bool) $this->option('force-bets');
        $orphans = (bool) $this->option('orphans');
        $wipeAll = (bool) $this->option('wipe-all');
        $keepProgress = (bool) $this->option('keep-progress');
        $keepLogs = (bool) $this->option('keep-logs');

        if ($month && !$year) {
            $this->error('--month は --year と併用してください');
            return self::FAILURE;
        }

        // --wipe-all は --force-bets と --orphans を含意
        if ($wipeAll) {
            $forceBets = true;
            $orphans = true;
        }

        // ========== 削除対象レースのクエリ構築 ==========
        $racesQuery = Race::query()->whereNotNull('netkeiba_id');
        $rangeLabel = '全期間';
        if ($year) {
            $racesQuery->whereYear('race_date', (int) $year);
            $rangeLabel = "{$year}年";
            if ($month) {
                $racesQuery->whereMonth('race_date', (int) $month);
                $rangeLabel = "{$year}年" . sprintf('%d月', (int) $month);
            }
        }

        // ========== 集計 ==========
        $raceIds = (clone $racesQuery)->pluck('id');
        $raceCount = $raceIds->count();

        if ($raceCount === 0) {
            $this->info("削除対象のレースはありません ({$rangeLabel})");
            return self::SUCCESS;
        }

        $resultCount = RaceResult::whereIn('race_id', $raceIds)->count();
        $payoutCount = Payout::whereIn('race_id', $raceIds)->count();

        // 馬券が紐づくレース
        $betRaceIds = Bet::whereIn('race_id', $raceIds)->pluck('race_id')->unique();
        $betCount = Bet::whereIn('race_id', $raceIds)->count();
        $protectedRaceCount = $betRaceIds->count();

        // 取込ログ
        $logQuery = ImportLog::query()->where('source', 'netkeiba');
        if ($year) {
            // reference 例: "year:2026(1-12)" / "2026-01-04 - 2026-01-05" を簡易マッチ
            $logQuery->where(function ($q) use ($year, $month) {
                $q->where('reference', 'like', "%{$year}%");
                if ($month) {
                    $q->where('reference', 'like', "%" . sprintf('%02d', (int) $month) . "%");
                }
            });
        }
        $logCount = $keepLogs ? 0 : $logQuery->count();

        // 進捗ファイル
        $progressFiles = [];
        if (!$keepProgress) {
            $allFiles = Storage::files();
            foreach ($allFiles as $f) {
                if (preg_match('/netkeiba_year_(\d{4})\.json$/', $f, $m)) {
                    if (!$year || (int) $m[1] === (int) $year) {
                        $progressFiles[] = $f;
                    }
                }
            }
        }

        // ========== 表示 ==========
        $this->info("=================================================");
        $this->info(" netkeiba データ削除 ({$rangeLabel})");
        $this->info("=================================================");
        $this->line(sprintf(" レース           : %s 件", number_format($raceCount)));
        $this->line(sprintf(" レース結果       : %s 件", number_format($resultCount)));
        $this->line(sprintf(" 払戻             : %s 件", number_format($payoutCount)));
        $this->line(sprintf(" 取込ログ         : %s 件%s", number_format($logCount), $keepLogs ? ' (--keep-logs で保持)' : ''));
        $this->line(sprintf(" 進捗ファイル     : %d 個%s", count($progressFiles), $keepProgress ? ' (--keep-progress で保持)' : ''));

        if ($protectedRaceCount > 0) {
            $this->newLine();
            if ($forceBets) {
                $this->warn(sprintf(' ⚠ 馬券データ      : %s 件 が連動削除されます (--force-bets 指定中)',
                    number_format($betCount)));
                $this->warn(sprintf('                    対象レース %d 件に紐づく馬券も全て消えます', $protectedRaceCount));
            } else {
                $this->warn(sprintf(' 🛡 保護中レース    : %d 件 (馬券が紐づくため削除されません)', $protectedRaceCount));
                $this->warn('                    強制削除する場合は --force-bets を指定');
            }
        }

        if ($wipeAll) {
            $this->newLine();
            $this->warn(' 💣 全削除モード   : 馬・騎手・調教師テーブルも TRUNCATE で全削除');
            $this->warn('                    (取込済データに含まれない馬も全部消えます)');
        } elseif ($orphans) {
            $this->line(' 孤立データ削除   : 有効 (馬・騎手・調教師の孤立分も削除)');
        }

        $this->info("=================================================");

        // ========== dry-run はここまで ==========
        if ($dryRun) {
            $this->info('[dry-run] 実際には削除していません');
            return self::SUCCESS;
        }

        // ========== 確認プロンプト ==========
        if (!$force) {
            $this->newLine();
            $msg = "本当に削除しますか？";
            if ($protectedRaceCount > 0 && $forceBets) {
                $msg .= " (馬券データも連動削除されます)";
            }
            if ($wipeAll) {
                $msg = "💣 馬・騎手・調教師テーブルも全部消えますが本当に削除しますか？";
            }
            if (!$this->confirm($msg, false)) {
                $this->info('キャンセルしました');
                return self::SUCCESS;
            }
        }

        // ========== 削除実行 ==========
        $deletedRaces = 0;
        $deletedBets = 0;

        try {
            DB::transaction(function () use ($racesQuery, $betRaceIds, $forceBets, &$deletedRaces, &$deletedBets) {
                $targetIds = $racesQuery->pluck('id');

                if (!$forceBets && $betRaceIds->isNotEmpty()) {
                    // 馬券が紐づくレースは除外
                    $targetIds = $targetIds->diff($betRaceIds);
                }

                if ($forceBets && $betRaceIds->isNotEmpty()) {
                    // 連動削除前の件数把握
                    $deletedBets = Bet::whereIn('race_id', $betRaceIds)->count();
                }

                // チャンク削除（FK cascade で results/payouts/bets/bet_legs も自動削除）
                Race::whereIn('id', $targetIds)->chunkById(500, function ($chunk) use (&$deletedRaces) {
                    $ids = $chunk->pluck('id')->all();
                    $deletedRaces += Race::whereIn('id', $ids)->delete();
                });
            });

            $this->info(sprintf('✓ レース %s 件を削除', number_format($deletedRaces)));
            if ($deletedBets > 0) {
                $this->warn(sprintf('  └ 連動削除された馬券: %s 件', number_format($deletedBets)));
            }

            // 取込ログ削除
            if (!$keepLogs && $logCount > 0) {
                $deletedLogs = $logQuery->delete();
                $this->info(sprintf('✓ 取込ログ %s 件を削除', number_format($deletedLogs)));
            }

            // 進捗ファイル削除
            if (!$keepProgress && !empty($progressFiles)) {
                foreach ($progressFiles as $f) {
                    Storage::delete($f);
                }
                $this->info(sprintf('✓ 進捗ファイル %d 個を削除', count($progressFiles)));
            }

            // 孤立データ / 全削除
            if ($wipeAll) {
                $this->newLine();
                $this->info('馬・騎手・調教師テーブルを TRUNCATE …');
                $wipeStats = $this->wipeMasterTables();
                foreach ($wipeStats as $label => $cnt) {
                    $this->info(sprintf('✓ %s %s 件を削除', $label, number_format($cnt)));
                }
            } elseif ($orphans) {
                $this->newLine();
                $this->info('孤立データの削除…');
                $orphanStats = $this->purgeOrphans();
                foreach ($orphanStats as $label => $cnt) {
                    if ($cnt > 0) {
                        $this->info(sprintf('✓ 孤立%s %s 件を削除', $label, number_format($cnt)));
                    }
                }
            }

            $this->newLine();
            $this->info('=================================================');
            $this->info(' 削除完了');
            $this->info('=================================================');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('削除エラー: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * 馬・騎手・調教師テーブルを TRUNCATE で全削除
     *  - 外部キー制約を一時無効化
     *  - 件数を返却
     */
    protected function wipeMasterTables(): array
    {
        $stats = [
            '馬'     => DB::table('horses')->count(),
            '騎手'   => DB::table('jockeys')->count(),
            '調教師' => DB::table('trainers')->count(),
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('horses')->truncate();
            DB::table('jockeys')->truncate();
            DB::table('trainers')->truncate();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $stats;
    }

    /**
     * 孤立データ（どのレース結果からも参照されていない）を削除
     */
    protected function purgeOrphans(): array
    {
        $stats = ['馬' => 0, '騎手' => 0, '調教師' => 0];

        // 馬: race_results からも bets からも参照されていないもの
        // ※ horses は race_results.horse_id 経由でのみ使われている想定
        $stats['馬'] = DB::table('horses')
            ->whereNotIn('id', function ($q) {
                $q->select('horse_id')->from('race_results')->whereNotNull('horse_id');
            })
            ->delete();

        // 騎手: race_results.jockey_id から参照されていないもの
        $stats['騎手'] = DB::table('jockeys')
            ->whereNotIn('id', function ($q) {
                $q->select('jockey_id')->from('race_results')->whereNotNull('jockey_id');
            })
            ->delete();

        // 調教師: race_results.trainer_id から参照されていないもの
        $stats['調教師'] = DB::table('trainers')
            ->whereNotIn('id', function ($q) {
                $q->select('trainer_id')->from('race_results')->whereNotNull('trainer_id');
            })
            ->delete();

        return $stats;
    }
}
