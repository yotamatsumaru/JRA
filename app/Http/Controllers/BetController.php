<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Race;
use App\Services\BetTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BetController extends Controller
{
    public function __construct(protected BetTicketService $tickets)
    {
    }

    public function index(Request $request): View
    {
        $userId = Auth::id();

        $query = Bet::with(['race.venue', 'legs'])
            ->where('user_id', $userId);

        // フィルタ
        if ($request->filled('kind')) {
            $query->where('kind', $request->kind);
        }
        if ($request->filled('status')) {
            match ($request->status) {
                'hit'    => $query->where('hit_count', '>', 0),
                'miss'   => $query->where('is_settled', true)->where('hit_count', 0),
                'open'   => $query->where('is_settled', false),
                default  => null,
            };
        }
        if ($request->filled('from')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        }
        if ($request->filled('to')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        }
        if ($request->filled('venue_id')) {
            $query->whereHas('race', fn($q) => $q->where('venue_id', $request->venue_id));
        }

        $bets = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // 集計サマリ
        $summary = $this->summary($userId, $request);

        $venues = \App\Models\Venue::orderBy('code')->get();
        $kinds  = Bet::KIND_LABELS;

        return view('bets.index', compact('bets', 'summary', 'venues', 'kinds'));
    }

    protected function summary(int $userId, Request $request): array
    {
        $base = Bet::where('user_id', $userId);
        if ($request->filled('from')) $base->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        if ($request->filled('to'))   $base->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        if ($request->filled('venue_id')) $base->whereHas('race', fn($q) => $q->where('venue_id', $request->venue_id));
        if ($request->filled('kind')) $base->where('kind', $request->kind);

        $row = (clone $base)->selectRaw('
            COUNT(*) as bet_count,
            COALESCE(SUM(total_stake),0)  as stake,
            COALESCE(SUM(total_return),0) as ret,
            COALESCE(SUM(CASE WHEN hit_count > 0 THEN 1 ELSE 0 END),0) as hits,
            COALESCE(SUM(CASE WHEN is_settled = 1 THEN 1 ELSE 0 END),0) as settled
        ')->first();

        $stake = (int) ($row->stake ?? 0);
        $ret   = (int) ($row->ret ?? 0);

        return [
            'count'    => (int) ($row->bet_count ?? 0),
            'stake'    => $stake,
            'return'   => $ret,
            'profit'   => $ret - $stake,
            'hits'     => (int) ($row->hits ?? 0),
            'settled'  => (int) ($row->settled ?? 0),
            'hit_rate' => ($row->settled ?? 0) > 0 ? round($row->hits / $row->settled * 100, 1) : null,
            'roi'      => $stake > 0 ? round($ret / $stake * 100, 1) : null,
        ];
    }

    public function create(Request $request): View
    {
        $race = null;
        if ($request->filled('race_id')) {
            $race = Race::with(['venue', 'results'])->find($request->race_id);
        }
        $races = Race::with('venue')
            ->orderByDesc('race_date')
            ->orderByDesc('race_number')
            ->limit(100)
            ->get();
        $kinds   = Bet::KIND_LABELS;
        $methods = Bet::METHOD_LABELS;

        return view('bets.create', compact('race', 'races', 'kinds', 'methods'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBet($request);
        $bet = $this->save(new Bet(['user_id' => Auth::id()]), $validated);

        // レース結果がすでに登録済なら自動精算
        $this->autoSettleIfPossible($bet);

        $this->audit('bet.create', $bet, [
            'kind' => $bet->kind, 'method' => $bet->method,
            'points' => $bet->points, 'total_stake' => $bet->total_stake,
            'race_id' => $bet->race_id,
        ]);

        return redirect()->route('bets.show', $bet)
            ->with('status', '馬券を登録しました（' . $bet->points . '点 ' . number_format($bet->total_stake) . '円）');
    }

    public function show(Bet $bet): View
    {
        $this->authorizeBet($bet);
        $bet->load(['race.venue', 'race.results.horse', 'legs', 'race.payouts']);
        return view('bets.show', compact('bet'));
    }

    public function edit(Bet $bet): View
    {
        $this->authorizeBet($bet);
        $bet->load('race.venue', 'legs');
        $races = Race::with('venue')->orderByDesc('race_date')->limit(100)->get();
        $kinds   = Bet::KIND_LABELS;
        $methods = Bet::METHOD_LABELS;
        return view('bets.edit', compact('bet', 'races', 'kinds', 'methods'));
    }

    public function update(Request $request, Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $validated = $this->validateBet($request);
        $bet = $this->save($bet, $validated);

        $this->autoSettleIfPossible($bet);

        $this->audit('bet.update', $bet, [
            'kind' => $bet->kind, 'method' => $bet->method,
            'points' => $bet->points, 'total_stake' => $bet->total_stake,
        ]);

        return redirect()->route('bets.show', $bet)->with('status', '馬券を更新しました');
    }

    public function destroy(Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $betId = $bet->id;
        $snapshot = ['kind' => $bet->kind, 'race_id' => $bet->race_id, 'total_stake' => $bet->total_stake];
        $bet->delete();
        $this->audit('bet.delete', null, ['bet_id' => $betId] + $snapshot);
        return redirect()->route('bets.index')->with('status', '馬券を削除しました');
    }

    /** 手動精算（レース結果を後から登録した場合の再判定にも使う） */
    public function settle(Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $result = $this->tickets->settle($bet);
        $this->audit('bet.settle', $bet, [
            'hit_count' => $result['hit_count'],
            'total_return' => $result['total_return'],
        ]);
        return back()->with('status', sprintf(
            '精算完了: %d点的中 / 払戻 %s円',
            $result['hit_count'],
            number_format($result['total_return'])
        ));
    }

    /**
     * 未精算馬券を一括精算 (Phase 2-F)
     *
     * is_settled=false かつ レース結果が確定済みの bets を全て settle する。
     * payouts が無いレースは hit 判定はできても払戻金額が 0 のままになる。
     */
    public function settleAll(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        // 結果が確定 (finish_position_int が1件以上ある) しているレースの未精算 bets
        $bets = Bet::where('user_id', $userId)
            ->where('is_settled', false)
            ->whereHas('race.results', fn($q) => $q->whereNotNull('finish_position_int'))
            ->with(['race.results', 'legs'])
            ->get();

        $settledCount = 0;
        $hitCount = 0;
        $totalReturn = 0;
        $errors = 0;

        foreach ($bets as $bet) {
            try {
                $r = $this->tickets->settle($bet);
                $settledCount++;
                $hitCount    += $r['hit_count'];
                $totalReturn += $r['total_return'];
            } catch (\Throwable $e) {
                \Log::warning('一括精算で失敗', ['bet_id' => $bet->id, 'error' => $e->getMessage()]);
                $errors++;
            }
        }

        $msg = sprintf(
            '%d 件の馬券を精算しました(的中 %d 点 / 払戻合計 %s 円)%s',
            $settledCount,
            $hitCount,
            number_format($totalReturn),
            $errors > 0 ? " / エラー {$errors} 件" : ''
        );
        $this->audit('bet.settle_all', null, [
            'settled' => $settledCount, 'hits' => $hitCount,
            'total_return' => $totalReturn, 'errors' => $errors,
        ]);
        return back()->with('status', $msg);
    }

    /** AuditLog 記録 (audit_logs テーブル未マイグレーションでも落ちないようガード) */
    protected function audit(string $action, ?\Illuminate\Database\Eloquent\Model $subject = null, array $meta = []): void
    {
        try {
            AuditLog::record($action, $subject, $meta);
        } catch (\Throwable $e) {
            // audit_logs テーブル未作成 / DB接続不可 などは無視
        }
    }

    // ==========================================================
    //  Phase 2-H: What-if シミュレーション
    // ==========================================================

    /**
     * What-if シミュレーション
     *  - 各 bet の unit_stake を倍率で増減した場合の合計収支を再計算
     *  - 不的中を全て賭け金 0 にした場合 (=的中だけ買えていれば)
     *  - 一定 ROI 閾値以上の券種だけに絞った場合の収支
     *  - 期間/券種でフィルタ
     */
    public function whatif(Request $request): View
    {
        $userId = Auth::id();
        $from   = $request->input('from');
        $to     = $request->input('to');
        $kind   = $request->input('kind');
        $multiplier = (float) $request->input('multiplier', 1.0);
        $multiplier = max(0.1, min(10.0, $multiplier));
        $minRoiKind = $request->filled('min_roi_kind') ? (float) $request->input('min_roi_kind') : null;

        $query = Bet::where('user_id', $userId)->where('is_settled', true);
        if ($from) $query->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $from));
        if ($to)   $query->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $to));
        if ($kind) $query->where('kind', $kind);

        $bets = $query->with('race')->get();

        // 実績ベースライン
        $actualStake  = (int) $bets->sum('total_stake');
        $actualReturn = (int) $bets->sum('total_return');
        $actualProfit = $actualReturn - $actualStake;
        $actualRoi    = $actualStake > 0 ? round($actualReturn / $actualStake * 100, 1) : null;

        // シナリオ1: 全買い目を multiplier 倍にした場合
        $scenarioMul = [
            'label'  => "全買い目 × {$multiplier}",
            'stake'  => (int) round($actualStake * $multiplier),
            'return' => (int) round($actualReturn * $multiplier),
        ];
        $scenarioMul['profit'] = $scenarioMul['return'] - $scenarioMul['stake'];
        $scenarioMul['roi']    = $scenarioMul['stake'] > 0 ? round($scenarioMul['return'] / $scenarioMul['stake'] * 100, 1) : null;

        // シナリオ2: 的中分のみ購入していた場合 (理想)
        $hitOnly = $bets->where('hit_count', '>', 0);
        $scenarioHit = [
            'label'  => '的中だけ購入していた場合',
            'stake'  => (int) $hitOnly->sum('total_stake'),
            'return' => (int) $hitOnly->sum('total_return'),
            'count'  => $hitOnly->count(),
        ];
        $scenarioHit['profit'] = $scenarioHit['return'] - $scenarioHit['stake'];
        $scenarioHit['roi']    = $scenarioHit['stake'] > 0 ? round($scenarioHit['return'] / $scenarioHit['stake'] * 100, 1) : null;

        // シナリオ3: 不的中だけスキップしていた場合（=シナリオ2 と同義だが、削減投資額を強調表示）
        $missCount = $bets->where('hit_count', 0)->count();
        $missStake = (int) $bets->where('hit_count', 0)->sum('total_stake');

        // シナリオ4: 券種別ROIが minRoiKind% 以上の券種だけに絞った場合
        $kindRoiTable = $bets->groupBy('kind')->map(function ($g, $k) {
            $stake = (int) $g->sum('total_stake');
            $ret   = (int) $g->sum('total_return');
            return [
                'kind'       => $k,
                'kind_label' => Bet::KIND_LABELS[$k] ?? $k,
                'stake'      => $stake,
                'return'     => $ret,
                'profit'     => $ret - $stake,
                'roi'        => $stake > 0 ? round($ret / $stake * 100, 1) : 0,
                'cnt'        => $g->count(),
            ];
        })->sortByDesc('roi')->values();

        $scenarioFilter = null;
        if ($minRoiKind !== null) {
            $okKinds = $kindRoiTable->where('roi', '>=', $minRoiKind)->pluck('kind')->all();
            $filtered = $bets->whereIn('kind', $okKinds);
            $st = (int) $filtered->sum('total_stake');
            $rt = (int) $filtered->sum('total_return');
            $scenarioFilter = [
                'label'   => "券種別ROI ≥ {$minRoiKind}% の券種だけ購入",
                'stake'   => $st,
                'return'  => $rt,
                'profit'  => $rt - $st,
                'roi'     => $st > 0 ? round($rt / $st * 100, 1) : null,
                'count'   => $filtered->count(),
                'kinds'   => $okKinds,
            ];
        }

        // シナリオ5: ケリー基準 (簡易版) - kind 別 ROI に応じて配分を最適化したと仮定
        // f* = (b*p - q) / b, ここで b=平均オッズ-1, p=的中率, q=1-p
        $scenarioKelly = null;
        if ($actualStake > 0) {
            $hitRate = $bets->count() > 0 ? $bets->where('hit_count', '>', 0)->count() / $bets->count() : 0;
            $avgOdds = $bets->where('hit_count', '>', 0)->avg(fn($b) => $b->total_stake > 0 ? $b->total_return / $b->total_stake : 0) ?? 1;
            $b = max(0.01, $avgOdds - 1);
            $kellyF = $hitRate > 0 ? max(0, min(1, ($b * $hitRate - (1 - $hitRate)) / $b)) : 0;
            $scenarioKelly = [
                'label'    => 'ケリー基準 (簡易) ベース投資配分',
                'hit_rate' => round($hitRate * 100, 1),
                'avg_odds' => round($avgOdds, 2),
                'fraction' => round($kellyF * 100, 1),
                'note'     => $kellyF > 0
                    ? sprintf('ケリー比率 %.1f%% → 100万円のバンクロールから %s 円推奨', $kellyF * 100, number_format(round(1000000 * $kellyF, -2)))
                    : 'ケリー基準では投資非推奨（期待値マイナス）',
            ];
        }

        $venues = \App\Models\Venue::orderBy('code')->get();
        $kinds  = Bet::KIND_LABELS;

        return view('bets.whatif', compact(
            'bets', 'venues', 'kinds',
            'actualStake', 'actualReturn', 'actualProfit', 'actualRoi',
            'scenarioMul', 'scenarioHit', 'scenarioFilter', 'scenarioKelly',
            'missCount', 'missStake',
            'kindRoiTable',
            'from', 'to', 'kind', 'multiplier', 'minRoiKind'
        ));
    }

    // ==========================================================
    //  Phase 2-L: 馬券一覧 CSV エクスポート
    // ==========================================================

    /**
     * 馬券一覧を CSV でストリームダウンロード
     */
    public function exportCsv(Request $request)
    {
        $userId = Auth::id();
        $query = Bet::with(['race.venue', 'legs'])->where('user_id', $userId);
        if ($request->filled('kind')) {
            $query->where('kind', $request->kind);
        }
        if ($request->filled('status')) {
            match ($request->status) {
                'hit'    => $query->where('hit_count', '>', 0),
                'miss'   => $query->where('is_settled', true)->where('hit_count', 0),
                'open'   => $query->where('is_settled', false),
                default  => null,
            };
        }
        if ($request->filled('from')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        }
        if ($request->filled('to')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        }
        if ($request->filled('venue_id')) {
            $query->whereHas('race', fn($q) => $q->where('venue_id', $request->venue_id));
        }
        $bets = $query->orderByDesc('id')->limit(50000)->get();

        $csv  = "ID,日付,競馬場,R,レース名,券種,方式,点数,単位,投資,払戻,収支,的中数,精算済,組合せ,メモ\n";
        foreach ($bets as $b) {
            $combos = $b->legs->pluck('combination')->implode(' ');
            $row = [
                $b->id,
                optional($b->race?->race_date)->format('Y-m-d'),
                $b->race?->venue?->name,
                $b->race?->race_number,
                str_replace([',', '"', "\n"], ['、', '”', ' '], $b->race?->name ?? ''),
                Bet::KIND_LABELS[$b->kind] ?? $b->kind,
                Bet::METHOD_LABELS[$b->method] ?? $b->method,
                $b->points,
                $b->unit_stake,
                $b->total_stake,
                $b->total_return,
                $b->total_return - $b->total_stake,
                $b->hit_count,
                $b->is_settled ? 'Y' : 'N',
                str_replace([',', '"', "\n"], ['、', '”', ' '], $combos),
                str_replace([',', '"', "\n"], ['、', '”', ' '], (string) $b->memo),
            ];
            $csv .= implode(',', $row) . "\n";
        }
        $body = "\xEF\xBB\xBF" . $csv;   // BOM 付き UTF-8 (Excel 互換)

        $filename = 'bets_' . now()->format('Ymd_His') . '.csv';
        return response($body, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * 印刷用ビュー (PDF はブラウザ印刷から → PDF 化を推奨)
     */
    public function printView(Request $request): View
    {
        $userId = Auth::id();
        $query = Bet::with(['race.venue', 'legs'])->where('user_id', $userId);
        if ($request->filled('kind'))   $query->where('kind', $request->kind);
        if ($request->filled('from'))   $query->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        if ($request->filled('to'))     $query->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        if ($request->filled('status')) {
            match ($request->status) {
                'hit'    => $query->where('hit_count', '>', 0),
                'miss'   => $query->where('is_settled', true)->where('hit_count', 0),
                'open'   => $query->where('is_settled', false),
                default  => null,
            };
        }
        $bets = $query->orderByDesc('id')->limit(2000)->get();

        $stake  = (int) $bets->sum('total_stake');
        $ret    = (int) $bets->sum('total_return');
        $hits   = $bets->where('hit_count', '>', 0)->count();
        $summary = [
            'count'    => $bets->count(),
            'stake'    => $stake,
            'return'   => $ret,
            'profit'   => $ret - $stake,
            'hits'     => $hits,
            'roi'      => $stake > 0 ? round($ret / $stake * 100, 1) : null,
            'hit_rate' => $bets->count() > 0 ? round($hits / $bets->count() * 100, 1) : null,
        ];

        return view('bets.print', compact('bets', 'summary'));
    }

    // ==========================================================
    //  内部ヘルパー
    // ==========================================================

    protected function authorizeBet(Bet $bet): void
    {
        abort_unless($bet->user_id === Auth::id(), 403);
    }

    protected function validateBet(Request $request): array
    {
        return $request->validate([
            'race_id'      => ['required', 'exists:races,id'],
            'kind'         => ['required', 'in:tan,fuku,waku-ren,uma-ren,uma-tan,wide,san-fuku,san-tan'],
            'method'       => ['required', 'in:single,box,formation'],
            'unit_stake'   => ['required', 'integer', 'min:100', 'max:1000000'],
            'numbers'      => ['nullable', 'array'],
            'numbers.*'    => ['integer', 'min:1', 'max:18'],
            'axis'         => ['nullable', 'array'],
            'axis.*'       => ['integer', 'min:1', 'max:18'],
            'second'       => ['nullable', 'array'],
            'second.*'     => ['integer', 'min:1', 'max:18'],
            'third'        => ['nullable', 'array'],
            'third.*'      => ['integer', 'min:1', 'max:18'],
            'purchased_at' => ['nullable', 'date'],
            'memo'         => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** Bet本体保存＆BetLeg展開 */
    protected function save(Bet $bet, array $v): Bet
    {
        return DB::transaction(function () use ($bet, $v) {
            $selection = [
                'numbers' => $v['numbers'] ?? [],
                'axis'    => $v['axis']    ?? [],
                'second'  => $v['second']  ?? [],
                'third'   => $v['third']   ?? [],
            ];

            $combos = $this->tickets->expandCombinations($v['kind'], $v['method'], $selection);
            if (empty($combos)) {
                abort(422, '買い目の組合せが0点です。入力を確認してください。');
            }

            $bet->fill([
                'race_id'      => $v['race_id'],
                'kind'         => $v['kind'],
                'method'       => $v['method'],
                'unit_stake'   => (int) $v['unit_stake'],
                'points'       => count($combos),
                'total_stake'  => count($combos) * (int) $v['unit_stake'],
                'selection'    => $selection,
                'purchased_at' => $v['purchased_at'] ?? null,
                'memo'         => $v['memo'] ?? null,
                'is_settled'   => false,
                'hit_count'    => 0,
                'total_return' => 0,
            ]);
            $bet->save();

            // 既存の legs を全削除して再作成
            $bet->legs()->delete();
            $rows = [];
            foreach ($combos as $c) {
                $rows[] = [
                    'bet_id'      => $bet->id,
                    'combination' => $c,
                    'stake'       => (int) $v['unit_stake'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
            BetLeg::insert($rows);

            return $bet->fresh('legs');
        });
    }

    /** レース結果が登録済なら自動精算 */
    protected function autoSettleIfPossible(Bet $bet): void
    {
        $bet->loadMissing('race.results');
        if ($bet->race && $bet->race->results->isNotEmpty()) {
            $this->tickets->settle($bet);
        }
    }
}
