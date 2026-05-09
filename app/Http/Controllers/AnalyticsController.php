<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Venue;
use App\Models\VenueCourse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    /**
     * 競馬場別傾向分析
     * 枠順×勝率ヒートマップ、脚質別成績、距離別成績
     */
    public function venue(Request $request): View
    {
        $venues = Venue::orderBy('code')->get();
        $venueId = $request->get('venue_id', $venues->first()?->id);
        $trackType = $request->get('track_type', '芝');
        $distance = $request->get('distance');

        $venue = Venue::find($venueId);

        // 枠番×勝率（ヒートマップ用）
        $frameQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venueId)
            ->where('races.track_type', $trackType)
            ->whereNotNull('frame_number')
            ->whereNotNull('finish_position_int');

        if ($distance) {
            $frameQuery->where('races.distance', $distance);
        }

        $frameStats = $frameQuery
            ->selectRaw('frame_number,
                count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=2 THEN 1 ELSE 0 END) as places,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('frame_number')
            ->orderBy('frame_number')
            ->get();

        // 脚質別
        $styleQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venueId)
            ->where('races.track_type', $trackType)
            ->whereNotNull('running_style')
            ->whereNotNull('finish_position_int');

        if ($distance) {
            $styleQuery->where('races.distance', $distance);
        }

        $styleStats = $styleQuery
            ->selectRaw('running_style,
                count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('running_style')
            ->get();

        // この競馬場で開催された距離一覧
        $availableDistances = DB::table('races')
            ->where('venue_id', $venueId)
            ->where('track_type', $trackType)
            ->select('distance')
            ->groupBy('distance')
            ->orderBy('distance')
            ->pluck('distance');

        return view('analytics.venue', compact(
            'venues', 'venue', 'venueId', 'trackType', 'distance',
            'frameStats', 'styleStats', 'availableDistances'
        ));
    }

    /**
     * ペース分析（H/M/S別の決着脚質）
     *
     * フィルター:
     *   - venue_id: 競馬場
     *   - track_type: 芝/ダート/障害
     *   - distance_cat: 短距離/マイル/中距離/中長距離/長距離
     *   - distance: 個別距離
     *   - course_condition: 良/稍重/重/不良
     *   - from / to: 開催日範囲
     */
    public function pace(Request $request): View
    {
        $venues = Venue::orderBy('code')->get();

        $f = [
            'venue_id'         => $request->get('venue_id'),
            'track_type'       => $request->get('track_type'),
            'distance_cat'     => $request->get('distance_cat'),
            'distance'         => $request->get('distance'),
            'course_condition' => $request->get('course_condition'),
            'from'             => $request->get('from'),
            'to'               => $request->get('to'),
        ];

        // 距離カテゴリ → 距離レンジ
        $distRange = match ($f['distance_cat']) {
            '短距離'   => [0, 1400],
            'マイル'   => [1401, 1800],
            '中距離'   => [1801, 2200],
            '中長距離' => [2201, 2600],
            '長距離'   => [2601, 9999],
            default    => null,
        };

        // 共通フィルター適用
        $applyFilters = function ($q) use ($f, $distRange) {
            if (!empty($f['venue_id']))         $q->where('races.venue_id', $f['venue_id']);
            if (!empty($f['track_type']))       $q->where('races.track_type', $f['track_type']);
            if (!empty($f['distance']))         $q->where('races.distance', $f['distance']);
            if ($distRange)                     $q->whereBetween('races.distance', $distRange);
            if (!empty($f['course_condition'])) $q->where('races.course_condition', $f['course_condition']);
            if (!empty($f['from']))             $q->whereDate('races.race_date', '>=', $f['from']);
            if (!empty($f['to']))               $q->whereDate('races.race_date', '<=', $f['to']);
        };

        // ============ ペース × 脚質ピボット ============
        $statsQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.pace')
            ->whereNotNull('running_style')
            ->where('finish_position_int', '<=', 3);
        $applyFilters($statsQuery);
        $stats = $statsQuery
            ->selectRaw('races.pace, running_style, count(*) as cnt')
            ->groupBy('races.pace', 'running_style')
            ->get();

        $pivot = [];
        foreach ($stats as $s) {
            $pivot[$s->pace][$s->running_style] = (int) $s->cnt;
        }

        // ============ コース(競馬場×芝ダ)別 ペース分布 ============
        $byCourseQuery = DB::table('races')
            ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
            ->whereNotNull('races.pace');
        $applyFilters($byCourseQuery);
        $byCourse = $byCourseQuery
            ->selectRaw("
                CONCAT(COALESCE(venues.name,'?'), ' ', COALESCE(races.track_type,'?')) as label,
                races.pace,
                count(*) as cnt
            ")
            ->groupBy('venues.name', 'races.track_type', 'races.pace')
            ->orderBy('venues.name')
            ->orderBy('races.track_type')
            ->get();
        $byCoursePivot = [];
        foreach ($byCourse as $r) {
            $byCoursePivot[$r->label][$r->pace] = (int) $r->cnt;
        }

        // ============ 距離カテゴリ別 ペース分布 ============
        $distCats = [
            '短距離'   => [0, 1400],
            'マイル'   => [1401, 1800],
            '中距離'   => [1801, 2200],
            '中長距離' => [2201, 2600],
            '長距離'   => [2601, 9999],
        ];
        $byDistance = [];
        foreach ($distCats as $cat => [$lo, $hi]) {
            $q = DB::table('races')->whereNotNull('races.pace')
                ->whereBetween('races.distance', [$lo, $hi]);
            $applyFilters($q);
            $rows = $q->selectRaw('races.pace, count(*) as cnt')
                ->groupBy('races.pace')
                ->pluck('cnt', 'pace')
                ->all();
            $byDistance[$cat] = [
                'H' => (int) ($rows['H'] ?? 0),
                'M' => (int) ($rows['M'] ?? 0),
                'S' => (int) ($rows['S'] ?? 0),
            ];
        }

        // ============ 馬場状態別 ペース分布 ============
        $conditions = ['良','稍重','重','不良'];
        $byCondition = [];
        foreach ($conditions as $c) {
            $q = DB::table('races')
                ->whereNotNull('races.pace')
                ->where('races.course_condition', $c);
            $applyFilters($q);
            $rows = $q->selectRaw('races.pace, count(*) as cnt')
                ->groupBy('races.pace')
                ->pluck('cnt', 'pace')
                ->all();
            $byCondition[$c] = [
                'H' => (int) ($rows['H'] ?? 0),
                'M' => (int) ($rows['M'] ?? 0),
                'S' => (int) ($rows['S'] ?? 0),
            ];
        }

        // ============ ペース別 平均勝ちタイム / 上がり3F ============
        $paceTimeQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.pace')
            ->where('finish_position_int', 1);
        $applyFilters($paceTimeQuery);
        $paceTime = $paceTimeQuery
            ->selectRaw('races.pace,
                AVG(race_results.time_seconds) as avg_time,
                AVG(race_results.last_3f_seconds) as avg_last3f,
                count(*) as cnt')
            ->groupBy('races.pace')
            ->get()
            ->keyBy('pace');

        // ============ 距離カテゴリ × ペース × 脚質ピボット (3次元) ============
        $cubeQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.pace')
            ->whereNotNull('running_style')
            ->where('finish_position_int', '<=', 3);
        $applyFilters($cubeQuery);
        $cubeRows = $cubeQuery
            ->selectRaw("
                CASE
                    WHEN races.distance <= 1400 THEN '短距離'
                    WHEN races.distance <= 1800 THEN 'マイル'
                    WHEN races.distance <= 2200 THEN '中距離'
                    WHEN races.distance <= 2600 THEN '中長距離'
                    ELSE '長距離'
                END as dist_cat,
                races.pace,
                running_style,
                count(*) as cnt
            ")
            ->groupByRaw("
                CASE
                    WHEN races.distance <= 1400 THEN '短距離'
                    WHEN races.distance <= 1800 THEN 'マイル'
                    WHEN races.distance <= 2200 THEN '中距離'
                    WHEN races.distance <= 2600 THEN '中長距離'
                    ELSE '長距離'
                END,
                races.pace,
                running_style
            ")
            ->get();
        $cube = [];
        foreach ($cubeRows as $r) {
            $cube[$r->dist_cat][$r->pace][$r->running_style] = (int) $r->cnt;
        }

        // 利用可能な距離一覧
        $availableDistances = DB::table('races')
            ->whereNotNull('distance')
            ->select('distance')
            ->groupBy('distance')
            ->orderBy('distance')
            ->pluck('distance');

        $totalRaces = DB::table('races')->whereNotNull('pace')->count();

        return view('analytics.pace', compact(
            'pivot', 'venues', 'f', 'byCoursePivot', 'byDistance',
            'byCondition', 'paceTime', 'cube', 'availableDistances',
            'totalRaces', 'distCats'
        ));
    }

    /**
     * 血統傾向（父系別の得意距離・コース）
     */
    public function pedigree(Request $request): View
    {
        $father = $request->get('father');

        $fatherList = DB::table('horses')
            ->whereNotNull('father')
            ->select('father', DB::raw('count(*) as cnt'))
            ->groupBy('father')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        $stats = collect();
        if ($father) {
            $stats = DB::table('race_results')
                ->join('horses', 'horses.id', '=', 'race_results.horse_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->where('horses.father', $father)
                ->whereNotNull('finish_position_int')
                ->selectRaw('venues.name as venue, races.track_type, races.distance,
                    count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
                ->groupBy('venues.name', 'races.track_type', 'races.distance')
                ->orderByDesc('runs')
                ->limit(50)
                ->get();
        }

        return view('analytics.pedigree', compact('fatherList', 'father', 'stats'));
    }

    // =====================================================================
    // 血統分析(拡張): overview / sires / broodmares / heatmap
    // 共通ポリシー:
    //   - 集計対象: race_results JOIN horses JOIN races (venues は全てJRAなので追加絞り込み不要)
    //   - 最低出走数: 20回以上 (NOISE排除)
    //   - 回収率: 単勝=win_odds*100、複勝=((place_odds_min+place_odds_max)/2)*100 (既存roi()と同じ)
    // =====================================================================

    /** 血統分析共通: 父別の集計クエリ */
    private function pedigreeFatherAggQuery(?string $from = null, ?string $to = null, ?string $trackType = null)
    {
        $q = DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->join('races',  'races.id',  '=', 'race_results.race_id')
            ->whereNotNull('horses.father')
            ->whereNotNull('race_results.finish_position_int');
        if ($from)      $q->whereDate('races.race_date', '>=', $from);
        if ($to)        $q->whereDate('races.race_date', '<=', $to);
        if ($trackType) $q->where('races.track_type', $trackType);
        return $q;
    }

    /** 血統分析共通: 母父別の集計クエリ */
    private function pedigreeBroodmareAggQuery(?string $from = null, ?string $to = null, ?string $trackType = null)
    {
        $q = DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->join('races',  'races.id',  '=', 'race_results.race_id')
            ->whereNotNull('horses.mother_father')
            ->whereNotNull('race_results.finish_position_int');
        if ($from)      $q->whereDate('races.race_date', '>=', $from);
        if ($to)        $q->whereDate('races.race_date', '<=', $to);
        if ($trackType) $q->where('races.track_type', $trackType);
        return $q;
    }

    /**
     * 父・母父集計の共通 SELECT 句
     *   - runs / wins / places(2着内) / shows(3着内)
     *   - 単勝回収・複勝回収 (win_odds / place_odds_min/max ベース)
     */
    private const PEDIGREE_AGG_SELECT = "count(*) as runs,
        SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
        SUM(CASE WHEN finish_position_int<=2 THEN 1 ELSE 0 END) as places,
        SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
        SUM(CASE WHEN finish_position_int=1 AND win_odds IS NOT NULL THEN win_odds*100 ELSE 0 END) as win_payout,
        SUM(CASE WHEN win_odds IS NOT NULL THEN 100 ELSE 0 END) as win_stake,
        SUM(CASE WHEN finish_position_int<=3
                 THEN ((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100
                 ELSE 0 END) as place_payout";

    /**
     * 父・母父集計の生レコードを「行ごとの指標」に整形
     *
     * @param iterable $rows  cnt系カラム + win_payout/win_stake/place_payout を含む行
     * @return array          指標が付加された配列
     */
    private function decoratePedigreeRows($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $runs   = (int)($r->runs   ?? 0);
            $wins   = (int)($r->wins   ?? 0);
            $places = (int)($r->places ?? 0);
            $shows  = (int)($r->shows  ?? 0);
            $winPayout   = (float)($r->win_payout   ?? 0);
            $winStake    = (float)($r->win_stake    ?? 0);    // win_odds が記録された行のみ
            $placePayout = (float)($r->place_payout ?? 0);
            $placeStake  = $runs * 100;

            $out[] = (object) [
                'name'          => $r->name ?? null,
                'runs'          => $runs,
                'wins'          => $wins,
                'places'        => $places,
                'shows'         => $shows,
                'win_rate'      => $runs > 0 ? round($wins  / $runs * 100, 1) : 0,
                'place_rate'    => $runs > 0 ? round($places/ $runs * 100, 1) : 0,
                'show_rate'     => $runs > 0 ? round($shows / $runs * 100, 1) : 0,
                'roi_win'       => $winStake  > 0 ? round($winPayout   / $winStake  * 100, 1) : 0,
                'roi_place'     => $placeStake> 0 ? round($placePayout / $placeStake* 100, 1) : 0,
            ];
        }
        return $out;
    }

    /**
     * 血統分析トップ(KPI + 父TOP20 + 母父TOP20)
     *
     * クエリ:
     *   ?from= ?to=  期間絞り込み (任意)
     *   ?min_runs=   最小出走数 (default 20)
     */
    public function pedigreeOverview(Request $request): View
    {
        $from     = $request->get('from');
        $to       = $request->get('to');
        $minRuns  = max(1, (int) $request->get('min_runs', 20));

        // KPI: 血統カバー率
        $totalHorses    = (int) DB::table('horses')->count();
        $fatherFilled   = (int) DB::table('horses')->whereNotNull('father')->count();
        $motherFilled   = (int) DB::table('horses')->whereNotNull('mother')->count();
        $mFatherFilled  = (int) DB::table('horses')->whereNotNull('mother_father')->count();
        $uniqueFathers  = (int) DB::table('horses')->whereNotNull('father')->distinct()->count('father');
        $uniqueMFathers = (int) DB::table('horses')->whereNotNull('mother_father')->distinct()->count('mother_father');

        // 父TOP20 (出走数順)
        $fatherRows = $this->pedigreeFatherAggQuery($from, $to)
            ->selectRaw("horses.father as name, " . self::PEDIGREE_AGG_SELECT)
            ->groupBy('horses.father')
            ->havingRaw('runs >= ?', [$minRuns])
            ->orderByDesc('runs')
            ->limit(20)
            ->get();
        $topFathers = $this->decoratePedigreeRows($fatherRows);

        // 母父TOP20 (出走数順)
        $mFatherRows = $this->pedigreeBroodmareAggQuery($from, $to)
            ->selectRaw("horses.mother_father as name, " . self::PEDIGREE_AGG_SELECT)
            ->groupBy('horses.mother_father')
            ->havingRaw('runs >= ?', [$minRuns])
            ->orderByDesc('runs')
            ->limit(20)
            ->get();
        $topBroodmares = $this->decoratePedigreeRows($mFatherRows);

        return view('analytics.pedigree_overview', [
            'kpi' => [
                'total_horses'    => $totalHorses,
                'father_filled'   => $fatherFilled,
                'mother_filled'   => $motherFilled,
                'm_father_filled' => $mFatherFilled,
                'father_pct'      => $totalHorses > 0 ? round($fatherFilled  / $totalHorses * 100, 1) : 0,
                'mother_pct'      => $totalHorses > 0 ? round($motherFilled  / $totalHorses * 100, 1) : 0,
                'm_father_pct'    => $totalHorses > 0 ? round($mFatherFilled / $totalHorses * 100, 1) : 0,
                'unique_fathers'  => $uniqueFathers,
                'unique_m_fathers'=> $uniqueMFathers,
            ],
            'topFathers'    => $topFathers,
            'topBroodmares' => $topBroodmares,
            'from'          => $from,
            'to'            => $to,
            'minRuns'       => $minRuns,
        ]);
    }

    /**
     * 父別ランキング(全件・ソート/絞込)
     *
     * クエリ:
     *   ?from= ?to=     期間
     *   ?track_type=    芝/ダート/障害
     *   ?min_runs=      最小出走数 (default 20)
     *   ?sort=          runs|wins|win_rate|place_rate|show_rate|roi_win|roi_place (default runs)
     *   ?keyword=       父名キーワード(部分一致)
     */
    public function pedigreeSires(Request $request): View
    {
        $from      = $request->get('from');
        $to        = $request->get('to');
        $trackType = $request->get('track_type');
        $minRuns   = max(1, (int) $request->get('min_runs', 20));
        $keyword   = trim((string) $request->get('keyword', ''));
        $sort      = $request->get('sort', 'runs');

        $allowedSort = ['runs','wins','win_rate','place_rate','show_rate','roi_win','roi_place'];
        if (!in_array($sort, $allowedSort, true)) $sort = 'runs';

        $q = $this->pedigreeFatherAggQuery($from, $to, $trackType)
            ->selectRaw("horses.father as name, " . self::PEDIGREE_AGG_SELECT)
            ->groupBy('horses.father')
            ->havingRaw('runs >= ?', [$minRuns]);

        if ($keyword !== '') {
            $q->where('horses.father', 'like', '%' . $keyword . '%');
        }

        // SQL側で並べ替えできるのは生集計値のみ。比率系はPHP側でソートする。
        $rawSortable = ['runs','wins'];
        if (in_array($sort, $rawSortable, true)) {
            $q->orderByDesc($sort);
        } else {
            $q->orderByDesc('runs'); // 一旦出走数で
        }

        $rows = $this->decoratePedigreeRows($q->limit(500)->get());

        // 比率/ROIで指定されたら PHP側で並べ直す
        if (!in_array($sort, $rawSortable, true)) {
            usort($rows, fn($a, $b) => $b->{$sort} <=> $a->{$sort});
        }

        return view('analytics.pedigree_sires', [
            'rows'      => $rows,
            'from'      => $from,
            'to'        => $to,
            'trackType' => $trackType,
            'minRuns'   => $minRuns,
            'keyword'   => $keyword,
            'sort'      => $sort,
        ]);
    }

    /**
     * 母父別ランキング(全件・ソート/絞込)
     */
    public function pedigreeBroodmares(Request $request): View
    {
        $from      = $request->get('from');
        $to        = $request->get('to');
        $trackType = $request->get('track_type');
        $minRuns   = max(1, (int) $request->get('min_runs', 20));
        $keyword   = trim((string) $request->get('keyword', ''));
        $sort      = $request->get('sort', 'runs');

        $allowedSort = ['runs','wins','win_rate','place_rate','show_rate','roi_win','roi_place'];
        if (!in_array($sort, $allowedSort, true)) $sort = 'runs';

        $q = $this->pedigreeBroodmareAggQuery($from, $to, $trackType)
            ->selectRaw("horses.mother_father as name, " . self::PEDIGREE_AGG_SELECT)
            ->groupBy('horses.mother_father')
            ->havingRaw('runs >= ?', [$minRuns]);

        if ($keyword !== '') {
            $q->where('horses.mother_father', 'like', '%' . $keyword . '%');
        }

        $rawSortable = ['runs','wins'];
        if (in_array($sort, $rawSortable, true)) {
            $q->orderByDesc($sort);
        } else {
            $q->orderByDesc('runs');
        }

        $rows = $this->decoratePedigreeRows($q->limit(500)->get());

        if (!in_array($sort, $rawSortable, true)) {
            usort($rows, fn($a, $b) => $b->{$sort} <=> $a->{$sort});
        }

        return view('analytics.pedigree_broodmares', [
            'rows'      => $rows,
            'from'      => $from,
            'to'        => $to,
            'trackType' => $trackType,
            'minRuns'   => $minRuns,
            'keyword'   => $keyword,
            'sort'      => $sort,
        ]);
    }

    /**
     * 父×距離 / 父×馬場 ヒートマップ
     *
     * クエリ:
     *   ?axis=distance|condition|venue   (default distance)
     *   ?track_type=芝|ダート (default 芝)
     *   ?metric=show_rate|win_rate|roi_win|roi_place (default show_rate)
     *   ?min_runs= (default 20、各セル単位)
     *   ?top= 父TOP数(default 20)
     */
    public function pedigreeHeatmap(Request $request): View
    {
        $axis      = $request->get('axis', 'distance');
        $trackType = $request->get('track_type', '芝');
        $metric    = $request->get('metric', 'show_rate');
        $minRuns   = max(1, (int) $request->get('min_runs', 20));
        $top       = max(5, min(50, (int) $request->get('top', 20)));

        if (!in_array($axis,   ['distance','condition','venue'], true)) $axis = 'distance';
        if (!in_array($metric, ['show_rate','win_rate','roi_win','roi_place'], true)) $metric = 'show_rate';

        // ===== 軸定義 =====
        // 距離はカテゴリ化(短距離/マイル/中距離/中長距離/長距離)
        $axisExpr  = null;
        $axisOrder = [];
        switch ($axis) {
            case 'distance':
                $axisExpr  = "CASE
                    WHEN races.distance < 1400 THEN '短距離'
                    WHEN races.distance < 1800 THEN 'マイル'
                    WHEN races.distance < 2200 THEN '中距離'
                    WHEN races.distance < 2600 THEN '中長距離'
                    ELSE '長距離' END";
                $axisOrder = ['短距離','マイル','中距離','中長距離','長距離'];
                break;
            case 'condition':
                $axisExpr  = "races.course_condition";
                $axisOrder = ['良','稍重','重','不良'];
                break;
            case 'venue':
                $axisExpr  = "(SELECT v.name FROM venues v WHERE v.id = races.venue_id)";
                $axisOrder = ['札幌','函館','福島','新潟','東京','中山','中京','京都','阪神','小倉'];
                break;
        }

        // 父TOP N (該当トラックの出走数順)
        $topFathers = $this->pedigreeFatherAggQuery(null, null, $trackType)
            ->select('horses.father as name')
            ->selectRaw('count(*) as runs')
            ->groupBy('horses.father')
            ->orderByDesc('runs')
            ->limit($top)
            ->pluck('name')
            ->all();

        if (empty($topFathers)) {
            return view('analytics.pedigree_heatmap', [
                'axis'       => $axis,
                'trackType'  => $trackType,
                'metric'     => $metric,
                'minRuns'    => $minRuns,
                'top'        => $top,
                'fathers'    => [],
                'columns'    => $axisOrder,
                'matrix'     => [],
            ]);
        }

        // セル集計
        $cells = $this->pedigreeFatherAggQuery(null, null, $trackType)
            ->whereIn('horses.father', $topFathers)
            ->selectRaw("horses.father as father, ($axisExpr) as ax, " . self::PEDIGREE_AGG_SELECT)
            ->groupBy('horses.father', DB::raw("($axisExpr)"))
            ->get();

        // 行(父) × 列(軸) のマトリクスへ整形
        $matrix = []; // [father][col] = ['metric'=>x, 'runs'=>n]
        foreach ($cells as $c) {
            $runs        = (int)($c->runs ?? 0);
            $wins        = (int)($c->wins ?? 0);
            $shows       = (int)($c->shows ?? 0);
            $winPayout   = (float)($c->win_payout   ?? 0);
            $winStake    = (float)($c->win_stake    ?? 0);
            $placePayout = (float)($c->place_payout ?? 0);
            $placeStake  = $runs * 100;

            $val = match ($metric) {
                'win_rate'   => $runs        > 0 ? round($wins  / $runs * 100, 1) : null,
                'show_rate'  => $runs        > 0 ? round($shows / $runs * 100, 1) : null,
                'roi_win'    => $winStake    > 0 ? round($winPayout   / $winStake  * 100, 1) : null,
                'roi_place'  => $placeStake  > 0 ? round($placePayout / $placeStake* 100, 1) : null,
                default      => null,
            };

            // 出走数が閾値未満のセルは null 扱いに(色付けしない)
            if ($runs < $minRuns) $val = null;

            $matrix[$c->father][$c->ax] = ['v' => $val, 'runs' => $runs];
        }

        return view('analytics.pedigree_heatmap', [
            'axis'      => $axis,
            'trackType' => $trackType,
            'metric'    => $metric,
            'minRuns'   => $minRuns,
            'top'       => $top,
            'fathers'   => $topFathers,
            'columns'   => $axisOrder,
            'matrix'    => $matrix,
        ]);
    }

    /**
     * 騎手×コース相性
     *
     * 一覧モード: 全騎手の主要指標を表で見える化(騎乗数・勝率・連対率・複勝率・平均人気・平均着順)
     * 詳細モード: 特定騎手の競馬場×トラック相性
     *
     * クエリ:
     *   ?jockey=    騎手名(個別ドリルダウン)
     *   ?min_runs=  最小騎乗数フィルタ(default 50)
     *   ?keyword=   騎手名キーワード検索
     *   ?sort=      runs|wins|win_rate|place_rate|show_rate|avg_pop (default win_rate)
     *   ?from= ?to= 期間
     *   ?venue_id=  競馬場
     *   ?track_type= 芝/ダート/障害
     */
    public function jockey(Request $request): View
    {
        $jockeyName = $request->get('jockey');
        $minRuns    = (int) $request->get('min_runs', 50);
        $keyword    = trim((string) $request->get('keyword', ''));
        $sort       = $request->get('sort', 'win_rate');
        $from       = $request->get('from');
        $to         = $request->get('to');
        $venueId    = $request->get('venue_id');
        $trackType  = $request->get('track_type');

        $venues = Venue::orderBy('code')->get();

        // 共通フィルター
        $applyFilters = function ($q) use ($from, $to, $venueId, $trackType) {
            if ($from)      $q->whereDate('races.race_date', '>=', $from);
            if ($to)        $q->whereDate('races.race_date', '<=', $to);
            if ($venueId)   $q->where('races.venue_id', $venueId);
            if ($trackType) $q->where('races.track_type', $trackType);
        };

        // ============ 騎手一覧(全騎手の主要指標) ============
        $listQuery = DB::table('race_results')
            ->join('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('finish_position_int');
        $applyFilters($listQuery);
        if ($keyword !== '') {
            $listQuery->where('jockeys.name', 'like', '%' . $keyword . '%');
        }
        $rawList = $listQuery
            ->selectRaw("
                jockeys.id as jockey_id,
                jockeys.name,
                count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=2 THEN 1 ELSE 0 END) as places,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
                AVG(race_results.popularity) as avg_pop,
                AVG(race_results.finish_position_int) as avg_finish,
                MAX(races.race_date) as last_ride
            ")
            ->groupBy('jockeys.id', 'jockeys.name')
            ->having('runs', '>=', $minRuns)
            ->get();

        // PHP 側で派生指標を計算
        $jockeyRows = $rawList->map(function ($r) {
            $r->win_rate   = $r->runs > 0 ? round($r->wins   / $r->runs * 100, 1) : 0;
            $r->place_rate = $r->runs > 0 ? round($r->places / $r->runs * 100, 1) : 0;
            $r->show_rate  = $r->runs > 0 ? round($r->shows  / $r->runs * 100, 1) : 0;
            $r->avg_pop    = $r->avg_pop    !== null ? round((float) $r->avg_pop, 2) : null;
            $r->avg_finish = $r->avg_finish !== null ? round((float) $r->avg_finish, 2) : null;
            return $r;
        });

        // ソート
        $sortMap = [
            'runs'       => fn($r) => -$r->runs,
            'wins'       => fn($r) => -$r->wins,
            'win_rate'   => fn($r) => -$r->win_rate,
            'place_rate' => fn($r) => -$r->place_rate,
            'show_rate'  => fn($r) => -$r->show_rate,
            'avg_pop'    => fn($r) => $r->avg_pop ?? 999,
        ];
        $sortFn = $sortMap[$sort] ?? $sortMap['win_rate'];
        $jockeyRows = $jockeyRows->sortBy($sortFn)->values();

        // サマリ
        $summary = [
            'jockey_count'   => $jockeyRows->count(),
            'total_runs'     => $jockeyRows->sum('runs'),
            'total_wins'     => $jockeyRows->sum('wins'),
            'top_win_rate'   => $jockeyRows->max('win_rate'),
            'top_show_rate'  => $jockeyRows->max('show_rate'),
        ];

        // ============ 詳細モード(従来) ============
        $stats = collect();
        if ($jockeyName) {
            $detailQuery = DB::table('race_results')
                ->join('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->where('jockeys.name', $jockeyName)
                ->whereNotNull('finish_position_int');
            $applyFilters($detailQuery);
            $stats = $detailQuery
                ->selectRaw('venues.name as venue, races.track_type,
                    count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
                ->groupBy('venues.name', 'races.track_type')
                ->orderByDesc('runs')
                ->get();
        }

        // 後方互換の jockeyList(キーワードに引っかかる上位騎乗数50)
        $jockeyList = DB::table('jockeys')
            ->join('race_results', 'race_results.jockey_id', '=', 'jockeys.id')
            ->select('jockeys.name', DB::raw('count(*) as cnt'))
            ->groupBy('jockeys.name')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        $filters = compact('minRuns', 'keyword', 'sort', 'from', 'to', 'venueId', 'trackType');

        return view('analytics.jockey', compact(
            'jockeyList', 'jockeyName', 'stats',
            'jockeyRows', 'summary', 'venues', 'filters'
        ));
    }

    /**
     * 通算成績スタッツ（騎手 / 調教師 / 馬）
     *
     * 表示項目:
     *   - 出走数 / 勝数 / 連対数 / 複勝数
     *   - 勝率 / 連対率 / 複勝率
     *   - 平均人気 / 平均着順
     *   - 競馬場別勝率（クロス集計）
     *
     * クエリ:
     *   ?type=jockey|trainer|horse  (default: jockey)
     *   ?venue_id=  (任意フィルタ)
     *   ?track_type=  (芝|ダート|障害)
     *   ?from=YYYY-MM-DD
     *   ?to=YYYY-MM-DD
     *   ?min_runs=10  (最小出走数)
     *   ?sort=win_rate|win|runs  (default: win_rate)
     */
    public function stats(Request $request): View
    {
        $type      = $request->input('type', 'jockey');         // jockey | trainer | horse
        $venueId   = $request->input('venue_id');
        $trackType = $request->input('track_type');
        $from      = $request->input('from');
        $to        = $request->input('to');
        $minRuns   = (int) ($request->input('min_runs', $type === 'horse' ? 3 : 20));
        $sort      = $request->input('sort', 'win_rate');

        // タイプごとに対象テーブル/カラムを切替
        $config = match ($type) {
            'trainer' => [
                'master_table' => 'trainers',
                'fk'           => 'trainer_id',
                'label'        => '調教師',
            ],
            'horse' => [
                'master_table' => 'horses',
                'fk'           => 'horse_id',
                'label'        => '馬',
            ],
            default => [
                'master_table' => 'jockeys',
                'fk'           => 'jockey_id',
                'label'        => '騎手',
                'type'         => 'jockey',
            ],
        };
        $masterTable = $config['master_table'];
        $fk          = $config['fk'];
        $label       = $config['label'];

        // 通算成績クエリ
        $base = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->join($masterTable, "{$masterTable}.id", '=', "race_results.{$fk}")
            ->whereNotNull("race_results.{$fk}")
            ->whereNotNull('race_results.finish_position_int');

        if ($venueId)   $base->where('races.venue_id', $venueId);
        if ($trackType) $base->where('races.track_type', $trackType);
        if ($from)      $base->whereDate('races.race_date', '>=', $from);
        if ($to)        $base->whereDate('races.race_date', '<=', $to);

        $rows = (clone $base)
            ->selectRaw("
                {$masterTable}.id,
                {$masterTable}.name,
                COUNT(*) as runs,
                SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN race_results.finish_position_int <= 2 THEN 1 ELSE 0 END) as places,
                SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows,
                AVG(race_results.popularity) as avg_pop,
                AVG(race_results.finish_position_int) as avg_finish
            ")
            ->groupBy("{$masterTable}.id", "{$masterTable}.name")
            ->havingRaw('COUNT(*) >= ?', [$minRuns])
            ->get()
            ->map(function ($r) {
                $runs = (int) $r->runs;
                return [
                    'id'         => (int) $r->id,
                    'name'       => $r->name,
                    'runs'       => $runs,
                    'wins'       => (int) $r->wins,
                    'places'     => (int) $r->places,
                    'shows'      => (int) $r->shows,
                    'win_rate'   => $runs > 0 ? round($r->wins   / $runs * 100, 1) : 0,
                    'place_rate' => $runs > 0 ? round($r->places / $runs * 100, 1) : 0,
                    'show_rate'  => $runs > 0 ? round($r->shows  / $runs * 100, 1) : 0,
                    'avg_pop'    => $r->avg_pop !== null ? round($r->avg_pop, 1) : null,
                    'avg_finish' => $r->avg_finish !== null ? round($r->avg_finish, 2) : null,
                ];
            });

        // ソート
        $rows = match ($sort) {
            'win'        => $rows->sortByDesc('wins'),
            'runs'       => $rows->sortByDesc('runs'),
            'place_rate' => $rows->sortByDesc('place_rate'),
            'show_rate'  => $rows->sortByDesc('show_rate'),
            default      => $rows->sortByDesc('win_rate'),
        };

        // 全体トータル（フィルタ後）
        $totalRow = (clone $base)->selectRaw('
            COUNT(*) as runs,
            SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows,
            COUNT(DISTINCT race_results.' . $fk . ') as actors
        ')->first();
        $total = [
            'runs'      => (int) ($totalRow->runs ?? 0),
            'wins'      => (int) ($totalRow->wins ?? 0),
            'shows'     => (int) ($totalRow->shows ?? 0),
            'actors'    => (int) ($totalRow->actors ?? 0),
            'win_rate'  => ($totalRow && $totalRow->runs > 0) ? round($totalRow->wins  / $totalRow->runs * 100, 1) : 0,
            'show_rate' => ($totalRow && $totalRow->runs > 0) ? round($totalRow->shows / $totalRow->runs * 100, 1) : 0,
        ];

        // 競馬場別勝率（TOP10対象者のみ）
        $top10Ids = $rows->take(10)->pluck('id')->all();
        $byVenue  = collect();
        if (!empty($top10Ids)) {
            $byVenue = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join($masterTable, "{$masterTable}.id", '=', "race_results.{$fk}")
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->whereIn("{$masterTable}.id", $top10Ids)
                ->whereNotNull('race_results.finish_position_int')
                ->when($trackType, fn($q) => $q->where('races.track_type', $trackType))
                ->when($from,      fn($q) => $q->whereDate('races.race_date', '>=', $from))
                ->when($to,        fn($q) => $q->whereDate('races.race_date', '<=', $to))
                ->selectRaw("
                    {$masterTable}.id as actor_id,
                    {$masterTable}.name as actor_name,
                    venues.id as venue_id,
                    venues.name as venue_name,
                    COUNT(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows
                ")
                ->groupBy("{$masterTable}.id", "{$masterTable}.name", 'venues.id', 'venues.name')
                ->get()
                ->map(fn($r) => (object) [
                    'actor_id'    => (int) $r->actor_id,
                    'actor_name'  => $r->actor_name,
                    'venue_id'    => (int) $r->venue_id,
                    'venue_name'  => $r->venue_name,
                    'runs'        => (int) $r->runs,
                    'wins'        => (int) $r->wins,
                    'shows'       => (int) $r->shows,
                    'win_rate'    => $r->runs > 0 ? round($r->wins / $r->runs * 100, 1) : 0,
                    'show_rate'   => $r->runs > 0 ? round($r->shows / $r->runs * 100, 1) : 0,
                ]);
        }

        // 競馬場マスタ（フィルタ用）
        $venues = Venue::orderBy('code')->get();

        // ページング相当（TOP100まで）
        $rows = $rows->values()->take(100);

        return view('analytics.stats', compact(
            'type', 'label', 'rows', 'total', 'byVenue', 'venues',
            'venueId', 'trackType', 'from', 'to', 'minRuns', 'sort'
        ));
    }

    /**
     * 回収率シミュレーション
     *
     * 対応券種: tan(単勝) / fuku(複勝) / uma-ren(馬連) / wide(ワイド) / san-fuku(3連複)
     *
     * - 単勝/複勝: race_results の win_odds / place_odds_* を使用
     * - 馬連/ワイド/3連複: payouts テーブルの公式払戻を使用（"全買い"想定）
     *   人気フィルタは「上位N人気の組合せ」で近似:
     *     - 馬連/ワイド: popularity<=N の馬同士の組合せが C(N,2) 通り
     *     - 3連複       : popularity<=N の馬同士の組合せが C(N,3) 通り
     */
    public function roi(Request $request): View
    {
        $kind       = $request->get('kind', 'tan');
        $popularity = $request->get('popularity');
        $venueId    = $request->get('venue_id');
        $trackType  = $request->get('track_type');
        $from       = $request->get('from');
        $to         = $request->get('to');

        $venues = Venue::orderBy('code')->get();

        // 共通フィルタを構築するクロージャ
        $applyFilters = function ($q) use ($venueId, $trackType, $from, $to) {
            if ($venueId)   $q->where('races.venue_id', $venueId);
            if ($trackType) $q->where('races.track_type', $trackType);
            if ($from)      $q->whereDate('races.race_date', '>=', $from);
            if ($to)        $q->whereDate('races.race_date', '<=', $to);
            return $q;
        };

        // 各集計を try/catch で個別に守る（1個失敗してもページ全体は表示）
        $simulation = $this->safe(fn() => match ($kind) {
            'fuku'     => $this->simulateFuku($applyFilters, $popularity),
            'uma-ren'  => $this->simulatePayoutKind($applyFilters, 'uma-ren', $popularity),
            'wide'     => $this->simulatePayoutKind($applyFilters, 'wide', $popularity),
            'san-fuku' => $this->simulatePayoutKind($applyFilters, 'san-fuku', $popularity),
            default    => $this->simulateTan($applyFilters, $popularity),
        }, $this->emptySimulation());

        // ====== グラフ用クロスタブ ======
        $charts = [
            'by_popularity' => $this->safe(fn() => $this->roiBreakdown($applyFilters, $kind, 'popularity'), []),
            'by_venue'      => $this->safe(fn() => $this->roiBreakdown($applyFilters, $kind, 'venue'),      []),
            'by_track'      => $this->safe(fn() => $this->roiBreakdown($applyFilters, $kind, 'track'),      []),
            'by_distance'   => $this->safe(fn() => $this->roiBreakdown($applyFilters, $kind, 'distance'),   []),
            'by_odds_band'  => $kind === 'tan'
                ? $this->safe(fn() => $this->roiByOddsBand($applyFilters), [])
                : [],
        ];

        $kindLabels = [
            'tan'      => '単勝',
            'fuku'     => '複勝',
            'uma-ren'  => '馬連',
            'wide'     => 'ワイド',
            'san-fuku' => '3連複',
        ];

        return view('analytics.roi', [
            'kind'        => $kind,
            'kindLabels'  => $kindLabels,
            'kindLabel'   => $kindLabels[$kind] ?? $kind,
            'simulation'  => $simulation,
            'charts'      => $charts,
            'venues'      => $venues,
            'popularity'  => $popularity,
            'venueId'     => $venueId,
            'trackType'   => $trackType,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    /**
     * 単勝シミュレーション
     */
    private function simulateTan(\Closure $applyFilters, $popularity): array
    {
        $q = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('win_odds')
            ->whereNotNull('finish_position_int');
        $applyFilters($q);
        if ($popularity) $q->where('popularity', $popularity);

        $row = $q->selectRaw('count(*) as bets,
            SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as hits,
            SUM(CASE WHEN finish_position_int=1 THEN win_odds*100 ELSE 0 END) as winnings'
        )->first();

        return $this->packSimulation((int)($row->bets ?? 0), (int)($row->hits ?? 0), (float)($row->winnings ?? 0));
    }

    /**
     * 複勝シミュレーション
     * place_odds_min/max があればその平均で計算、無ければ payouts テーブルから補完
     */
    private function simulateFuku(\Closure $applyFilters, $popularity): array
    {
        $q = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('finish_position_int');
        $applyFilters($q);
        if ($popularity) $q->where('popularity', $popularity);

        $row = $q->selectRaw("count(*) as bets,
            SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as hits,
            SUM(CASE WHEN finish_position_int<=3
                     THEN COALESCE(((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100, 0)
                     ELSE 0 END) as winnings"
        )->first();

        return $this->packSimulation((int)($row->bets ?? 0), (int)($row->hits ?? 0), (float)($row->winnings ?? 0));
    }

    /**
     * payouts ベースの券種シミュレーション(馬連/ワイド/3連複)
     *
     * 「全買い」想定:
     *   購入数 = 各レースで C(N,k) 点 (Nは絞り込み: popularity指定なら N、無指定は出走頭数)
     *   払戻   = payouts テーブルの該当券種の amount 合計
     */
    private function simulatePayoutKind(\Closure $applyFilters, string $kind, $popularity): array
    {
        // 払戻合計
        $pq = DB::table('payouts')
            ->join('races', 'races.id', '=', 'payouts.race_id')
            ->where('payouts.kind', $kind);
        $applyFilters($pq);
        $payoutRow = $pq->selectRaw('SUM(payouts.amount) as winnings, COUNT(DISTINCT payouts.race_id) as hit_races')
            ->first();
        $winnings = (float) ($payoutRow->winnings ?? 0);
        $hitRaces = (int) ($payoutRow->hit_races ?? 0);

        // 購入レース母集団 = 該当券種の払戻があったレース母集団
        // (払戻データ自体がそのレースの該当券種の存在を保証する)
        $rq = DB::table('races')
            ->whereExists(function ($sub) use ($kind) {
                $sub->select(DB::raw(1))
                    ->from('payouts')
                    ->whereColumn('payouts.race_id', 'races.id')
                    ->where('payouts.kind', $kind);
            })
            ->select('races.id', 'races.horses_count');
        $applyFilters($rq);
        $races = $rq->get();
        $raceCount = $races->count();

        // 1レースあたりの購入点数
        $combosPerRace = match ($kind) {
            'uma-ren', 'wide' => fn(int $n) => max(0, intdiv($n * ($n-1), 2)),
            'san-fuku'        => fn(int $n) => max(0, intdiv($n * ($n-1) * ($n-2), 6)),
            default           => fn(int $n) => 0,
        };

        // 人気フィルタ: 上位N人気で買う想定
        $totalCombos = 0;
        foreach ($races as $r) {
            $n = $popularity ? min((int)$popularity, (int)($r->horses_count ?? 0)) : (int)($r->horses_count ?? 0);
            if ($n <= 0) continue;
            $totalCombos += $combosPerRace($n);
        }

        // 投資額(全買い想定: 1点100円)
        $stake = $totalCombos * 100;

        return [
            'kind_basis' => 'payouts',
            'races'      => $raceCount,
            'bets'       => $totalCombos,
            'hits'       => $hitRaces,
            'stake'      => $stake,
            'winnings'   => (int) $winnings,
            'profit'     => (int) ($winnings - $stake),
            'roi'        => $stake > 0 ? round($winnings / $stake * 100, 1) : 0,
            'hit_rate'   => $raceCount > 0 ? round($hitRaces / $raceCount * 100, 1) : 0,
        ];
    }

    /**
     * 単勝/複勝シミュレーションの結果を統一フォーマットに整える
     */
    private function packSimulation(int $bets, int $hits, float $winnings): array
    {
        $stake = $bets * 100;
        return [
            'kind_basis' => 'odds',
            'races'      => $bets,
            'bets'       => $bets,
            'hits'       => $hits,
            'stake'      => $stake,
            'winnings'   => (int) $winnings,
            'profit'     => (int) ($winnings - $stake),
            'roi'        => $stake > 0 ? round($winnings / $stake * 100, 1) : 0,
            'hit_rate'   => $bets > 0 ? round($hits / $bets * 100, 1) : 0,
        ];
    }

    /**
     * グラフ用クロスタブ集計
     * @param string $axis  'popularity' | 'venue' | 'track' | 'distance'
     */
    private function roiBreakdown(\Closure $applyFilters, string $kind, string $axis): array
    {
        // ===== 軸の指定 =====
        // [select句, groupBy句, ラベル取得関数(stdClass→string)]
        $axisSpec = match ($axis) {
            'popularity' => [
                'race_results.popularity as ax',
                'race_results.popularity',
                fn($r) => $r->ax !== null ? $r->ax . '番人気' : '不明',
            ],
            'venue' => [
                'venues.name as ax',
                'venues.id, venues.name',
                fn($r) => $r->ax ?? '不明',
            ],
            'track' => [
                'races.track_type as ax',
                'races.track_type',
                fn($r) => $r->ax ?? '不明',
            ],
            'distance' => [
                "CASE
                    WHEN races.distance <= 1400 THEN '短(〜1400)'
                    WHEN races.distance <= 1800 THEN 'マ(〜1800)'
                    WHEN races.distance <= 2200 THEN '中(〜2200)'
                    WHEN races.distance <= 2600 THEN '中長(〜2600)'
                    ELSE '長(2700〜)' END as ax",
                "CASE
                    WHEN races.distance <= 1400 THEN '短(〜1400)'
                    WHEN races.distance <= 1800 THEN 'マ(〜1800)'
                    WHEN races.distance <= 2200 THEN '中(〜2200)'
                    WHEN races.distance <= 2600 THEN '中長(〜2600)'
                    ELSE '長(2700〜)' END",
                fn($r) => $r->ax ?? '不明',
            ],
            default => null,
        };
        if (!$axisSpec) return [];

        [$select, $groupBy, $labelFn] = $axisSpec;

        // ===== 券種別クエリ =====
        if ($kind === 'tan') {
            $q = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
                ->whereNotNull('win_odds')
                ->whereNotNull('finish_position_int');
            $applyFilters($q);

            $rows = $q->selectRaw("
                {$select},
                count(*) as bets,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as hits,
                SUM(CASE WHEN finish_position_int=1 THEN win_odds*100 ELSE 0 END) as winnings
            ")->groupByRaw($groupBy)->get();

            return $this->mapBreakdown($rows, $labelFn, 'odds');
        }

        if ($kind === 'fuku') {
            $q = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
                ->whereNotNull('finish_position_int');
            $applyFilters($q);

            $rows = $q->selectRaw("
                {$select},
                count(*) as bets,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as hits,
                SUM(CASE WHEN finish_position_int<=3
                         THEN COALESCE(((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100, 0)
                         ELSE 0 END) as winnings
            ")->groupByRaw($groupBy)->get();

            return $this->mapBreakdown($rows, $labelFn, 'odds');
        }

        // payouts ベース (uma-ren/wide/san-fuku) — PHP側で1パス集計
        // popularity 軸はオッズ的な意味を持たないので非対応
        if ($axis === 'popularity') return [];

        // SQLは出来るだけ単純にして strict mode の罠を回避する
        // 必要な情報だけを races + payouts 集計値で取ってきて PHP 側で軸別に振り分ける
        $rq = DB::table('races')
            ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
            ->leftJoin('payouts', function ($join) use ($kind) {
                $join->on('payouts.race_id', '=', 'races.id')
                     ->where('payouts.kind', '=', $kind);
            });
        $applyFilters($rq);

        // 軸ラベル取得用の生SQL(SELECT)はそのまま使う(group byしない)
        // 1レース複数 payouts に対応するため SUM/COUNT で集約
        $raceRows = $rq->selectRaw("
            races.id as race_id,
            races.horses_count as hc,
            races.track_type as track_type,
            races.distance as distance,
            races.venue_id as venue_id,
            venues.name as venue_name,
            COUNT(payouts.id) as payout_rows,
            COALESCE(SUM(payouts.amount), 0) as winnings_sum
        ")
        ->groupBy('races.id', 'races.horses_count', 'races.track_type', 'races.distance', 'races.venue_id', 'venues.name')
        ->get();

        $combosPerRace = match ($kind) {
            'uma-ren', 'wide' => fn(int $n) => max(0, intdiv($n * ($n-1), 2)),
            'san-fuku'        => fn(int $n) => max(0, intdiv($n * ($n-1) * ($n-2), 6)),
            default           => fn(int $n) => 0,
        };

        // PHP 側でラベル決定
        $axisLabel = function ($r) use ($axis) {
            return match ($axis) {
                'venue'    => $r->venue_name ?? '不明',
                'track'    => $r->track_type ?? '不明',
                'distance' => match (true) {
                    ($r->distance ?? 0) <= 1400 => '短(〜1400)',
                    ($r->distance ?? 0) <= 1800 => 'マ(〜1800)',
                    ($r->distance ?? 0) <= 2200 => '中(〜2200)',
                    ($r->distance ?? 0) <= 2600 => '中長(〜2600)',
                    default => '長(2700〜)',
                },
                default => '不明',
            };
        };

        // 軸ラベルごとに集計
        $agg = []; // label => [bets, hits(racesWithPayout), races, winnings]
        foreach ($raceRows as $r) {
            $label = $axisLabel($r);
            $n = (int) ($r->hc ?? 0);
            $combos = $n > 0 ? $combosPerRace($n) : 0;
            $hasPayout = ((int) $r->payout_rows) > 0 ? 1 : 0;

            if (!isset($agg[$label])) {
                $agg[$label] = ['bets'=>0, 'hits'=>0, 'races'=>0, 'winnings'=>0];
            }
            $agg[$label]['bets']     += $combos;
            $agg[$label]['hits']     += $hasPayout;
            $agg[$label]['races']    += 1;
            $agg[$label]['winnings'] += (float) ($r->winnings_sum ?? 0);
        }

        $out = [];
        foreach ($agg as $label => $a) {
            $stake = $a['bets'] * 100;
            $winnings = $a['winnings'];
            $out[] = [
                'label'    => $label,
                'bets'     => $a['bets'],
                'hits'     => $a['hits'],
                'races'    => $a['races'],
                'stake'    => $stake,
                'winnings' => (int) $winnings,
                'roi'      => $stake > 0 ? round($winnings / $stake * 100, 1) : 0,
                'hit_rate' => $a['races'] > 0 ? round($a['hits'] / $a['races'] * 100, 1) : 0,
            ];
        }

        // 並び順
        if ($axis === 'distance') {
            $order = ['短(〜1400)','マ(〜1800)','中(〜2200)','中長(〜2600)','長(2700〜)'];
            usort($out, fn($a,$b) => array_search($a['label'],$order) - array_search($b['label'],$order));
        } else {
            usort($out, fn($a,$b) => $b['bets'] - $a['bets']);
        }

        return $out;
    }

    /**
     * 単勝/複勝の breakdown 行を統一フォーマットに整える
     */
    private function mapBreakdown($rows, \Closure $labelFn, string $basis): array
    {
        $out = [];
        foreach ($rows as $r) {
            $bets = (int) ($r->bets ?? 0);
            $hits = (int) ($r->hits ?? 0);
            $stake = $bets * 100;
            $winnings = (float) ($r->winnings ?? 0);
            $out[] = [
                'label'    => $labelFn($r),
                'bets'     => $bets,
                'hits'     => $hits,
                'races'    => $bets,
                'stake'    => $stake,
                'winnings' => (int) $winnings,
                'roi'      => $stake > 0 ? round($winnings / $stake * 100, 1) : 0,
                'hit_rate' => $bets > 0 ? round($hits / $bets * 100, 1) : 0,
            ];
        }
        // 数の多い順に並べる
        usort($out, fn($a,$b) => $b['bets'] - $a['bets']);
        return $out;
    }

    /**
     * 単勝のオッズ帯別ROI(1.x / 2.x / 3-4 / 5-9 / 10-19 / 20-49 / 50+)
     */
    private function roiByOddsBand(\Closure $applyFilters): array
    {
        $bandExpr = "CASE
            WHEN win_odds < 2  THEN '1倍台'
            WHEN win_odds < 3  THEN '2倍台'
            WHEN win_odds < 5  THEN '3-4倍'
            WHEN win_odds < 10 THEN '5-9倍'
            WHEN win_odds < 20 THEN '10-19倍'
            WHEN win_odds < 50 THEN '20-49倍'
            ELSE '50倍〜' END";

        $q = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('win_odds')
            ->whereNotNull('finish_position_int');
        $applyFilters($q);

        $rows = $q->selectRaw("
            {$bandExpr} as ax,
            count(*) as bets,
            SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as hits,
            SUM(CASE WHEN finish_position_int=1 THEN win_odds*100 ELSE 0 END) as winnings
        ")->groupByRaw($bandExpr)->get();

        $out = $this->mapBreakdown($rows, fn($r) => $r->ax ?? '不明', 'odds');

        $order = ['1倍台','2倍台','3-4倍','5-9倍','10-19倍','20-49倍','50倍〜'];
        usort($out, fn($a,$b) => array_search($a['label'],$order) - array_search($b['label'],$order));
        return $out;
    }

    /**
     * クロージャを安全に実行し、例外時はログ出力してデフォルト値を返す
     * (1個のSQL失敗でページ全体が500にならないようにするための保険)
     */
    private function safe(\Closure $fn, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('AnalyticsController safe() caught exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return $default;
        }
    }

    /**
     * シミュレーション結果のゼロ埋めデフォルト値
     */
    private function emptySimulation(): array
    {
        return [
            'kind_basis' => 'odds',
            'races'      => 0,
            'bets'       => 0,
            'hits'       => 0,
            'stake'      => 0,
            'winnings'   => 0,
            'profit'     => 0,
            'roi'        => 0,
            'hit_rate'   => 0,
        ];
    }

    /**
     * 馬別コース優位性分析
     * - 競馬場別 / トラック別 / 距離別 / 馬場状態別の成績を一覧化
     * - 馬を選ぶと右サイドパネルで詳細表示
     */
    public function horse(Request $request): View
    {
        $keyword   = trim((string) $request->get('keyword', ''));
        $minRuns   = max(1, (int) $request->get('min_runs', 3));
        $sort      = $request->get('sort', 'show_rate'); // win_rate / show_rate / runs / wins / avg_finish
        $from      = $request->get('from');
        $to        = $request->get('to');
        $horseName = $request->get('horse');

        // ============ 馬別サマリ（一覧用） ============
        $rowsQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->whereNotNull('race_results.finish_position_int');

        if ($keyword !== '') {
            $rowsQuery->where('horses.name', 'like', '%' . $keyword . '%');
        }
        if ($from) {
            $rowsQuery->whereDate('races.race_date', '>=', $from);
        }
        if ($to) {
            $rowsQuery->whereDate('races.race_date', '<=', $to);
        }

        $rows = $rowsQuery
            ->selectRaw('
                horses.id as horse_id,
                horses.name as name,
                horses.sex as sex,
                horses.father as father,
                COUNT(*) as runs,
                SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN race_results.finish_position_int <= 2 THEN 1 ELSE 0 END) as places,
                SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows,
                AVG(race_results.finish_position_int) as avg_finish,
                MAX(races.race_date) as last_run
            ')
            ->groupBy('horses.id', 'horses.name', 'horses.sex', 'horses.father')
            ->having('runs', '>=', $minRuns)
            ->get()
            ->map(function ($r) {
                $r->win_rate   = $r->runs > 0 ? round($r->wins   / $r->runs * 100, 1) : 0;
                $r->place_rate = $r->runs > 0 ? round($r->places / $r->runs * 100, 1) : 0;
                $r->show_rate  = $r->runs > 0 ? round($r->shows  / $r->runs * 100, 1) : 0;
                $r->avg_finish = $r->avg_finish !== null ? round($r->avg_finish, 2) : null;
                return $r;
            });

        // ソート
        $rows = match ($sort) {
            'runs'       => $rows->sortByDesc('runs'),
            'wins'       => $rows->sortByDesc('wins'),
            'win_rate'   => $rows->sortByDesc('win_rate'),
            'place_rate' => $rows->sortByDesc('place_rate'),
            'avg_finish' => $rows->sortBy(fn($r) => $r->avg_finish ?? 99),
            'name'       => $rows->sortBy('name'),
            default      => $rows->sortByDesc('show_rate'),
        };
        $rows = $rows->values();

        // KPIサマリ
        $summary = [
            'total_horses' => $rows->count(),
            'avg_runs'     => $rows->count() > 0 ? round($rows->avg('runs'), 1) : 0,
            'avg_show'     => $rows->count() > 0 ? round($rows->avg('show_rate'), 1) : 0,
            'best_horse'   => $rows->sortByDesc('show_rate')->first(),
        ];

        // ============ 選択された馬の詳細 ============
        $horseModel = null;
        $byVenue    = collect();
        $byTrack    = collect();
        $byDistance = collect();
        $byCondition = collect();
        $recentRuns = collect();

        if ($horseName) {
            $horseModel = Horse::where('name', $horseName)->first();
        }

        if ($horseModel) {
            $detailBase = function () use ($horseModel, $from, $to) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
                    ->where('race_results.horse_id', $horseModel->id)
                    ->whereNotNull('race_results.finish_position_int');
                if ($from) $q->whereDate('races.race_date', '>=', $from);
                if ($to)   $q->whereDate('races.race_date', '<=', $to);
                return $q;
            };

            // 競馬場別
            $byVenue = (clone $detailBase())
                ->selectRaw('
                    venues.name as venue,
                    COUNT(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
                    AVG(race_results.finish_position_int) as avg_finish
                ')
                ->groupBy('venues.name')
                ->orderByDesc('runs')
                ->get();

            // トラック別（芝/ダート × 右/左 など簡易に track_type のみ）
            $byTrack = (clone $detailBase())
                ->selectRaw('
                    races.track_type as track,
                    COUNT(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
                    AVG(race_results.finish_position_int) as avg_finish
                ')
                ->groupBy('races.track_type')
                ->orderByDesc('runs')
                ->get();

            // 距離別（カテゴリ化）
            $byDistance = (clone $detailBase())
                ->selectRaw("
                    CASE
                        WHEN races.distance < 1400 THEN '短距離(〜1399)'
                        WHEN races.distance < 1800 THEN 'マイル(1400-1799)'
                        WHEN races.distance < 2200 THEN '中距離(1800-2199)'
                        WHEN races.distance < 2600 THEN '中長(2200-2599)'
                        ELSE '長距離(2600〜)'
                    END as dist_cat,
                    COUNT(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
                    AVG(race_results.finish_position_int) as avg_finish
                ")
                ->groupByRaw("
                    CASE
                        WHEN races.distance < 1400 THEN '短距離(〜1399)'
                        WHEN races.distance < 1800 THEN 'マイル(1400-1799)'
                        WHEN races.distance < 2200 THEN '中距離(1800-2199)'
                        WHEN races.distance < 2600 THEN '中長(2200-2599)'
                        ELSE '長距離(2600〜)'
                    END
                ")
                ->orderByDesc('runs')
                ->get();

            // 馬場状態別
            $byCondition = (clone $detailBase())
                ->whereNotNull('races.course_condition')
                ->selectRaw('
                    races.course_condition as cond,
                    COUNT(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ')
                ->groupBy('races.course_condition')
                ->orderByDesc('runs')
                ->get();

            // 直近10走
            $recentRuns = (clone $detailBase())
                ->selectRaw('
                    races.id as race_id,
                    races.race_date,
                    races.race_name,
                    venues.name as venue,
                    races.track_type,
                    races.distance,
                    races.course_condition,
                    race_results.finish_position_int as finish,
                    race_results.popularity,
                    race_results.win_odds,
                    race_results.last_3f
                ')
                ->orderByDesc('races.race_date')
                ->limit(10)
                ->get();
        }

        // 最終的に整形した行をビューに渡す
        $rows = $rows->map(function ($r) {
            $r->show_score = $r->show_rate; // ヒートマップ用
            return $r;
        });

        return view('analytics.horse', [
            'rows'        => $rows,
            'summary'     => $summary,
            'horseName'   => $horseName,
            'horseModel'  => $horseModel,
            'byVenue'     => $byVenue,
            'byTrack'     => $byTrack,
            'byDistance'  => $byDistance,
            'byCondition' => $byCondition,
            'recentRuns'  => $recentRuns,
            'filters'     => [
                'keyword' => $keyword,
                'minRuns' => $minRuns,
                'sort'    => $sort,
                'from'    => $from,
                'to'      => $to,
            ],
        ]);
    }

    /**
     * コース別傾向 (競馬場 × トラック × 距離)
     *
     * 静的マスタ (venue_courses) と 実績集計 (race_results) を突き合わせ、
     * - 公式コース情報 (直線長/高低差/コーナー数/スタート位置)
     * - 想定傾向 (有利脚質/有利枠/ペース傾向/コメント)
     * - 実績傾向 (枠別勝率/脚質別勝率/平均上がり3F/平均勝ちタイム/レース数)
     * を1テーブルに並べて見える化する。
     *
     * クエリ:
     *   ?venue_id=    競馬場
     *   ?track_type=  芝/ダート/障害
     *   ?distance_cat= 短距離/マイル/中距離/中長距離/長距離
     */
    public function courseTrends(Request $request): View
    {
        $venues    = Venue::orderBy('code')->get();
        $venueId   = $request->get('venue_id');
        $trackType = $request->get('track_type');
        $distCat   = $request->get('distance_cat');

        $distRange = match ($distCat) {
            '短距離'   => [0, 1400],
            'マイル'   => [1401, 1800],
            '中距離'   => [1801, 2200],
            '中長距離' => [2201, 2600],
            '長距離'   => [2601, 9999],
            default    => null,
        };

        // ========== 静的マスタ取得 ==========
        $courseQuery = VenueCourse::with('venue')
            ->join('venues', 'venues.id', '=', 'venue_courses.venue_id')
            ->orderBy('venues.code')
            ->orderBy('venue_courses.track_type')
            ->orderBy('venue_courses.distance')
            ->select('venue_courses.*');

        if ($venueId)   $courseQuery->where('venue_courses.venue_id', $venueId);
        if ($trackType) $courseQuery->where('venue_courses.track_type', $trackType);
        if ($distRange) $courseQuery->whereBetween('venue_courses.distance', $distRange);

        $courses = $courseQuery->get();

        // ========== 実績集計 ==========
        $aggQuery = DB::table('races')
            ->leftJoin('race_results', 'race_results.race_id', '=', 'races.id')
            ->whereNotNull('races.distance')
            ->whereNotNull('races.track_type');

        if ($venueId)   $aggQuery->where('races.venue_id', $venueId);
        if ($trackType) $aggQuery->where('races.track_type', $trackType);
        if ($distRange) $aggQuery->whereBetween('races.distance', $distRange);

        $aggRows = $aggQuery
            ->selectRaw("
                races.venue_id,
                races.track_type,
                races.distance,
                COUNT(DISTINCT races.id) as race_cnt,
                AVG(CASE WHEN race_results.finish_position_int=1 THEN race_results.last_3f_seconds END) as avg_win_last3f,
                AVG(CASE WHEN race_results.finish_position_int=1 THEN race_results.time_seconds END) as avg_win_time,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.frame_number BETWEEN 1 AND 3 THEN 1 ELSE 0 END) as inner_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.frame_number BETWEEN 4 AND 5 THEN 1 ELSE 0 END) as middle_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.frame_number BETWEEN 6 AND 8 THEN 1 ELSE 0 END) as outer_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.running_style='逃げ'   THEN 1 ELSE 0 END) as nige_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.running_style='先行'   THEN 1 ELSE 0 END) as senko_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.running_style='差し'   THEN 1 ELSE 0 END) as sashi_wins,
                SUM(CASE WHEN race_results.finish_position_int=1 AND race_results.running_style='追込'   THEN 1 ELSE 0 END) as oikomi_wins,
                SUM(CASE WHEN races.pace='H' THEN 1 ELSE 0 END) as pace_h,
                SUM(CASE WHEN races.pace='M' THEN 1 ELSE 0 END) as pace_m,
                SUM(CASE WHEN races.pace='S' THEN 1 ELSE 0 END) as pace_s
            ")
            ->groupBy('races.venue_id', 'races.track_type', 'races.distance')
            ->get();

        $aggMap = [];
        foreach ($aggRows as $r) {
            $key = $r->venue_id . '|' . $r->track_type . '|' . $r->distance;
            $aggMap[$key] = $r;
        }

        // ========== 静的 + 実績 をマージ ==========
        $merged = $courses->map(function (VenueCourse $c) use ($aggMap) {
            $key = $c->venue_id . '|' . $c->track_type . '|' . $c->distance;
            $a = $aggMap[$key] ?? null;

            $totalStyle = $a ? ((int)$a->nige_wins + (int)$a->senko_wins + (int)$a->sashi_wins + (int)$a->oikomi_wins) : 0;
            $totalFrame = $a ? ((int)$a->inner_wins + (int)$a->middle_wins + (int)$a->outer_wins) : 0;
            $totalPace  = $a ? ((int)$a->pace_h + (int)$a->pace_m + (int)$a->pace_s) : 0;

            $pct = fn($n, $d) => $d > 0 ? round($n / $d * 100, 1) : null;

            return (object) [
                'venue_code'      => $c->venue->code ?? null,
                'venue_name'      => $c->venue->name ?? '?',
                'track_type'      => $c->track_type,
                'distance'        => $c->distance,
                'course_variation'=> $c->course_variation,
                'straight_length' => $c->straight_length,
                'elevation_diff'  => $c->elevation_diff,
                'corner_count'    => $c->corner_count,
                'start_position'  => $c->start_position,
                'favored_style'   => $c->favored_style,
                'favored_frame'   => $c->favored_frame,
                'pace_tendency'   => $c->pace_tendency,
                'notes'           => $c->notes,
                'race_cnt'        => $a ? (int) $a->race_cnt : 0,
                'avg_win_last3f'  => ($a && $a->avg_win_last3f !== null) ? round((float)$a->avg_win_last3f, 2) : null,
                'avg_win_time'    => ($a && $a->avg_win_time   !== null) ? round((float)$a->avg_win_time, 2)   : null,
                'inner_pct'       => $a ? $pct($a->inner_wins,  $totalFrame) : null,
                'middle_pct'      => $a ? $pct($a->middle_wins, $totalFrame) : null,
                'outer_pct'       => $a ? $pct($a->outer_wins,  $totalFrame) : null,
                'nige_pct'        => $a ? $pct($a->nige_wins,   $totalStyle) : null,
                'senko_pct'       => $a ? $pct($a->senko_wins,  $totalStyle) : null,
                'sashi_pct'       => $a ? $pct($a->sashi_wins,  $totalStyle) : null,
                'oikomi_pct'      => $a ? $pct($a->oikomi_wins, $totalStyle) : null,
                'pace_h_pct'      => $a ? $pct($a->pace_h, $totalPace) : null,
                'pace_m_pct'      => $a ? $pct($a->pace_m, $totalPace) : null,
                'pace_s_pct'      => $a ? $pct($a->pace_s, $totalPace) : null,
            ];
        });

        $summary = [
            'course_count' => $merged->count(),
            'with_data'    => $merged->where('race_cnt', '>', 0)->count(),
            'total_races'  => $merged->sum('race_cnt'),
        ];

        $distCats = ['短距離','マイル','中距離','中長距離','長距離'];

        return view('analytics.course-trends', [
            'venues'    => $venues,
            'venueId'   => $venueId,
            'trackType' => $trackType,
            'distCat'   => $distCat,
            'distCats'  => $distCats,
            'rows'      => $merged,
            'summary'   => $summary,
        ]);
    }
}
