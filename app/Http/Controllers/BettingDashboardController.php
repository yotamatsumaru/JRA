<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Payout;
use App\Models\Race;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BettingDashboardController extends Controller
{
    /**
     * 収支ダッシュボード
     */
    public function index(Request $request): View
    {
        $userId = Auth::id();

        $from = $request->input('from');
        $to   = $request->input('to');

        $base = Bet::where('user_id', $userId)->where('is_settled', true);
        if ($from) $base->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $from));
        if ($to)   $base->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $to));

        // ===== 累計KPI =====
        $kpiRow = (clone $base)->selectRaw('
            COUNT(*) as cnt,
            COALESCE(SUM(total_stake),0)  as stake,
            COALESCE(SUM(total_return),0) as ret,
            COALESCE(SUM(CASE WHEN hit_count > 0 THEN 1 ELSE 0 END),0) as hits
        ')->first();

        $kpi = [
            'count'    => (int) $kpiRow->cnt,
            'stake'    => (int) $kpiRow->stake,
            'return'   => (int) $kpiRow->ret,
            'profit'   => (int) ($kpiRow->ret - $kpiRow->stake),
            'hits'     => (int) $kpiRow->hits,
            'hit_rate' => $kpiRow->cnt > 0 ? round($kpiRow->hits / $kpiRow->cnt * 100, 1) : null,
            'roi'      => $kpiRow->stake > 0 ? round($kpiRow->ret / $kpiRow->stake * 100, 1) : null,
        ];

        // ===== 月次推移（直近12ヶ月） =====
        $monthly = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw("
                DATE_FORMAT(races.race_date, '%Y-%m') as ym,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret,
                COUNT(*) as cnt
            ")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->map(fn($r) => [
                'ym'     => $r->ym,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) ($r->ret - $r->stake),
                'roi'    => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
            ]);

        // ===== 累積回収率推移（日次） =====
        $daily = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw("
                races.race_date as d,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret
            ")
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $cumStake = 0; $cumReturn = 0;
        $cumulative = $daily->map(function ($r) use (&$cumStake, &$cumReturn) {
            $cumStake  += (int) $r->stake;
            $cumReturn += (int) $r->ret;
            return [
                'd'     => $r->d,
                'stake' => $cumStake,
                'ret'   => $cumReturn,
                'roi'   => $cumStake > 0 ? round($cumReturn / $cumStake * 100, 1) : 0,
                'profit'=> $cumReturn - $cumStake,
            ];
        });

        // ===== 券種別ROI =====
        $byKind = (clone $base)->selectRaw('
                kind,
                COUNT(*) as cnt,
                SUM(total_stake)  as stake,
                SUM(total_return) as ret,
                SUM(CASE WHEN hit_count > 0 THEN 1 ELSE 0 END) as hits
            ')
            ->groupBy('kind')
            ->get()
            ->map(fn($r) => [
                'kind'      => $r->kind,
                'kind_label'=> Bet::KIND_LABELS[$r->kind] ?? $r->kind,
                'cnt'       => (int) $r->cnt,
                'stake'     => (int) $r->stake,
                'return'    => (int) $r->ret,
                'profit'    => (int) ($r->ret - $r->stake),
                'roi'       => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
                'hit_rate'  => $r->cnt > 0 ? round($r->hits / $r->cnt * 100, 1) : 0,
            ])
            ->sortByDesc('roi')
            ->values();

        // ===== 競馬場別ROI =====
        $byVenue = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->join('venues', 'races.venue_id', '=', 'venues.id')
            ->selectRaw('
                venues.id as vid, venues.name,
                COUNT(*) as cnt,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret,
                SUM(CASE WHEN bets.hit_count > 0 THEN 1 ELSE 0 END) as hits
            ')
            ->groupBy('vid', 'venues.name')
            ->get()
            ->map(fn($r) => [
                'name'   => $r->name,
                'cnt'    => (int) $r->cnt,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) ($r->ret - $r->stake),
                'roi'    => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
                'hit_rate'=> $r->cnt > 0 ? round($r->hits / $r->cnt * 100, 1) : 0,
            ])
            ->sortByDesc('roi')
            ->values();

        // ===== 距離・トラック別ROI =====
        $byTrack = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw("
                races.track_type as tt,
                CASE
                    WHEN races.distance <= 1400 THEN '短距離'
                    WHEN races.distance <= 1800 THEN 'マイル'
                    WHEN races.distance <= 2200 THEN '中距離'
                    WHEN races.distance <= 2600 THEN '中長距離'
                    ELSE '長距離'
                END as dist_cat,
                COUNT(*) as cnt,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret
            ")
            ->groupBy('tt', 'dist_cat')
            ->get()
            ->map(fn($r) => [
                'label'  => $r->tt . ' ' . $r->dist_cat,
                'cnt'    => (int) $r->cnt,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) ($r->ret - $r->stake),
                'roi'    => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
            ])
            ->sortByDesc('roi')
            ->values();

        // ===== 騎手別ROI（自分の的中買い目に紐づく騎手） =====
        // bet_legs の combination 内に含まれる馬番のうち、レース結果で finish_position_int=1 だった騎手を集計
        // 簡易版: 1着騎手だけを対象（複勝・ワイドだと2-3着騎手も貢献するが、シンプルに1着で集計）
        $byJockey = DB::table('bets')
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->join('race_results', function ($j) {
                $j->on('race_results.race_id', '=', 'races.id')
                  ->where('race_results.finish_position_int', 1);
            })
            ->join('jockeys', 'race_results.jockey_id', '=', 'jockeys.id')
            ->where('bets.user_id', $userId)
            ->where('bets.is_settled', true)
            ->when($from, fn($q) => $q->whereDate('races.race_date', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('races.race_date', '<=', $to))
            ->selectRaw('
                jockeys.id, jockeys.name,
                COUNT(*) as cnt,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret,
                SUM(CASE WHEN bets.hit_count > 0 THEN 1 ELSE 0 END) as hits
            ')
            ->groupBy('jockeys.id', 'jockeys.name')
            ->havingRaw('cnt >= 3')   // 3レース以上のみ
            ->orderByRaw('SUM(bets.total_return) - SUM(bets.total_stake) DESC')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'name'   => $r->name,
                'cnt'    => (int) $r->cnt,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) ($r->ret - $r->stake),
                'roi'    => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
                'hit_rate'=> $r->cnt > 0 ? round($r->hits / $r->cnt * 100, 1) : 0,
            ]);

        // ===== 馬別ROI（買い目に含まれる馬の人気馬TOP） =====
        // 単純化: bet_legs.combination の先頭馬番を「軸馬」とみなす
        $byHorse = DB::table('bet_legs')
            ->join('bets', 'bet_legs.bet_id', '=', 'bets.id')
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->joinSub(
                DB::table('race_results')
                    ->select('race_id', 'horse_id', 'horse_number'),
                'rr',
                function ($j) {
                    $j->on('rr.race_id', '=', 'races.id')
                      ->whereColumn('rr.horse_number', '=', DB::raw('CAST(SUBSTRING_INDEX(bet_legs.combination, "-", 1) AS UNSIGNED)'));
                }
            )
            ->join('horses', 'rr.horse_id', '=', 'horses.id')
            ->where('bets.user_id', $userId)
            ->where('bets.is_settled', true)
            ->when($from, fn($q) => $q->whereDate('races.race_date', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('races.race_date', '<=', $to))
            ->selectRaw('
                horses.id, horses.name,
                COUNT(DISTINCT bets.id) as cnt,
                SUM(bet_legs.stake)  as stake,
                SUM(bet_legs.payout) as ret,
                SUM(CASE WHEN bet_legs.is_hit = 1 THEN 1 ELSE 0 END) as hit_legs
            ')
            ->groupBy('horses.id', 'horses.name')
            ->havingRaw('cnt >= 2')
            ->orderByRaw('SUM(bet_legs.payout) - SUM(bet_legs.stake) DESC')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'name'   => $r->name,
                'cnt'    => (int) $r->cnt,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) ($r->ret - $r->stake),
                'roi'    => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
            ]);

        // ===== 配当ベスト10（自分の的中の中で） =====
        $bestPayouts = BetLeg::with(['bet.race.venue', 'bet'])
            ->whereHas('bet', fn($q) => $q->where('user_id', $userId))
            ->where('is_hit', true)
            ->orderByDesc('payout')
            ->limit(10)
            ->get();

        // ===== 連勝・連敗 =====
        $streaks = $this->computeStreaks($userId, $from, $to);

        // ===== 月次目標 vs 実績 =====
        $monthlyTarget = $this->monthlyTargetVsActual($userId);

        // ===== 払戻データ概況（自分の馬券に関係なく、取込済の全レース母集団から） =====
        $payoutOverview = $this->buildPayoutOverview($from, $to);

        // ===== 拡張: 払戻系の詳細分析 =====
        $payoutAnalytics = $this->buildPayoutAnalytics($from, $to);

        // ===== 拡張: 自分の馬券のさらなる分析 =====
        $myAnalytics = $this->buildMyAnalytics($userId, $from, $to);

        return view('bets.dashboard', compact(
            'kpi', 'monthly', 'cumulative', 'byKind', 'byVenue', 'byTrack',
            'byJockey', 'byHorse', 'bestPayouts', 'streaks', 'monthlyTarget',
            'payoutOverview', 'payoutAnalytics', 'myAnalytics',
            'from', 'to'
        ));
    }

    /**
     * 拡張: 払戻データの多次元分析
     *  - 配当帯別ヒストグラム（券種別）
     *  - 人気別の平均配当・件数
     *  - 月別 高額配当発生回数
     *  - 曜日別の平均配当
     *  - 競馬場別の平均配当・最高配当
     *  - 万馬券（10000円以上）発生レース数
     */
    protected function buildPayoutAnalytics(?string $from, ?string $to): array
    {
        $base = Payout::query()
            ->join('races', 'payouts.race_id', '=', 'races.id')
            ->when($from, fn($q) => $q->whereDate('races.race_date', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('races.race_date', '<=', $to));

        // ===== 配当帯別ヒストグラム（券種別） =====
        // 配当を 1000円帯/3000円帯/10000円帯/30000円帯/100000円帯/それ以上 に分類
        $bands = [
            ['min' => 0,      'max' => 1000,     'label' => '〜1,000'],
            ['min' => 1000,   'max' => 3000,     'label' => '1,000〜3,000'],
            ['min' => 3000,   'max' => 10000,    'label' => '3,000〜10,000'],
            ['min' => 10000,  'max' => 30000,    'label' => '10,000〜30,000'],
            ['min' => 30000,  'max' => 100000,   'label' => '30,000〜100,000'],
            ['min' => 100000, 'max' => 99999999, 'label' => '100,000〜'],
        ];

        $bandCountsByKind = [];
        foreach (array_keys(Bet::KIND_LABELS) as $kindKey) {
            $kindData = (clone $base)->where('payouts.kind', $kindKey)->pluck('payouts.amount');
            $row = ['kind' => $kindKey, 'kind_label' => Bet::KIND_LABELS[$kindKey], 'bands' => []];
            foreach ($bands as $b) {
                $cnt = $kindData->filter(fn($a) => $a >= $b['min'] && $a < $b['max'])->count();
                $row['bands'][] = ['label' => $b['label'], 'cnt' => $cnt];
            }
            $row['total'] = $kindData->count();
            $bandCountsByKind[] = $row;
        }
        $bandLabels = array_map(fn($b) => $b['label'], $bands);

        // ===== 人気別の平均配当・件数（単勝のみで集計） =====
        $byPopularity = (clone $base)
            ->whereNotNull('payouts.popularity')
            ->where('payouts.kind', 'tan')   // 単勝で人気の意味が明確
            ->selectRaw('payouts.popularity as pop, COUNT(*) as cnt, AVG(payouts.amount) as avg_amt, MAX(payouts.amount) as max_amt')
            ->groupBy('pop')
            ->orderBy('pop')
            ->get()
            ->map(fn($r) => [
                'pop'      => (int) $r->pop,
                'cnt'      => (int) $r->cnt,
                'avg'      => (int) round($r->avg_amt),
                'max'      => (int) $r->max_amt,
            ]);

        // ===== 月別 万馬券（10000円以上）発生数 =====
        $manbakenMonthly = (clone $base)
            ->where('payouts.amount', '>=', 10000)
            ->selectRaw("DATE_FORMAT(races.race_date, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->map(fn($r) => ['ym' => $r->ym, 'cnt' => (int) $r->cnt]);

        // ===== 曜日別の平均配当（券種混合） =====
        $byWeekday = (clone $base)
            ->selectRaw('DAYOFWEEK(races.race_date) as dow, COUNT(*) as cnt, AVG(payouts.amount) as avg_amt, MAX(payouts.amount) as max_amt')
            ->groupBy('dow')
            ->orderBy('dow')
            ->get()
            ->map(function ($r) {
                $names = ['', '日', '月', '火', '水', '木', '金', '土'];
                return [
                    'dow'   => (int) $r->dow,
                    'label' => $names[(int) $r->dow] ?? '?',
                    'cnt'   => (int) $r->cnt,
                    'avg'   => (int) round($r->avg_amt),
                    'max'   => (int) $r->max_amt,
                ];
            });

        // ===== 競馬場別の払戻サマリ =====
        $byVenue = (clone $base)
            ->join('venues', 'venues.id', '=', 'races.venue_id')
            ->selectRaw('venues.id, venues.name, COUNT(*) as cnt, AVG(payouts.amount) as avg_amt, MAX(payouts.amount) as max_amt,
                SUM(CASE WHEN payouts.amount >= 10000 THEN 1 ELSE 0 END) as manbaken_cnt')
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('avg_amt')
            ->get()
            ->map(fn($r) => [
                'name'         => $r->name,
                'cnt'          => (int) $r->cnt,
                'avg'          => (int) round($r->avg_amt),
                'max'          => (int) $r->max_amt,
                'manbaken_cnt' => (int) $r->manbaken_cnt,
            ]);

        // ===== 全体サマリ =====
        $manbakenAll = (clone $base)->where('payouts.amount', '>=', 10000)->count();
        $hyaku = (clone $base)->where('payouts.amount', '>=', 100000)->count();   // 10万円以上
        $millionPayout = (clone $base)->where('payouts.amount', '>=', 1000000)->count();   // 100万円以上

        // ===== 期間中の歴代TOP10高額配当 =====
        $top10 = (clone $base)
            ->select('payouts.*', 'races.race_date', 'races.race_number', 'races.name as race_name', 'races.venue_id')
            ->orderByDesc('payouts.amount')
            ->limit(10)
            ->with('race.venue')
            ->get();

        // ===== 券種別 平均配当（バーチャート用） =====
        $kindAvg = (clone $base)
            ->selectRaw('payouts.kind, AVG(payouts.amount) as avg_amt, COUNT(*) as cnt')
            ->groupBy('payouts.kind')
            ->get()
            ->map(fn($r) => [
                'kind'  => $r->kind,
                'label' => Bet::KIND_LABELS[$r->kind] ?? $r->kind,
                'avg'   => (int) round($r->avg_amt),
                'cnt'   => (int) $r->cnt,
            ])
            ->sortBy(fn($r) => array_search($r['kind'], array_keys(Bet::KIND_LABELS)))
            ->values();

        return [
            'band_labels'           => $bandLabels,
            'band_counts_by_kind'   => $bandCountsByKind,
            'by_popularity'         => $byPopularity,
            'manbaken_monthly'      => $manbakenMonthly,
            'by_weekday'            => $byWeekday,
            'by_venue'              => $byVenue,
            'manbaken_count'        => $manbakenAll,
            'hyaku_count'           => $hyaku,
            'million_count'         => $millionPayout,
            'top10'                 => $top10,
            'kind_avg'              => $kindAvg,
        ];
    }

    /**
     * 拡張: 自分の馬券のさらなる分析
     *  - 投資額帯別の購入分布
     *  - 曜日別の自分の収支
     *  - 直近30日の収支推移
     *  - 投資/払戻の散布図用データ
     */
    protected function buildMyAnalytics(int $userId, ?string $from, ?string $to): array
    {
        $base = Bet::where('user_id', $userId)->where('is_settled', true)
            ->when($from, fn($q) => $q->whereHas('race', fn($r) => $r->whereDate('race_date', '>=', $from)))
            ->when($to,   fn($q) => $q->whereHas('race', fn($r) => $r->whereDate('race_date', '<=', $to)));

        // ===== 曜日別の自分の収支 =====
        $myByWeekday = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw('DAYOFWEEK(races.race_date) as dow, COUNT(*) as cnt,
                SUM(bets.total_stake) as stake, SUM(bets.total_return) as ret,
                SUM(CASE WHEN bets.hit_count > 0 THEN 1 ELSE 0 END) as hits')
            ->groupBy('dow')
            ->orderBy('dow')
            ->get()
            ->map(function ($r) {
                $names = ['', '日', '月', '火', '水', '木', '金', '土'];
                $stake = (int) $r->stake; $ret = (int) $r->ret;
                return [
                    'dow'      => (int) $r->dow,
                    'label'    => $names[(int) $r->dow] ?? '?',
                    'cnt'      => (int) $r->cnt,
                    'stake'    => $stake,
                    'return'   => $ret,
                    'profit'   => $ret - $stake,
                    'roi'      => $stake > 0 ? round($ret / $stake * 100, 1) : 0,
                    'hit_rate' => $r->cnt > 0 ? round($r->hits / $r->cnt * 100, 1) : 0,
                ];
            });

        // ===== 投資額帯別の購入分布 =====
        $stakeBands = [
            ['min' => 0,     'max' => 500,    'label' => '〜500'],
            ['min' => 500,   'max' => 1000,   'label' => '500〜1,000'],
            ['min' => 1000,  'max' => 3000,   'label' => '1,000〜3,000'],
            ['min' => 3000,  'max' => 5000,   'label' => '3,000〜5,000'],
            ['min' => 5000,  'max' => 10000,  'label' => '5,000〜10,000'],
            ['min' => 10000, 'max' => 99999999, 'label' => '10,000〜'],
        ];
        $allStakes = (clone $base)->pluck('total_stake');
        $stakeDist = [];
        foreach ($stakeBands as $b) {
            $cnt = $allStakes->filter(fn($s) => $s >= $b['min'] && $s < $b['max'])->count();
            $stakeDist[] = ['label' => $b['label'], 'cnt' => $cnt];
        }

        // ===== 直近30日の日次収支 =====
        $thirtyDaysAgo = now()->subDays(30)->toDateString();
        $recent30 = Bet::where('user_id', $userId)->where('is_settled', true)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->whereDate('races.race_date', '>=', $thirtyDaysAgo)
            ->selectRaw("races.race_date as d,
                SUM(bets.total_stake) as stake, SUM(bets.total_return) as ret, COUNT(*) as cnt")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn($r) => [
                'd'      => $r->d,
                'stake'  => (int) $r->stake,
                'return' => (int) $r->ret,
                'profit' => (int) $r->ret - (int) $r->stake,
                'cnt'    => (int) $r->cnt,
            ]);

        // ===== 投資 vs 払戻 散布図（最大200件） =====
        $scatter = (clone $base)
            ->select('total_stake', 'total_return', 'race_id')
            ->limit(200)
            ->get()
            ->map(fn($b) => ['x' => (int) $b->total_stake, 'y' => (int) $b->total_return]);

        // ===== グレード別の収支（GI/GII/GIII/OP/その他） =====
        $byGrade = (clone $base)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw("
                COALESCE(races.grade, 'その他') as grade,
                COUNT(*) as cnt,
                SUM(bets.total_stake) as stake,
                SUM(bets.total_return) as ret,
                SUM(CASE WHEN bets.hit_count > 0 THEN 1 ELSE 0 END) as hits
            ")
            ->groupBy('grade')
            ->get()
            ->map(fn($r) => [
                'grade'    => $r->grade ?: 'その他',
                'cnt'      => (int) $r->cnt,
                'stake'    => (int) $r->stake,
                'return'   => (int) $r->ret,
                'profit'   => (int) ($r->ret - $r->stake),
                'roi'      => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
                'hit_rate' => $r->cnt > 0 ? round($r->hits / $r->cnt * 100, 1) : 0,
            ])
            ->sortByDesc('roi')
            ->values();

        return [
            'by_weekday' => $myByWeekday,
            'stake_dist' => $stakeDist,
            'recent30'   => $recent30,
            'scatter'    => $scatter,
            'by_grade'   => $byGrade,
        ];
    }

    /**
     * 払戻データ概況の集計
     *  - 取込レース数 / 払戻レコード数
     *  - 券種別取込件数・平均/最大配当
     *  - 直近高額配当TOP5
     */
    protected function buildPayoutOverview(?string $from, ?string $to): array
    {
        // 期間で絞る場合は races 経由
        $payoutBase = Payout::query()
            ->when($from || $to, function ($q) use ($from, $to) {
                $q->whereHas('race', function ($r) use ($from, $to) {
                    if ($from) $r->whereDate('race_date', '>=', $from);
                    if ($to)   $r->whereDate('race_date', '<=', $to);
                });
            });

        $totalPayouts = (clone $payoutBase)->count();
        $totalRaces = (clone $payoutBase)->distinct('race_id')->count('race_id');

        // 券種別の集計
        $byKind = (clone $payoutBase)
            ->selectRaw('
                kind,
                COUNT(*) as cnt,
                AVG(amount) as avg_amount,
                MAX(amount) as max_amount
            ')
            ->groupBy('kind')
            ->get()
            ->map(fn($r) => [
                'kind'       => $r->kind,
                'kind_label' => Bet::KIND_LABELS[$r->kind] ?? $r->kind,
                'cnt'        => (int) $r->cnt,
                'avg'        => (int) round($r->avg_amount),
                'max'        => (int) $r->max_amount,
            ])
            ->sortBy(fn($r) => array_search($r['kind'], array_keys(Bet::KIND_LABELS)))
            ->values();

        // 直近の高額配当TOP5（券種混合）
        $topRecent = (clone $payoutBase)
            ->with('race.venue')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        // 全体の平均配当（参考値）
        $avgAll = (clone $payoutBase)->avg('amount');

        return [
            'total_payouts' => $totalPayouts,
            'total_races'   => $totalRaces,
            'avg_amount'    => (int) round($avgAll ?? 0),
            'by_kind'       => $byKind,
            'top_recent'    => $topRecent,
        ];
    }

    /**
     * 連勝・連敗の最大記録 + 直近の状態
     */
    protected function computeStreaks(int $userId, ?string $from, ?string $to): array
    {
        $bets = Bet::where('user_id', $userId)
            ->where('is_settled', true)
            ->when($from, fn($q) => $q->whereHas('race', fn($r) => $r->whereDate('race_date', '>=', $from)))
            ->when($to,   fn($q) => $q->whereHas('race', fn($r) => $r->whereDate('race_date', '<=', $to)))
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->orderBy('races.race_date')
            ->orderBy('races.race_number')
            ->select('bets.hit_count', 'races.race_date')
            ->get();

        $maxWin = 0; $maxLose = 0; $curWin = 0; $curLose = 0;
        foreach ($bets as $b) {
            if ($b->hit_count > 0) {
                $curWin++; $curLose = 0;
                $maxWin = max($maxWin, $curWin);
            } else {
                $curLose++; $curWin = 0;
                $maxLose = max($maxLose, $curLose);
            }
        }

        return [
            'max_win'   => $maxWin,
            'max_lose'  => $maxLose,
            'cur_win'   => $curWin,
            'cur_lose'  => $curLose,
        ];
    }

    /**
     * 月次目標 vs 実績（user_settings 等は持っていないので、簡易: 過去6ヶ月平均を目標とする）
     */
    protected function monthlyTargetVsActual(int $userId): array
    {
        $rows = Bet::where('user_id', $userId)
            ->where('is_settled', true)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->selectRaw("
                DATE_FORMAT(races.race_date, '%Y-%m') as ym,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret
            ")
            ->groupBy('ym')
            ->orderBy('ym', 'desc')
            ->limit(6)
            ->get();

        if ($rows->isEmpty()) return ['target_roi' => 100, 'actual' => []];

        $avgRoi = $rows->avg(fn($r) => $r->stake > 0 ? $r->ret / $r->stake * 100 : 0);

        return [
            'target_roi' => 100,  // 100%（収支トントン）を最低ライン
            'actual'     => $rows->reverse()->values()->map(fn($r) => [
                'ym'  => $r->ym,
                'roi' => $r->stake > 0 ? round($r->ret / $r->stake * 100, 1) : 0,
            ])->toArray(),
            'avg_roi'    => round($avgRoi, 1),
        ];
    }

    /**
     * 払戻金一覧（フィルタ・ソート・ページネーション）
     *  - 期間/券種/競馬場/金額帯/人気でフィルタ
     *  - 日付・金額・人気でソート
     *  - CSVエクスポート対応（?export=csv）
     */
    public function payoutsList(Request $request)
    {
        $kind        = $request->input('kind');
        $venueId     = $request->input('venue_id');
        $from        = $request->input('from');
        $to          = $request->input('to');
        $minAmount   = $request->input('min_amount');
        $maxAmount   = $request->input('max_amount');
        $popularity  = $request->input('popularity');
        $sort        = $request->input('sort', 'date_desc');

        $q = Payout::query()
            ->join('races', 'payouts.race_id', '=', 'races.id')
            ->leftJoin('venues', 'races.venue_id', '=', 'venues.id')
            ->select(
                'payouts.*',
                'races.race_date',
                'races.race_number',
                'races.name as race_name',
                'races.netkeiba_id',
                'venues.name as venue_name',
                'venues.id as vid'
            );

        if ($kind)       $q->where('payouts.kind', $kind);
        if ($venueId)    $q->where('races.venue_id', $venueId);
        if ($from)       $q->whereDate('races.race_date', '>=', $from);
        if ($to)         $q->whereDate('races.race_date', '<=', $to);
        if ($minAmount)  $q->where('payouts.amount', '>=', (int) $minAmount);
        if ($maxAmount)  $q->where('payouts.amount', '<=', (int) $maxAmount);
        if ($popularity) $q->where('payouts.popularity', (int) $popularity);

        match ($sort) {
            'amount_desc' => $q->orderByDesc('payouts.amount'),
            'amount_asc'  => $q->orderBy('payouts.amount'),
            'pop_desc'    => $q->orderByDesc('payouts.popularity'),
            'pop_asc'     => $q->orderBy('payouts.popularity'),
            'date_asc'    => $q->orderBy('races.race_date')->orderBy('races.race_number'),
            default       => $q->orderByDesc('races.race_date')->orderByDesc('races.race_number')->orderBy('payouts.kind'),
        };

        // CSVエクスポート
        if ($request->input('export') === 'csv') {
            return $this->exportPayoutsCsv($q);
        }

        // 集計サマリ（フィルタ後）— $q は select 済みなので、サマリ用に独立クエリを再構築
        $sumQuery = Payout::query()
            ->join('races', 'payouts.race_id', '=', 'races.id')
            ->leftJoin('venues', 'races.venue_id', '=', 'venues.id');
        if ($kind)       $sumQuery->where('payouts.kind', $kind);
        if ($venueId)    $sumQuery->where('races.venue_id', $venueId);
        if ($from)       $sumQuery->whereDate('races.race_date', '>=', $from);
        if ($to)         $sumQuery->whereDate('races.race_date', '<=', $to);
        if ($minAmount)  $sumQuery->where('payouts.amount', '>=', (int) $minAmount);
        if ($maxAmount)  $sumQuery->where('payouts.amount', '<=', (int) $maxAmount);
        if ($popularity) $sumQuery->where('payouts.popularity', (int) $popularity);

        $summaryRow = $sumQuery->selectRaw('
                COUNT(*) as cnt,
                COALESCE(AVG(payouts.amount), 0) as avg_amt,
                COALESCE(MAX(payouts.amount), 0) as max_amt,
                COALESCE(MIN(payouts.amount), 0) as min_amt
            ')->first();
        $summary = [
            'cnt' => (int) ($summaryRow->cnt ?? 0),
            'avg' => (int) round($summaryRow->avg_amt ?? 0),
            'max' => (int) ($summaryRow->max_amt ?? 0),
            'min' => (int) ($summaryRow->min_amt ?? 0),
        ];

        $payouts = $q->paginate(50)->withQueryString();

        $kinds  = Bet::KIND_LABELS;
        $venues = Venue::orderBy('code')->get();

        return view('bets.payouts_list', compact(
            'payouts', 'summary', 'kinds', 'venues',
            'kind', 'venueId', 'from', 'to',
            'minAmount', 'maxAmount', 'popularity', 'sort'
        ));
    }

    /**
     * 払戻一覧をCSVでストリームダウンロード
     */
    protected function exportPayoutsCsv($q): Response
    {
        $rows = (clone $q)->limit(50000)->get();

        $csv = "日付,競馬場,R,レース名,券種,組合せ,払戻金額,人気\n";
        foreach ($rows as $r) {
            $row = [
                $r->race_date,
                $r->venue_name,
                $r->race_number,
                str_replace([',', '"', "\n"], ['、', '”', ' '], $r->race_name ?? ''),
                Bet::KIND_LABELS[$r->kind] ?? $r->kind,
                $r->combination,
                $r->amount,
                $r->popularity ?? '',
            ];
            $csv .= implode(',', $row) . "\n";
        }
        // Excel互換のためBOM付きUTF-8
        $body = "\xEF\xBB\xBF" . $csv;

        $filename = 'payouts_' . now()->format('Ymd_His') . '.csv';
        return response($body, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * 払戻傾向画面（自分の馬券に関係なく、netkeiba取込済の全レース母集団から）
     */
    public function payouts(Request $request): View
    {
        $kind = $request->input('kind', 'san-fuku');

        // 券種別の平均配当
        $kindStats = Payout::selectRaw('
                kind,
                COUNT(*) as cnt,
                AVG(amount) as avg_amount,
                MAX(amount) as max_amount,
                MIN(amount) as min_amount
            ')
            ->groupBy('kind')
            ->get()
            ->map(fn($r) => [
                'kind'       => $r->kind,
                'kind_label' => Bet::KIND_LABELS[$r->kind] ?? $r->kind,
                'cnt'        => (int) $r->cnt,
                'avg'        => (int) round($r->avg_amount),
                'max'        => (int) $r->max_amount,
                'min'        => (int) $r->min_amount,
            ])
            ->sortBy(fn($r) => array_search($r['kind'], array_keys(Bet::KIND_LABELS)))
            ->values();

        // 選択した券種の人気別払戻分布
        $popDist = Payout::where('kind', $kind)
            ->whereNotNull('popularity')
            ->selectRaw('popularity, COUNT(*) as cnt, AVG(amount) as avg_amount, MAX(amount) as max_amount')
            ->groupBy('popularity')
            ->orderBy('popularity')
            ->get()
            ->map(fn($r) => [
                'popularity' => (int) $r->popularity,
                'cnt'        => (int) $r->cnt,
                'avg'        => (int) round($r->avg_amount),
                'max'        => (int) $r->max_amount,
            ]);

        // 配当帯別レース数（選択した券種）
        $bands = [
            ['lt' => 1000,    'label' => '〜10倍'],
            ['lt' => 3000,    'label' => '10〜30倍'],
            ['lt' => 10000,   'label' => '30〜100倍'],
            ['lt' => 30000,   'label' => '100〜300倍'],
            ['lt' => 100000,  'label' => '300〜1000倍'],
            ['lt' => 99999999,'label' => '1000倍〜'],
        ];
        $payouts = Payout::where('kind', $kind)->pluck('amount');
        $bandCounts = [];
        $prev = 0;
        foreach ($bands as $b) {
            $cnt = $payouts->filter(fn($a) => $a >= $prev && $a < $b['lt'])->count();
            $bandCounts[] = ['label' => $b['label'], 'cnt' => $cnt];
            $prev = $b['lt'];
        }

        // 高額配当TOP20
        $highest = Payout::with('race.venue')
            ->where('kind', $kind)
            ->orderByDesc('amount')
            ->limit(20)
            ->get();

        return view('bets.payouts', compact('kindStats', 'popDist', 'bandCounts', 'highest', 'kind'));
    }
}
