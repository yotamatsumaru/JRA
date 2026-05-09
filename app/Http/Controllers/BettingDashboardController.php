<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Payout;
use App\Models\Venue;
use Illuminate\Http\Request;
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

        return view('bets.dashboard', compact(
            'kpi', 'monthly', 'cumulative', 'byKind', 'byVenue', 'byTrack',
            'byJockey', 'byHorse', 'bestPayouts', 'streaks', 'monthlyTarget',
            'from', 'to'
        ));
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
