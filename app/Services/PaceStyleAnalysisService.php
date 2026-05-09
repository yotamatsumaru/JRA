<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * コース × ペース × 脚質 3D 分析 (Phase 4-K)
 *
 *  - venue × track_type × distance_band × pace × running_style の
 *    勝率/複勝率/回収率(単勝)を集計する
 *  - フロントエンドはヒートマップ + ピボットテーブルで可視化
 */
class PaceStyleAnalysisService
{
    /** 距離帯 (label => [min, max])  */
    public const DISTANCE_BANDS = [
        '短距離'   => [0, 1400],
        'マイル'   => [1401, 1800],
        '中距離'   => [1801, 2200],
        '中長距離' => [2201, 2600],
        '長距離'   => [2601, 9999],
    ];

    /**
     * 3D 集計
     *  filters: from, to, venue_id, track_type, grade, distance_band, pace
     *
     * @return array{
     *   pivot: array<int, array{pace:string, style:string, runs:int, wins:int, top3:int, win_rate:float, place_rate:float, win_roi:float}>,
     *   bands: array<int, array>,    // 距離帯別の集計
     *   venues: array<int, array>,   // 競馬場別の集計
     *   total: array,                // 全体集計
     * }
     */
    public function analyze(array $filters = []): array
    {
        $pivot = $this->aggregate(['races.pace', 'race_results.running_style'], $filters);
        $bands = $this->aggregateByBand($filters);
        $venues = $this->aggregate(['venues.name', 'races.track_type'], $filters, ['venues']);

        // 全体
        $tq = $this->baseQuery($filters);
        $row = $tq->select(
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3'),
                DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return')
            )->first();
        $runs = (int) ($row->runs ?? 0);
        $total = [
            'runs' => $runs,
            'wins' => (int) ($row->wins ?? 0),
            'top3' => (int) ($row->top3 ?? 0),
            'win_rate'   => $runs > 0 ? round($row->wins / $runs * 100, 1) : 0,
            'place_rate' => $runs > 0 ? round($row->top3 / $runs * 100, 1) : 0,
            'win_roi'    => $runs > 0 ? round($row->win_return / ($runs * 100) * 100, 1) : 0,
        ];

        // ペース×脚質 ピボット (UI が直接消費しやすい構造)
        $paceStyleMatrix = $this->buildMatrix($pivot);

        return compact('pivot', 'bands', 'venues', 'total', 'paceStyleMatrix');
    }

    /**
     * 距離帯別の集計
     */
    protected function aggregateByBand(array $filters): array
    {
        $bands = [];
        foreach (self::DISTANCE_BANDS as $label => [$min, $max]) {
            $f = $filters;
            $f['distance_min'] = $min;
            $f['distance_max'] = $max;
            $q = $this->baseQuery($f);
            $row = $q->select(
                    DB::raw('COUNT(*) as runs'),
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins'),
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3'),
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return')
                )->first();
            $runs = (int) ($row->runs ?? 0);
            $bands[] = [
                'band'       => $label,
                'min'        => $min, 'max' => $max,
                'runs'       => $runs,
                'wins'       => (int) ($row->wins ?? 0),
                'top3'       => (int) ($row->top3 ?? 0),
                'win_rate'   => $runs > 0 ? round($row->wins / $runs * 100, 1) : 0,
                'place_rate' => $runs > 0 ? round($row->top3 / $runs * 100, 1) : 0,
                'win_roi'    => $runs > 0 ? round($row->win_return / ($runs * 100) * 100, 1) : 0,
            ];
        }
        return $bands;
    }

    /**
     * 任意のグループキーで集計
     *
     * @param  array<int, string> $groupCols ex: ['races.pace', 'race_results.running_style']
     * @param  array $filters
     * @param  array $extraJoins ['venues' => true] のように指定すると追加 join
     */
    protected function aggregate(array $groupCols, array $filters, array $extraJoins = []): array
    {
        $q = $this->baseQuery($filters, $extraJoins);

        $selects = $groupCols;
        $selects[] = DB::raw('COUNT(*) as runs');
        $selects[] = DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins');
        $selects[] = DB::raw('SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as top3');
        $selects[] = DB::raw('SUM(CASE WHEN race_results.finish_position_int=1 THEN race_results.win_odds * 100 ELSE 0 END) as win_return');

        $rows = $q->select($selects)->groupBy(...$groupCols)->orderByDesc('runs')->get();

        return $rows->map(function ($r) use ($groupCols) {
            $runs = (int) $r->runs;
            $key = [];
            foreach ($groupCols as $col) {
                // "races.pace" → "pace", "venues.name" → "name"
                $alias = preg_replace('/^[^.]+\./', '', $col);
                $key[$alias] = $r->{$alias};
            }
            return $key + [
                'runs'       => $runs,
                'wins'       => (int) $r->wins,
                'top3'       => (int) $r->top3,
                'win_rate'   => $runs > 0 ? round($r->wins / $runs * 100, 1) : 0,
                'place_rate' => $runs > 0 ? round($r->top3 / $runs * 100, 1) : 0,
                'win_roi'    => $runs > 0 ? round($r->win_return / ($runs * 100) * 100, 1) : 0,
            ];
        })->toArray();
    }

    /**
     * pivot を pace × style の 2 次元行列に整形
     *
     * @return array<string, array<string, array>>
     */
    protected function buildMatrix(array $pivot): array
    {
        $matrix = [];
        $paces  = ['H', 'M', 'S'];
        $styles = ['逃', '先', '差', '追'];
        foreach ($paces as $p) {
            foreach ($styles as $s) {
                $matrix[$p][$s] = [
                    'runs' => 0, 'wins' => 0, 'top3' => 0,
                    'win_rate' => 0, 'place_rate' => 0, 'win_roi' => 0,
                ];
            }
        }
        foreach ($pivot as $row) {
            $p = $row['pace'] ?? null;
            $s = $row['running_style'] ?? null;
            if (!$p || !$s) continue;
            if (!in_array($p, $paces, true)) continue;
            if (!in_array($s, $styles, true)) continue;
            $matrix[$p][$s] = [
                'runs'       => $row['runs'],
                'wins'       => $row['wins'],
                'top3'       => $row['top3'],
                'win_rate'   => $row['win_rate'],
                'place_rate' => $row['place_rate'],
                'win_roi'    => $row['win_roi'],
            ];
        }
        return $matrix;
    }

    /**
     * race_results × races (× venues) のベースクエリ
     */
    protected function baseQuery(array $filters, array $extraJoins = []): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('race_results.finish_position_int');

        // 必要なら venues を join (常にやっておく方が安全)
        $q->leftJoin('venues', 'venues.id', '=', 'races.venue_id');

        if (!empty($filters['from']))         $q->whereDate('races.race_date', '>=', $filters['from']);
        if (!empty($filters['to']))           $q->whereDate('races.race_date', '<=', $filters['to']);
        if (!empty($filters['venue_id']))     $q->where('races.venue_id', $filters['venue_id']);
        if (!empty($filters['track_type']))   $q->where('races.track_type', $filters['track_type']);
        if (!empty($filters['grade']))        $q->where('races.grade', $filters['grade']);
        if (!empty($filters['pace']))         $q->where('races.pace', $filters['pace']);
        if (!empty($filters['style']))        $q->where('race_results.running_style', $filters['style']);
        if (!empty($filters['distance_min'])) $q->where('races.distance', '>=', (int) $filters['distance_min']);
        if (!empty($filters['distance_max'])) $q->where('races.distance', '<=', (int) $filters['distance_max']);

        if (!empty($filters['distance_band']) && isset(self::DISTANCE_BANDS[$filters['distance_band']])) {
            [$bmin, $bmax] = self::DISTANCE_BANDS[$filters['distance_band']];
            $q->whereBetween('races.distance', [$bmin, $bmax]);
        }

        return $q;
    }
}
