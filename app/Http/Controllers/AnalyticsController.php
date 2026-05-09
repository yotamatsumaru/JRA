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

        $simulation = match ($kind) {
            'fuku'     => $this->simulateFuku($applyFilters, $popularity),
            'uma-ren'  => $this->simulatePayoutKind($applyFilters, 'uma-ren', $popularity),
            'wide'     => $this->simulatePayoutKind($applyFilters, 'wide', $popularity),
            'san-fuku' => $this->simulatePayoutKind($applyFilters, 'san-fuku', $popularity),
            default    => $this->simulateTan($applyFilters, $popularity),
        };

        // ====== グラフ用クロスタブ ======
        $charts = [
            'by_popularity' => $this->roiBreakdown($applyFilters, $kind, 'popularity'),
            'by_venue'      => $this->roiBreakdown($applyFilters, $kind, 'venue'),
            'by_track'      => $this->roiBreakdown($applyFilters, $kind, 'track'),
            'by_distance'   => $this->roiBreakdown($applyFilters, $kind, 'distance'),
            'by_odds_band'  => $kind === 'tan' ? $this->roiByOddsBand($applyFilters) : [],
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
            ->join('payouts', function ($join) use ($kind) {
                $join->on('payouts.race_id', '=', 'races.id')
                     ->where('payouts.kind', '=', $kind);
            })
            ->select('races.id', 'races.horses_count');
        $applyFilters($rq);
        $races = $rq->distinct()->get();
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

        // payouts ベース (uma-ren/wide/san-fuku) — 軸別に集計
        // popularity 軸はオッズ的な意味を持たないので非対応
        if ($axis === 'popularity') return [];

        // 払戻合計とレース数を軸別に集計
        $pq = DB::table('payouts')
            ->join('races', 'races.id', '=', 'payouts.race_id')
            ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
            ->where('payouts.kind', $kind);
        $applyFilters($pq);

        $rows = $pq->selectRaw("
            {$select},
            COUNT(DISTINCT payouts.race_id) as hit_races,
            SUM(payouts.amount) as winnings
        ")->groupByRaw($groupBy)->get();

        // レース母集団(各軸の C(N,k)*100 を計算)
        $rq = DB::table('races')
            ->join('payouts', function ($join) use ($kind) {
                $join->on('payouts.race_id', '=', 'races.id')
                     ->where('payouts.kind', '=', $kind);
            })
            ->leftJoin('venues', 'venues.id', '=', 'races.venue_id');
        $applyFilters($rq);
        $raceRows = $rq->selectRaw("{$select}, races.horses_count as hc")->distinct()->get();

        $combosPerRace = match ($kind) {
            'uma-ren', 'wide' => fn(int $n) => max(0, intdiv($n * ($n-1), 2)),
            'san-fuku'        => fn(int $n) => max(0, intdiv($n * ($n-1) * ($n-2), 6)),
            default           => fn(int $n) => 0,
        };

        // 軸ラベルごとの combo 合計
        $combosByAxis = [];
        $racesByAxis  = [];
        foreach ($raceRows as $r) {
            $label = $labelFn($r);
            $n = (int) ($r->hc ?? 0);
            if ($n <= 0) continue;
            $combosByAxis[$label] = ($combosByAxis[$label] ?? 0) + $combosPerRace($n);
            $racesByAxis[$label]  = ($racesByAxis[$label]  ?? 0) + 1;
        }

        $out = [];
        foreach ($rows as $r) {
            $label = $labelFn($r);
            $combos = $combosByAxis[$label] ?? 0;
            $stake = $combos * 100;
            $winnings = (float) ($r->winnings ?? 0);
            $races = $racesByAxis[$label] ?? 0;
            $hitRaces = (int) ($r->hit_races ?? 0);

            $out[] = [
                'label'    => $label,
                'bets'     => $combos,
                'hits'     => $hitRaces,
                'races'    => $races,
                'stake'    => $stake,
                'winnings' => (int) $winnings,
                'roi'      => $stake > 0 ? round($winnings / $stake * 100, 1) : 0,
                'hit_rate' => $races > 0 ? round($hitRaces / $races * 100, 1) : 0,
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
}
