<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     */
    public function pace(): View
    {
        $stats = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.pace')
            ->whereNotNull('running_style')
            ->where('finish_position_int', '<=', 3)
            ->selectRaw('races.pace, running_style, count(*) as cnt')
            ->groupBy('races.pace', 'running_style')
            ->get();

        // ピボット
        $pivot = [];
        foreach ($stats as $s) {
            $pivot[$s->pace][$s->running_style] = $s->cnt;
        }

        return view('analytics.pace', compact('pivot'));
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

    /**
     * 騎手×コース相性
     */
    public function jockey(Request $request): View
    {
        $jockeyName = $request->get('jockey');

        $stats = collect();
        if ($jockeyName) {
            $stats = DB::table('race_results')
                ->join('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->where('jockeys.name', $jockeyName)
                ->whereNotNull('finish_position_int')
                ->selectRaw('venues.name as venue, races.track_type,
                    count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
                ->groupBy('venues.name', 'races.track_type')
                ->orderByDesc('runs')
                ->get();
        }

        $jockeyList = DB::table('jockeys')
            ->join('race_results', 'race_results.jockey_id', '=', 'jockeys.id')
            ->select('jockeys.name', DB::raw('count(*) as cnt'))
            ->groupBy('jockeys.name')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        return view('analytics.jockey', compact('jockeyList', 'jockeyName', 'stats'));
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
     */
    public function roi(Request $request): View
    {
        $popularity = $request->get('popularity');
        $venueId = $request->get('venue_id');
        $trackType = $request->get('track_type');

        $query = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('win_odds')
            ->whereNotNull('finish_position_int');

        if ($popularity) {
            $query->where('popularity', $popularity);
        }
        if ($venueId) {
            $query->where('races.venue_id', $venueId);
        }
        if ($trackType) {
            $query->where('races.track_type', $trackType);
        }

        $bets = $query->selectRaw('count(*) as bets,
            SUM(CASE WHEN finish_position_int=1 THEN win_odds*100 ELSE 0 END) as winnings')
            ->first();

        $roi = $bets && $bets->bets > 0
            ? round($bets->winnings / ($bets->bets * 100) * 100, 1)
            : 0;

        $venues = Venue::orderBy('code')->get();

        return view('analytics.roi', compact('bets', 'roi', 'venues', 'popularity', 'venueId', 'trackType'));
    }
}
