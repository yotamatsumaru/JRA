<?php

namespace App\Services;

use App\Models\RaceMark;
use Illuminate\Support\Facades\DB;

/**
 * 予想精度トラッキング (Phase 4-N)
 *
 *  ユーザーの印 (◎○▲△☆✕) と着順を突き合わせて
 *   - 印別の的中率(複勝圏=1〜3着)、勝率、回収率(単勝/複勝)
 *   - 期間 / コース / トラック別の集計
 *  を返す。
 */
class PredictionAccuracyService
{
    /**
     * 印別の精度サマリ
     *
     * @param  array  $filters [from, to, venue_id, track_type, distance_min, distance_max, grade]
     * @return array<string, array>  mark => { runs, wins, top3, hit_rate, win_rate, win_roi, place_roi, ... }
     */
    public function summary(int $userId, array $filters = []): array
    {
        $q = RaceMark::query()
            ->where('race_marks.user_id', $userId)
            ->whereNotNull('race_marks.mark')
            ->join('race_results', 'race_results.id', '=', 'race_marks.race_result_id')
            ->join('races', 'races.id', '=', 'race_marks.race_id')
            ->whereNotNull('race_results.finish_position_int');

        $this->applyFilters($q, $filters);

        $rows = $q->select(
                'race_marks.mark',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=2 THEN 1 ELSE 0 END) as top2'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3'),
                // 単勝オッズ × 100円ベースの理論回収 (的中時のみオッズ加算)
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return'),
                // 複勝は place_odds_min を保守側に使う (なければ win_odds の 1/3 で代替)
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN COALESCE(race_results.place_odds_min, race_results.win_odds/3) * 100 ELSE 0 END) as place_return')
            )
            ->groupBy('race_marks.mark')
            ->get();

        $out = [];
        foreach (RaceMark::MARKS as $m) {
            $out[$m] = [
                'mark' => $m, 'runs' => 0, 'wins' => 0, 'top2' => 0, 'top3' => 0,
                'win_rate' => null, 'place_rate' => null, 'top2_rate' => null,
                'win_roi'  => null, 'place_roi' => null,
            ];
        }
        foreach ($rows as $r) {
            $runs = (int) $r->runs;
            if ($runs <= 0) continue;
            $invested = $runs * 100;  // 1点 100 円ベース
            $out[$r->mark] = [
                'mark'       => $r->mark,
                'runs'       => $runs,
                'wins'       => (int) $r->wins,
                'top2'       => (int) $r->top2,
                'top3'       => (int) $r->top3,
                'win_rate'   => round($r->wins  / $runs * 100, 1),
                'top2_rate'  => round($r->top2  / $runs * 100, 1),
                'place_rate' => round($r->top3  / $runs * 100, 1),
                'win_roi'    => $invested > 0 ? round($r->win_return   / $invested * 100, 1) : null,
                'place_roi'  => $invested > 0 ? round($r->place_return / $invested * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * 月別の的中率推移
     * @return array<int, array>  ym, runs, top3, place_rate, win_roi
     */
    public function monthlyTrend(int $userId, array $filters = []): array
    {
        $q = RaceMark::query()
            ->where('race_marks.user_id', $userId)
            ->whereNotNull('race_marks.mark')
            ->join('race_results', 'race_results.id', '=', 'race_marks.race_result_id')
            ->join('races', 'races.id', '=', 'race_marks.race_id')
            ->whereNotNull('race_results.finish_position_int');

        $this->applyFilters($q, $filters);

        return $q->select(
                DB::raw("DATE_FORMAT(races.race_date, '%Y-%m') as ym"),
                'race_marks.mark',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return')
            )
            ->groupBy('ym', 'race_marks.mark')
            ->orderBy('ym')
            ->get()
            ->map(fn($r) => [
                'ym'         => $r->ym,
                'mark'       => $r->mark,
                'runs'       => (int) $r->runs,
                'top3'       => (int) $r->top3,
                'place_rate' => $r->runs > 0 ? round($r->top3 / $r->runs * 100, 1) : 0,
                'win_roi'    => $r->runs > 0 ? round($r->win_return / ($r->runs * 100) * 100, 1) : 0,
            ])
            ->toArray();
    }

    /**
     * コース別の本命(◎)精度
     */
    public function courseBreakdown(int $userId, array $filters = []): array
    {
        $q = RaceMark::query()
            ->where('race_marks.user_id', $userId)
            ->where('race_marks.mark', '◎')
            ->join('race_results', 'race_results.id', '=', 'race_marks.race_result_id')
            ->join('races', 'races.id', '=', 'race_marks.race_id')
            ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
            ->whereNotNull('race_results.finish_position_int');

        $this->applyFilters($q, $filters);

        return $q->select(
                'venues.name as venue_name',
                'races.track_type',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return')
            )
            ->groupBy('venues.name', 'races.track_type')
            ->orderByDesc('runs')
            ->get()
            ->map(fn($r) => [
                'venue'      => $r->venue_name,
                'track_type' => $r->track_type,
                'runs'       => (int) $r->runs,
                'wins'       => (int) $r->wins,
                'top3'       => (int) $r->top3,
                'win_rate'   => $r->runs > 0 ? round($r->wins / $r->runs * 100, 1) : 0,
                'place_rate' => $r->runs > 0 ? round($r->top3 / $r->runs * 100, 1) : 0,
                'win_roi'    => $r->runs > 0 ? round($r->win_return / ($r->runs * 100) * 100, 1) : 0,
            ])
            ->toArray();
    }

    /**
     * フィルタを適用 (race_marks JOIN を前提)
     */
    protected function applyFilters($q, array $f): void
    {
        if (!empty($f['from']))     $q->whereDate('races.race_date', '>=', $f['from']);
        if (!empty($f['to']))       $q->whereDate('races.race_date', '<=', $f['to']);
        if (!empty($f['venue_id'])) $q->where('races.venue_id', $f['venue_id']);
        if (!empty($f['track_type'])) $q->where('races.track_type', $f['track_type']);
        if (!empty($f['grade']))    $q->where('races.grade', $f['grade']);
        if (!empty($f['distance_min'])) $q->where('races.distance', '>=', (int) $f['distance_min']);
        if (!empty($f['distance_max'])) $q->where('races.distance', '<=', (int) $f['distance_max']);
    }
}
