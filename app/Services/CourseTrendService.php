<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * コース傾向集計サービス (Phase EV-4)
 *
 * 出馬表ページ (shutuba.show) に「このコースの過去傾向」パネルを表示するための
 * 集計データを提供する。
 *
 * 集計母集団:
 *   同じ (venue_id, track_type, distance) のレースのうち、直近36ヶ月分
 *
 * キャッシュ:
 *   (venue_id, track_type, distance) をキーに 24h キャッシュ
 *   → 24h の間、同コースは同じ結果を返す。
 *   → コース傾向は日次で大きく変わらないので十分。
 *
 * 現在レース (対象レース) の course_condition (良/稍重/重/不良) にマッチした
 * 「馬場状態別」の集計も並列で返す (フロントで両方表示)。
 */
class CourseTrendService
{
    /** @var int 集計対象期間 (月) */
    protected const LOOKBACK_MONTHS = 36;

    /** @var int キャッシュTTL (秒) */
    protected const CACHE_TTL = 86400; // 24h

    /**
     * コース傾向データを返す
     *
     * @return array{
     *   sample_size: int,
     *   period: array{from: string, to: string},
     *   summary: array,
     *   frame_stats: array,
     *   style_stats: array,
     *   condition_stats: array,
     *   pace_stats: array,
     *   lap_stats: array,
     *   current_condition_summary: array|null
     * }
     */
    public function analyze(Race $race): array
    {
        // 集計に必要な最小情報が揃っていないコースは空を返す
        if (!$race->venue_id || !$race->track_type || !$race->distance) {
            return $this->emptyResult();
        }

        try {
            $cacheKey = sprintf(
                'course_trend:v1:%d:%s:%d',
                $race->venue_id,
                $race->track_type,
                $race->distance
            );

            $base = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($race) {
                return $this->computeBaseTrend($race);
            });

            // 現在レースの馬場状態にマッチした集計 (キャッシュ外: 条件が変わりうるため軽い集計だけ)
            $currentCondition = null;
            if ($race->course_condition) {
                $currentCondition = Cache::remember(
                    $cacheKey . ':cond:' . $race->course_condition,
                    self::CACHE_TTL,
                    function () use ($race) {
                        return $this->computeConditionSummary($race, $race->course_condition);
                    }
                );
            }

            $base['current_condition_summary'] = $currentCondition;
            $base['current_condition_label']   = $race->course_condition;

            return $base;
        } catch (Throwable $e) {
            // コース傾向は補助情報。集計失敗で出馬表画面全体を落とさない。
            Log::warning('CourseTrendService::analyze failed', [
                'race_id'    => $race->id,
                'venue_id'   => $race->venue_id,
                'track_type' => $race->track_type,
                'distance'   => $race->distance,
                'error'      => $e->getMessage(),
            ]);
            return $this->emptyResult();
        }
    }

    /**
     * (venue, track_type, distance) 母集団のベース集計
     */
    protected function computeBaseTrend(Race $race): array
    {
        $from = Carbon::now()->subMonths(self::LOOKBACK_MONTHS)->toDateString();
        $to   = Carbon::now()->toDateString();

        // 対象レースIDを取得 (母集団)
        $raceIds = Race::where('venue_id', $race->venue_id)
            ->where('track_type', $race->track_type)
            ->where('distance', $race->distance)
            ->whereBetween('race_date', [$from, $to])
            ->whereHas('results', fn($q) => $q->whereNotNull('finish_position_int'))
            ->pluck('id')
            ->all();

        $sampleSize = count($raceIds);

        if ($sampleSize === 0) {
            return array_merge($this->emptyResult(), [
                'period' => ['from' => $from, 'to' => $to],
            ]);
        }

        return [
            'sample_size'     => $sampleSize,
            'period'          => ['from' => $from, 'to' => $to],
            'summary'         => $this->summarize($raceIds),
            'frame_stats'     => $this->frameStats($raceIds),
            'style_stats'     => $this->styleStats($raceIds),
            'condition_stats' => $this->conditionStats($raceIds),
            'pace_stats'      => $this->paceStats($raceIds),
            'lap_stats'       => $this->lapStats($raceIds),
        ];
    }

    /**
     * サマリー (平均勝ちタイム / 平均ペース / 頭数傾向 など)
     */
    protected function summarize($raceIds): array
    {
        // 平均勝ちタイム (finish_position_int = 1 の time_seconds 平均)
        $avgWinTime = DB::table('race_results')
            ->whereIn('race_id', $raceIds)
            ->where('finish_position_int', 1)
            ->whereNotNull('time_seconds')
            ->avg('time_seconds');

        // 平均出走頭数
        $avgHorseCount = DB::table('races')
            ->whereIn('id', $raceIds)
            ->whereNotNull('horses_count')
            ->avg('horses_count');

        // 平均上がり3F (勝ち馬の last_3f_seconds 平均)
        $avgWinLast3f = DB::table('race_results')
            ->whereIn('race_id', $raceIds)
            ->where('finish_position_int', 1)
            ->whereNotNull('last_3f_seconds')
            ->avg('last_3f_seconds');

        // 平均払戻 (単勝) — 勝ち馬の win_odds 平均
        $avgWinOdds = DB::table('race_results')
            ->whereIn('race_id', $raceIds)
            ->where('finish_position_int', 1)
            ->whereNotNull('win_odds')
            ->avg('win_odds');

        return [
            'avg_win_time_seconds' => $avgWinTime ? round((float) $avgWinTime, 2) : null,
            'avg_win_time_display' => $avgWinTime ? $this->formatSeconds((float) $avgWinTime) : null,
            'avg_horse_count'      => $avgHorseCount ? round((float) $avgHorseCount, 1) : null,
            'avg_win_last_3f'      => $avgWinLast3f ? round((float) $avgWinLast3f, 1) : null,
            'avg_win_odds'         => $avgWinOdds ? round((float) $avgWinOdds, 2) : null,
        ];
    }

    /**
     * 枠番別成績 (1〜8枠それぞれの 1着率 / 3着内率 / サンプル数)
     */
    protected function frameStats($raceIds): array
    {
        $rows = DB::table('race_results')
            ->select(
                'frame_number',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN finish_position_int = 1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN finish_position_int <= 3 THEN 1 ELSE 0 END) as shows')
            )
            ->whereIn('race_id', $raceIds)
            ->whereNotNull('frame_number')
            ->whereNotNull('finish_position_int')
            ->groupBy('frame_number')
            ->orderBy('frame_number')
            ->get();

        $result = [];
        for ($i = 1; $i <= 8; $i++) {
            $row = $rows->firstWhere('frame_number', $i);
            $runs  = $row ? (int) $row->runs  : 0;
            $wins  = $row ? (int) $row->wins  : 0;
            $shows = $row ? (int) $row->shows : 0;
            $result[] = [
                'frame'      => $i,
                'runs'       => $runs,
                'wins'       => $wins,
                'shows'      => $shows,
                'win_rate'   => $runs > 0 ? round($wins  / $runs * 100, 1) : 0,
                'show_rate'  => $runs > 0 ? round($shows / $runs * 100, 1) : 0,
            ];
        }
        return $result;
    }

    /**
     * 脚質別成績 (逃/先/差/追/マ)
     */
    protected function styleStats($raceIds): array
    {
        $rows = DB::table('race_results')
            ->select(
                'running_style',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(CASE WHEN finish_position_int = 1 THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN finish_position_int <= 3 THEN 1 ELSE 0 END) as shows')
            )
            ->whereIn('race_id', $raceIds)
            ->whereNotNull('running_style')
            ->whereNotNull('finish_position_int')
            ->groupBy('running_style')
            ->get()
            ->keyBy('running_style');

        // 表示順を固定
        $order = ['逃', '先', '差', '追', 'マ'];
        $result = [];
        foreach ($order as $style) {
            $row = $rows->get($style);
            $runs  = $row ? (int) $row->runs  : 0;
            $wins  = $row ? (int) $row->wins  : 0;
            $shows = $row ? (int) $row->shows : 0;
            $result[] = [
                'style'      => $style,
                'runs'       => $runs,
                'wins'       => $wins,
                'shows'      => $shows,
                'win_rate'   => $runs > 0 ? round($wins  / $runs * 100, 1) : 0,
                'show_rate'  => $runs > 0 ? round($shows / $runs * 100, 1) : 0,
            ];
        }
        return $result;
    }

    /**
     * 馬場状態別レース数 (良/稍重/重/不良)
     */
    protected function conditionStats($raceIds): array
    {
        $rows = DB::table('races')
            ->select('course_condition', DB::raw('COUNT(*) as races'))
            ->whereIn('id', $raceIds)
            ->whereNotNull('course_condition')
            ->groupBy('course_condition')
            ->get()
            ->keyBy('course_condition');

        $order = ['良', '稍重', '重', '不良'];
        $result = [];
        $total = 0;
        foreach ($order as $c) {
            $n = $rows->get($c)?->races ?? 0;
            $total += (int) $n;
            $result[] = ['condition' => $c, 'races' => (int) $n];
        }
        // % を後付けで
        foreach ($result as &$r) {
            $r['pct'] = $total > 0 ? round($r['races'] / $total * 100, 1) : 0;
        }
        return $result;
    }

    /**
     * ペース傾向 (H/M/S の割合)
     */
    protected function paceStats($raceIds): array
    {
        $rows = DB::table('races')
            ->select('pace', DB::raw('COUNT(*) as races'))
            ->whereIn('id', $raceIds)
            ->whereNotNull('pace')
            ->groupBy('pace')
            ->get()
            ->keyBy('pace');

        $order = ['H' => 'ハイ', 'M' => 'ミドル', 'S' => 'スロー'];
        $result = [];
        $total = 0;
        foreach ($order as $k => $label) {
            $n = $rows->get($k)?->races ?? 0;
            $total += (int) $n;
            $result[] = ['pace' => $k, 'label' => $label, 'races' => (int) $n];
        }
        foreach ($result as &$r) {
            $r['pct'] = $total > 0 ? round($r['races'] / $total * 100, 1) : 0;
        }
        return $result;
    }

    /**
     * ラップ傾向 (前3F / 上がり3F 平均)
     */
    protected function lapStats($raceIds): array
    {
        // races.first_3f / last_3f は文字列 "34.5" 想定なので DB 側で CAST が必要
        // MariaDB では CAST(col AS DECIMAL(4,1)) が使える
        $stats = DB::table('races')
            ->whereIn('id', $raceIds)
            ->selectRaw('AVG(CAST(first_3f AS DECIMAL(4,1))) as avg_first_3f')
            ->selectRaw('AVG(CAST(last_3f  AS DECIMAL(4,1))) as avg_last_3f')
            ->first();

        return [
            'avg_first_3f' => $stats && $stats->avg_first_3f ? round((float) $stats->avg_first_3f, 1) : null,
            'avg_last_3f'  => $stats && $stats->avg_last_3f  ? round((float) $stats->avg_last_3f,  1) : null,
        ];
    }

    /**
     * 現在レースの馬場状態にマッチした軽量サマリー
     * (「重馬場だとどの脚質が有利か」を並列表示するため)
     */
    protected function computeConditionSummary(Race $race, string $condition): array
    {
        $from = Carbon::now()->subMonths(self::LOOKBACK_MONTHS)->toDateString();
        $to   = Carbon::now()->toDateString();

        $raceIds = Race::where('venue_id', $race->venue_id)
            ->where('track_type', $race->track_type)
            ->where('distance', $race->distance)
            ->where('course_condition', $condition)
            ->whereBetween('race_date', [$from, $to])
            ->whereHas('results', fn($q) => $q->whereNotNull('finish_position_int'))
            ->pluck('id')
            ->all();

        $sample = count($raceIds);

        if ($sample === 0) {
            return [
                'condition'   => $condition,
                'sample_size' => 0,
                'frame_stats' => [],
                'style_stats' => [],
            ];
        }

        return [
            'condition'   => $condition,
            'sample_size' => $sample,
            'frame_stats' => $this->frameStats($raceIds),
            'style_stats' => $this->styleStats($raceIds),
        ];
    }

    /**
     * サンプル 0 のとき返す空データ
     */
    protected function emptyResult(): array
    {
        $frames = [];
        for ($i = 1; $i <= 8; $i++) {
            $frames[] = ['frame' => $i, 'runs' => 0, 'wins' => 0, 'shows' => 0, 'win_rate' => 0, 'show_rate' => 0];
        }
        $styles = [];
        foreach (['逃', '先', '差', '追', 'マ'] as $s) {
            $styles[] = ['style' => $s, 'runs' => 0, 'wins' => 0, 'shows' => 0, 'win_rate' => 0, 'show_rate' => 0];
        }

        return [
            'sample_size'      => 0,
            'period'           => ['from' => null, 'to' => null],
            'summary'          => [
                'avg_win_time_seconds' => null,
                'avg_win_time_display' => null,
                'avg_horse_count'      => null,
                'avg_win_last_3f'      => null,
                'avg_win_odds'         => null,
            ],
            'frame_stats'      => $frames,
            'style_stats'      => $styles,
            'condition_stats'  => [],
            'pace_stats'       => [],
            'lap_stats'        => ['avg_first_3f' => null, 'avg_last_3f' => null],
            'current_condition_summary' => null,
            'current_condition_label'   => null,
        ];
    }

    /**
     * 秒 → "1:23.4" 形式
     */
    protected function formatSeconds(float $sec): string
    {
        $m = (int) floor($sec / 60);
        $s = $sec - $m * 60;
        return sprintf('%d:%04.1f', $m, $s);
    }
}
