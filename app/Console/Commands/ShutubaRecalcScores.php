<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Models\RaceMark;
use App\Models\RaceResult;
use App\Services\PedigreeRecommendService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 既存 race_marks のスコアを新ロジック(7軸サブスコア)で再計算
 *
 * 旧版は score_pedigree/score_jockey/score_horse/score_roi の4軸のみで保存されていたため、
 * 枠/コース/脚質 の3軸が NULL のレコードを対象に再計算する。
 *
 * 使い方:
 *   php artisan shutuba:recalc-scores                      # 新3軸が未保存の race_marks を全件
 *   php artisan shutuba:recalc-scores --all                # 全 race_marks を再計算(古いキャッシュも上書き)
 *   php artisan shutuba:recalc-scores --user=1             # 特定ユーザーの分のみ
 *   php artisan shutuba:recalc-scores --race=202608030801  # 特定レース(race_id_text)
 *   php artisan shutuba:recalc-scores --limit=500          # 処理上限
 *   php artisan shutuba:recalc-scores --dry-run            # 実行せず件数だけ表示
 *
 * 設計メモ:
 *   レース単位で処理する(ペース推定が全頭の脚質に依存するため)。
 *   1レースぶんを 1トランザクション + デッドロック自動リトライで包む。
 */
class ShutubaRecalcScores extends Command
{
    protected $signature = 'shutuba:recalc-scores
                            {--all : 全 race_marks を強制再計算(旧キャッシュも上書き)}
                            {--user= : 対象ユーザーID}
                            {--race= : 対象 race_id_text (例 202608030801)}
                            {--limit=0 : 処理 race_mark 件数の上限(0=無制限)}
                            {--dry-run : 件数だけ表示して実行しない}';

    protected $description = '既存 race_marks のスコアを新ロジック(枠/コース/脚質追加)で再計算';

    public function handle(PedigreeRecommendService $svc): int
    {
        $all     = (bool) $this->option('all');
        $userId  = $this->option('user') !== null ? (int) $this->option('user') : null;
        $raceTxt = $this->option('race');
        $limit   = (int) $this->option('limit');
        $dryRun  = (bool) $this->option('dry-run');

        // 対象 race_marks を絞り込み
        $q = RaceMark::query();
        if ($userId)  $q->where('user_id', $userId);
        if ($raceTxt) {
            $race = Race::where('race_id_text', $raceTxt)->first();
            if (!$race) {
                $this->error("race_id_text={$raceTxt} のレースが見つかりません");
                return Command::FAILURE;
            }
            $q->where('race_id', $race->id);
        }
        if (!$all) {
            // 新3軸のいずれかが NULL のものだけ
            $q->where(function ($qq) {
                $qq->whereNull('score_frame')
                   ->orWhereNull('score_course')
                   ->orWhereNull('score_style');
            });
        }
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $this->info('対象 race_mark がありません(全件最新済 or 条件にマッチなし)');
            return Command::SUCCESS;
        }

        // レース単位でグループ化(ペース推定はレース内全頭に依存)
        $raceIds = (clone $q)->select('race_id')->distinct()->pluck('race_id');
        $userIds = (clone $q)->select('user_id')->distinct()->pluck('user_id');

        $this->info("対象: race_marks {$total} 件 / レース " . $raceIds->count() . " 件 / ユーザー " . $userIds->count() . " 件");
        if ($dryRun) {
            $this->warn('--dry-run 指定: 実行せず終了');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($raceIds->count() * $userIds->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% / %elapsed:6s%');
        $bar->start();

        $settings = $svc->getSettings();
        $weights  = $settings['weights'];
        $minRuns  = $settings['min_runs'];

        $processed = 0;
        $failed    = 0;

        foreach ($raceIds as $raceId) {
            $race = Race::with(['results.horse'])->find($raceId);
            if (!$race) { $bar->advance($userIds->count()); continue; }

            $cond = [
                'venue_id'         => $race->venue_id,
                'track_type'       => $race->track_type,
                'distance'         => $race->distance,
                'course_condition' => $race->course_condition,
                'direction'        => $race->direction,
            ];

            // 各馬の脚質を推定
            $styles = [];
            foreach ($race->results as $rr) {
                $recent = [];
                if ($rr->horse_id) {
                    $recent = RaceResult::with(['race'])
                        ->where('horse_id', $rr->horse_id)
                        ->where('race_id', '!=', $race->id)
                        ->whereNotNull('finish_position_int')
                        ->whereHas('race', fn($qq) => $qq->where('race_date', '<=', $race->race_date))
                        ->join('races', 'races.id', '=', 'race_results.race_id')
                        ->orderByDesc('races.race_date')
                        ->select('race_results.*')
                        ->limit(5)
                        ->get();
                }
                $styles[$rr->id] = $this->detectStyleFromRecent($recent);
            }
            $cond['pace'] = $svc->estimatePace(array_values($styles));

            foreach ($userIds as $uid) {
                $marks = RaceMark::where('user_id', $uid)
                    ->where('race_id', $raceId)
                    ->get()
                    ->keyBy('race_result_id');
                if ($marks->isEmpty()) {
                    $bar->advance();
                    continue;
                }

                try {
                    DB::transaction(function () use ($race, $marks, $styles, $cond, $weights, $minRuns, $svc, $uid, &$processed) {
                        foreach ($race->results as $rr) {
                            if (!$rr->horse) continue;
                            $mark = $marks->get($rr->id);
                            if (!$mark) continue;

                            $eval = $svc->evaluateHorse(
                                horse:    [
                                    'id'            => $rr->horse->id,
                                    'father'        => $rr->horse->father,
                                    'mother_father' => $rr->horse->mother_father,
                                    'frame_number'  => $rr->frame_number,
                                    'running_style' => $styles[$rr->id] ?? '不',
                                ],
                                jockeyId: $rr->jockey_id ? (int) $rr->jockey_id : null,
                                cond:     $cond,
                                weights:  $weights,
                                minRuns:  $minRuns,
                            );

                            $mark->update([
                                'score_total'    => round((float) $eval['total'], 2),
                                'score_pedigree' => round((float) $eval['sub']['pedigree'], 2),
                                'score_jockey'   => round((float) $eval['sub']['jockey'], 2),
                                'score_horse'    => round((float) $eval['sub']['horse'], 2),
                                'score_roi'      => round((float) $eval['sub']['roi'], 2),
                                'score_frame'    => round((float) $eval['sub']['frame'], 2),
                                'score_course'   => round((float) $eval['sub']['course'], 2),
                                'score_style'    => round((float) $eval['sub']['style'], 2),
                                'scored_at'      => now(),
                            ]);
                            $processed++;
                        }
                    }, 3);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("[skip] race_id={$raceId} user_id={$uid}: " . $e->getMessage());
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("完了: race_marks {$processed} 件を更新 / 失敗 {$failed} ペア");
        return Command::SUCCESS;
    }

    /**
     * 過去走の通過順位から脚質を推定(ShutubaController::estimateRunningStyle の簡易版)
     */
    private function detectStyleFromRecent($recent): string
    {
        if (!$recent || count($recent) === 0) return '不';
        $styles = [];
        foreach ($recent as $past) {
            $cp = $past->corner_positions ?? null;
            $count = 16;  // 頭数情報が無い場合は主要レース想定
            $style = \App\Models\RaceResult::detectRunningStyle($cp, $count);
            if ($style) $styles[] = $style;
        }
        if (empty($styles)) return '不';
        $counts = array_count_values($styles);
        arsort($counts);
        return (string) array_key_first($counts);
    }
}
