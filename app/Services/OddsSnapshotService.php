<?php

namespace App\Services;

use App\Models\OddsSnapshot;
use App\Models\Race;

/**
 * オッズスナップショット保存サービス (Phase 3-I)
 *  - NetkeibaScraper::fetchShutuba() の結果から win_odds / popularity を抽出
 *  - odds_snapshots テーブルへ時系列保存
 *
 * Phase EV-5:
 *  - fetchShutuba() は同じ HTTP アクセスで天候(weather) / 馬場状態(course_condition) も
 *    パース済みなので、オッズ取得のついでに races テーブルへ反映する (リアルタイム馬場状況)。
 *    追加のスクレイピングは発生しない。
 */
class OddsSnapshotService
{
    public function __construct(protected NetkeibaScraper $scraper)
    {
    }

    /**
     * レースのオッズを取得して保存
     *
     * 副作用: 取得できた天候/馬場状態を races.weather / races.course_condition /
     *         races.course_condition_checked_at に反映する (Phase EV-5)。
     *
     * @return OddsSnapshot|null  保存できなかった (発走後など) は null
     */
    public function captureForRace(Race $race): ?OddsSnapshot
    {
        if (empty($race->netkeiba_id)) {
            return null;
        }

        try {
            $shutuba = $this->scraper->fetchShutuba($race->netkeiba_id);
        } catch (\Throwable $e) {
            \Log::info("OddsSnapshot fetch failed: race#{$race->id}: " . $e->getMessage());
            return null;
        }

        // 馬場状況の反映 (オッズが取れない/0頭でも、天候・馬場は分かる場合があるため
        // オッズ抽出より先に行い、オッズ取得失敗時も馬場状況だけは更新されるようにする)
        $this->applyRaceConditions($race, $shutuba);

        $rows = $shutuba['results'] ?? $shutuba['rows'] ?? $shutuba['horses'] ?? [];
        if (empty($rows)) return null;

        $payload = [];
        foreach ($rows as $row) {
            $hno = (int) ($row['horse_number'] ?? 0);
            if ($hno < 1) continue;
            $payload[$hno] = [
                'horse_name' => $row['horse_name'] ?? null,
                'win_odds'   => isset($row['win_odds']) ? (float) $row['win_odds'] : null,
                'popularity' => isset($row['popularity']) ? (int) $row['popularity'] : null,
            ];
        }

        if (empty($payload)) return null;

        return OddsSnapshot::create([
            'race_id'     => $race->id,
            'captured_at' => now(),
            'source'      => 'netkeiba',
            'payload'     => $payload,
        ]);
    }

    /**
     * 天候・馬場状態を races テーブルへ反映 (Phase EV-5)
     *
     * - weather / course_condition のいずれかが取得できていれば更新
     * - 変化が無くても「確認できた時刻」として course_condition_checked_at は毎回更新する
     *   (「◯時◯分現在も良馬場」という情報自体に意味があるため)
     * - 更新失敗はオッズ取得の成否に影響させない (握りつぶしてログのみ)
     */
    protected function applyRaceConditions(Race $race, array $shutuba): void
    {
        $weather   = $shutuba['weather'] ?? null;
        $condition = $shutuba['course_condition'] ?? null;

        if ($weather === null && $condition === null) {
            return; // 取得できなかった(発走直前でまだ確定していない等) → 何もしない
        }

        try {
            $dirty = false;
            if ($weather !== null && $race->weather !== $weather) {
                $race->weather = $weather;
                $dirty = true;
            }
            if ($condition !== null && $race->course_condition !== $condition) {
                $race->course_condition = $condition;
                $dirty = true;
            }
            // 値の変化有無に関わらず「確認時刻」は更新する
            $race->course_condition_checked_at = now();
            $race->save();

            if ($dirty) {
                \Log::info("RaceCondition updated: race#{$race->id} weather={$race->weather} course_condition={$race->course_condition}");
            }
        } catch (\Throwable $e) {
            \Log::warning("RaceCondition update failed: race#{$race->id}: " . $e->getMessage());
        }
    }

    /**
     * 出走前のレースを対象に一括スナップショット
     *
     * @param  int $minutesBefore レース発走何分前までを対象にするか
     * @param  int $limit         一度に処理する最大件数
     */
    public function captureUpcoming(int $minutesBefore = 30, int $limit = 50): array
    {
        $now = now();
        $races = Race::whereNotNull('netkeiba_id')
            ->whereDate('race_date', $now->toDateString())
            ->whereDoesntHave('results', fn($q) => $q->whereNotNull('finish_position_int'))
            ->orderBy('race_number')
            ->limit($limit)
            ->get();

        $captured = 0; $skipped = 0; $errors = 0;
        foreach ($races as $race) {
            try {
                $snap = $this->captureForRace($race);
                $snap ? $captured++ : $skipped++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return ['captured' => $captured, 'skipped' => $skipped, 'errors' => $errors, 'total' => $races->count()];
    }

    /**
     * レースのオッズ推移を取得 (グラフ用)
     */
    public function timeline(int $raceId): array
    {
        $snaps = OddsSnapshot::where('race_id', $raceId)
            ->orderBy('captured_at')
            ->get();

        $series = [];
        foreach ($snaps as $s) {
            $ts = $s->captured_at->format('H:i');
            foreach (($s->payload ?? []) as $hno => $row) {
                $series[$hno][] = [
                    't'    => $ts,
                    'odds' => $row['win_odds'] ?? null,
                    'pop'  => $row['popularity'] ?? null,
                ];
            }
        }
        return $series;
    }
}
